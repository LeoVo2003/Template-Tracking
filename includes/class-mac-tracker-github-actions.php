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
	const LOCAL_WORKFLOW_FILE = 'capture-visual-tone-local.yml';
	const LOCAL_WORKFLOW_PATH = '.github/workflows/capture-visual-tone-local.yml';

	private function token() {
		return MAC_Tracker_Crypto::decrypt( get_option( 'mac_tracker_github_dispatch_token', '' ) );
	}

	private function endpoint( $path ) {
		return 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPOSITORY . $path;
	}

	private function request( $method, $path, $body = null, $raw_response = false, $with_headers = false ) {
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
		$result = $raw_response ? $raw : ( is_array( $data ) ? $data : array() );
		return $with_headers ? array( 'data' => $result, 'headers' => wp_remote_retrieve_headers( $response ) ) : $result;
	}

	public function dispatch( array $inputs ) {
		return $this->request( 'POST', '/actions/workflows/' . self::WORKFLOW_FILE . '/dispatches', array( 'ref' => 'main', 'inputs' => $inputs ) );
	}

	public function dispatch_local( array $inputs ) {
		return $this->request( 'POST', '/actions/workflows/capture-visual-tone-local.yml/dispatches', array( 'ref' => 'main', 'inputs' => $inputs ) );
	}

	/** Read the self-hosted runner pool before creating a local retry. */
	public function local_runner_status() {
		$required_labels = array( 'self-hosted', 'windows', 'mac-visual' );
		$setup_url = 'https://github.com/' . self::OWNER . '/' . self::REPOSITORY . '/settings/actions/runners/new';
		$checked_at = gmdate( 'c' );
		$response = $this->request( 'GET', '/actions/runners?per_page=100' );
		if ( is_wp_error( $response ) ) {
			return array( 'state' => 'unknown', 'total' => null, 'matching_online' => null, 'required_labels' => $required_labels, 'checked_at' => $checked_at, 'setup_url' => $setup_url, 'message' => 'Runner status unavailable. GitHub could not confirm whether a self-hosted runner is online; the retry can still be dispatched and must be checked in Actions.', 'error_code' => $response->get_error_code() );
		}
		$total = array_key_exists( 'total_count', (array) $response ) ? absint( $response['total_count'] ) : count( (array) ( $response['runners'] ?? array() ) );
		$matching = array();
		foreach ( (array) ( $response['runners'] ?? array() ) as $runner ) {
			$labels = array_map( 'strtolower', array_filter( array_map( function( $label ) { return sanitize_key( (string) ( is_array( $label ) ? ( $label['name'] ?? '' ) : $label ) ); }, (array) ( $runner['labels'] ?? array() ) ) ) );
			if ( in_array( 'windows', $labels, true ) && in_array( 'mac-visual', $labels, true ) && 'online' === strtolower( (string) ( $runner['status'] ?? '' ) ) ) { $matching[] = $runner; }
		}
		if ( empty( $matching ) ) { return array( 'state' => 'no_matching', 'total' => $total, 'matching_online' => 0, 'required_labels' => $required_labels, 'checked_at' => $checked_at, 'setup_url' => $setup_url, 'message' => sprintf( 'No matching self-hosted runner online (%d runners). Install/register/start a runner with labels self-hosted, windows, mac-visual, then retry. Setup: %s', $total, $setup_url ) ); }
		$busy = count( array_filter( $matching, function( $runner ) { return ! empty( $runner['busy'] ); } ) );
		return array( 'state' => $busy === count( $matching ) ? 'busy' : 'online', 'total' => $total, 'matching_online' => count( $matching ), 'required_labels' => $required_labels, 'checked_at' => $checked_at, 'setup_url' => $setup_url, 'message' => $busy === count( $matching ) ? 'Matching local runner is online but busy; GitHub will keep the retry queued.' : 'Matching local runner is online and ready.', 'busy_count' => $busy );
	}

	public function preflight_local_runner() {
		$status = $this->local_runner_status();
		if ( 'no_matching' === ( $status['state'] ?? '' ) ) { return new WP_Error( 'LOCAL_RUNNER_NOT_READY', $status['message'], array( 'runner_status' => $status, 'status' => 409 ) ); }
		return $status;
	}

	/** List only the tracker workflow. Active results have a short cache. */
	public function list_visual_runs( $page = 1, $per_page = 5, $force = false ) {
		$page = max( 1, absint( $page ) );
		$per_page = max( 1, min( 20, absint( $per_page ) ) );
		$key = 'mac_tracker_visual_workflow_runs_' . $page . '_' . $per_page;
		if ( ! $force ) { $cached = get_transient( $key ); if ( is_array( $cached ) ) { return $cached; } }
		$runs = array();
		$fresh_runs = array();
		$seen_run_ids = array();
		$workflow_totals = array();
		$fresh_source_count = 0;
		$success = false;
		foreach ( array( self::WORKFLOW_FILE, self::LOCAL_WORKFLOW_FILE ) as $workflow_file ) {
			$source_total = 0;
			$source_loaded = false;
			for ( $source_page = 1; $source_page <= $page; $source_page++ ) {
				$response = $this->request( 'GET', '/actions/workflows/' . $workflow_file . '/runs?page=' . $source_page . '&per_page=' . $per_page, null, false, true );
				if ( is_wp_error( $response ) ) { break; }
				$success = true;
				$data = (array) ( $response['data'] ?? array() );
				$source_runs = (array) ( $data['workflow_runs'] ?? array() );
				if ( ! $source_loaded ) {
					$has_total_count = array_key_exists( 'total_count', $data );
					$source_total = $has_total_count ? absint( $data['total_count'] ) : count( $source_runs );
					$link = (string) ( $response['headers']['link'] ?? $response['headers']['Link'] ?? '' );
					if ( ! $has_total_count && preg_match( '/[?&]page=(\d+)[^>]*>;\s*rel="last"/i', $link, $match ) ) { $source_total = max( $source_total, ( (int) $match[1] - 1 ) * $per_page + count( $source_runs ) ); }
					$source_loaded = true;
				}
				foreach ( $source_runs as $run ) {
					if ( ! $this->is_visual_run( $run ) ) { continue; }
					$run_id = absint( $run['id'] ?? 0 );
					if ( $run_id > 0 && isset( $seen_run_ids[ $run_id ] ) ) { continue; }
					if ( $run_id > 0 ) { $seen_run_ids[ $run_id ] = true; }
					$normalized = $this->normalize_run( $run );
					$runs[] = $normalized;
					if ( 1 === $source_page ) { $fresh_runs[] = $normalized; }
				}
				if ( count( $source_runs ) < $per_page ) { break; }
			}
			if ( $source_loaded ) { $workflow_totals[] = $source_total; ++$fresh_source_count; }
		}
		if ( ! $success ) { return new WP_Error( 'GITHUB_UNAVAILABLE', 'Could not refresh Visual Tone workflow status. Showing last known data.', array( 'status' => 503 ) ); }
		$total = array_sum( $workflow_totals );
		usort( $runs, function( $a, $b ) { $number = (int) ( $b['run_number'] ?? 0 ) <=> (int) ( $a['run_number'] ?? 0 ); return 0 !== $number ? $number : ( (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) ); } );
		$fresh = array();
		foreach ( $fresh_runs as $run ) { if ( ! isset( $fresh[ (int) ( $run['id'] ?? 0 ) ] ) ) { $fresh[ (int) ( $run['id'] ?? 0 ) ] = $run; } }
		$fresh_summary = array( 'active' => array( 'running_batches' => 0, 'queued_batches' => 0 ) );
		foreach ( $fresh as $run ) {
			if ( in_array( $run['status'] ?? '', array( 'in_progress', 'cancelling' ), true ) ) { ++$fresh_summary['active']['running_batches']; }
			elseif ( 'queued' === ( $run['status'] ?? '' ) ) { ++$fresh_summary['active']['queued_batches']; }
		}
		$fresh_summary['scope'] = $fresh_source_count >= 2 ? 'github_fresh_page' : 'github_fresh_partial';
		$fresh_summary['updated_at'] = gmdate( 'c' );
		$fresh_summary['summary_scope'] = $fresh_summary['scope'];
		$fresh_summary['summary_updated_at'] = $fresh_summary['updated_at'];
		$fresh_summary['stale_count'] = $fresh_source_count >= 2 ? 0 : 1;
		$fresh_summary['stale'] = $fresh_source_count < 2;
		set_transient( $key, array( 'runs' => $runs, 'total' => $total, 'summary' => $fresh_summary ), 8 );
		return array( 'runs' => array_slice( $runs, ( $page - 1 ) * $per_page, $per_page ), 'total' => $total, 'summary' => $fresh_summary );
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
				'id' => absint( $job['id'] ?? 0 ),
				'name' => sanitize_text_field( (string) ( $job['name'] ?? '' ) ),
				'status' => sanitize_key( (string) ( $job['status'] ?? '' ) ),
				'conclusion' => sanitize_key( (string) ( $job['conclusion'] ?? '' ) ),
				'steps' => array_map(
					function( $step ) {
						return array(
							'name'       => sanitize_text_field( (string) ( $step['name'] ?? '' ) ),
							'status'     => sanitize_key( (string) ( $step['status'] ?? '' ) ),
							'conclusion' => sanitize_key( (string) ( $step['conclusion'] ?? '' ) ),
						);
					},
					(array) ( $job['steps'] ?? array() )
				),
			);
		}
		return array( 'run' => $run, 'jobs' => $jobs );
	}

	/** Fetch and sanitize only the worker step output on explicit operator request. */
	public function get_visual_job_log( $run_id ) {
		$jobs = $this->get_visual_jobs( $run_id );
		if ( is_wp_error( $jobs ) ) { return $jobs; }
		$worker = null;
		foreach ( (array) $jobs['jobs'] as $job ) {
			foreach ( (array) ( $job['steps'] ?? array() ) as $step ) {
				if ( 'Capture full pages and classify tone' === (string) ( $step['name'] ?? '' ) ) {
					$worker = array( 'job_id' => absint( $job['id'] ?? 0 ), 'step_name' => (string) $step['name'] );
					break 2;
				}
			}
		}
		if ( ! $worker || $worker['job_id'] <= 0 ) {
			return array( 'run_id' => absint( $run_id ), 'job_id' => 0, 'step_name' => 'Capture full pages and classify tone', 'log' => '', 'truncated' => false );
		}
		$raw = $this->download_visual_job_log( $worker['job_id'] );
		if ( is_wp_error( $raw ) ) { return $raw; }
		$log = $this->sanitize_worker_log( (string) $raw );
		$max = 180 * 1024;
		$truncated = strlen( $log ) > $max;
		if ( $truncated ) { $log = substr( $log, 0, $max ) . "\n\nLog truncated. Open on GitHub for full output."; }
		return array( 'run_id' => absint( $run_id ), 'job_id' => $worker['job_id'], 'step_name' => $worker['step_name'], 'log' => $log, 'truncated' => $truncated );
	}

	/** GitHub returns a short-lived redirect for job logs; never expose it or the token to the browser. */
	private function download_visual_job_log( $job_id ) {
		$token = $this->token();
		$first = wp_remote_get( $this->endpoint( '/actions/jobs/' . absint( $job_id ) . '/logs' ), array( 'timeout' => 25, 'redirection' => 0, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION ) ) );
		if ( is_wp_error( $first ) ) { return new WP_Error( 'GITHUB_LOG_UNAVAILABLE', 'Could not load GitHub worker log.', array( 'status' => 503 ) ); }
		$status = (int) wp_remote_retrieve_response_code( $first );
		if ( 200 === $status ) { return (string) wp_remote_retrieve_body( $first ); }
		$location = (string) wp_remote_retrieve_header( $first, 'location' );
		$host = (string) wp_parse_url( $location, PHP_URL_HOST );
		if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) || '' === $location || ! preg_match( '/(^|\.)githubusercontent\.com$|(^|\.)github\.com$/i', $host ) ) { return new WP_Error( 'GITHUB_LOG_UNAVAILABLE', 'Could not load GitHub worker log.', array( 'status' => 502 ) ); }
		$download = wp_remote_get( $location, array( 'timeout' => 25, 'redirection' => 2, 'limit_response_size' => 512 * 1024, 'headers' => array( 'User-Agent' => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION ) ) );
		if ( is_wp_error( $download ) || (int) wp_remote_retrieve_response_code( $download ) < 200 || (int) wp_remote_retrieve_response_code( $download ) >= 300 ) { return new WP_Error( 'GITHUB_LOG_UNAVAILABLE', 'Could not load GitHub worker log.', array( 'status' => 503 ) ); }
		return $this->normalize_downloaded_log( (string) wp_remote_retrieve_body( $download ) );
	}

	private function normalize_downloaded_log( $raw ) {
		if ( 0 === strpos( $raw, "\x1F\x8B" ) && function_exists( 'gzdecode' ) ) {
			$decoded = @gzdecode( $raw );
			if ( false !== $decoded ) { $raw = $decoded; }
		}
		if ( 0 === strpos( $raw, "PK\x03\x04" ) && class_exists( 'ZipArchive' ) ) {
			$tmp = wp_tempnam( 'mac-tracker-visual-log.zip' );
			if ( $tmp ) {
				file_put_contents( $tmp, $raw );
				$zip = new ZipArchive();
				if ( true === $zip->open( $tmp ) ) {
					$parts = array();
					for ( $index = 0; $index < $zip->numFiles; $index++ ) { $parts[] = (string) $zip->getFromIndex( $index ); }
					$zip->close();
					$raw = implode( "\n", $parts );
				}
				wp_delete_file( $tmp );
			}
		}
		return $raw;
	}

	private function sanitize_worker_log( $raw ) {
		$raw = wp_check_invalid_utf8( (string) $raw, true );
		$raw = preg_replace( '/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $raw );
		$raw = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw );
		$raw = preg_replace( '/(GEMINI_API_KEY|GROQ_API_KEY|CLOUDFLARE_API_TOKEN|MAC_TRACKER_AUTOMATION_SECRET)\s*[:=]\s*[^\s]+/i', '$1=[REDACTED]', $raw );
		$raw = preg_replace( '/Authorization\s*:\s*Bearer\s+[^\s]+/i', 'Authorization: Bearer [REDACTED]', $raw );
		$start = strpos( $raw, 'MAC_VISUAL_WORKER_START' );
		$end = strpos( $raw, 'MAC_VISUAL_WORKER_END' );
		if ( false !== $start ) { $raw = substr( $raw, $start, false !== $end && $end > $start ? $end - $start : null ); }
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$keep = array();
		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/^\d{4}-\d\d-\d\dT[^ ]+\s+/', '', $line ) );
			if ( '' === $line ) { continue; }
			if ( preg_match( '/(Captured bundle|Capture failed #\d+:|Tone failed #\d+:|Capturing #|Preparing analysis|Qwen analyzing|Llama|Gemini|Visual Tone Action|Action:|Scope:|Logical website limit|Capture:|Analysis:|Success:|Blocked:|Failed:|Processed:|Needs review:|Final:|Pixel:|Qwen:|HTTP_\d+|CF_CHALLENGE|CAPTCHA|PARKED_DOMAIN|MAINTENANCE|LOGIN_WALL|BAD_REDIRECT|EMPTY_PAGE|NAV_TIMEOUT|DNS|PLAYWRIGHT)/i', $line ) || false !== strpos( $line, 'MAC_VISUAL_WORKER_' ) ) { $keep[] = $line; }
		}
		return implode( "\n", $keep );
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
		foreach ( array( 1, 2, 3, 4, 5, 10, 20 ) as $page ) { foreach ( array( 5, 10, 20 ) as $per_page ) { delete_transient( 'mac_tracker_visual_workflow_runs_' . $page . '_' . $per_page ); } }
		foreach ( array( 5, 10, 20 ) as $legacy_per_page ) { delete_transient( 'mac_tracker_visual_workflow_runs_' . $legacy_per_page ); }
		if ( $run_id ) { delete_transient( 'mac_tracker_visual_workflow_run_' . absint( $run_id ) ); }
	}

	private function is_visual_run( array $run ) {
		$path = (string) ( $run['path'] ?? '' );
		$workflow = (string) ( $run['workflow_url'] ?? '' );
		return 0 === strpos( $path, self::WORKFLOW_PATH ) || 0 === strpos( $path, self::LOCAL_WORKFLOW_PATH ) || false !== strpos( $workflow, '/actions/workflows/' . self::WORKFLOW_FILE ) || false !== strpos( $workflow, '/actions/workflows/' . self::LOCAL_WORKFLOW_FILE );
	}

	private function normalize_run( array $run ) {
		return array(
			'id' => absint( $run['id'] ?? 0 ), 'run_number' => absint( $run['run_number'] ?? 0 ), 'run_attempt' => max( 1, absint( $run['run_attempt'] ?? 1 ) ),
			'status' => sanitize_key( (string) ( $run['status'] ?? 'queued' ) ), 'conclusion' => sanitize_key( (string) ( $run['conclusion'] ?? '' ) ),
			'html_url' => esc_url_raw( (string) ( $run['html_url'] ?? '' ) ), 'event' => sanitize_key( (string) ( $run['event'] ?? '' ) ),
			'head_branch' => sanitize_text_field( (string) ( $run['head_branch'] ?? '' ) ), 'head_sha' => sanitize_text_field( (string) ( $run['head_sha'] ?? '' ) ),
			'created_at' => sanitize_text_field( (string) ( $run['created_at'] ?? '' ) ), 'updated_at' => sanitize_text_field( (string) ( $run['updated_at'] ?? '' ) ),
			'workflow_file' => false !== strpos( (string) ( $run['path'] ?? '' ), self::LOCAL_WORKFLOW_PATH ) ? self::LOCAL_WORKFLOW_FILE : self::WORKFLOW_FILE,
			'runner_type' => false !== strpos( (string) ( $run['path'] ?? '' ), self::LOCAL_WORKFLOW_PATH ) ? 'self-hosted-windows' : 'github-hosted',
			'run_started_at' => sanitize_text_field( (string) ( $run['run_started_at'] ?? '' ) ),
		);
	}
}
