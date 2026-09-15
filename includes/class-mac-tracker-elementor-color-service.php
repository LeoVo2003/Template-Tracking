<?php

defined( 'ABSPATH' ) || exit;

/**
 * Extract only Elementor Global Color variables. This deliberately does not
 * inspect ordinary CSS declarations, page images, or any external drive link.
 */
class MAC_Tracker_Elementor_Color_Service {

	const MAX_COLORS = 6;
	const MAX_STYLESHEETS = 12;
	const CRON_HOOK = 'mac_tracker_extract_elementor_colors';
	const STATE_OPTION = 'mac_tracker_color_extract_state';
	const BATCH_SIZE = 6;

	private $repository;

	public function __construct( MAC_Tracker_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run_queued_batch' ) );
	}

	/** Queue every currently unextracted website and return without blocking wp-admin. */
	public function queue_all() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return array( 'queued' => true, 'remaining' => $this->repository->pending_color_snapshot_count() );
		}
		$remaining = $this->repository->pending_color_snapshot_count();
		if ( 0 === $remaining ) {
			return array( 'queued' => false, 'remaining' => 0 );
		}
		update_option( self::STATE_OPTION, array( 'status' => 'queued', 'processed' => 0, 'found' => 0, 'failed' => 0, 'remaining' => $remaining, 'updated_at' => MAC_Tracker_Time::now_utc() ), false );
		$scheduled = wp_schedule_single_event( time(), self::CRON_HOOK, array(), true );
		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return array( 'queued' => true, 'remaining' => $remaining );
	}

	/** Process a small batch and schedule the next one until the queue is empty. */
	public function run_queued_batch() {
		$state = (array) get_option( self::STATE_OPTION, array() );
		$ids   = $this->repository->pending_color_snapshot_ids( self::BATCH_SIZE );
		if ( empty( $ids ) ) {
			$state['status'] = 'complete';
			$state['remaining'] = 0;
			$state['updated_at'] = MAC_Tracker_Time::now_utc();
			update_option( self::STATE_OPTION, $state, false );
			return;
		}

		$state['status'] = 'running';
		foreach ( $ids as $snapshot_id ) {
			$result = $this->extract_for_snapshot( $snapshot_id );
			$state['processed'] = absint( $state['processed'] ?? 0 ) + 1;
			if ( is_wp_error( $result ) ) {
				$state['failed'] = absint( $state['failed'] ?? 0 ) + 1;
				$this->repository->record_color_failure( $snapshot_id, $result->get_error_message() );
			} else {
				$state['found'] = absint( $state['found'] ?? 0 ) + 1;
			}
		}
		$state['remaining'] = $this->repository->pending_color_snapshot_count();
		$state['updated_at'] = MAC_Tracker_Time::now_utc();
		if ( $state['remaining'] > 0 ) {
			$state['status'] = 'queued';
			update_option( self::STATE_OPTION, $state, false );
			wp_schedule_single_event( time() + 2, self::CRON_HOOK );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
			return;
		}
		$state['status'] = 'complete';
		update_option( self::STATE_OPTION, $state, false );
	}

	public function extraction_state() {
		return (array) get_option( self::STATE_OPTION, array() );
	}

	/** @return array|WP_Error */
	public function extract_for_snapshot( $snapshot_id ) {
		$snapshot = $this->repository->snapshot( $snapshot_id );
		if ( ! $snapshot ) {
			return new WP_Error( 'mac_tracker_color_snapshot_missing', 'That project snapshot no longer exists.' );
		}
		if ( ! empty( $snapshot['color_locked'] ) ) {
			return new WP_Error( 'mac_tracker_color_locked', 'This palette is approved and locked. It was not changed.' );
		}

		$url = $this->site_url( $snapshot['website_url'] ?? '' );
		if ( '' === $url ) {
			return new WP_Error( 'mac_tracker_color_website_missing', 'This snapshot has no valid website URL.' );
		}

		$html = $this->fetch( $url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$css_sources = array( $html );
		$style_urls  = $this->elementor_stylesheet_urls( $html, $url );
		foreach ( $style_urls as $style_url ) {
			$css = $this->fetch( $style_url );
			if ( ! is_wp_error( $css ) ) {
				$css_sources[] = $css;
			}
		}

		$variables = array();
		foreach ( $css_sources as $css ) {
			foreach ( $this->global_variables( $css ) as $name => $value ) {
				$variables[ $name ] = $value;
			}
		}
		$colors = array_values( array_unique( array_values( $variables ) ) );
		$colors = array_slice( $colors, 0, self::MAX_COLORS );
		if ( empty( $colors ) ) {
			return new WP_Error( 'mac_tracker_color_not_found', 'No usable Elementor Global Color variables were found on this website.' );
		}

		$stored = $this->repository->upsert_color_record(
			(int) $snapshot['id'],
			'elementor_global',
			wp_json_encode(
				array(
					'website'          => $url,
					'stylesheet_urls'  => $style_urls,
					'variables'        => $variables,
				)
			),
			$colors,
			'waiting'
		);
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array( 'colors' => $colors, 'locked' => ! empty( $stored['locked'] ) );
	}

	private function fetch( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 3,
				'limit_response_size' => 750 * KB_IN_BYTES,
				'headers'             => array( 'User-Agent' => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mac_tracker_color_fetch', 'Could not read website: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'mac_tracker_color_http', sprintf( 'Website returned HTTP %d while reading Elementor colors.', $status ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	private function elementor_stylesheet_urls( $html, $base_url ) {
		$urls = array();
		if ( ! preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i', (string) $html, $matches ) ) {
			return $urls;
		}
		foreach ( $matches[1] as $href ) {
			$href = html_entity_decode( trim( (string) $href ), ENT_QUOTES, 'UTF-8' );
			if ( false === stripos( $href, 'elementor' ) ) {
				continue;
			}
			$url = $this->absolute_url( $href, $base_url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_STYLESHEETS );
	}

	private function global_variables( $css ) {
		$found = array();
		if ( ! preg_match_all( '/(--e-global-color-[a-z0-9_-]+)\s*:\s*([^;}]+)\s*[;}]/i', (string) $css, $matches, PREG_SET_ORDER ) ) {
			return $found;
		}
		foreach ( $matches as $match ) {
			$color = $this->normalize_color( $match[2] );
			if ( '' !== $color ) {
				$found[ strtolower( $match[1] ) ] = $color;
			}
		}
		return $found;
	}

	private function normalize_color( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/#([0-9a-f]{3}|[0-9a-f]{6})(?![0-9a-f])/i', $value, $hex ) ) {
			$hex = strtoupper( $hex[1] );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			return '#' . $hex;
		}
		if ( preg_match( '/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $value, $rgb ) ) {
			$channels = array_map( 'intval', array_slice( $rgb, 1, 3 ) );
			if ( max( $channels ) <= 255 ) {
				return sprintf( '#%02X%02X%02X', $channels[0], $channels[1], $channels[2] );
			}
		}
		return '';
	}

	private function site_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}

	private function absolute_url( $href, $base_url ) {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$base = wp_parse_url( $base_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}
		$origin = $base['scheme'] . '://' . $base['host'] . ( empty( $base['port'] ) ? '' : ':' . (int) $base['port'] );
		if ( 0 === strpos( $href, '//' ) ) {
			return $base['scheme'] . ':' . $href;
		}
		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}
		$path = isset( $base['path'] ) ? dirname( $base['path'] ) : '/';
		return $origin . rtrim( '/' === $path ? '' : $path, '/' ) . '/' . ltrim( $href, '/' );
	}
}
