<?php

defined( 'ABSPATH' ) || exit;

/**
 * Extract only Elementor Global Color variables. This deliberately does not
 * inspect ordinary CSS declarations, page images, or any external drive link.
 */
class MAC_Tracker_Elementor_Color_Service {

	const MAX_COLORS = 6;
	const MAX_STYLESHEETS = 12;

	private $repository;

	public function __construct( MAC_Tracker_Repository $repository ) {
		$this->repository = $repository;
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
