<?php

defined( 'ABSPATH' ) || exit;

/**
 * Server-side GitHub Actions bridge for the Visual Tone workflow only.
 * The fine-grained token never leaves this class or a server-side HTTP request.
 */
class MAC_Tracker_GitHub_Actions {

	const OWNER = 'LeoVo2003';
	const REPOSITORY = 'Template-Tracking';
	const WORKFLOW_FILE = 'capture-visual-tone.yml';
	const WORKFLOW_PATH = '.github/workflows/capture-visual-tone.yml';

	private function token() {
		return MAC_Tracker_Crypto::decrypt( get_option( 'mac_tracker_github_dispatch_token', '' ) );
	}

	private function endpoint( $path ) {
		return 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPOSITORY . $path;
	}

	private function request( $method, $path, $body = null ) {
		$token = $this->token();
		if ( '' === $token ) { return new WP_Error( 'GITHUB_AUTH', 'GitHub workflow monitor unavailable. Configure a GitHub token with Actions access in Settings.' ); }
		$args = array(
			'timeout' => 20,
			'method' => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2026-03-10',
				'User-Agent' => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION,
			),
		);
		if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( $this->endpoint( $path ), $args );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'GITHUB_UNAVAILABLE', 'Could not refresh GitHub workflow status. Showing last known data.', array( 'status' => 503 ) ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw = (string) wp_remote_retrieve_body( $response );
		$data = '' === $raw ? array() : json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$code = 401 === $status ? 'GITHUB_AUTH' : ( 403 === $status ? 'GITHUB_FORBIDDEN' : ( 404 === $status ? 'GITHUB_NOT_FOUND' : ( 429 === $status ? 'GITHUB_RATE_LIMIT' : 'GITHUB_BAD_RESPONSE' ) ) );
			$message = 'GitHub workflow request failed.';
			if ( 'GITHUB_FORBIDDEN' === $code ) { $message = 'GitHub token lacks the required Actions permission. The monitor is read-only.'; }
			if ( 'GITHUB_AUTH' === $code ) { $message = 'GitHub token is invalid or no longer has repository access.'; }
			if ( 'GITHUB_RATE_LIMIT' === $code ) { $message = 'GitHub API rate limit reached. Showing last known data.'; }
			return new WP_Error( $code, $message, array( 'status' => $status, 'github' => is_array( $data ) ? $data : array() ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public function dispatch( array $inputs ) {
		return $this->request( 'POST', '/actions/workflows/' . self::WORKFLOW_FILE . '/dispatches', array( 'ref' => 'main', 'inputs' => $inputs ) );
	}

	/** List only the tracker workflow. Active results have a short cache. */
	public function list_visual_runs( $per_page = 10, $force = false ) {
		$per_page = max( 1, min( 20, absint( $per_page ) ) );
		$key = 'mac_tracker_visual_workflow_runs_' . $per_page;
		if ( ! $force ) { $cached = get_transient( $key ); if ( is_array( $cached ) ) { return $cached; } }
		$data = $this->request( 'GET', '/actions/workflows/' . self::WORKFLOW_FILE . '/runs?per_page=' . $per_page );
		if ( is_wp_error( $data ) ) { return $data; }
		$runs = array();
		foreach ( (array) ( $data['workflow_runs'] ?? array() ) as $run ) {
			if ( ! $this->is_visual_run( $run ) ) { continue; }
			$runs[] = $this->normalize_run( $run );
		}
		set_transient( $key, $runs, 8 );
		return $runs;
	}

	public function get_visual_run( $run_id, $force = false ) {
		$run_id = absint( $run_id );
		if ( $run_id <= 0 ) { return new WP_Error( 'GITHUB_NOT_FOUND', 'Invalid workflow run.' ); }
		$key = 'mac_tracker_visual_workflow_run_' . $run_id;
		if ( ! $force ) { $cached = get_transient( $key ); if ( is_array( $cached ) ) { return $cached; } }
		$data = $this->request( 'GET', '/actions/runs/' . $run_id );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( ! $this->is_visual_run( $data ) ) { return new WP_Error( 'GITHUB_NOT_FOUND', 'This run does not belong to the Visual Tone workflow.', array( 'status' => 404 ) ); }
		$run = $this->normalize_run( $data );
		set_transient( $key, $run, 8 );
		return $run;
	}

	public function get_visual_jobs( $run_id ) {
		$run = $this->get_visual_run( $run_id, true );
		if ( is_wp_error( $run ) ) { return $run; }
		$data = $this->request( 'GET', '/actions/runs/' . absint( $run_id ) . '/jobs?per_page=20' );
		if ( is_wp_error( $data ) ) { return $data; }
		$jobs = array();
		foreach ( (array) ( $data['jobs'] ?? array() ) as $job ) {
			$jobs[] = array(
				'name' => sanitize_text_field( (string) ( $job['name'] ?? '' ) ),
				'status' => sanitize_key( (string) ( $job['status'] ?? '' ) ),
				'conclusion' => sanitize_key( (string) ( $job['conclusion'] ?? '' ) ),
				'steps' => array_map( function( $step ) { return array( 'name' => sanitize_text_field( (string) ( $step['name'] ?? '' ) ), 'status' => sanitize_key( (string) ( $step['status'] ?? '' ) ), 'conclusion' => sanitize_key( (string) ( $step['conclusion'] ?? '' ) ); }, (array) ( $job['steps'] ?? array() ) ),
			);
		}
		return array( 'run' => $run, 'jobs' => $jobs );
	}

	public function action( $run_id, $action ) {
		$run = $this->get_visual_run( $run_id, true );
		if ( is_wp_error( $run ) ) { return $run; }
		$action = sanitize_key( $action );
		$paths = array( 'cancel' => '/actions/runs/' . absint( $run_id ) . '/cancel', 'rerun' => '/actions/runs/' . absint( $run_id ) . '/rerun', 'rerun_failed' => '/actions/runs/' . absint( $run_id ) . '/rerun-failed-jobs' );
		if ( ! isset( $paths[ $action ] ) ) { return new WP_Error( 'GITHUB_BAD_RESPONSE', 'Unsupported workflow action.', array( 'status' => 400 ) ); }
		if ( 'cancel' === $action && ! in_array( $run['status'], array( 'queued', 'in_progress' ), true ) ) { return new WP_Error( 'GITHUB_BAD_RESPONSE', 'Only queued or running Visual Tone runs can be cancelled.', array( 'status' => 409 ) ); }
		if ( 'cancel' !== $action && 'completed' !== $run['status'] ) { return new WP_Error( 'GITHUB_BAD_RESPONSE', 'GitHub can re-run only a completed workflow execution.', array( 'status' => 409 ) ); }
		$result = $this->request( 'POST', $paths[ $action ] );
		if ( is_wp_error( $result ) ) { return $result; }
		$this->clear_cache( $run_id );
		return $run;
	}

	public function clear_cache( $run_id = 0 ) {
		foreach ( array( 5, 10, 20 ) as $per_page ) { delete_transient( 'mac_tracker_visual_workflow_runs_' . $per_page ); }
		if ( $run_id ) { delete_transient( 'mac_tracker_visual_workflow_run_' . absint( $run_id ) ); }
	}

	private function is_visual_run( array $run ) {
		$path = (string) ( $run['path'] ?? '' );
		$workflow = (string) ( $run['workflow_url'] ?? '' );
		return 0 === strpos( $path, self::WORKFLOW_PATH ) || false !== strpos( $workflow, '/actions/workflows/' . self::WORKFLOW_FILE );
	}

	private function normalize_run( array $run ) {
		return array(
			'id' => absint( $run['id'] ?? 0 ), 'run_number' => absint( $run['run_number'] ?? 0 ), 'run_attempt' => max( 1, absint( $run['run_attempt'] ?? 1 ) ),
			'status' => sanitize_key( (string) ( $run['status'] ?? 'queued' ) ), 'conclusion' => sanitize_key( (string) ( $run['conclusion'] ?? '' ) ),
			'html_url' => esc_url_raw( (string) ( $run['html_url'] ?? '' ) ), 'event' => sanitize_key( (string) ( $run['event'] ?? '' ) ),
			'head_branch' => sanitize_text_field( (string) ( $run['head_branch'] ?? '' ) ), 'head_sha' => sanitize_text_field( (string) ( $run['head_sha'] ?? '' ) ),
			'created_at' => sanitize_text_field( (string) ( $run['created_at'] ?? '' ) ), 'updated_at' => sanitize_text_field( (string) ( $run['updated_at'] ?? '' ) ),
			'run_started_at' => sanitize_text_field( (string) ( $run['run_started_at'] ?? '' ) ),
		);
	}
}
