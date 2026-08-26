<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Color_Service {

	/** @var MAC_Tracker_Repository */
	private $repository;

	public function __construct( MAC_Tracker_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Create the one color record associated with a project source.
	 * Plain HEX sources can become pending immediately; image sources wait for
	 * the Drive/download/Gemini worker that will be connected next.
	 *
	 * @param int    $project_id Local project ID.
	 * @param string $raw Original WPM template-color value.
	 * @return int|WP_Error|null
	 */
	public function capture_source( $project_id, $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return $this->repository->clear_unapproved_palette( $project_id );
		}

		$source_type = $this->detect_source_type( $raw );
		$record_id   = $this->repository->upsert_color_source( $project_id, $source_type, $raw );

		if ( is_wp_error( $record_id ) ) {
			return $record_id;
		}

		$colors = $this->extract_hex_candidates( $raw );
		$count  = count( $colors );

		if ( $count >= 4 && $count <= 6 && 'hex_text' === $source_type ) {
			$tool_colors = array();
			foreach ( $colors as $color ) {
				$tool_colors[] = array(
					'hex'        => $color,
					'source'     => 'text_hex',
					'confidence' => 1,
				);
			}

			return $this->repository->save_pending_palette(
				$project_id,
				$tool_colors,
				array(),
				$colors,
				1
			);
		}

		return $record_id;
	}

	/**
	 * @param string $raw Original WPM value.
	 * @return string
	 */
	public function detect_source_type( $raw ) {
		$raw   = trim( (string) $raw );
		$lower = strtolower( $raw );

		if ( false !== strpos( $lower, 'drive.google.com/drive/folders/' ) ) {
			return 'drive_folder';
		}

		if (
			false !== strpos( $lower, 'drive.google.com/file/d/' )
			|| false !== strpos( $lower, 'drive.google.com/open?id=' )
		) {
			return 'drive_file';
		}

		if ( false !== strpos( $lower, 'prnt.sc/' ) || false !== strpos( $lower, 'prntscr.com/' ) ) {
			return 'prntsc';
		}

		if ( preg_match( '~https?://[^\s]+\.(?:jpe?g|png|webp|heic|heif)(?:\?[^\s]*)?~i', $raw ) ) {
			return 'image_url';
		}

		if ( false === strpos( $lower, 'http://' ) && false === strpos( $lower, 'https://' ) ) {
			$count = count( $this->extract_hex_candidates( $raw ) );
			if ( $count >= 1 ) {
				return 'hex_text';
			}
		}

		if ( false !== strpos( $lower, 'http://' ) || false !== strpos( $lower, 'https://' ) ) {
			return 'mixed_url';
		}

		return 'unknown';
	}

	/**
	 * Extract explicit HEX tokens only. URL fragments and arbitrary image pixels
	 * are deliberately ignored here.
	 *
	 * @param string $raw Source text.
	 * @return array Unique normalized HEX values.
	 */
	public function extract_hex_candidates( $raw ) {
		$raw    = (string) $raw;
		$colors = array();

		if ( preg_match_all( '/#([0-9A-Fa-f]{6})(?![0-9A-Fa-f])/', $raw, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$colors[] = '#' . strtoupper( $match );
			}
		}

		$tokens = preg_split( '/[\s,;|]+/', $raw );
		foreach ( (array) $tokens as $token ) {
			$token = trim( $token, " \t\n\r\0\x0B[](){}<>.'\"" );
			if ( preg_match( '/^[0-9A-Fa-f]{6}$/', $token ) ) {
				$colors[] = '#' . strtoupper( $token );
			}
		}

		return array_values( array_unique( $colors ) );
	}

	/**
	 * Normalize Gemini/tool values before saving.
	 *
	 * @param array $colors Candidate values or objects containing hex.
	 * @return array
	 */
	public function normalize_colors( array $colors ) {
		$normalized = array();

		foreach ( $colors as $item ) {
			$value = is_array( $item ) ? ( $item['hex'] ?? '' ) : $item;
			$value = strtoupper( trim( (string) $value ) );
			if ( '#' !== substr( $value, 0, 1 ) ) {
				$value = '#' . $value;
			}

			if ( preg_match( '/^#[0-9A-F]{6}$/', $value ) ) {
				$normalized[] = $value;
			}
		}

		return array_slice( array_values( array_unique( $normalized ) ), 0, 6 );
	}
}
