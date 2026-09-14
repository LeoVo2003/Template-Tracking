<?php

defined( 'ABSPATH' ) || exit;

/** Local storage. Admin pages read these tables only; they never contact WPM. */
class MAC_Tracker_Repository {

	private $wpdb;
	private $projects;
	private $colors;
	private $pins;
	private $logs;

	public function __construct() {
		global $wpdb;
		$this->wpdb     = $wpdb;
		$this->projects = $wpdb->prefix . 'mac_tracker_projects';
		$this->colors   = $wpdb->prefix . 'mac_tracker_color_records';
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

	/** Remove only local tracker records. Connection settings are intentionally retained. */
	public function clear_local_data() {
		$tables = array( $this->colors, $this->projects, $this->pins, $this->logs );
		foreach ( $tables as $table ) {
			if ( false === $this->wpdb->query( "DELETE FROM {$table}" ) ) {
				return new WP_Error( 'mac_tracker_clear_failed', $this->wpdb->last_error ?: 'Unable to clear local tracker data.' );
			}
		}
		delete_option( 'mac_tracker_backfill_cursor' );
		delete_option( 'mac_tracker_sync_queued_at' );
		delete_option( 'mac_tracker_roster_ids' );
		return true;
	}

	/** One bulk query for the Projects screen; no WPM/API call and no N+1. */
	public function project_page( array $filters = array() ) {
		$per_page = isset( $filters['per_page'] ) ? (int) $filters['per_page'] : 0;
		$per_page = in_array( $per_page, array( 50, 100, 200 ), true ) ? $per_page : 0;
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

		foreach ( array( 'website', 'layout' ) as $field ) {
			$value  = sanitize_key( $filters[ $field ] ?? '' );
			$column = 'website' === $field ? 'p.website_url' : 'p.layout_url';
			if ( 'yes' === $value ) { $where[] = "{$column} <> ''"; }
			if ( 'no' === $value ) { $where[] = "{$column} = ''"; }
		}

		$where_sql = implode( ' AND ', $where );
		$order_map = array(
			'id' => 'p.wpm_project_id', 'project' => 'p.name', 'website' => 'p.website_url',
			'layout' => 'p.layout_url', 'assignee' => 'p.assignee_json', 'date' => 'p.task_completed_at', 'time' => 'p.task_completed_at', 'palette' => 'c.status',
		);
		$sort     = sanitize_key( $filters['orderby'] ?? 'id' );
		$order_by = $order_map[ $sort ] ?? $order_map['id'];
		$order    = 'asc' === strtolower( (string) ( $filters['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$order_sql = "{$order_by} {$order}, p.id {$order}";

		$count_sql = "SELECT COUNT(*) FROM {$this->projects} p LEFT JOIN {$this->colors} c ON c.project_id = p.id WHERE {$where_sql}";
		$total     = empty( $args ) ? (int) $this->wpdb->get_var( $count_sql ) : (int) $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $args ) );
		$query     = "SELECT p.*, c.status AS color_status, c.colors_json, c.approved_at, c.locked AS color_locked FROM {$this->projects} p LEFT JOIN {$this->colors} c ON c.project_id = p.id WHERE {$where_sql} ORDER BY {$order_sql}";
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
		$visible = "(p.record_kind = 'action_design' OR (p.record_kind = 'csv_pin' AND NOT EXISTS (SELECT 1 FROM {$this->projects} action_snapshot WHERE action_snapshot.wpm_project_id = p.wpm_project_id AND action_snapshot.record_kind = 'action_design')))";
		return (array) $this->wpdb->get_row(
			"SELECT COUNT(*) AS snapshots, COUNT(DISTINCT p.wpm_project_id) AS projects, SUM(p.record_kind = 'action_design') AS action_design, SUM(p.record_kind = 'csv_pin') AS csv_pins FROM {$this->projects} p WHERE {$visible}",
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
			$name   = is_array( $person ) ? trim( (string) ( $person['name'] ?? '' ) ) : '';
			if ( '' !== $name ) { $names[ strtolower( $name ) ] = $name; }
		}
		natcasesort( $names );
		return array_values( $names );
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

	private function encode_json( $value ) { return wp_json_encode( $value ); }
}
