<?php

defined( 'ABSPATH' ) || exit;

/** Private REST bridge used only by the GitHub Actions capture/classify job. */
class MAC_Tracker_Visual_Service {

	const NAMESPACE = 'mac-tracker/v1';
	const HEADER = 'x-mac-tracker-automation';

	private $repository;

	public function __construct( MAC_Tracker_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/visual/queue', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'queue' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'stage' => array( 'default' => 'capture' ), 'limit' => array( 'default' => 10 ) ),
		) );
		register_rest_route( self::NAMESPACE, '/visual/ingest', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'ingest' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function queue( WP_REST_Request $request ) {
		if ( ! $this->authorized( $request ) ) { return new WP_Error( 'mac_tracker_visual_forbidden', 'Automation authorization failed.', array( 'status' => 401 ) ); }
		$stage = 'tone' === $request->get_param( 'stage' ) ? 'tone' : 'capture';
		return rest_ensure_response( array( 'items' => $this->repository->visual_queue( $stage, $request->get_param( 'limit' ) ), 'stage' => $stage ) );
	}

	public function ingest( WP_REST_Request $request ) {
		if ( ! $this->authorized( $request ) ) { return new WP_Error( 'mac_tracker_visual_forbidden', 'Automation authorization failed.', array( 'status' => 401 ) ); }
		$snapshot_id = absint( $request->get_param( 'snapshot_id' ) );
		$mode = sanitize_key( $request->get_param( 'mode' ) );
		if ( 'capture' === $mode ) {
			return $this->ingest_capture( $snapshot_id );
		}
		if ( 'tone' === $mode ) {
			$result = $this->repository->save_visual_tone( $snapshot_id, sanitize_text_field( $request->get_param( 'tone' ) ), sanitize_key( $request->get_param( 'confidence' ) ), sanitize_text_field( $request->get_param( 'reason' ) ), wp_json_encode( $request->get_json_params() ) );
		} elseif ( 'capture_failed' === $mode || 'tone_failed' === $mode ) {
			$result = $this->repository->save_visual_failure( $snapshot_id, 'tone_failed' === $mode ? 'tone' : 'capture', sanitize_text_field( $request->get_param( 'message' ) ) );
		} else {
			return new WP_Error( 'mac_tracker_visual_mode', 'Unsupported visual ingest mode.', array( 'status' => 400 ) );
		}
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'saved' => true ) );
	}

	private function ingest_capture( $snapshot_id ) {
		$files = $_FILES;
		if ( empty( $files['screenshot']['tmp_name'] ) ) { return new WP_Error( 'mac_tracker_visual_file', 'Screenshot file is required.', array( 'status' => 400 ) ); }
		if ( (int) $files['screenshot']['size'] > 10 * MB_IN_BYTES ) { return new WP_Error( 'mac_tracker_visual_size', 'Screenshot must be 10 MB or smaller.', array( 'status' => 413 ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_handle_upload( 'screenshot', 0, array( 'post_title' => 'MAC Tracker visual #' . $snapshot_id ) );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		$url = (string) wp_get_attachment_url( $attachment_id );
		$result = $this->repository->save_visual_capture( $snapshot_id, $attachment_id, $url );
		if ( is_wp_error( $result ) ) { wp_delete_attachment( $attachment_id, true ); return $result; }
		return rest_ensure_response( array( 'saved' => true, 'screenshot_url' => $url ) );
	}

	private function authorized( WP_REST_Request $request ) {
		$stored = MAC_Tracker_Crypto::decrypt( get_option( 'mac_tracker_visual_secret', '' ) );
		$given = trim( (string) $request->get_header( self::HEADER ) );
		return '' !== $stored && '' !== $given && hash_equals( $stored, $given );
	}
}
