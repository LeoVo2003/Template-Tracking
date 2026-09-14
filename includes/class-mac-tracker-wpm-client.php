<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only WPM REST client. Connection settings are supplied by the sync job.
 */
class MAC_Tracker_WPM_Client {

	const API_KEY_HEADER = 'Tracking-Template-Header';

	private $endpoint;
	private $secret;
	private $per_page;

	/**
	 * @param string $endpoint WPM list endpoint.
	 * @param string $secret   Header value. Never log it.
	 * @param int    $per_page Requested page size.
	 */
	public function __construct( $endpoint, $secret = '', $per_page = 100 ) {
		$this->endpoint = rtrim( trim( $endpoint ), '/' );
		$this->secret   = trim( (string) $secret );
		$this->per_page = max( 1, min( 100, (int) $per_page ) );
	}

	/**
	 * Fetch every WPM page. This method does not write WPM or local data.
	 *
	 * @param array $filters Additional request fields.
	 * @return array|WP_Error {items:array,pages:int,total:?int,raw_pages:array}
	 */
	public function fetch_all( array $filters = array() ) {
		if ( '' === $this->endpoint ) {
			return new WP_Error( 'mac_tracker_wpm_endpoint', 'WPM endpoint is required.' );
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $this->endpoint, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'mac_tracker_wpm_https', 'WPM endpoint must use HTTPS.' );
		}

		$page      = 1;
		$items     = array();
		$raw_pages = array();
		$total     = null;

		while ( true ) {
			$payload          = $filters;
			$payload['page']  = $page;
			$payload['per_page'] = $this->per_page;
			$response         = $this->request_page( $payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$raw_pages[] = $response;
			$page_items  = MAC_Tracker_Normalizer::response_items( $response );
			$items       = array_merge( $items, $page_items );
			$pagination  = MAC_Tracker_Normalizer::pagination( $response );
			$total       = null !== $pagination['total'] ? $pagination['total'] : $total;

			if ( empty( $page_items ) ) {
				break;
			}
			if ( null !== $pagination['total_pages'] && $page >= $pagination['total_pages'] ) {
				break;
			}
			if ( null === $pagination['total_pages'] && count( $page_items ) < $this->per_page ) {
				break;
			}
			++$page;
			if ( $page > 10000 ) {
				return new WP_Error( 'mac_tracker_wpm_pagination', 'WPM pagination exceeded the safety limit.' );
			}
		}

		return array(
			'items'     => $items,
			'pages'     => count( $raw_pages ),
			'total'     => $total,
			'raw_pages' => $raw_pages,
		);
	}

	public function fetch_project_by_id( $id ) {
		$id = absint( $id );
		if ( $id <= 0 || '' === $this->endpoint ) return new WP_Error( 'mac_tracker_wpm_id', 'Project ID is required.' );
		if ( 'https' !== strtolower( (string) wp_parse_url( $this->endpoint, PHP_URL_SCHEME ) ) ) return new WP_Error( 'mac_tracker_wpm_https', 'WPM endpoint must use HTTPS.' );
		$response = $this->request_url( $this->endpoint . '/' . $id, array() );
		if ( is_wp_error( $response ) ) return $response;
		$items = MAC_Tracker_Normalizer::response_items( $response );
		if ( ! empty( $items ) ) return $items[0];
		return isset( $response['id'] ) ? $response : new WP_Error( 'mac_tracker_wpm_empty', 'WPM returned no project.' );
	}

	/**
	 * @param array $payload Request JSON payload.
	 * @return array|WP_Error
	 */
	private function request_page( array $payload ) {
		return $this->request_url( $this->endpoint, $payload );
	}

	private function request_url( $url, array $payload ) {
		$headers = array(
			'Accept'     => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent' => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION,
		);
		if ( '' !== $this->secret ) {
			$headers[ self::API_KEY_HEADER ] = $this->secret;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'mac_tracker_wpm_http', 'WPM returned HTTP ' . $status . '.', array( 'status' => $status ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'mac_tracker_wpm_json', 'WPM returned invalid JSON.' );
		}

		return $decoded;
	}
}
