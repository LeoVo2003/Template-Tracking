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
		register_rest_route( self::NAMESPACE, '/visual/config', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'config' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/visual/jobs/claim', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'claim_jobs' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'run_mode' => array( 'default' => 'batch' ), 'stage' => array( 'default' => 'full' ), 'target_ids' => array( 'default' => array() ), 'limit' => array( 'default' => 10 ) ),
		) );
		// Retained for a release so an older runner fails safely rather than exposing
		// an unauthenticated queue. New runners must use scoped jobs/claim.
		register_rest_route( self::NAMESPACE, '/visual/queue', array(
			'methods'             => WP_REST_Server::CREATABLE,
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

	/** Read-only worker configuration. API secrets remain GitHub-only. */
	public function config( WP_REST_Request $request ) {
		if ( ! $this->authorized( $request ) ) { return new WP_Error( 'mac_tracker_visual_forbidden', 'Automation authorization failed.', array( 'status' => 401 ) ); }
		// V3.2 has a benchmark gate. Keep cloud workers manual-first until the
		// reviewed dataset proves the new semantic pipeline is production-ready.
		$mode = 'manual';
		$strategy = sanitize_key( (string) get_option( 'mac_tracker_visual_ai_strategy', 'smart' ) );
		if ( ! in_array( $strategy, array( 'off', 'qwen', 'gemini', 'smart' ), true ) ) { $strategy = 'smart'; }
		return rest_ensure_response( array(
			'mode' => $mode,
			'ai_strategy' => $strategy,
			'pipeline_version' => 2,
			'auto_accept_threshold' => (float) get_option( 'mac_tracker_visual_auto_accept', 0.85 ),
			'max_capture_retries' => max( 0, min( 3, absint( get_option( 'mac_tracker_visual_max_capture_retries', 3 ) ) ) ),
			'gemini_daily_budget_per_key' => max( 1, min( 20, absint( get_option( 'mac_tracker_visual_gemini_daily_budget', 8 ) ) ) ),
		) );
	}

	public function queue( WP_REST_Request $request ) {
		if ( ! $this->authorized( $request ) ) { return new WP_Error( 'mac_tracker_visual_forbidden', 'Automation authorization failed.', array( 'status' => 401 ) ); }
		$stage = 'tone' === $request->get_param( 'stage' ) ? 'tone' : 'capture';
		return rest_ensure_response( array( 'items' => $this->repository->visual_queue( $stage, $request->get_param( 'limit' ) ), 'stage' => $stage ) );
	}

	/** Claim a precise manual scope or a single logical-website batch plan. */
	public function claim_jobs( WP_REST_Request $request ) {
		if ( ! $this->authorized( $request ) ) { return new WP_Error( 'mac_tracker_visual_forbidden', 'Automation authorization failed.', array( 'status' => 401 ) ); }
		$run_mode = 'targeted' === sanitize_key( (string) $request->get_param( 'run_mode' ) ) ? 'targeted' : 'batch';
		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( ! in_array( $stage, array( 'capture', 'tone', 'full', 'auto' ), true ) ) { return new WP_Error( 'mac_tracker_visual_scope', 'Invalid Visual Tone job stage.', array( 'status' => 400 ) ); }
		$target_ids = $request->get_param( 'target_ids' );
		if ( is_string( $target_ids ) ) { $target_ids = json_decode( $target_ids, true ); }
		if ( ! is_array( $target_ids ) ) { return new WP_Error( 'mac_tracker_visual_targets', 'target_ids must be a JSON array.', array( 'status' => 400 ) ); }
		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );
		$result = $this->repository->visual_claim_jobs( $run_mode, $stage, $target_ids, $request->get_param( 'limit' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function ingest( WP_REST_Request $request ) {
		if ( ! $this->authorized( $request ) ) { return new WP_Error( 'mac_tracker_visual_forbidden', 'Automation authorization failed.', array( 'status' => 401 ) ); }
		$snapshot_id = absint( $request->get_param( 'snapshot_id' ) );
		$mode = sanitize_key( $request->get_param( 'mode' ) );
		$job_token = sanitize_text_field( (string) $request->get_param( 'job_token' ) );
		if ( '' === $job_token ) { return new WP_Error( 'mac_tracker_visual_token', 'Visual job token is required.', array( 'status' => 400 ) ); }
		if ( 'capture' === $mode ) {
			return $this->ingest_capture( $snapshot_id, $job_token );
		}
		$raw_json = (string) $request->get_param( 'raw_json' );
		if ( strlen( $raw_json ) > 60000 ) { $raw_json = substr( $raw_json, 0, 60000 ); }
		$metadata = array(
			'provider'      => sanitize_key( (string) $request->get_param( 'provider' ) ),
			'model'         => sanitize_text_field( (string) $request->get_param( 'model' ) ),
			'ai_confidence' => (float) $request->get_param( 'confidence' ),
			'needs_review'  => rest_sanitize_boolean( $request->get_param( 'needs_review' ) ),
			'tone_group'    => sanitize_text_field( (string) $request->get_param( 'tone_group' ) ),
			'precise_tone'  => sanitize_text_field( (string) $request->get_param( 'precise_tone' ) ),
		);
		if ( 'tone' === $mode ) {
			$result = $this->repository->save_visual_tone( $snapshot_id, sanitize_text_field( $request->get_param( 'tone' ) ), sanitize_text_field( $request->get_param( 'confidence' ) ), sanitize_text_field( $request->get_param( 'reason' ) ), $raw_json, $job_token, $metadata );
		} elseif ( 'tone_needs_review' === $mode ) {
			$metadata['needs_review'] = true;
			$result = $this->repository->save_visual_tone( $snapshot_id, sanitize_text_field( $request->get_param( 'tone' ) ), sanitize_text_field( $request->get_param( 'confidence' ) ), sanitize_text_field( $request->get_param( 'reason' ) ), $raw_json, $job_token, $metadata );
		} elseif ( 'tone_retry' === $mode ) {
			$result = $this->repository->save_visual_retry( $snapshot_id, 'tone', sanitize_text_field( $request->get_param( 'message' ) ), $job_token, sanitize_key( $request->get_param( 'error_code' ) ), absint( $request->get_param( 'retry_after_seconds' ) ), $raw_json );
		} elseif ( 'capture_started' === $mode || 'tone_started' === $mode ) {
			$result = $this->repository->mark_visual_stage( $snapshot_id, 'tone_started' === $mode ? 'tone' : 'capture', $job_token );
		} elseif ( 'capture_failed' === $mode || 'tone_failed' === $mode ) {
			$result = $this->repository->save_visual_failure( $snapshot_id, 'tone_failed' === $mode ? 'tone' : 'capture', sanitize_text_field( $request->get_param( 'message' ) ), $job_token, sanitize_key( $request->get_param( 'error_code' ) ) );
		} elseif ( 'tone_deferred' === $mode ) {
			$result = $this->repository->release_visual_claim( $snapshot_id, 'tone', $job_token );
		} else {
			return new WP_Error( 'mac_tracker_visual_mode', 'Unsupported visual ingest mode.', array( 'status' => 400 ) );
		}
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'saved' => true ) );
	}

	private function ingest_capture( $snapshot_id, $job_token ) {
		$files = $_FILES;
		$old_attachment_ids = $this->repository->visual_attachment_ids( array( $snapshot_id ) );
		if ( empty( $files['screenshot']['tmp_name'] ) ) { return new WP_Error( 'mac_tracker_visual_file', 'Screenshot file is required.', array( 'status' => 400 ) ); }
		if ( (int) $files['screenshot']['size'] > 10 * MB_IN_BYTES ) { return new WP_Error( 'mac_tracker_visual_size', 'Screenshot must be 10 MB or smaller.', array( 'status' => 413 ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_handle_upload( 'screenshot', 0, array( 'post_title' => 'MAC Tracker visual #' . $snapshot_id ) );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		$url = (string) wp_get_attachment_url( $attachment_id );
		$preview_id = 0;
		$preview_url = '';
		if ( ! empty( $files['ai_preview']['tmp_name'] ) ) {
			if ( (int) $files['ai_preview']['size'] > 5 * MB_IN_BYTES ) { wp_delete_attachment( $attachment_id, true ); return new WP_Error( 'mac_tracker_visual_preview_size', 'AI preview must be 5 MB or smaller.', array( 'status' => 413 ) ); }
			$preview_id = media_handle_upload( 'ai_preview', 0, array( 'post_title' => 'MAC Tracker AI preview #' . $snapshot_id ) );
			if ( is_wp_error( $preview_id ) ) { wp_delete_attachment( $attachment_id, true ); return $preview_id; }
			$preview_url = (string) wp_get_attachment_url( $preview_id );
		}
		$raw_bundle = isset( $_POST['bundle_json'] ) ? wp_unslash( $_POST['bundle_json'] ) : '';
		if ( strlen( (string) $raw_bundle ) > 400000 ) { wp_delete_attachment( $attachment_id, true ); if ( $preview_id ) { wp_delete_attachment( $preview_id, true ); } return new WP_Error( 'mac_tracker_visual_bundle_size', 'Capture bundle metadata is too large.', array( 'status' => 413 ) ); }
		$bundle = json_decode( (string) $raw_bundle, true );
		if ( ! is_array( $bundle ) ) { $bundle = array(); }
		$bundle['version'] = 2;
		$bundle['snapshot_id'] = $snapshot_id;
		$result = $this->repository->save_visual_capture( $snapshot_id, $attachment_id, $url, $job_token, $bundle, $preview_url, $preview_id );
		if ( is_wp_error( $result ) ) { wp_delete_attachment( $attachment_id, true ); if ( $preview_id ) { wp_delete_attachment( $preview_id, true ); } return $result; }
		foreach ( $old_attachment_ids as $old_attachment_id ) {
			if ( ! in_array( (int) $old_attachment_id, array( (int) $attachment_id, (int) $preview_id ), true ) ) { wp_delete_attachment( (int) $old_attachment_id, true ); }
		}
		return rest_ensure_response( array( 'saved' => true, 'screenshot_url' => $url, 'ai_preview_url' => $preview_url ) );
	}

	private function authorized( WP_REST_Request $request ) {
		$stored = MAC_Tracker_Crypto::decrypt( get_option( 'mac_tracker_visual_secret', '' ) );
		$given = trim( (string) $request->get_header( self::HEADER ) );
		return '' !== $stored && '' !== $given && hash_equals( $stored, $given );
	}
}
