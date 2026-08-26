<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Gemini_Client {

	/**
	 * Analyze one already-downloaded image. Gemini never receives Drive access.
	 *
	 * @param string $file_path Absolute local image path.
	 * @return array|WP_Error
	 */
	public function analyze_image( $file_path ) {
		if ( ! is_readable( $file_path ) || ! is_file( $file_path ) ) {
			return new WP_Error( 'mac_tracker_image_unreadable', 'Downloaded image is not readable.' );
		}

		$file_size = filesize( $file_path );
		if ( false === $file_size || $file_size <= 0 || $file_size > 20 * MB_IN_BYTES ) {
			return new WP_Error( 'mac_tracker_image_size', 'Inline Gemini images must be between 1 byte and 20 MB.' );
		}

		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $file_path ) : false;
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true ) ) {
			return new WP_Error( 'mac_tracker_image_type', 'Unsupported image type.' );
		}

		$api_key = MAC_Tracker_Crypto::decrypt(
			(string) get_option( 'mac_tracker_gemini_api_key', '' )
		);
		if ( '' === $api_key ) {
			return new WP_Error( 'mac_tracker_gemini_key_missing', 'Gemini API key is not configured.' );
		}

		$settings = get_option( 'mac_tracker_settings', array() );
		$model    = sanitize_key( (string) ( $settings['gemini_model'] ?? 'gemini-3-flash-preview' ) );
		$url      = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
			rawurlencode( $model )
		);

		$bytes = file_get_contents( $file_path );
		if ( false === $bytes ) {
			return new WP_Error( 'mac_tracker_image_read_failed', 'Unable to read the downloaded image.' );
		}

		$prompt = implode(
			"\n",
			array(
				'Detect only intentional color-palette swatches in this image.',
				'Return between 4 and 6 swatches, maximum 6.',
				'Ignore colors from photos, backgrounds, borders, shadows, and decorative content.',
				'For each swatch return bbox normalized from 0 to 1000 and transcribe printed HEX only when visibly present.',
				'Accept printed HEX with or without #. Never estimate HEX from visual appearance.',
				'If printed HEX is absent, return null. Return JSON only.',
			)
		);

		$payload = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
						array(
							'inline_data' => array(
								'mime_type' => $mime,
								'data'      => base64_encode( $bytes ),
							),
						),
					),
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'has_palette'   => array( 'type' => 'BOOLEAN' ),
						'detected_count'=> array( 'type' => 'INTEGER' ),
						'swatches'      => array(
							'type'     => 'ARRAY',
							'maxItems' => 6,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'bbox'        => array(
										'type'     => 'ARRAY',
										'minItems' => 4,
										'maxItems' => 4,
										'items'    => array( 'type' => 'INTEGER' ),
									),
									'printed_hex' => array(
										'type'     => 'STRING',
										'nullable' => true,
									),
									'confidence'  => array( 'type' => 'NUMBER' ),
								),
								'required'   => array( 'bbox', 'printed_hex', 'confidence' ),
							),
						),
					),
					'required'   => array( 'has_palette', 'detected_count', 'swatches' ),
				),
			),
		);

		$encoded_payload = wp_json_encode( $payload );
		if ( false === $encoded_payload ) {
			return new WP_Error( 'mac_tracker_gemini_encode_failed', 'Unable to encode the Gemini request.' );
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'headers'             => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'                => $encoded_payload,
				'timeout'             => 60,
				'redirection'         => 0,
				'limit_response_size' => MB_IN_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = isset( $body['error']['message'] )
				? (string) $body['error']['message']
				: sprintf( 'Gemini API returned HTTP %d.', $status_code );
			return new WP_Error( 'mac_tracker_gemini_http_error', $message );
		}

		$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		$data = json_decode( (string) $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mac_tracker_gemini_invalid_json', 'Gemini did not return the expected JSON.' );
		}

		return $data;
	}
}
