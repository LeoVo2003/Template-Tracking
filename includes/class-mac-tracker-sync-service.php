<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Sync_Service {

	const CRON_HOOK   = 'mac_tracker_sync_projects';
	const CRON_MANUAL = 'mac_tracker_sync_manual';
	const LOCK_KEY    = 'mac_tracker_sync_lock';
	const WEBSITE_ACTION_TASK_TYPE = '[Website] Action Design';
	const PIN_BACKFILL_CURSOR = 'mac_tracker_pin_backfill_cursor';
	const PIN_BACKFILL_QUOTA  = 150;

	/** @var MAC_Tracker_Repository */
	private $repository;

	/** @var MAC_Tracker_WPM_Client */
	private $client;

	/** @var MAC_Tracker_Color_Service */
	private $color_service;

	public function __construct(
		MAC_Tracker_Repository $repository,
		MAC_Tracker_WPM_Client $client,
		MAC_Tracker_Color_Service $color_service
	) {
		$this->repository    = $repository;
		$this->client        = $client;
		$this->color_service = $color_service;
	}

	/**
	 * WordPress cron callback — full API sync only in Bangkok business hours.
	 */
	public function run_cron() {
		if ( ! MAC_Tracker_Time::is_auto_sync_window() ) {
			return;
		}

		$this->sync();
	}

	/**
	 * Manual Sync Now — always runs (ignores 10–17 window).
	 */
	public function run_manual() {
		$this->sync();
	}

	/**
	 * Queue a background full sync via WP-Cron (manual path, not hour-gated).
	 *
	 * @return true|WP_Error
	 */
	public function queue_background_sync() {
		if ( get_option( self::LOCK_KEY ) ) {
			return new WP_Error( 'mac_tracker_sync_locked', 'Another project sync is already running.' );
		}

		$next = wp_next_scheduled( self::CRON_MANUAL );
		if ( $next && $next <= ( time() + 120 ) ) {
			return true;
		}

		wp_schedule_single_event( time() + 5, self::CRON_MANUAL );
		update_option( 'mac_tracker_sync_queued_at', gmdate( 'Y-m-d H:i:s' ), false );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return true;
	}

	/**
	 * Full synchronize WPM projects (no incremental updated_after — API rejects bad cursors).
	 *
	 * @return array|WP_Error
	 */
	public function sync() {
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'mac_tracker_sync_locked', 'Another project sync is already running.' );
		}

		$started_at_mysql = MAC_Tracker_Time::now_mysql();
		$started_mono     = microtime( true );
		delete_option( 'mac_tracker_last_sync_cursor' );

		$page               = 1;
		$limit              = 100;
		$processed          = 0;
		$skipped            = 0;
		$snapshots_created  = 0;
		$snapshots_updated  = 0;
		$snapshots_existed  = 0;
		$done_task_triggers = 0;
		$done_after_cutoff  = 0;
		$pages_fetched      = 0;
		$sync_source        = 'real';
		$seen_wpm_ids       = array();
		$pin_backfill           = 0;
		$pin_backfill_miss      = 0;
		$pin_backfill_attempted = 0;
		$pin_backfill_cursor    = 0;

		try {
			while ( $page <= 200 ) {
				$result = $this->client->fetch_page( $page, $limit );
				if ( is_wp_error( $result ) ) {
					$duration = round( microtime( true ) - $started_mono, 1 );
					$this->repository->add_sync_log(
						'failed',
						$processed,
						$this->append_duration( $result->get_error_message(), $duration, $started_at_mysql )
					);
					return $result;
				}

				$items = isset( $result['items'] ) && is_array( $result['items'] )
					? $result['items']
					: array();
				++$pages_fetched;

				foreach ( $items as $raw_project ) {
					if ( ! is_array( $raw_project ) ) {
						++$skipped;
						continue;
					}

					$raw_id = isset( $raw_project['id'] ) && is_scalar( $raw_project['id'] )
						? (int) $raw_project['id']
						: 0;
					if ( $raw_id > 0 ) {
						$seen_wpm_ids[ $raw_id ] = true;
					}

					$project_result = $this->sync_project( $raw_project, $sync_source );
					if ( is_wp_error( $project_result ) ) {
						if ( 'mac_tracker_wpm_id_missing' === $project_result->get_error_code() ) {
							++$skipped;
							continue;
						}

						$duration = round( microtime( true ) - $started_mono, 1 );
						$this->repository->add_sync_log(
							'failed',
							$processed,
							$this->append_duration( $project_result->get_error_message(), $duration, $started_at_mysql )
						);
						return $project_result;
					}

					if ( ! empty( $project_result['skipped'] ) ) {
						$snapshots_updated += (int) $project_result['updated'];
						++$skipped;
						continue;
					}

					++$processed;
					$snapshots_created += (int) $project_result['created'];
					$snapshots_updated += (int) $project_result['updated'];
					$snapshots_existed += (int) ( $project_result['existed'] ?? 0 );
					$done_task_triggers += (int) $project_result['done_task_triggers'];
					$done_after_cutoff += (int) ( $project_result['done_after_cutoff'] ?? 0 );
				}

				$pagination = isset( $result['pagination'] ) && is_array( $result['pagination'] )
					? $result['pagination']
					: array();
				$total_pages = isset( $pagination['total_pages'] ) && null !== $pagination['total_pages']
					? (int) $pagination['total_pages']
					: null;

				if (
					empty( $items )
					|| ( null !== $total_pages && $page >= $total_pages )
					|| ( null === $total_pages && count( $items ) < $limit )
				) {
					break;
				}

				++$page;
			}

			// Cancelled/deleted pins often missing from paginated list — fetch by id.
			// Rotating cursor: each run resumes after the last attempted WPM id and
			// wraps to the smallest missing pin, so a backlog larger than the per-run
			// quota is fully covered across successive syncs (no id starves).
			$pin_ids = $this->repository->list_pinned_project_ids();
			$missing = array();
			foreach ( $pin_ids as $pin_id ) {
				if ( isset( $seen_wpm_ids[ $pin_id ] ) || $this->repository->is_purged_project( $pin_id ) ) {
					continue;
				}
				$missing[] = $pin_id;
			}

			if ( empty( $missing ) ) {
				delete_option( self::PIN_BACKFILL_CURSOR );
			} else {
				$cursor        = (int) get_option( self::PIN_BACKFILL_CURSOR, 0 );
				$after_cursor  = array();
				$before_cursor = array();
				foreach ( $missing as $pin_id ) {
					if ( $pin_id > $cursor ) {
						$after_cursor[] = $pin_id;
					} else {
						$before_cursor[] = $pin_id;
					}
				}
				// IDs above the cursor first, then wrap to the rest of the backlog.
				$backfill_order = array_merge( $after_cursor, $before_cursor );
				$attempted      = 0;
				$last_pin_id    = $cursor;
				foreach ( $backfill_order as $pin_id ) {
					if ( $attempted >= self::PIN_BACKFILL_QUOTA ) {
						break;
					}
					++$attempted;
					++$pin_backfill_attempted;
					$last_pin_id = $pin_id;

					$one = $this->client->fetch_project_by_id( $pin_id );
					if ( is_wp_error( $one ) ) {
						++$pin_backfill_miss;
						continue;
					}
					$raw_project = isset( $one['items'][0] ) && is_array( $one['items'][0] ) ? $one['items'][0] : null;
					if ( ! $raw_project ) {
						++$pin_backfill_miss;
						continue;
					}

					$seen_wpm_ids[ $pin_id ] = true;
					$project_result          = $this->sync_project( $raw_project, $sync_source );
					if ( is_wp_error( $project_result ) ) {
						++$pin_backfill_miss;
						continue;
					}

					++$pin_backfill;
					$snapshots_created += (int) ( $project_result['created'] ?? 0 );
					$snapshots_updated += (int) ( $project_result['updated'] ?? 0 );
					$snapshots_existed += (int) ( $project_result['existed'] ?? 0 );
					$done_task_triggers += (int) ( $project_result['done_task_triggers'] ?? 0 );
					$done_after_cutoff += (int) ( $project_result['done_after_cutoff'] ?? 0 );
					if ( empty( $project_result['skipped'] ) ) {
						++$processed;
					} else {
						++$skipped;
					}
				}

				// Covered the whole backlog this run → restart from the top next time.
				$pin_backfill_cursor = $attempted >= count( $backfill_order ) ? 0 : $last_pin_id;
				update_option( self::PIN_BACKFILL_CURSOR, $pin_backfill_cursor, false );
			}

			// Pins missing from WPM API still get Project label from CSV Projects column.
			$snapshots_updated += $this->repository->apply_pin_csv_labels_to_empty_snapshots();

			$duration = round( microtime( true ) - $started_mono, 1 );
			update_option( 'mac_tracker_last_sync_at', gmdate( 'Y-m-d H:i:s' ), false );
			delete_option( 'mac_tracker_sync_queued_at' );

			$visible_snapshots = $this->repository->count_projects();
			$pin_count         = $this->repository->count_pins();
			$storage           = $this->repository->snapshot_storage_stats();
			$message           = sprintf(
				'Full sync OK: %1$d projects / %2$d API pages; AD done %3$d (≥%4$s: %5$d); visible %6$d; new %7$d / existed %8$d / labels %9$d; skipped %10$d; pins %11$d; pin-backfill attempted %12$d ok %13$d miss %14$d next-cursor %15$d; DB total %16$d action %17$d projects %18$d.',
				$processed,
				$pages_fetched,
				$done_task_triggers,
				MAC_TRACKER_AD_COMPLETED_CUTOFF,
				$done_after_cutoff,
				$visible_snapshots,
				$snapshots_created,
				$snapshots_existed,
				$snapshots_updated,
				$skipped,
				$pin_count,
				$pin_backfill_attempted,
				$pin_backfill,
				$pin_backfill_miss,
				$pin_backfill_cursor,
				(int) $storage['total'],
				(int) $storage['action_design'],
				(int) $storage['distinct_projects']
			);
			$message = $this->append_duration( $message, $duration, $started_at_mysql );
			$this->repository->add_sync_log( 'success', $processed, $message );

			return array(
				'processed'          => $processed,
				'skipped'            => $skipped,
				'snapshots_created'  => $snapshots_created,
				'snapshots_existed'  => $snapshots_existed,
				'snapshots_updated'  => $snapshots_updated,
				'done_task_triggers' => $done_task_triggers,
				'done_after_cutoff'  => $done_after_cutoff,
				'visible_snapshots'  => $visible_snapshots,
				'pin_backfill'           => $pin_backfill,
				'pin_backfill_miss'      => $pin_backfill_miss,
				'pin_backfill_attempted' => $pin_backfill_attempted,
				'pin_backfill_cursor'    => $pin_backfill_cursor,
				'duration_seconds'       => $duration,
				'message'            => $message,
			);
		} finally {
			delete_option( self::LOCK_KEY );
		}
	}

	/**
	 * @param string $message Base message.
	 * @param float  $duration Seconds.
	 * @param string $started_at_mysql Bangkok start time.
	 * @return string
	 */
	private function append_duration( $message, $duration, $started_at_mysql ) {
		return sprintf(
			'%1$s | duration=%2$ss | started=%3$s | ended=%4$s (Bangkok GMT+7)',
			rtrim( (string) $message ),
			number_format( (float) $duration, 1, '.', '' ),
			$started_at_mysql,
			MAC_Tracker_Time::now_mysql()
		);
	}

	/**
	 * Apply pin + Action Design cutoff rules for one WPM project.
	 *
	 * @param array  $raw WPM project payload.
	 * @param string $sync_source real.
	 * @return array|WP_Error
	 */
	private function sync_project( array $raw, $sync_source ) {
		$base = $this->normalize_project_base( $raw, $sync_source );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$wpm_id    = (int) $base['wpm_project_id'];
		$is_pinned = $this->repository->is_pinned( $wpm_id );

		if ( $this->repository->is_purged_project( $wpm_id ) ) {
			return array(
				'created'            => 0,
				'updated'            => 0,
				'done_task_triggers' => 0,
				'done_after_cutoff'  => 0,
				'skipped'            => true,
			);
		}

		$labels_updated = $this->repository->refresh_project_snapshot_labels( $base );
		if ( is_wp_error( $labels_updated ) ) {
			return $labels_updated;
		}

		$tasks      = isset( $raw['tasks'] ) && is_array( $raw['tasks'] ) ? $raw['tasks'] : array();
		$done_tasks = array();
		$seen_tasks = array();

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) || ! $this->is_website_action_task( $task ) ) {
				continue;
			}
			$task_id = isset( $task['id'] ) && is_scalar( $task['id'] ) ? (int) $task['id'] : 0;
			if ( $task_id <= 0 || isset( $seen_tasks[ $task_id ] ) ) {
				continue;
			}
			$seen_tasks[ $task_id ] = true;
			if ( ! $this->is_done_status( $task['status'] ?? '' ) ) {
				continue;
			}
			$done_tasks[] = $task;
		}

		$created            = 0;
		$existed            = 0;
		$updated            = (int) $labels_updated;
		$done_task_triggers = count( $done_tasks );
		$done_after_cutoff  = 0;

		// Baseline csv_pin: fill empty Layout/Palette even if project is cancelled
		// (no new AD rows). Oldest done AD first, else project web_layout / template_color.
		if ( $is_pinned ) {
			$layout_source = $this->resolve_pin_layout_source( $done_tasks, $base );
			if ( '' !== $layout_source && $this->repository->fill_empty_csv_pin_layout( $wpm_id, $layout_source ) ) {
				++$updated;
			}
			$pin_snapshot_id = $this->repository->get_csv_pin_snapshot_id( $wpm_id );
			$palette_source  = $this->resolve_pin_palette_source( $done_tasks, $base );
			if ( $pin_snapshot_id > 0 && '' !== $palette_source ) {
				$color_result = $this->color_service->capture_source( $pin_snapshot_id, $palette_source );
				if ( is_wp_error( $color_result ) ) {
					return $color_result;
				}
			}
		}

		// Existing snapshots remain visible forever. Cancelled / deleted /
		// archived projects must not create new Action Design rows.
		if ( ! empty( $base['blocks_new_snapshots'] ) ) {
			return array(
				'created'            => 0,
				'existed'            => 0,
				'updated'            => $updated,
				'done_task_triggers' => $done_task_triggers,
				'done_after_cutoff'  => 0,
				'skipped'            => true,
			);
		}

		foreach ( $done_tasks as $task ) {
			if ( ! $this->task_completed_on_or_after_cutoff( $task ) ) {
				continue;
			}
			++$done_after_cutoff;

			$task_id         = (int) $task['id'];
			$action_snapshot = $this->build_action_snapshot( $base, $task, $task_id );
			$action_result   = $this->persist_snapshot( $action_snapshot, true, true );
			if ( is_wp_error( $action_result ) ) {
				return $action_result;
			}
			$created += $action_result['created'];
			$existed += (int) ( $action_result['existed'] ?? 0 );
			$updated += $action_result['updated'];
		}

		// Unpinned projects with no qualifying AD and no prior rows → ignore.
		if ( ! $is_pinned && 0 === $done_after_cutoff && 0 === $created && 0 === $existed && 0 === $updated ) {
			return array(
				'created'            => 0,
				'existed'            => 0,
				'updated'            => 0,
				'done_task_triggers' => $done_task_triggers,
				'done_after_cutoff'  => 0,
				'skipped'            => true,
			);
		}

		return array(
			'created'            => $created,
			'existed'            => $existed,
			'updated'            => $updated,
			'done_task_triggers' => $done_task_triggers,
			'done_after_cutoff'  => $done_after_cutoff,
			'skipped'            => false,
		);
	}

	/**
	 * @param array $snapshot Normalized snapshot row.
	 * @param bool  $allow_insert Insert only after the visibility trigger fires.
	 * @param bool  $capture_palette Capture the pinned task palette on first insert.
	 * @return array|WP_Error
	 */
	private function persist_snapshot( array $snapshot, $allow_insert, $capture_palette ) {
		$saved = $this->repository->ensure_project_snapshot( $snapshot, $allow_insert );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( null === $saved ) {
			return array( 'created' => 0, 'existed' => 0, 'updated' => 0 );
		}

		if ( ! empty( $saved['created'] ) && $capture_palette ) {
			$color_result = $this->color_service->capture_source(
				(int) $saved['id'],
				(string) $snapshot['template_color_raw']
			);
			if ( is_wp_error( $color_result ) ) {
				return $color_result;
			}
		}

		if (
			empty( $saved['created'] )
			&& 'action_design' === (string) ( $snapshot['record_kind'] ?? '' )
			&& ! empty( $snapshot['task_completed_at'] )
		) {
			$this->repository->set_task_completed_at_if_empty(
				(int) $saved['id'],
				(string) $snapshot['task_completed_at']
			);
		}

		return array(
			'created' => ! empty( $saved['created'] ) ? 1 : 0,
			'existed' => ! empty( $saved['existed'] ) ? 1 : 0,
			'updated' => 0,
		);
	}

	/**
	 * @param mixed $status Task status from WPM.
	 * @return bool
	 */
	private function is_done_status( $status ) {
		$value = strtolower( $this->named_value( $status ) );
		return in_array( $value, array( 'done', 'completed', 'complete' ), true );
	}

	/**
	 * Compare completed_at date part with MAC_TRACKER_AD_COMPLETED_CUTOFF.
	 *
	 * @param array $task WPM task.
	 * @return bool
	 */
	private function task_completed_on_or_after_cutoff( array $task ) {
		$raw = '';
		foreach ( array( 'completed_at', 'completedAt', 'done_at' ) as $key ) {
			if ( isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ) {
				$raw = trim( (string) $task[ $key ] );
				if ( '' !== $raw ) {
					break;
				}
			}
		}
		if ( '' === $raw ) {
			return false;
		}

		$timestamp = strtotime( $raw );
		if ( false === $timestamp ) {
			return false;
		}

		$day = gmdate( 'Y-m-d', $timestamp );
		return $day >= MAC_TRACKER_AD_COMPLETED_CUTOFF;
	}

	/**
	 * Normalize values shared by every snapshot of the project.
	 *
	 * @param array  $raw WPM project payload.
	 * @param string $sync_source real or mock.
	 * @return array|WP_Error
	 */
	private function normalize_project_base( array $raw, $sync_source ) {
		$external_id = $this->scalar( $raw['id'] ?? '' );
		if ( '' === $external_id ) {
			return new WP_Error( 'mac_tracker_wpm_id_missing', 'A WPM project is missing its id.' );
		}

		$layout_values = array();
		foreach ( array( $raw['web_layout'] ?? '', $raw['layout_url'] ?? '' ) as $layout_candidate ) {
			$layout_candidate = MAC_Tracker_Normalize::normalize_layout_value(
				$this->scalar( $layout_candidate )
			);
			if ( '' !== $layout_candidate ) {
				$layout_values[] = $layout_candidate;
			}
		}

		$updated_at = null;
		if ( ! empty( $raw['updated_at'] ) && is_scalar( $raw['updated_at'] ) ) {
			$timestamp = strtotime( (string) $raw['updated_at'] );
			if ( false !== $timestamp ) {
				$updated_at = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$status = $this->named_value( $raw['status'] ?? '' );
		$is_removed = ! empty( $raw['is_archived'] )
			|| ! empty( $raw['archived_at'] )
			|| ! empty( $raw['is_deleted'] )
			|| ! empty( $raw['deleted_at'] );
		$is_cancelled = 0 === strcasecmp( $status, 'cancelled' )
			|| 0 === strcasecmp( $status, 'canceled' );

		$domain = $this->scalar( $raw['domain_url'] ?? '' );
		if ( '' === $domain ) {
			$domain = $this->scalar( $raw['domain'] ?? '' );
		}

		$name    = $this->scalar( $raw['name'] ?? '' );
		$zip     = $this->scalar( $raw['zipcode'] ?? ( $raw['zip_code'] ?? '' ) );
		$package = $this->structured_value( $raw['package'] ?? null );
		// Fallback from full_name when WPM omits split fields.
		$from_full = MAC_Tracker_Normalize::parse_projects_label( $this->scalar( $raw['full_name'] ?? '' ) );
		if ( '' === $name && '' !== (string) ( $from_full['store_name'] ?? '' ) ) {
			$name = (string) $from_full['store_name'];
		}
		if ( '' === $zip && '' !== (string) ( $from_full['zip'] ?? '' ) ) {
			$zip = (string) $from_full['zip'];
		}
		if ( ( null === $package || '' === $package || ( is_string( $package ) && '' === trim( $package ) ) ) && '' !== (string) ( $from_full['package'] ?? '' ) ) {
			$package = (string) $from_full['package'];
		}

		return array(
			'wpm_project_id'       => (int) $external_id,
			'name'                 => $name,
			'zip_code'             => $zip,
			'package'              => $package,
			'account_manager'      => $this->structured_value( $raw['account_manager'] ?? ( $raw['am'] ?? null ) ),
			'assignee'             => $this->structured_value( $raw['assignee'] ?? null ),
			'status'               => $status,
			'notes'                => $this->scalar( $raw['notes'] ?? '' ),
			'domain'               => $domain,
			'layout_url'           => implode( ' | ', array_values( array_unique( $layout_values ) ) ),
			'template_color_raw'   => $this->scalar( $raw['template_color_url'] ?? ( $raw['template_color'] ?? '' ) ),
			'content_url'          => $this->scalar( $raw['content_url'] ?? '' ),
			'menu_url'             => $this->scalar( $raw['menu_web_url'] ?? ( $raw['menu_url'] ?? '' ) ),
			'wpm_updated_at'       => $updated_at,
			'is_archived'          => 0,
			'blocks_new_snapshots' => ( $is_removed || $is_cancelled ) ? 1 : 0,
			'sync_source'          => (string) $sync_source,
		);
	}

	/**
	 * New Action Design row: Website/Layout from the task; Assignee from project at create time.
	 *
	 * @param array $base Normalized project values.
	 * @param array $task Exact Action Design task.
	 * @param int   $task_id WPM task ID.
	 * @return array
	 */
	private function build_action_snapshot( array $base, array $task, $task_id ) {
		$extra   = $this->task_extra_data( $task );
		$website = MAC_Tracker_Normalize::normalize_website_url_value(
			$this->scalar( $extra['web_demo_url'] ?? '' )
		);

		return array_merge(
			$base,
			array(
				'record_kind'             => 'action_design',
				'wpm_action_task_id'      => (int) $task_id,
				'website_url'             => $website,
				'layout_url'              => MAC_Tracker_Normalize::normalize_layout_value(
					$this->scalar( $extra['web_layout'] ?? '' )
				),
				'has_website_action_task' => 1,
				'template_color_raw'      => $this->scalar( $extra['color_template'] ?? '' ),
				'task_completed_at'       => $this->parse_task_completed_at( $task ),
				'sync_source'             => 'real',
			)
		);
	}

	/**
	 * @param array $task WPM task.
	 * @return string|null MySQL datetime UTC or null.
	 */
	private function parse_task_completed_at( array $task ) {
		$raw = '';
		foreach ( array( 'completed_at', 'completedAt', 'done_at' ) as $key ) {
			if ( isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ) {
				$raw = trim( (string) $task[ $key ] );
				if ( '' !== $raw ) {
					break;
				}
			}
		}
		if ( '' === $raw ) {
			return null;
		}
		$timestamp = strtotime( $raw );
		if ( false === $timestamp ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Palette for empty csv_pin: oldest AD color_template, else project template.
	 *
	 * @param array $done_tasks Done Action Design tasks.
	 * @param array $base Normalized project base.
	 * @return string
	 */
	private function resolve_pin_palette_source( array $done_tasks, array $base ) {
		$oldest = $this->oldest_done_action_task( $done_tasks );
		if ( $oldest ) {
			$extra = $this->task_extra_data( $oldest );
			$raw   = $this->scalar( $extra['color_template'] ?? '' );
			if ( '' !== $raw ) {
				return $raw;
			}
		}

		return $this->scalar( $base['template_color_raw'] ?? '' );
	}

	/**
	 * Layout for empty csv_pin: oldest AD web_layout, else project web_layout.
	 *
	 * @param array $done_tasks Done Action Design tasks.
	 * @param array $base Normalized project base.
	 * @return string
	 */
	private function resolve_pin_layout_source( array $done_tasks, array $base ) {
		$oldest = $this->oldest_done_action_task( $done_tasks );
		if ( $oldest ) {
			$extra  = $this->task_extra_data( $oldest );
			$layout = MAC_Tracker_Normalize::normalize_layout_value(
				$this->scalar( $extra['web_layout'] ?? '' )
			);
			if ( '' !== $layout ) {
				return $layout;
			}
		}

		return MAC_Tracker_Normalize::normalize_layout_value(
			$this->scalar( $base['layout_url'] ?? '' )
		);
	}

	/**
	 * @param array $done_tasks Done Action Design tasks.
	 * @return array|null Oldest task by completed_at (then lowest id).
	 */
	private function oldest_done_action_task( array $done_tasks ) {
		if ( empty( $done_tasks ) ) {
			return null;
		}

		$best    = null;
		$best_ts = null;
		$best_id = null;
		foreach ( $done_tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$task_id = isset( $task['id'] ) ? (int) $task['id'] : 0;
			$raw     = '';
			foreach ( array( 'completed_at', 'completedAt', 'done_at' ) as $key ) {
				if ( isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ) {
					$raw = trim( (string) $task[ $key ] );
					if ( '' !== $raw ) {
						break;
					}
				}
			}
			$ts = '' !== $raw ? strtotime( $raw ) : false;
			$ts = false === $ts ? PHP_INT_MAX : (int) $ts;

			if (
				null === $best
				|| $ts < $best_ts
				|| ( $ts === $best_ts && ( null === $best_id || $task_id < $best_id ) )
			) {
				$best    = $task;
				$best_ts = $ts;
				$best_id = $task_id;
			}
		}

		return $best;
	}

	/**
	 * @param array $task WPM task.
	 * @return bool
	 */
	private function is_website_action_task( array $task ) {
		$type = $this->named_value( $task['task_type'] ?? '' );
		return 0 === strcasecmp( $type, self::WEBSITE_ACTION_TASK_TYPE );
	}

	/**
	 * @param array $task WPM task.
	 * @return array
	 */
	private function task_extra_data( array $task ) {
		$extra = $task['extra_data'] ?? array();
		if ( is_array( $extra ) ) {
			return $extra;
		}
		if ( is_string( $extra ) ) {
			$decoded = json_decode( $extra, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	/**
	 * @return bool
	 */
	private function acquire_lock() {
		$now = time();
		if ( add_option( self::LOCK_KEY, $now, '', false ) ) {
			return true;
		}

		$existing = (int) get_option( self::LOCK_KEY, 0 );
		if ( $existing > 0 && $existing < ( $now - HOUR_IN_SECONDS ) ) {
			delete_option( self::LOCK_KEY );
			return add_option( self::LOCK_KEY, $now, '', false );
		}

		return false;
	}

	/**
	 * @param mixed $value Scalar or object-like value containing a name/status.
	 * @return string
	 */
	private function named_value( $value ) {
		if ( is_array( $value ) ) {
			foreach ( array( 'name', 'status', 'label', 'code' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					return trim( (string) $value[ $key ] );
				}
			}
		}

		return $this->scalar( $value );
	}

	/**
	 * @param mixed $value Value to reduce to text.
	 * @return string
	 */
	private function scalar( $value ) {
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}

		return '';
	}

	/**
	 * @param mixed $value Package/user value.
	 * @return mixed
	 */
	private function structured_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) || null === $value ) {
			return $value;
		}

		return array( 'name' => $this->scalar( $value ) );
	}
}
