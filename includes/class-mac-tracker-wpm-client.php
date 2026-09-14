<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only client for the WPM Tracking Template API.
 *
 * The endpoint is deliberately constrained to the approved list route. It stops
 * a typo in Settings from becoming an opaque HTTP 404 in a background sync.
 */
class MAC_Tracker_WPM_Client {

	const API_KEY_HEADER   = 'Tracking-Template-Header';
	const EXPECTED_HOST    = 'wpm.macusaone.com';
	const EXPECTED_PATH    = '/api/v1/tracking-template/projects';
	const EXPECTED_ENDPOINT = 'https://wpm.macusaone.com/api/v1/tracking-template/projects';

	private $endpoint;
	private $secret;
	private $per_page;

	/**
	 * @param string $endpoint WPM list endpoint.
	 * @param string $secret   Header value. Never log it.
	 * @param int    $per_page Requested page size.
	 */
	public function __construct( $endpoint, $secret = '', $per_page = 100 ) {
		$this->endpoint = rtrim( trim( (string) $endpoint ), '/' );
		$this->secret   = trim( (string) $secret );
		$this->per_page = max( 1, min( 100, (int) $per_page ) );
	}

	/**
	 * Validate and canonicalize the only WPM route this plugin is allowed to call.
	 * The older route without /projects is corrected here because it is a common
	 * copy/paste mistake; all other paths are rejected before a sync is queued.
	 *
	 * @return string|WP_Error
	 */
	public static function normalize_endpoint( $endpoint ) {
		$endpoint = trim( (string) $endpoint );
		$parts    = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'mac_tracker_wpm_endpoint', 'Enter the complete WPM HTTPS endpoint.' );
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( 'mac_tracker_wpm_https', 'WPM endpoint must use HTTPS.' );
		}

		$host = strtolower( (string) $parts['host'] );
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		if ( '/api/v1/tracking-template' === $path ) {
			$path = self::EXPECTED_PATH;
		}
		if ( self::EXPECTED_HOST !== $host || self::EXPECTED_PATH !== $path || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return new WP_Error(
				'mac_tracker_wpm_endpoint',
				'Use this WPM list endpoint exactly: ' . self::EXPECTED_ENDPOINT
			);
		}

		return self::EXPECTED_ENDPOINT;
	}

	/** Fetch every WPM page. This method never writes WPM or local data. */
	public function fetch_all( array $filters = array() ) {
		$endpoint = self::normalize_endpoint( $this->endpoint );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$this->endpoint = $endpoint;

		$page      = 1;
		$items     = array();
		$raw_pages = array();
		$total     = null;

		while ( true ) {
			$payload             = $filters;
			$payload['page']     = $page;
			$payload['per_page'] = $this->per_page;
			$response            = $this->request_page( $payload );
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

	/**
	 * Make the smallest possible request to confirm the saved route and header.
	 * The payload is intentionally a GET body to match WPM's documented curl API.
	 */
	public function test_connection() {
		$endpoint = self::normalize_endpoint( $this->endpoint );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$this->endpoint = $endpoint;
		$response       = $this->request_page( array( 'page' => 1, 'per_page' => 1 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array( 'items' => count( MAC_Tracker_Normalizer::response_items( $response ) ) );
	}

	/** Fetch a project detail for bounded CSV-pin backfill. */
	public function fetch_project_by_id( $id ) {
		$id       = absint( $id );
		$endpoint = self::normalize_endpoint( $this->endpoint );
		if ( $id <= 0 ) {
			return new WP_Error( 'mac_tracker_wpm_id', 'Project ID is required.' );
		}
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$this->endpoint = $endpoint;
		$response       = $this->request_url( $this->endpoint . '/' . $id, array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$items = MAC_Tracker_Normalizer::response_items( $response );
		if ( ! empty( $items ) ) {
			return $items[0];
		}
		return isset( $response['id'] ) ? $response : new WP_Error( 'mac_tracker_wpm_empty', 'WPM returned no project.' );
	}

	/** @return array|WP_Error */
	private function request_page( array $payload ) {
		return $this->request_url( $this->endpoint, $payload );
	}

	/**
	 * WPM documents GET plus a JSON body. wp_safe_remote_request preserves that
	 * exact request shape while retaining WordPress' URL safety checks.
	 */
	private function request_url( $url, array $payload ) {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent'   => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION,
		);
		if ( '' !== $this->secret ) {
			$headers[ self::API_KEY_HEADER ] = $this->secret;
		}

		$response = wp_safe_remote_request(
			$url,
			array(
				'method'             => 'GET',
				'timeout'            => 30,
				'redirection'        => 0,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'headers'            => $headers,
				'body'               => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			return new WP_Error(
				'mac_tracker_wpm_http',
				sprintf( 'WPM returned HTTP %d at %s. Verify Settings → WPM endpoint.', $status, $path ),
				array( 'status' => $status, 'path' => $path )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'mac_tracker_wpm_json', 'WPM returned invalid JSON.' );
		}

		return $decoded;
	}
}
