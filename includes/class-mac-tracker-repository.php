<?php

defined( 'ABSPATH' ) || exit;

/** Local storage. Admin pages read these tables only; they never contact WPM. */
class MAC_Tracker_Repository {

	private $wpdb;
	private $projects;
	private $colors;
	private $visuals;
	private $pins;
	private $logs;

	public function __construct() {
		global $wpdb;
		$this->wpdb     = $wpdb;
		$this->projects = $wpdb->prefix . 'mac_tracker_projects';
		$this->colors   = $wpdb->prefix . 'mac_tracker_color_records';
		$this->visuals  = $wpdb->prefix . 'mac_tracker_visual_reviews';
		$this->pins     = $wpdb->prefix . 'mac_tracker_pinned_projects';
		$this->logs     = $wpdb->prefix . 'mac_tracker_sync_logs';
	}

	/** Create a snapshot once. Existing payloads never change. */
	public function upsert_snapshot( array $snapshot ) {
		$project_id = absint( $snapshot['wpm_project_id'] ?? 0 );
		$kind       = sanitize_key( $snapshot['record_kind'] ?? '' );
		$task_id    = absint( $snapshot['wpm_action_task_id'] ?? 0 );

		if ( $project_id <= 0 || ! in_array( $kind, array( 'csv_pin', 'action_design' ), true ) ) {
			return new WP_Error( 'mac_tracker_snapshot_invalid', 'Invalid project snapshot.' );
		}
		if ( 'action_design' === $kind && $task_id <= 0 ) {
			return new WP_Error( 'mac_tracker_snapshot_task_missing', 'Action Design snapshots need a task ID.' );
		}
		if ( 'action_design' !== $kind ) {
			$task_id = 0;
		}

		$existing_id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->projects} WHERE wpm_project_id = %d AND record_kind = %s AND wpm_action_task_id = %d LIMIT 1",
				$project_id,
				$kind,
				$task_id
			)
		);

		if ( $existing_id > 0 ) {
			$this->refresh_snapshot_labels( $project_id, $snapshot );
			// Date/Time is a corrected source field: CSV supplies its historical
			// timestamp and WPM Action Design supplies Due Date. Refresh only this
			// value; Website, Layout, Assignee and palette stay immutable.
			if ( array_key_exists( 'task_completed_at', $snapshot ) ) {
				$this->wpdb->update(
					$this->projects,
					array( 'task_completed_at' => $snapshot['task_completed_at'], 'updated_at' => MAC_Tracker_Time::now_utc() ),
					array( 'id' => $existing_id )
				);
			}
			return array( 'id' => $existing_id, 'created' => false );
		}

		$now  = MAC_Tracker_Time::now_utc();
		$data = array(
			'wpm_project_id'       => $project_id,
			'record_kind'          => $kind,
			'wpm_action_task_id'   => $task_id,
			'name'                 => (string) ( $snapshot['name'] ?? '' ),
			'zip_code'             => (string) ( $snapshot['zip_code'] ?? '' ),
			'package_json'         => $this->encode_json( $snapshot['package'] ?? '' ),
			'account_manager_json' => $this->encode_json( $snapshot['account_manager'] ?? null ),
			'assignee_json'        => $this->encode_json( $snapshot['assignee'] ?? null ),
			'domain'               => (string) ( $snapshot['domain'] ?? '' ),
			'layout_url'           => (string) ( $snapshot['layout_url'] ?? '' ),
			'website_url'          => (string) ( $snapshot['website_url'] ?? '' ),
			'status'               => (string) ( $snapshot['status'] ?? '' ),
			'template_color_raw'   => (string) ( $snapshot['template_color_raw'] ?? '' ),
			'raw_payload'          => $this->encode_json( $snapshot['raw'] ?? array() ),
			'sync_source'          => (string) ( $snapshot['sync_source'] ?? 'real' ),
			'task_completed_at'    => $snapshot['task_completed_at'] ?? null,
			'pin_position'         => absint( $snapshot['pin_position'] ?? 0 ),
			'created_at'           => $now,
			'updated_at'           => $now,
		);

		if ( false === $this->wpdb->insert( $this->projects, $data ) ) {
			return new WP_Error( 'mac_tracker_snapshot_insert', $this->wpdb->last_error ?: 'Unable to save snapshot.' );
		}

		return array( 'id' => (int) $this->wpdb->insert_id, 'created' => true );
	}

	/** Name, ZIP and package are the only labels allowed to refresh. */
	public function refresh_snapshot_labels( $wpm_project_id, array $project ) {
		$labels  = array();
		$name    = trim( (string) ( $project['name'] ?? '' ) );
		$zip     = trim( (string) ( $project['zip_code'] ?? $project['zipcode'] ?? '' ) );
		$package = trim( (string) ( $project['package'] ?? '' ) );

		if ( '' !== $name ) { $labels['name'] = $name; }
		if ( '' !== $zip ) { $labels['zip_code'] = $zip; }
		if ( '' !== $package ) { $labels['package_json'] = $this->encode_json( $package ); }
		if ( empty( $labels ) ) { return 0; }

		$labels['updated_at'] = MAC_Tracker_Time::now_utc();
		return (int) $this->wpdb->update( $this->projects, $labels, array( 'wpm_project_id' => absint( $wpm_project_id ) ) );
	}

	public function upsert_pin( array $pin ) {
		$project_id = absint( $pin['wpm_project_id'] ?? 0 );
		if ( $project_id <= 0 ) {
			return new WP_Error( 'mac_tracker_pin_invalid', 'Pin needs a WPM project ID.' );
		}

		$now  = MAC_Tracker_Time::now_utc();
		$data = array(
			'website_url'       => (string) ( $pin['website_url'] ?? '' ),
			'layout_url'        => (string) ( $pin['layout_url'] ?? '' ),
			'assignee_name'     => (string) ( $pin['assignee_name'] ?? '' ),
			'projects_raw'      => (string) ( $pin['projects_raw'] ?? '' ),
			'task_completed_at' => $pin['task_completed_at'] ?? null,
			'pin_position'      => absint( $pin['pin_position'] ?? 0 ),
			'updated_at'        => $now,
		);
		$existing = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT id FROM {$this->pins} WHERE wpm_project_id = %d LIMIT 1", $project_id )
		);

		if ( $existing > 0 ) {
			if ( false === $this->wpdb->update( $this->pins, $data, array( 'id' => $existing ) ) ) {
				return new WP_Error( 'mac_tracker_pin_update', $this->wpdb->last_error ?: 'Unable to update pin.' );
			}
			return $existing;
		}

		$data['wpm_project_id'] = $project_id;
		$data['created_at']     = $now;
		if ( false === $this->wpdb->insert( $this->pins, $data ) ) {
			return new WP_Error( 'mac_tracker_pin_insert', $this->wpdb->last_error ?: 'Unable to save pin.' );
		}
		return (int) $this->wpdb->insert_id;
	}

	public function pin_ids() {
		return array_map( 'intval', (array) $this->wpdb->get_col( "SELECT wpm_project_id FROM {$this->pins} ORDER BY pin_position ASC, id ASC" ) );
	}

	/** Count retired direct-domain snapshots without touching Action Design or CSV pin data. */
	public function domain_snapshot_count() {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->projects} WHERE record_kind = %s", 'domain' )
		);
	}

	/**
	 * One-time cleanup for domain rows made by pre-0.7.2 tracker logic.
	 * Related color records are removed as well so the color table has no orphan IDs.
	 * CSV pins and Action Design rows use other record kinds and are never selected.
	 *
	 * @return array|WP_Error
	 */
	public function purge_domain_snapshots() {
		$ids = array_map(
			'intval',
			(array) $this->wpdb->get_col(
				$this->wpdb->prepare( "SELECT id FROM {$this->projects} WHERE record_kind = %s", 'domain' )
			)
		);
		if ( empty( $ids ) ) {
			return array( 'snapshots' => 0, 'colors' => 0 );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$color_sql    = $this->wpdb->prepare( "DELETE FROM {$this->colors} WHERE project_id IN ({$placeholders})", $ids );
		$colors       = $this->wpdb->query( $color_sql );
		if ( false === $colors ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mac_tracker_domain_cleanup_colors', $this->wpdb->last_error ?: 'Unable to remove legacy palette records.' );
		}

		$deleted = $this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM {$this->projects} WHERE record_kind = %s", 'domain' )
		);
		if ( false === $deleted ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mac_tracker_domain_cleanup', $this->wpdb->last_error ?: 'Unable to remove legacy Domain snapshots.' );
		}
		$this->wpdb->query( 'COMMIT' );

		$now = MAC_Tracker_Time::now_utc();
		$this->wpdb->insert(
			$this->logs,
			array(
				'status'      => 'maintenance',
				'processed'   => (int) $deleted,
				'message'     => sprintf( 'Cleanup removed %d retired Domain snapshot(s) and %d related palette record(s).', (int) $deleted, (int) $colors ),
				'started_at'  => $now,
				'finished_at' => $now,
			)
		);

		return array( 'snapshots' => (int) $deleted, 'colors' => (int) $colors );
	}

	/** Remove known WPM test rows and a title-only false positive from old syncs. */
	public function purge_excluded_wpm_actions() {
		return $this->wpdb->query( "DELETE FROM {$this->projects} WHERE wpm_project_id IN (3018, 3189) OR wpm_action_task_id = 8336" );
	}

	public function missing_pin_ids( array $seen_wpm_ids ) {
		return array_values( array_diff( $this->pin_ids(), array_map( 'intval', $seen_wpm_ids ) ) );
	}

	public function cursor() { return absint( get_option( 'mac_tracker_backfill_cursor', 0 ) ); }
	public function set_cursor( $project_id ) { update_option( 'mac_tracker_backfill_cursor', absint( $project_id ), false ); }
	/** The approved CSV roster is the only WPM scope allowed to create snapshots. */
	public function set_roster_ids( array $project_ids ) {
		$project_ids = array_values( array_unique( array_filter( array_map( 'absint', $project_ids ) ) ) );
		update_option( 'mac_tracker_roster_ids', $project_ids, false );
	}

	public function roster_ids() {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) get_option( 'mac_tracker_roster_ids', array() ) ) ) ) );
	}
	public function set_roster_cutoff( $utc ) { update_option( 'mac_tracker_roster_cutoff_utc', $utc, false ); }
	public function roster_cutoff() { return (string) get_option( 'mac_tracker_roster_cutoff_utc', '' ); }
	/** The one-time baseline comparison establishes when ordinary sync begins. */
	public function mark_baseline_compared( $utc ) { update_option( 'mac_tracker_baseline_compared_at', (string) $utc, false ); }
	public function baseline_compared_at() { return (string) get_option( 'mac_tracker_baseline_compared_at', '' ); }
	public function baseline_is_compared() { return '' !== $this->baseline_compared_at(); }

	public function edit_project_identity( $snapshot_id, $new_project_id, $new_name, $date_time = '' ) {
		$snapshot_id   = absint( $snapshot_id );
		$new_project_id = absint( $new_project_id );
		$new_name      = sanitize_text_field( $new_name );
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT wpm_project_id FROM {$this->projects} WHERE id = %d", $snapshot_id ), ARRAY_A );
		if ( ! $row || $new_project_id <= 0 || '' === $new_name ) { return new WP_Error( 'mac_tracker_edit_invalid', 'Valid project ID and name are required.' ); }
		$old_project_id = (int) $row['wpm_project_id'];
		$timestamp = MAC_Tracker_Time::csv_bangkok_to_utc( str_replace( 'T', ' ', (string) $date_time ), '' );
		$raw = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT raw_payload FROM {$this->projects} WHERE id = %d", $snapshot_id ) );
		$raw = json_decode( (string) $raw, true );
		if ( is_array( $raw ) ) { unset( $raw['match_confidence'] ); }
		$data = array( 'wpm_project_id' => $new_project_id, 'name' => $new_name, 'raw_payload' => $this->encode_json( is_array( $raw ) ? $raw : array() ), 'updated_at' => MAC_Tracker_Time::now_utc() ); if ( $timestamp ) { $data['task_completed_at'] = $timestamp; }
		if ( false === $this->wpdb->update( $this->projects, $data, array( 'wpm_project_id' => $old_project_id ) ) ) { return new WP_Error( 'mac_tracker_edit_failed', $this->wpdb->last_error ?: 'Unable to update project snapshots.' ); }
		$pin_data = array( 'wpm_project_id' => $new_project_id, 'projects_raw' => $new_name, 'updated_at' => MAC_Tracker_Time::now_utc() ); if ( $timestamp ) { $pin_data['task_completed_at'] = $timestamp; }
		$this->wpdb->update( $this->pins, $pin_data, array( 'wpm_project_id' => $old_project_id ) );
		$roster = array_map( function ( $id ) use ( $old_project_id, $new_project_id ) { return (int) $id === $old_project_id ? $new_project_id : (int) $id; }, $this->roster_ids() );
		$this->set_roster_ids( $roster );
		return true;
	}

	/** Repair legacy CSV timestamps after the date parser was made unambiguous. */
	public function repair_csv_pin_datetimes() {
		$rows = (array) $this->wpdb->get_results( "SELECT id, wpm_project_id, task_completed_at, raw_payload FROM {$this->projects} WHERE record_kind = 'csv_pin'", ARRAY_A );
		$fixed = 0;
		foreach ( $rows as $row ) {
			$raw = json_decode( (string) $row['raw_payload'], true );
			if ( ! is_array( $raw ) ) { continue; }
			$timestamp = MAC_Tracker_Time::csv_bangkok_to_utc( $raw['date'] ?? $raw['done_date'] ?? '', $raw['time'] ?? '' );
			if ( ! $timestamp || $timestamp === (string) $row['task_completed_at'] ) { continue; }
			$this->wpdb->update( $this->projects, array( 'task_completed_at' => $timestamp, 'updated_at' => MAC_Tracker_Time::now_utc() ), array( 'id' => (int) $row['id'] ) );
			$this->wpdb->update( $this->pins, array( 'task_completed_at' => $timestamp, 'updated_at' => MAC_Tracker_Time::now_utc() ), array( 'wpm_project_id' => (int) $row['wpm_project_id'] ) );
			++$fixed;
		}
		return $fixed;
	}

	/** Keep only the newest Action Design snapshot per WPM project. */
	public function collapse_action_snapshots() {
		$rows = (array) $this->wpdb->get_results( "SELECT id, wpm_project_id, wpm_action_task_id, raw_payload FROM {$this->projects} WHERE record_kind = 'action_design' ORDER BY wpm_project_id ASC, id ASC", ARRAY_A );
		$ids = array();
		$keepers = array();
		foreach ( $rows as $row ) {
			$project_id = (int) $row['wpm_project_id'];
			$rank = (int) $row['wpm_action_task_id'];
			$raw = json_decode( (string) $row['raw_payload'], true );
			if ( is_array( $raw ) && ! empty( $raw['tasks'] ) && is_array( $raw['tasks'] ) ) {
				foreach ( $raw['tasks'] as $task_raw ) {
					$task = MAC_Tracker_Normalizer::task( $task_raw );
					if ( $task && (int) $task['id'] === (int) $row['wpm_action_task_id'] ) {
						$when = MAC_Tracker_Time::normalize_utc( $task['due_at'] ?: $task['completed_at'] );
						$rank = (int) strtotime( $when ?: '1970-01-01 00:00:00' ) * 1000000 + (int) $task['id'];
						break;
					}
				}
			}
			if ( ! isset( $keepers[ $project_id ] ) || $rank > $keepers[ $project_id ]['rank'] ) {
				if ( isset( $keepers[ $project_id ] ) ) { $ids[] = (int) $keepers[ $project_id ]['id']; }
				$keepers[ $project_id ] = array( 'id' => (int) $row['id'], 'rank' => $rank );
			} else {
				$ids[] = (int) $row['id'];
			}
		}
		if ( empty( $ids ) ) { return 0; }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->colors} WHERE project_id IN ({$placeholders})", $ids ) );
		$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->projects} WHERE id IN ({$placeholders})", $ids ) );
		return count( $ids );
	}

	/** Remove only local tracker records. Connection settings are intentionally retained. */
	public function clear_local_data() {
		$attachments = array_map( 'intval', (array) $this->wpdb->get_col( "SELECT attachment_id FROM {$this->visuals} WHERE attachment_id > 0" ) );
		$tables = array( $this->colors, $this->visuals, $this->projects, $this->pins, $this->logs );
		foreach ( $tables as $table ) {
			if ( false === $this->wpdb->query( "DELETE FROM {$table}" ) ) {
				return new WP_Error( 'mac_tracker_clear_failed', $this->wpdb->last_error ?: 'Unable to clear local tracker data.' );
			}
		}
		foreach ( $attachments as $attachment_id ) { wp_delete_attachment( $attachment_id, true ); }
		delete_option( 'mac_tracker_backfill_cursor' );
		delete_option( 'mac_tracker_sync_queued_at' );
		delete_option( 'mac_tracker_sync_mode' );
		delete_option( 'mac_tracker_roster_ids' );
		delete_option( 'mac_tracker_roster_cutoff_utc' );
		delete_option( 'mac_tracker_roster_source_rows' );
		delete_option( 'mac_tracker_baseline_compared_at' );
		return true;
	}

	/** One bulk query for the Projects screen; no WPM/API call and no N+1. */
	public function project_page( array $filters = array() ) {
		$per_page = isset( $filters['per_page'] ) ? (int) $filters['per_page'] : 150;
		$per_page = in_array( $per_page, array( 50, 100, 150, 200 ), true ) ? $per_page : 0;
		$page     = max( 1, absint( $filters['paged'] ?? 1 ) );
		// A CSV pin is the verified historical fallback. Once WPM has an
		// Action Design snapshot for the same project, show the task row(s)
		// instead of duplicating that project with its CSV row.
		$visible = "(p.record_kind = 'action_design' OR (p.record_kind = 'csv_pin' AND NOT EXISTS (SELECT 1 FROM {$this->projects} action_snapshot WHERE action_snapshot.wpm_project_id = p.wpm_project_id AND action_snapshot.record_kind = 'action_design')))";
		$where    = array( $visible );
		$args     = array();

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$where[] = 'p.wpm_project_id = %d';
				$args[]  = (int) $search;
			} else {
				$like    = '%' . $this->wpdb->esc_like( $search ) . '%';
				$where[] = '(p.name LIKE %s OR p.zip_code LIKE %s OR p.website_url LIKE %s)';
				$args    = array_merge( $args, array( $like, $like, $like ) );
			}
		}

		$kind = sanitize_key( $filters['kind'] ?? '' );
		if ( in_array( $kind, array( 'action_design', 'csv_pin' ), true ) ) {
			$where[] = 'p.record_kind = %s';
			$args[]  = $kind;
		}

		$assignee = trim( (string) ( $filters['assignee'] ?? '' ) );
		if ( '' !== $assignee ) {
			$where[] = 'p.assignee_json LIKE %s';
			$args[]  = '%' . $this->wpdb->esc_like( $assignee ) . '%';
		}

		$month_from = trim( (string) ( $filters['month_from'] ?? '' ) );
		$month_to   = trim( (string) ( $filters['month_to'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}$/', $month_from ) ) {
			$where[] = 'p.task_completed_at >= %s';
			$args[]  = MAC_Tracker_Time::bangkok_month_start_utc( $month_from );
		}
		if ( preg_match( '/^\d{4}-\d{2}$/', $month_to ) ) {
			$where[] = 'p.task_completed_at < %s';
			$args[]  = MAC_Tracker_Time::bangkok_next_month_start_utc( $month_to );
		}

		foreach ( array( 'website', 'layout' ) as $field ) {
			$value  = sanitize_key( $filters[ $field ] ?? '' );
			$column = 'website' === $field ? 'p.website_url' : 'p.layout_url';
			if ( 'yes' === $value ) { $where[] = "{$column} <> ''"; }
			if ( 'no' === $value ) { $where[] = "{$column} = ''"; }
		}

		$tone = sanitize_text_field( (string) ( $filters['tone'] ?? '' ) );
		$allowed_tones = $this->visual_tones();
		if ( in_array( $tone, $allowed_tones, true ) ) {
			$where[] = 'v.tone = %s';
			$args[]  = $tone;
		} elseif ( 'pending' === $tone ) {
			$where[] = "(v.id IS NULL OR v.capture_status <> 'captured' OR v.tone_status <> 'classified')";
		}

		$where_sql = implode( ' AND ', $where );
		$order_map = array(
			'id' => 'p.wpm_project_id', 'project' => 'p.name', 'website' => 'p.website_url',
			'layout' => 'p.layout_url', 'assignee' => 'p.assignee_json', 'date' => 'p.task_completed_at', 'palette' => 'c.status', 'tone' => 'v.tone',
		);
		$sort     = sanitize_key( $filters['orderby'] ?? 'id' );
		$order_by = $order_map[ $sort ] ?? $order_map['id'];
		$order    = 'asc' === strtolower( (string) ( $filters['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$order_sql = "{$order_by} {$order}, p.id {$order}";

		$count_sql = "SELECT COUNT(*) FROM {$this->projects} p LEFT JOIN {$this->colors} c ON c.project_id = p.id LEFT JOIN {$this->visuals} v ON v.project_id = p.id WHERE {$where_sql}";
		$total     = empty( $args ) ? (int) $this->wpdb->get_var( $count_sql ) : (int) $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $args ) );
		$query     = "SELECT p.*, c.status AS color_status, c.colors_json, c.source_type AS color_source_type, c.source_raw AS color_source_raw, c.approved_at, c.locked AS color_locked, v.screenshot_url, v.tone, v.confidence AS tone_confidence, v.tone_reason, v.tone_status, v.capture_status, v.captured_at FROM {$this->projects} p LEFT JOIN {$this->colors} c ON c.project_id = p.id LEFT JOIN {$this->visuals} v ON v.project_id = p.id WHERE {$where_sql} ORDER BY {$order_sql}";
		$query_args = $args;
		if ( $per_page > 0 ) {
			$query       .= ' LIMIT %d OFFSET %d';
			$query_args[] = $per_page;
			$query_args[] = ( $page - 1 ) * $per_page;
		}
		$rows = empty( $query_args ) ? $this->wpdb->get_results( $query, ARRAY_A ) : $this->wpdb->get_results( $this->wpdb->prepare( $query, $query_args ), ARRAY_A );

		return array(
			'rows'       => (array) $rows,
			'total'      => $total,
			'per_page'   => $per_page,
			'paged'      => $page,
			'total_pages' => $per_page > 0 ? max( 1, (int) ceil( $total / $per_page ) ) : 1,
		);
	}

	public function dashboard_stats() {
		return (array) $this->wpdb->get_row(
			"SELECT COUNT(*) AS snapshots, COUNT(DISTINCT p.wpm_project_id) AS projects, SUM(p.record_kind = 'action_design') AS action_design, SUM(p.record_kind = 'csv_pin') AS csv_pins FROM {$this->projects} p",
			ARRAY_A
		);
	}

	public function pin_count() { return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->pins}" ); }
	public function latest_log() { return $this->wpdb->get_row( "SELECT * FROM {$this->logs} ORDER BY id DESC LIMIT 1", ARRAY_A ); }
	public function recent_logs( $limit = 8 ) { return (array) $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->logs} ORDER BY id DESC LIMIT %d", max( 1, absint( $limit ) ) ), ARRAY_A ); }

	public function begin_sync_log() {
		$this->wpdb->insert( $this->logs, array( 'status' => 'running', 'processed' => 0, 'message' => 'Sync started.', 'started_at' => MAC_Tracker_Time::now_utc() ) );
		return (int) $this->wpdb->insert_id;
	}

	public function finish_sync_log( $log_id, $status, $processed, $message ) {
		$this->wpdb->update(
			$this->logs,
			array( 'status' => sanitize_key( $status ), 'processed' => absint( $processed ), 'message' => (string) $message, 'finished_at' => MAC_Tracker_Time::now_utc() ),
			array( 'id' => absint( $log_id ) )
		);
	}

	public function list_assignees() {
		$visible = "(p.record_kind = 'action_design' OR (p.record_kind = 'csv_pin' AND NOT EXISTS (SELECT 1 FROM {$this->projects} action_snapshot WHERE action_snapshot.wpm_project_id = p.wpm_project_id AND action_snapshot.record_kind = 'action_design')))";
		$raw = (array) $this->wpdb->get_col( "SELECT DISTINCT p.assignee_json FROM {$this->projects} p WHERE {$visible} AND p.assignee_json IS NOT NULL AND p.assignee_json <> ''" );
		$names = array();
		foreach ( $raw as $value ) {
			$person = json_decode( $value, true );
			$name   = is_array( $person ) ? $this->canonical_person_name( $person['name'] ?? '' ) : '';
			if ( '' !== $name ) { $names[ strtolower( $name ) ] = $name; }
		}
		natcasesort( $names );
		return array_values( $names );
	}

	/** Strip decorative emoji so one person has one filter option. */
	public function canonical_person_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) { return ''; }
		$name = preg_replace( '/[\p{So}\p{Sk}\x{FE0F}\x{200D}]+/u', '', $name );
		return trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
	}

	/** Reserved for color phase; locked records ignore later sync changes. */
	public function upsert_color_record( $project_id, $source_type, $source_raw, array $colors = array(), $status = 'pending' ) {
		$project_id = absint( $project_id );
		$existing   = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT id, locked FROM {$this->colors} WHERE project_id = %d", $project_id ), ARRAY_A );
		if ( $existing && ! empty( $existing['locked'] ) ) {
			return array( 'id' => (int) $existing['id'], 'locked' => true, 'changed' => false );
		}
		$now  = MAC_Tracker_Time::now_utc();
		$data = array( 'source_type' => (string) $source_type, 'source_raw' => (string) $source_raw, 'status' => sanitize_key( $status ), 'colors_json' => $this->encode_json( array_values( $colors ) ), 'updated_at' => $now );
		if ( $existing ) {
			$this->wpdb->update( $this->colors, $data, array( 'id' => (int) $existing['id'] ) );
			return array( 'id' => (int) $existing['id'], 'locked' => false, 'changed' => true );
		}
		$data['project_id'] = $project_id;
		$data['created_at'] = $now;
		$this->wpdb->insert( $this->colors, $data );
		return array( 'id' => (int) $this->wpdb->insert_id, 'locked' => false, 'changed' => true );
	}

	/** One local snapshot, used by the bounded Elementor color extractor. */
	public function snapshot( $snapshot_id ) {
		$snapshot_id = absint( $snapshot_id );
		if ( $snapshot_id <= 0 ) {
			return null;
		}
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT p.*, c.status AS color_status, c.colors_json, c.source_type AS color_source_type, c.source_raw AS color_source_raw, c.approved_at, c.locked AS color_locked FROM {$this->projects} p LEFT JOIN {$this->colors} c ON c.project_id = p.id WHERE p.id = %d LIMIT 1",
				$snapshot_id
			),
			ARRAY_A
		);
	}

	/** The review queue is entirely local and only includes currently visible project rows. */
	public function color_review_rows() {
		$page = $this->project_page(
			array(
				'website' => 'yes',
				'per_page' => 0,
				'orderby'  => 'date',
				'order'    => 'desc',
			)
		);
		return (array) $page['rows'];
	}

	/** IDs without a prior extraction; one batch is deliberately bounded. */
	public function pending_color_snapshot_ids( $limit = 6 ) {
		$limit = max( 1, min( 12, absint( $limit ) ) );
		$visible = "(p.record_kind = 'action_design' OR (p.record_kind = 'csv_pin' AND NOT EXISTS (SELECT 1 FROM {$this->projects} action_snapshot WHERE action_snapshot.wpm_project_id = p.wpm_project_id AND action_snapshot.record_kind = 'action_design')))";
		$sql = "SELECT p.id FROM {$this->projects} p LEFT JOIN {$this->colors} c ON c.project_id = p.id WHERE {$visible} AND p.website_url <> '' AND c.id IS NULL ORDER BY p.task_completed_at DESC, p.id DESC LIMIT %d";
		return array_map( 'intval', (array) $this->wpdb->get_col( $this->wpdb->prepare( $sql, $limit ) ) );
	}

	public function pending_color_snapshot_count() {
		$visible = "(p.record_kind = 'action_design' OR (p.record_kind = 'csv_pin' AND NOT EXISTS (SELECT 1 FROM {$this->projects} action_snapshot WHERE action_snapshot.wpm_project_id = p.wpm_project_id AND action_snapshot.record_kind = 'action_design')))";
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->projects} p LEFT JOIN {$this->colors} c ON c.project_id = p.id WHERE {$visible} AND p.website_url <> '' AND c.id IS NULL" );
	}

	/** Retain an actionable failure rather than retrying a bad site forever in the queue. */
	public function record_color_failure( $project_id, $message ) {
		return $this->upsert_color_record( $project_id, 'elementor_global', wp_json_encode( array( 'error' => (string) $message ) ), array(), 'failed' );
	}

	/** Reset only machine-generated candidates when the extractor rules change. */
	public function purge_unapproved_color_records() {
		return (int) $this->wpdb->query( "DELETE FROM {$this->colors} WHERE locked = 0" );
	}

	public function visual_stats() {
		return (array) $this->wpdb->get_row( "SELECT COUNT(*) AS total, SUM(capture_status = 'captured') AS captured, SUM(tone_status = 'classified') AS classified, SUM(capture_status = 'failed' OR tone_status = 'failed') AS failed, SUM(capture_status IN ('pending', 'capturing')) AS capture_pending, SUM(capture_status = 'captured' AND tone_status IN ('pending', 'failed', 'analyzing')) AS tone_pending FROM {$this->visuals}", ARRAY_A );
	}

	/** Current snapshot rows with their locally stored visual review. */
	public function visual_review_rows( $limit = 120 ) {
		$limit = max( 1, min( 300, absint( $limit ) ) );
		$visible = "(p.record_kind = 'action_design' OR (p.record_kind = 'csv_pin' AND NOT EXISTS (SELECT 1 FROM {$this->projects} action_snapshot WHERE action_snapshot.wpm_project_id = p.wpm_project_id AND action_snapshot.record_kind = 'action_design')))";
		$sql = "SELECT p.*, v.screenshot_url, v.tone, v.confidence AS tone_confidence, v.tone_reason, v.tone_status, v.capture_status, v.captured_at, v.updated_at AS visual_updated_at FROM {$this->projects} p INNER JOIN {$this->visuals} v ON v.project_id = p.id WHERE {$visible} AND (v.screenshot_url <> '' OR v.capture_status IN ('pending', 'capturing', 'failed')) ORDER BY CASE WHEN v.capture_status IN ('pending', 'capturing') THEN 0 WHEN v.tone_status = 'classified' THEN 1 ELSE 2 END, v.updated_at DESC LIMIT %d";
		return (array) $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit ), ARRAY_A );
	}

	/** Atomically claim safe local queue data for one authenticated worker. */
	public function visual_queue( $stage, $limit = 10 ) {
		$limit = max( 1, min( 25, absint( $limit ) ) );
		$stage = 'tone' === $stage ? 'tone' : 'capture';
		$visible = "(p.record_kind = 'action_design' OR (p.record_kind = 'csv_pin' AND NOT EXISTS (SELECT 1 FROM {$this->projects} action_snapshot WHERE action_snapshot.wpm_project_id = p.wpm_project_id AND action_snapshot.record_kind = 'action_design')))";
		$lock_name = 'mac_tracker_visual_' . md5( $this->visuals );
		$locked = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $locked ) { return array(); }
		$items = array();
		try {
			if ( 'tone' === $stage ) {
				$sql = "SELECT p.id, p.website_url, v.screenshot_url, c.source_raw AS color_source_raw FROM {$this->projects} p INNER JOIN {$this->visuals} v ON v.project_id = p.id LEFT JOIN {$this->colors} c ON c.project_id = p.id WHERE {$visible} AND v.capture_status = 'captured' AND (v.tone_status IN ('pending', 'failed') OR (v.tone_status = 'analyzing' AND v.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))) AND v.screenshot_url <> '' ORDER BY v.updated_at DESC LIMIT %d";
			} else {
				$sql = "SELECT p.id, p.website_url, '' AS screenshot_url, c.source_raw AS color_source_raw FROM {$this->projects} p LEFT JOIN {$this->visuals} v ON v.project_id = p.id LEFT JOIN {$this->colors} c ON c.project_id = p.id WHERE {$visible} AND p.website_url <> '' AND (v.id IS NULL OR v.capture_status = 'pending' OR (v.capture_status = 'capturing' AND v.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))) ORDER BY CASE WHEN v.capture_status IN ('pending', 'capturing') THEN 0 ELSE 1 END, v.updated_at DESC, p.task_completed_at DESC, p.id DESC LIMIT %d";
			}
			$candidates = (array) $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit ), ARRAY_A );
			foreach ( $candidates as $item ) {
				$token = wp_generate_uuid4();
				$claim = $this->claim_visual_stage( (int) $item['id'], $stage, $token );
				if ( is_wp_error( $claim ) ) { continue; }
				$item['job_token'] = $token;
				$items[] = $item;
			}
		} finally {
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		return $items;
	}

	public function save_visual_capture( $snapshot_id, $attachment_id, $url, $token ) {
		return $this->save_claimed_visual( $snapshot_id, 'capture', $token, array( 'capture_status' => 'captured', 'attachment_id' => absint( $attachment_id ), 'screenshot_url' => esc_url_raw( $url ), 'tone_status' => 'pending', 'tone_token' => '', 'captured_at' => MAC_Tracker_Time::now_utc() ) );
	}

	/** Claim a queue item before returning it to a GitHub worker. */
	private function claim_visual_stage( $snapshot_id, $stage, $token ) {
		$stage = 'tone' === $stage ? 'tone' : 'capture';
		$data = 'tone' === $stage ? array( 'tone_status' => 'analyzing', 'tone_token' => $token ) : array( 'capture_status' => 'capturing', 'capture_token' => $token );
		return $this->save_visual( $snapshot_id, $data );
	}

	/** Confirm that a progress callback belongs to the currently claimed job. */
	public function mark_visual_stage( $snapshot_id, $stage, $token ) {
		return $this->visual_claim_matches( $snapshot_id, 'tone' === $stage ? 'tone' : 'capture', $token ) ? true : new WP_Error( 'mac_tracker_visual_stale', 'This visual job is no longer current.', array( 'status' => 409 ) );
	}

	public function save_visual_failure( $snapshot_id, $stage, $message, $token ) {
		$stage = 'tone' === $stage ? 'tone' : 'capture';
		$data = 'tone' === $stage ? array( 'tone_status' => 'failed' ) : array( 'capture_status' => 'failed' );
		$data['ai_raw'] = wp_json_encode( array( 'stage' => $stage, 'error' => sanitize_text_field( $message ) ) );
		return $this->save_claimed_visual( $snapshot_id, $stage, $token, $data );
	}

	public function release_visual_claim( $snapshot_id, $stage, $token ) {
		$stage = 'tone' === $stage ? 'tone' : 'capture';
		$data = 'tone' === $stage ? array( 'tone_status' => 'pending' ) : array( 'capture_status' => 'pending' );
		return $this->save_claimed_visual( $snapshot_id, $stage, $token, $data );
	}

	public function save_visual_tone( $snapshot_id, $tone, $confidence, $reason, $raw, $token ) {
		$allowed = $this->visual_tones();
		$tone = in_array( $tone, $allowed, true ) ? $tone : 'Cần duyệt';
		$confidence = in_array( $confidence, array( 'high', 'medium', 'low' ), true ) ? $confidence : 'low';
		return $this->save_claimed_visual( $snapshot_id, 'tone', $token, array( 'tone' => $tone, 'confidence' => $confidence, 'tone_reason' => sanitize_text_field( $reason ), 'tone_status' => 'classified', 'ai_raw' => (string) $raw ) );
	}

	public function save_manual_visual_tone( $snapshot_id, $tone ) {
		$tone = sanitize_text_field( (string) $tone );
		$allowed = array_diff( $this->visual_tones(), array( 'Cần duyệt' ) );
		if ( ! in_array( $tone, $allowed, true ) ) {
			return new WP_Error( 'mac_tracker_visual_manual_tone', 'Choose a valid visual tone first.' );
		}
		return $this->save_visual( absint( $snapshot_id ), array(
			'tone'        => $tone,
			'confidence'  => 'high',
			'tone_reason' => 'Manually reviewed in MAC Project Tracker.',
			'tone_status' => 'classified',
			'tone_token'  => '',
			'ai_raw'      => wp_json_encode( array( 'manual' => true, 'user_id' => get_current_user_id() ) ),
		) );
	}

	/** A visual-model prompt change can safely reuse the stored screenshots. */
	public function requeue_visual_tones() {
		return (int) $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->visuals} SET tone = '', confidence = '', tone_reason = '', tone_status = 'pending', tone_token = '', ai_raw = '', updated_at = %s WHERE capture_status = 'captured'", MAC_Tracker_Time::now_utc() ) );
	}

	/** Revisit only categories affected by a visual taxonomy adjustment. */
	public function requeue_visual_tones_by_labels( array $labels ) {
		$labels = array_values( array_intersect( array_map( 'sanitize_text_field', $labels ), $this->visual_tones() ) );
		if ( empty( $labels ) ) { return 0; }
		$placeholders = implode( ',', array_fill( 0, count( $labels ), '%s' ) );
		$args = array_merge( array( MAC_Tracker_Time::now_utc() ), $labels );
		$sql = "UPDATE {$this->visuals} SET tone = '', confidence = '', tone_reason = '', tone_status = 'pending', tone_token = '', ai_raw = '', updated_at = %s WHERE capture_status = 'captured' AND tone IN ({$placeholders})";
		return (int) $this->wpdb->query( $this->wpdb->prepare( $sql, $args ) );
	}

	/** Queue stored captures or fresh captures without deleting the existing image. */
	public function requeue_visual_items( array $snapshot_ids, $mode ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $snapshot_ids ) ) ) );
		$mode = in_array( $mode, array( 'reanalyze', 'recapture', 'retry' ), true ) ? $mode : '';
		if ( empty( $ids ) || '' === $mode ) { return new WP_Error( 'mac_tracker_visual_selection', 'Select at least one screenshot first.' ); }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now = MAC_Tracker_Time::now_utc();
		if ( 'reanalyze' === $mode ) {
			$sql = "UPDATE {$this->visuals} SET tone = '', confidence = '', tone_reason = '', tone_status = 'pending', tone_token = '', ai_raw = '', updated_at = %s WHERE capture_status = 'captured' AND screenshot_url <> '' AND project_id IN ({$placeholders})";
			$args = array_merge( array( $now ), $ids );
		} elseif ( 'recapture' === $mode ) {
			// A fresh capture must not keep showing the previous image or AI result.
			$sql = "UPDATE {$this->visuals} SET capture_status = 'pending', capture_token = '', attachment_id = 0, screenshot_url = '', captured_at = NULL, tone = '', confidence = '', tone_reason = '', tone_status = 'pending', tone_token = '', ai_raw = '', updated_at = %s WHERE project_id IN ({$placeholders})";
			$args = array_merge( array( $now ), $ids );
		} else {
			$sql = "UPDATE {$this->visuals} SET capture_token = IF(capture_status = 'failed', '', capture_token), tone_token = IF(tone_status = 'failed', '', tone_token), capture_status = IF(capture_status = 'failed', 'pending', capture_status), tone_status = IF(tone_status = 'failed', 'pending', tone_status), ai_raw = '', updated_at = %s WHERE (capture_status = 'failed' OR tone_status = 'failed') AND project_id IN ({$placeholders})";
			$args = array_merge( array( $now ), $ids );
		}
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $args ) );
		return false === $result ? new WP_Error( 'mac_tracker_visual_requeue', $this->wpdb->last_error ?: 'Unable to queue visual work.' ) : (int) $result;
	}

	/** Attachment IDs are returned before a recapture clears their local references. */
	public function visual_attachment_ids( array $snapshot_ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $snapshot_ids ) ) ) );
		if ( empty( $ids ) ) { return array(); }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = "SELECT attachment_id FROM {$this->visuals} WHERE project_id IN ({$placeholders}) AND attachment_id > 0";
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $this->wpdb->get_col( $this->wpdb->prepare( $sql, $ids ) ) ) ) ) );
	}

	public function requeue_failed_visual_items() {
		$now = MAC_Tracker_Time::now_utc();
		$result = $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->visuals} SET capture_token = IF(capture_status = 'failed', '', capture_token), tone_token = IF(tone_status = 'failed', '', tone_token), capture_status = IF(capture_status = 'failed', 'pending', capture_status), tone_status = IF(tone_status = 'failed', 'pending', tone_status), ai_raw = '', updated_at = %s WHERE capture_status = 'failed' OR tone_status = 'failed'", $now ) );
		return false === $result ? new WP_Error( 'mac_tracker_visual_retry', $this->wpdb->last_error ?: 'Unable to retry failed visual work.' ) : (int) $result;
	}

	/** Move pre-token in-flight rows back to a clean queue during the upgrade. */
	public function repair_legacy_visual_claims() {
		$now = MAC_Tracker_Time::now_utc();
		$sql = "UPDATE {$this->visuals} SET capture_status = IF(capture_status = 'capturing', 'pending', capture_status), capture_token = '', tone_status = IF(tone_status = 'analyzing', 'pending', tone_status), tone_token = '', updated_at = %s WHERE capture_status = 'capturing' OR tone_status = 'analyzing'";
		return (int) $this->wpdb->query( $this->wpdb->prepare( $sql, $now ) );
	}

	public function visual_tones() {
		return array( 'Vàng kem sáng', 'Đen vàng', 'Hồng xanh trắng', 'Hồng trắng', 'Đỏ trắng', 'Nâu kem', 'Xanh trắng', 'Xanh đen', 'Đen trắng', 'Đỏ hồng', 'Tím hồng', 'Cần duyệt' );
	}

	/** Reject late callbacks from a superseded Analyze/Capture request. */
	private function visual_claim_matches( $snapshot_id, $stage, $token ) {
		$snapshot_id = absint( $snapshot_id );
		$token = sanitize_text_field( (string) $token );
		$column = 'tone' === $stage ? 'tone_token' : 'capture_token';
		if ( $snapshot_id <= 0 || '' === $token ) { return false; }
		$stored = (string) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT {$column} FROM {$this->visuals} WHERE project_id = %d", $snapshot_id ) );
		return '' !== $stored && hash_equals( $stored, $token );
	}

	private function save_claimed_visual( $snapshot_id, $stage, $token, array $data ) {
		$snapshot_id = absint( $snapshot_id );
		$stage = 'tone' === $stage ? 'tone' : 'capture';
		$token = sanitize_text_field( (string) $token );
		if ( ! $this->visual_claim_matches( $snapshot_id, $stage, $token ) ) {
			return new WP_Error( 'mac_tracker_visual_stale', 'This visual job was replaced by a newer request.', array( 'status' => 409 ) );
		}
		$column = 'tone' === $stage ? 'tone_token' : 'capture_token';
		$data[ $column ] = '';
		$data['updated_at'] = MAC_Tracker_Time::now_utc();
		$result = $this->wpdb->update( $this->visuals, $data, array( 'project_id' => $snapshot_id, $column => $token ) );
		if ( false === $result ) { return new WP_Error( 'mac_tracker_visual_save', $this->wpdb->last_error ?: 'Unable to save visual review.' ); }
		if ( 0 === $result ) { return new WP_Error( 'mac_tracker_visual_stale', 'This visual job was replaced by a newer request.', array( 'status' => 409 ) ); }
		return true;
	}

	private function save_visual( $snapshot_id, array $data ) {
		$snapshot_id = absint( $snapshot_id );
		if ( $snapshot_id <= 0 ) { return new WP_Error( 'mac_tracker_visual_invalid', 'Invalid snapshot.' ); }
		if ( ! $this->snapshot( $snapshot_id ) ) { return new WP_Error( 'mac_tracker_visual_missing', 'Snapshot not found.' ); }
		$existing = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$this->visuals} WHERE project_id = %d", $snapshot_id ) );
		$data['updated_at'] = MAC_Tracker_Time::now_utc();
		if ( $existing ) {
			return false === $this->wpdb->update( $this->visuals, $data, array( 'id' => $existing ) ) ? new WP_Error( 'mac_tracker_visual_save', $this->wpdb->last_error ?: 'Unable to save visual review.' ) : true;
		}
		$data['project_id'] = $snapshot_id;
		$data['created_at'] = MAC_Tracker_Time::now_utc();
		return false === $this->wpdb->insert( $this->visuals, $data ) ? new WP_Error( 'mac_tracker_visual_insert', $this->wpdb->last_error ?: 'Unable to save visual review.' ) : true;
	}

	/** Approval locks a reviewed palette so later extraction cannot overwrite it. */
	public function approve_color_record( $project_id, array $colors ) {
		$project_id = absint( $project_id );
		if ( $project_id <= 0 || empty( $colors ) ) {
			return new WP_Error( 'mac_tracker_color_approve_invalid', 'Add at least one valid color before approving.' );
		}
		$existing = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT id FROM {$this->colors} WHERE project_id = %d", $project_id ), ARRAY_A );
		if ( ! $existing ) {
			return new WP_Error( 'mac_tracker_color_missing', 'Extract Elementor colors before approving this palette.' );
		}
		$updated = $this->wpdb->update(
			$this->colors,
			array(
				'colors_json' => $this->encode_json( array_values( $colors ) ),
				'status'      => 'approved',
				'locked'      => 1,
				'approved_at' => MAC_Tracker_Time::now_utc(),
				'updated_at'  => MAC_Tracker_Time::now_utc(),
			),
			array( 'id' => (int) $existing['id'] )
		);
		if ( false === $updated ) {
			return new WP_Error( 'mac_tracker_color_approve_failed', $this->wpdb->last_error ?: 'Unable to approve this palette.' );
		}
		return true;
	}

	private function encode_json( $value ) { return wp_json_encode( $value ); }
}
