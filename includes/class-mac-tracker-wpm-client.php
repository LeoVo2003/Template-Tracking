<?php

defined( 'ABSPATH' ) || exit;

/**
 * Live WPM REST client (full-page fetches only).
 */
class MAC_Tracker_WPM_Client {

	const API_KEY_HEADER = 'Tracking-Template-Header';

	/**
	 * @param int $page Page number.
	 * @param int $limit Items per page.
	 * @return array|WP_Error
	 */
	public function fetch_page( $page = 1, $limit = 100 ) {
		$settings = $this->settings();
		$api_url  = esc_url_raw( (string) ( $settings['wpm_api_url'] ?? '' ) );
		if ( '' === $api_url ) {
			return new WP_Error( 'mac_tracker_wpm_url_missing', 'WPM API URL is not configured.' );
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $api_url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'mac_tracker_wpm_https_required', 'WPM API URL must use HTTPS.' );
		}

		$page  = max( 1, (int) $page );
		$limit = max( 1, min( 500, (int) $limit ) );
		$query = array(
			'page'       => $page,
			'per_page'   => $limit,
			'page_index' => $page,
			'page_size'  => $limit,
		);

		$url = add_query_arg( $query, $api_url );
		return $this->request_json( $url, $page, $limit );
	}

	/**
	 * Fetch one project by WPM id (pins missing from paginated list, e.g. some Cancel rows).
	 *
	 * @param int $wpm_project_id WPM project ID.
	 * @return array|WP_Error Normalized fetch_page-like payload with one item, or error.
	 */
	public function fetch_project_by_id( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		if ( $wpm_project_id <= 0 ) {
			return new WP_Error( 'mac_tracker_wpm_id_missing', 'WPM project id is required.' );
		}

		$settings = $this->settings();
		$api_url  = esc_url_raw( (string) ( $settings['wpm_api_url'] ?? '' ) );
		if ( '' === $api_url ) {
			return new WP_Error( 'mac_tracker_wpm_url_missing', 'WPM API URL is not configured.' );
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $api_url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'mac_tracker_wpm_https_required', 'WPM API URL must use HTTPS.' );
		}

		$base = untrailingslashit( preg_replace( '/\?.*$/', '', $api_url ) );
		$url  = $base . '/' . $wpm_project_id;
		$result = $this->request_json( $url, 1, 1 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		if ( empty( $items ) && isset( $result['raw'] ) && is_array( $result['raw'] ) ) {
			$raw = $result['raw'];
			if ( isset( $raw['id'] ) ) {
				$items = array( $raw );
			} elseif ( isset( $raw['data'] ) && is_array( $raw['data'] ) && isset( $raw['data']['id'] ) ) {
				$items = array( $raw['data'] );
			}
		}

		if ( empty( $items ) ) {
			return new WP_Error(
				'mac_tracker_wpm_project_missing',
				sprintf( 'WPM project #%d not returned by API.', $wpm_project_id )
			);
		}

		return array(
			'items'      => array( $items[0] ),
			'pagination' => array(
				'page'        => 1,
				'limit'       => 1,
				'total'       => 1,
				'total_pages' => 1,
			),
		);
	}

	/**
	 * @param string $url Absolute HTTPS URL.
	 * @param int    $page Page hint for pagination echo.
	 * @param int    $limit Limit hint.
	 * @return array|WP_Error
	 */
	private function request_json( $url, $page = 1, $limit = 100 ) {
		$settings = $this->settings();
		$headers  = array( 'Accept' => 'application/json' );
		$secret   = MAC_Tracker_Crypto::decrypt( (string) get_option( 'mac_tracker_wpm_secret', '' ) );
		$auth     = $settings['wpm_auth_type'] ?? 'api_key';

		if ( 'bearer' === $auth && '' !== $secret ) {
			$headers['Authorization'] = 'Bearer ' . $secret;
		} elseif ( 'api_key' === $auth && '' !== $secret ) {
			$headers[ self::API_KEY_HEADER ] = $secret;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'headers'             => $headers,
				'timeout'             => 30,
				'redirection'         => 0,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'user-agent'          => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body_raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'mac_tracker_wpm_http_error',
				$this->format_http_error( $status_code, $body_raw ),
				array(
					'status' => $status_code,
					'url'    => $url,
					'body'   => $this->truncate_for_log( $body_raw ),
				)
			);
		}

		$decoded = json_decode( $body_raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'mac_tracker_wpm_invalid_json', 'WPM API returned invalid JSON.' );
		}
		if ( isset( $decoded['success'] ) && false === $decoded['success'] ) {
			$message = isset( $decoded['message'] ) && is_scalar( $decoded['message'] )
				? (string) $decoded['message']
				: 'WPM API error.';
			$detail = $this->extract_error_detail( $decoded );
			if ( '' !== $detail && false === stripos( $message, $detail ) ) {
				$message .= ' — ' . $detail;
			}
			return new WP_Error( 'mac_tracker_wpm_api_error', $message );
		}

		// Single-project payloads: { data: { id: … } } or bare project object.
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) && isset( $decoded['data']['id'] ) && ! $this->is_list( $decoded['data'] ) ) {
			$items = array( $decoded['data'] );
		} elseif ( isset( $decoded['id'] ) && ( isset( $decoded['tasks'] ) || isset( $decoded['full_name'] ) || isset( $decoded['status'] ) ) ) {
			$items = array( $decoded );
		} elseif ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			$items = $decoded['data'];
		} elseif ( isset( $decoded['projects'] ) && is_array( $decoded['projects'] ) ) {
			$items = $decoded['projects'];
		} elseif ( $this->is_list( $decoded ) ) {
			$items = $decoded;
		} else {
			return new WP_Error( 'mac_tracker_wpm_shape_error', 'WPM JSON must contain a data or projects array.' );
		}

		$pagination = isset( $decoded['pagination'] ) && is_array( $decoded['pagination'] )
			? $decoded['pagination']
			: array();

		return array(
			'items'      => $items,
			'raw'        => $decoded,
			'pagination' => array(
				'page'        => (int) ( $pagination['page'] ?? ( $pagination['page_index'] ?? $page ) ),
				'limit'       => (int) ( $pagination['per_page'] ?? ( $pagination['page_size'] ?? ( $pagination['limit'] ?? $limit ) ) ),
				'total'       => isset( $pagination['total'] ) ? (int) $pagination['total'] : null,
				'total_pages' => isset( $pagination['total_pages'] )
					? (int) $pagination['total_pages']
					: ( isset( $pagination['total_page'] ) ? (int) $pagination['total_page'] : null ),
			),
		);
	}

	/**
	 * @return array
	 */
	private function settings() {
		$settings = get_option( 'mac_tracker_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * @param int    $status_code Response status.
	 * @param string $body_raw Raw response body.
	 * @return string
	 */
	private function format_http_error( $status_code, $body_raw ) {
		$parts = array( sprintf( 'HTTP %d', (int) $status_code ) );
		$decoded = json_decode( (string) $body_raw, true );
		if ( is_array( $decoded ) ) {
			$detail = $this->extract_error_detail( $decoded );
			if ( '' !== $detail ) {
				$parts[] = $detail;
			}
		}
		return implode( ' — ', $parts );
	}

	/**
	 * @param array $decoded Decoded JSON body.
	 * @return string
	 */
	private function extract_error_detail( array $decoded ) {
		$chunks = array();
		foreach ( array( 'message', 'error', 'detail', 'title', 'code' ) as $key ) {
			if ( ! isset( $decoded[ $key ] ) ) {
				continue;
			}
			$value = $decoded[ $key ];
			if ( is_scalar( $value ) ) {
				$text = trim( (string) $value );
				if ( '' !== $text ) {
					$chunks[] = $text;
				}
			} elseif ( is_array( $value ) ) {
				$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false !== $encoded && '[]' !== $encoded && '{}' !== $encoded ) {
					$chunks[] = $encoded;
				}
			}
		}

		if ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			$encoded = wp_json_encode( $decoded['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false !== $encoded ) {
				$chunks[] = 'errors=' . $encoded;
			}
		}

		return $this->truncate_for_log( implode( ' | ', $chunks ), 800 );
	}

	/**
	 * @param string $text Raw text.
	 * @param int    $max Maximum characters.
	 * @return string
	 */
	private function truncate_for_log( $text, $max = 1500 ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		$max  = max( 100, (int) $max );
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return substr( $text, 0, $max - 3 ) . '...';
	}

	/**
	 * @param array $value Array to test.
	 * @return bool
	 */
	private function is_list( array $value ) {
		return empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
