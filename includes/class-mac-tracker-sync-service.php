<?php

defined( 'ABSPATH' ) || exit;

/** Runs WPM work outside page rendering through one-off and hourly WP-Cron jobs. */
class MAC_Tracker_Sync_Service {

	const CRON_HOOK        = 'mac_tracker_sync_hourly';
	const MANUAL_CRON_HOOK = 'mac_tracker_sync_manual';
	const LOCK_OPTION      = 'mac_tracker_sync_lock';
	const LOCK_TTL         = 1800;
	const BACKFILL_QUOTA   = 150;

	private $repository;

	public function __construct( MAC_Tracker_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::MANUAL_CRON_HOOK, array( $this, 'run_manual' ) );
		add_action( 'init', array( $this, 'ensure_hourly_schedule' ) );
	}

	public function ensure_hourly_schedule() {
		if ( ! $this->is_configured() || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
	}

	/** Queue a manual run and return immediately to the admin page. */
	public function queue_background_sync() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'mac_tracker_wpm_unconfigured', 'Save the HTTPS WPM endpoint and API header value before syncing.' );
		}
		if ( $this->is_running() ) {
			return new WP_Error( 'mac_tracker_sync_running', 'A sync is already running.' );
		}
		if ( ! wp_next_scheduled( self::MANUAL_CRON_HOOK ) ) {
			// Make the event due before calling spawn_cron(). The old five-second
			// delay could leave a manual sync queued forever on a quiet site.
			$scheduled = wp_schedule_single_event( time(), self::MANUAL_CRON_HOOK, array(), true );
			if ( is_wp_error( $scheduled ) ) {
				return $scheduled;
			}
		}
		update_option( 'mac_tracker_sync_queued_at', MAC_Tracker_Time::now_utc(), false );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return true;
	}

	public function run_scheduled() {
		if ( $this->is_configured() ) {
			$this->run_sync();
		}
	}

	public function run_manual() {
		$this->run_sync();
	}

	public function is_running() {
		$lock = (int) get_option( self::LOCK_OPTION, 0 );
		return $lock > 0 && ( time() - $lock ) < self::LOCK_TTL;
	}

	public function is_queued() {
		return (bool) wp_next_scheduled( self::MANUAL_CRON_HOOK );
	}

	/** Confirm the saved WPM route and header without writing any local snapshots. */
	public function test_connection() {
		$client = $this->client_from_settings();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->test_connection();
	}

	/**
	 * Full sync runs in cron, never in the Projects request. It preserves prior
	 * snapshots and records a short audit log instead of exposing credentials.
	 */
	public function run_sync() {
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'mac_tracker_sync_running', 'Another sync is already running.' );
		}

		$log_id  = $this->repository->begin_sync_log();
		$started = microtime( true );
		try {
			$roster_ids = $this->repository->roster_ids();
			if ( empty( $roster_ids ) ) {
				$error = new WP_Error( 'mac_tracker_roster_missing', 'Import the hybrid master CSV before syncing WPM.' );
				$this->repository->finish_sync_log( $log_id, 'failed', 0, $error->get_error_message() );
				return $error;
			}
			$roster = array_fill_keys( $roster_ids, true );
			$roster_cutoff = $this->repository->roster_cutoff();
			$client = $this->client_from_settings();
			if ( is_wp_error( $client ) ) {
				$this->repository->finish_sync_log( $log_id, 'failed', 0, $client->get_error_message() );
				return $client;
			}

			$result = $client->fetch_all();
			if ( is_wp_error( $result ) ) {
				$this->repository->finish_sync_log( $log_id, 'failed', 0, $this->with_duration( $result->get_error_message(), $started ) );
				return $result;
			}

			$seen      = array();
			$scanned   = 0;
			$processed = 0;
			$created   = 0;
			$errors    = 0;
			foreach ( $result['items'] as $raw_project ) {
				$project = MAC_Tracker_Normalizer::project( $raw_project );
				if ( ! $project ) {
					continue;
				}
				++$scanned;
				// Keep the imported baseline fixed. A new project joins only when it
				// was created after the latest CSV pin date and has a done action.
				$created_at = MAC_Tracker_Time::normalize_utc( $project['created_at'] );
				if ( ! isset( $roster[ $project['id'] ] ) && ( '' === $roster_cutoff || ! $created_at || $created_at <= $roster_cutoff ) ) {
					continue;
				}
				$seen[ $project['id'] ] = true;
				$one = $this->sync_project( $project );
				if ( is_wp_error( $one ) ) {
					++$errors;
					continue;
				}
				$processed += (int) $one['eligible'];
				$created += (int) $one['created'];
			}

			$backfill = $this->backfill_missing_pins( array_keys( $seen ), $client );
			$message  = sprintf(
				'pages=%d, scanned=%d, roster=%d, eligible_action_tasks=%d, created=%d, backfill=%d/%d, errors=%d, %s',
				(int) $result['pages'],
				$scanned,
				count( $roster_ids ),
				$processed,
				$created,
				(int) $backfill['found'],
				(int) $backfill['attempted'],
				$errors,
				$this->duration( $started )
			);
			$this->repository->finish_sync_log( $log_id, 'success', $processed, $message );

			return array(
				'pages'     => (int) $result['pages'],
				'scanned'   => $scanned,
				'processed' => $processed,
				'created'   => $created,
				'backfill'  => $backfill,
				'errors'    => $errors,
			);
		} catch ( Exception $exception ) {
			$this->repository->finish_sync_log( $log_id, 'failed', 0, $this->with_duration( 'Unexpected sync error.', $started ) );
			return new WP_Error( 'mac_tracker_sync_exception', 'Unexpected sync error.' );
		} finally {
			$this->release_lock();
		}
	}

	/** Implements the current snapshot rules from hướng đi mới.md. */
	public function sync_project( array $project ) {
		$created  = 0;
		$eligible = 0;
		$this->repository->refresh_snapshot_labels( $project['id'], $project );

		$base = array(
			'wpm_project_id'  => $project['id'],
			'name'            => $project['name'],
			'zip_code'        => $project['zipcode'],
			'package'         => $project['package'],
			'account_manager' => $project['account_manager'],
			'assignee'        => $project['assignee'],
			'domain'          => $project['domain'],
			'status'          => $project['status'],
			'sync_source'     => 'real',
			'raw'             => $project['raw'],
		);

		// Action Design is the authoritative WPM source. CSV pins are the
		// historical fallback; direct Domain rows are never created.
		foreach ( $project['tasks'] as $task ) {
			if ( ! $task['is_action_design'] || ! $this->task_is_done( $task['status'] ) ) {
				continue;
			}
			++$eligible;
			$action_snapshot = $this->repository->upsert_snapshot(
				array_merge( $base, array(
					'record_kind'          => 'action_design',
					'wpm_action_task_id'   => $task['id'],
					'website_url'          => '' !== $task['demo_url'] ? $task['demo_url'] : $project['domain'],
					'layout_url'           => '' !== $task['layout_url'] ? $task['layout_url'] : $project['layout'],
					'template_color_raw'   => $task['color_template'],
					// The Projects timeline uses the Action Design due date, not its
					// completion timestamp. A missing Due Date remains blank in UI.
					'task_completed_at'    => MAC_Tracker_Time::normalize_utc( $task['due_at'] ),
				) )
			);
			if ( is_wp_error( $action_snapshot ) ) {
				return $action_snapshot;
			}
			$created += ! empty( $action_snapshot['created'] ) ? 1 : 0;
		}

		return array( 'created' => $created, 'eligible' => $eligible );
	}

	private function backfill_missing_pins( array $seen_wpm_ids, MAC_Tracker_WPM_Client $client ) {
		$missing = $this->repository->missing_pin_ids( $seen_wpm_ids );
		if ( empty( $missing ) ) {
			delete_option( 'mac_tracker_backfill_cursor' );
			return array( 'attempted' => 0, 'found' => 0, 'missed' => 0 );
		}

		$cursor = $this->repository->cursor();
		$order  = array();
		foreach ( $missing as $project_id ) {
			if ( $project_id > $cursor ) { $order[] = $project_id; }
		}
		foreach ( $missing as $project_id ) {
			if ( $project_id <= $cursor ) { $order[] = $project_id; }
		}

		$attempted = 0;
		$found     = 0;
		$missed    = 0;
		$last_id   = $cursor;
		foreach ( $order as $project_id ) {
			if ( $attempted >= self::BACKFILL_QUOTA ) { break; }
			++$attempted;
			$last_id = $project_id;
			$raw     = $client->fetch_project_by_id( $project_id );
			if ( is_wp_error( $raw ) ) {
				++$missed;
				continue;
			}
			$project = MAC_Tracker_Normalizer::project( $raw );
			if ( ! $project ) {
				++$missed;
				continue;
			}
			$this->sync_project( $project );
			++$found;
		}

		$this->repository->set_cursor( $attempted >= count( $order ) ? 0 : $last_id );
		return array( 'attempted' => $attempted, 'found' => $found, 'missed' => $missed );
	}

	private function client_from_settings() {
		$settings = (array) get_option( 'mac_tracker_settings', array() );
		$endpoint = isset( $settings['wpm_endpoint'] ) ? esc_url_raw( $settings['wpm_endpoint'] ) : '';
		$secret   = MAC_Tracker_Crypto::decrypt( get_option( 'mac_tracker_wpm_secret', '' ) );
		if ( '' === $endpoint || '' === $secret ) {
			return new WP_Error( 'mac_tracker_wpm_unconfigured', 'WPM endpoint or API header value is missing.' );
		}
		$endpoint = MAC_Tracker_WPM_Client::normalize_endpoint( $endpoint );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		return new MAC_Tracker_WPM_Client( $endpoint, $secret, 100 );
	}

	private function is_configured() {
		$settings = (array) get_option( 'mac_tracker_settings', array() );
		if ( empty( $settings['wpm_endpoint'] ) ) {
			return false;
		}
		$endpoint = MAC_Tracker_WPM_Client::normalize_endpoint( $settings['wpm_endpoint'] );
		return ! is_wp_error( $endpoint ) && '' !== MAC_Tracker_Crypto::decrypt( get_option( 'mac_tracker_wpm_secret', '' ) );
	}

	private function acquire_lock() {
		$existing = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $existing > 0 && ( time() - $existing ) >= self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
		}
		return add_option( self::LOCK_OPTION, time(), '', 'no' );
	}

	private function release_lock() { delete_option( self::LOCK_OPTION ); }
	/** Existing snapshots remain after status changes; new ones require done now. */
	private function task_is_done( $status ) {
		return in_array( strtolower( trim( (string) $status ) ), array( 'done', 'complete', 'completed' ), true );
	}
	private function duration( $started ) { return 'duration=' . number_format( microtime( true ) - $started, 1 ) . 's'; }
	private function with_duration( $message, $started ) { return $message . ', ' . $this->duration( $started ); }
}
