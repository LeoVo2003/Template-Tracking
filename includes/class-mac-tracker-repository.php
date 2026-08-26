<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Repository {

	/** @var wpdb */
	private $wpdb;

	/** @var string */
	private $projects_table;

	/** @var string */
	private $colors_table;

	/** @var string */
	private $logs_table;

	/** @var string */
	private $pins_table;

	public function __construct() {
		global $wpdb;

		$this->wpdb           = $wpdb;
		$this->projects_table = $wpdb->prefix . 'mac_tracker_projects';
		$this->colors_table   = $wpdb->prefix . 'mac_tracker_color_records';
		$this->logs_table     = $wpdb->prefix . 'mac_tracker_sync_logs';
		$this->pins_table     = $wpdb->prefix . 'mac_tracker_pinned_projects';
	}

	/**
	 * Ensure one immutable local snapshot exists.
	 *
	 * Existing rows are returned unchanged. Their three mutable Project-label
	 * fields are refreshed in one project-wide operation before this method.
	 *
	 * @param array $project Normalized snapshot.
	 * @param bool  $allow_insert Whether a missing snapshot may be inserted.
	 * @return array|WP_Error|null Array with id/created, or null when missing and insert is disallowed.
	 */
	public function ensure_project_snapshot( array $project, $allow_insert = true ) {
		$external_id = (int) ( $project['wpm_project_id'] ?? 0 );
		$record_kind = (string) ( $project['record_kind'] ?? '' );
		$task_id     = (int) ( $project['wpm_action_task_id'] ?? 0 );
		$sync_source = (string) ( $project['sync_source'] ?? 'unknown' );

		if ( $external_id <= 0 ) {
			return new WP_Error( 'mac_tracker_missing_project_id', 'Project ID is required.' );
		}
		if ( ! in_array( $record_kind, array( 'domain', 'csv_pin', 'action_design' ), true ) ) {
			return new WP_Error( 'mac_tracker_invalid_record_kind', 'Snapshot record kind is invalid.' );
		}
		if ( in_array( $record_kind, array( 'domain', 'csv_pin' ), true ) ) {
			$task_id = 0;
		} elseif ( $task_id <= 0 ) {
			return new WP_Error( 'mac_tracker_missing_task_id', 'Action Design snapshot requires a task ID.' );
		}

		$now     = current_time( 'mysql', true );
		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id, sync_source, record_kind, wpm_action_task_id
				FROM {$this->projects_table}
				WHERE wpm_project_id = %d
				AND record_kind = %s
				AND wpm_action_task_id = %d
				LIMIT 1",
				$external_id,
				$record_kind,
				$task_id
			),
			ARRAY_A
		);

		if ( $existing ) {
			$existing_id = (int) $existing['id'];
			// Repair rows created under mock/unknown so they become visible in real mode.
			if ( '' !== $sync_source && (string) $existing['sync_source'] !== $sync_source ) {
				$this->wpdb->update(
					$this->projects_table,
					array(
						'sync_source' => $sync_source,
						'updated_at'  => $now,
					),
					array( 'id' => $existing_id )
				);
			}

			return array(
				'id'        => $existing_id,
				'created'   => false,
				'existed'   => true,
			);
		}

		if ( ! $allow_insert ) {
			return null;
		}

		$data = array(
			'wpm_project_id'          => $external_id,
			'record_kind'             => $record_kind,
			'wpm_action_task_id'      => $task_id,
			'name'                    => (string) ( $project['name'] ?? '' ),
			'zip_code'                => (string) ( $project['zip_code'] ?? '' ),
			'package_json'            => $this->encode_json( $project['package'] ?? null ),
			'account_manager_json'    => $this->encode_json( $project['account_manager'] ?? null ),
			'assignee_json'           => $this->encode_json( $project['assignee'] ?? null ),
			'status'                  => (string) ( $project['status'] ?? '' ),
			'notes'                   => (string) ( $project['notes'] ?? '' ),
			'domain'                  => (string) ( $project['domain'] ?? '' ),
			'layout_url'              => (string) ( $project['layout_url'] ?? '' ),
			'website_url'             => (string) ( $project['website_url'] ?? '' ),
			'has_website_action_task' => ! empty( $project['has_website_action_task'] ) ? 1 : 0,
			'sync_source'             => $sync_source,
			'template_color_raw'      => (string) ( $project['template_color_raw'] ?? '' ),
			'content_url'             => (string) ( $project['content_url'] ?? '' ),
			'menu_url'                => (string) ( $project['menu_url'] ?? '' ),
			'wpm_updated_at'          => $project['wpm_updated_at'] ?? null,
			'task_completed_at'       => $project['task_completed_at'] ?? null,
			'pin_position'            => max( 0, (int) ( $project['pin_position'] ?? 0 ) ),
			// Snapshots remain listable forever, even if WPM later archives the project.
			'is_archived'             => 0,
			// Keep payload tiny — full WPM project JSON blows past packet limits and
			// can abort a sync after only a handful of inserts on some hosts.
			'raw_payload'             => $this->encode_json(
				array(
					'id'                 => $external_id,
					'record_kind'        => $record_kind,
					'wpm_action_task_id' => $task_id,
				)
			),
			'synced_at'               => $now,
			'updated_at'              => $now,
			'created_at'              => $now,
		);

		$result = $this->wpdb->insert( $this->projects_table, $data );

		if ( false === $result ) {
			$error = (string) $this->wpdb->last_error;
			// Legacy unique(wpm_project_id) or a race: treat duplicate as "already there".
			if ( false !== stripos( $error, 'Duplicate' ) ) {
				$existing_id = (int) $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT id FROM {$this->projects_table}
						WHERE wpm_project_id = %d
						AND record_kind = %s
						AND wpm_action_task_id = %d
						LIMIT 1",
						$external_id,
						$record_kind,
						$task_id
					)
				);
				if ( $existing_id > 0 ) {
					return array(
						'id'      => $existing_id,
						'created' => false,
						'existed' => true,
					);
				}

				// Composite key missing / blocked by legacy unique on project id only.
				return new WP_Error(
					'mac_tracker_project_unique_conflict',
					sprintf( 'Unique key conflict #%d / %s / task #%d.', $external_id, $record_kind, $task_id )
				);
			}

			return new WP_Error(
				'mac_tracker_project_insert_failed',
				sprintf( 'Insert failed #%d: %s', $external_id, $error ? $error : 'db error' )
			);
		}

		return array(
			'id'      => (int) $this->wpdb->insert_id,
			'created' => true,
			'existed' => false,
		);
	}

	/**
	 * Storage diagnostics for sync logs.
	 *
	 * @return array
	 */
	public function snapshot_storage_stats() {
		$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->projects_table}" );
		$real  = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->projects_table} WHERE sync_source = 'real'"
		);
		$domain = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->projects_table} WHERE record_kind = 'domain'"
		);
		$csv_pin = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->projects_table} WHERE record_kind = 'csv_pin'"
		);
		$action = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->projects_table} WHERE record_kind = 'action_design'"
		);
		$distinct_projects = (int) $this->wpdb->get_var(
			"SELECT COUNT(DISTINCT wpm_project_id) FROM {$this->projects_table}"
		);

		return array(
			'total'             => $total,
			'real'              => $real,
			'domain'            => $domain,
			'csv_pin'           => $csv_pin,
			'action_design'     => $action,
			'distinct_projects' => $distinct_projects,
		);
	}

	/**
	 * Refresh only the mutable Project-label fields across every snapshot of a
	 * WPM project. This also covers snapshots whose task is no longer returned.
	 *
	 * @param array $project Normalized project base.
	 * @return int|WP_Error Number of rows whose values changed.
	 */
	public function refresh_project_snapshot_labels( array $project ) {
		$external_id = (int) ( $project['wpm_project_id'] ?? 0 );
		if ( $external_id <= 0 ) {
			return new WP_Error( 'mac_tracker_missing_project_id', 'Project ID is required.' );
		}

		$name = trim( (string) ( $project['name'] ?? '' ) );
		$zip  = trim( (string) ( $project['zip_code'] ?? '' ) );
		$data = array(
			'package_json' => $this->encode_json( $project['package'] ?? null ),
		);
		// Do not blank CSV-filled labels when WPM omits name/zip for this page.
		if ( '' !== $name ) {
			$data['name'] = $name;
		}
		if ( '' !== $zip ) {
			$data['zip_code'] = $zip;
		}

		$result = $this->wpdb->update(
			$this->projects_table,
			$data,
			array( 'wpm_project_id' => $external_id )
		);

		if ( false === $result ) {
			return new WP_Error( 'mac_tracker_snapshot_update_failed', $this->wpdb->last_error );
		}

		return (int) $result;
	}

	/**
	 * @param array $filters Optional project filters.
	 * @return int
	 */
	public function count_projects( array $filters = array() ) {
		$params = array();
		$where  = $this->build_project_where( $filters, $params );
		$sql    = "SELECT COUNT(*)
			FROM {$this->projects_table} p
			LEFT JOIN {$this->colors_table} c ON c.project_id = p.id
			WHERE {$where}";

		return (int) $this->wpdb->get_var(
			call_user_func_array(
				array( $this->wpdb, 'prepare' ),
				array_merge( array( $sql ), $params )
			)
		);
	}

	/**
	 * @param int $limit Maximum number of projects. Use 0 for all matching rows.
	 * @param int $offset Row offset.
	 * @param array $filters Optional project filters.
	 * @return array
	 */
	public function list_projects( $limit = 0, $offset = 0, array $filters = array() ) {
		$limit  = (int) $limit;
		$offset = max( 0, (int) $offset );
		$params = array();
		$where  = $this->build_project_where( $filters, $params );
		$sort_key   = (string) ( $filters['sort_by'] ?? 'timeline' );
		$sort_order = 'asc' === strtolower( (string) ( $filters['sort_order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';

		// Default: AD since cutoff on top (newest → today first), then CSV pin bottom-up.
		if ( in_array( $sort_key, array( 'timeline', 'id', '' ), true ) ) {
			$cutoff = MAC_TRACKER_AD_COMPLETED_CUTOFF;
			$order  = $this->wpdb->prepare(
				"ORDER BY
					CASE
						WHEN p.record_kind = 'action_design'
							AND p.task_completed_at IS NOT NULL
							AND DATE(p.task_completed_at) >= %s
						THEN 0
						ELSE 1
					END ASC,
					CASE
						WHEN p.record_kind = 'action_design'
							AND p.task_completed_at IS NOT NULL
							AND DATE(p.task_completed_at) >= %s
						THEN p.task_completed_at
						ELSE NULL
					END DESC,
					p.pin_position DESC,
					p.wpm_project_id DESC,
					p.wpm_action_task_id DESC,
					p.id DESC",
				$cutoff,
				$cutoff
			);
		} else {
			$sort_columns = array(
				'task'      => 'p.wpm_action_task_id',
				'project'   => "CONCAT(COALESCE(p.zip_code, ''), ' ', COALESCE(p.name, ''))",
				'assignee'  => 'p.assignee_json',
				'website'   => 'p.website_url',
				'layout'    => 'p.layout_url',
				'palette'   => "COALESCE(c.status, 'none')",
				'completed' => 'p.task_completed_at',
				'time'      => 'TIME(p.task_completed_at)',
			);
			$sort_column = isset( $sort_columns[ $sort_key ] ) ? $sort_columns[ $sort_key ] : 'p.wpm_project_id';
			$order       = "ORDER BY {$sort_column} {$sort_order}, p.wpm_project_id DESC, p.wpm_action_task_id DESC, p.id DESC";
		}

		$sql = "SELECT
				p.id,
				p.wpm_project_id,
				p.record_kind,
				p.wpm_action_task_id,
				p.name,
				p.zip_code,
				p.package_json,
				p.assignee_json,
				p.status,
				p.domain,
				p.layout_url,
				p.website_url,
				p.has_website_action_task,
				p.sync_source,
				p.wpm_updated_at,
				p.task_completed_at,
				p.pin_position,
				p.is_archived,
				p.synced_at,
				p.created_at,
				p.updated_at,
				pin.projects_raw AS pin_projects_raw,
				c.status AS color_status,
				c.final_colors,
				c.color_count
			FROM {$this->projects_table} p
			LEFT JOIN {$this->pins_table} pin ON pin.wpm_project_id = p.wpm_project_id
			LEFT JOIN {$this->colors_table} c ON c.project_id = p.id
			WHERE {$where}
			{$order}";

		// 0 = show every matching snapshot in one table (not WPM-style 100/page).
		if ( $limit > 0 ) {
			$limit    = max( 1, min( 10000, $limit ) );
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		return $this->wpdb->get_results(
			empty( $params )
				? $sql
				: call_user_func_array(
					array( $this->wpdb, 'prepare' ),
					array_merge( array( $sql ), $params )
				),
			ARRAY_A
		);
	}

	/**
	 * Distinct assignees for the Projects filter dropdown (emoji/noise collapsed).
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public function list_assignee_options() {
		$rows = $this->wpdb->get_col(
			"SELECT DISTINCT p.assignee_json
			FROM {$this->projects_table} p
			WHERE p.sync_source IN ('real', 'pin')
			AND p.assignee_json IS NOT NULL
			AND p.assignee_json <> ''
			AND p.assignee_json <> 'null'
			LIMIT 2000"
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$grouped = array();
		foreach ( $rows as $json ) {
			$decoded = json_decode( (string) $json, true );
			$raw     = '';
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['name'] ) && is_scalar( $decoded['name'] ) ) {
					$raw = (string) $decoded['name'];
				} elseif ( isset( $decoded[0] ) && is_array( $decoded[0] ) && isset( $decoded[0]['name'] ) ) {
					$raw = (string) $decoded[0]['name'];
				}
			} elseif ( is_string( $json ) && '{' !== substr( trim( (string) $json ), 0, 1 ) ) {
				$raw = (string) $json;
			}
			$key = MAC_Tracker_Normalize::normalize_person_key( $raw );
			if ( '' === $key ) {
				continue;
			}
			$label = MAC_Tracker_Normalize::display_person_name( $raw );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = $label;
			}
		}

		ksort( $grouped, SORT_NATURAL | SORT_FLAG_CASE );
		$out = array();
		foreach ( $grouped as $key => $label ) {
			$out[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Distinct Layout filter groups: package codes (F01/S06…), External, None.
	 *
	 * @return array<int, array{key:string,label:string}>
	 */
	public function list_layout_group_options() {
		$rows = $this->wpdb->get_col(
			"SELECT DISTINCT p.layout_url
			FROM {$this->projects_table} p
			WHERE p.sync_source IN ('real', 'pin')
			LIMIT 4000"
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$codes    = array();
		$has_none = false;
		$has_ext  = false;
		foreach ( $rows as $layout_url ) {
			$key = MAC_Tracker_Normalize::layout_group_key( $layout_url );
			if ( 'none' === $key ) {
				$has_none = true;
				continue;
			}
			if ( 'external' === $key ) {
				$has_ext = true;
				continue;
			}
			$codes[ $key ] = $key;
		}

		ksort( $codes, SORT_NATURAL | SORT_FLAG_CASE );
		$out = array();
		foreach ( $codes as $code ) {
			$out[] = array(
				'key'   => $code,
				'label' => $code,
			);
		}
		if ( $has_ext ) {
			$out[] = array(
				'key'   => 'external',
				'label' => 'External',
			);
		}
		if ( $has_none ) {
			$out[] = array(
				'key'   => 'none',
				'label' => 'None',
			);
		}
		return $out;
	}

	/**
	 * Build a fixed-clause filtered query for the Projects admin table.
	 *
	 * @param array $filters Project filters.
	 * @param array $params Prepared-query parameters, filled by reference.
	 * @return string
	 */
	private function build_project_where( array $filters, array &$params ) {
		$clauses = array(
			"p.sync_source IN ('real', 'pin')",
		);

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like = '%' . $this->wpdb->esc_like( $search ) . '%';
			$clauses[] = '(CAST(p.wpm_project_id AS CHAR) LIKE %s
				OR CAST(p.wpm_action_task_id AS CHAR) LIKE %s
				OR p.name LIKE %s
				OR p.zip_code LIKE %s
				OR p.package_json LIKE %s
				OR p.assignee_json LIKE %s
				OR p.domain LIKE %s
				OR p.website_url LIKE %s
				OR p.layout_url LIKE %s)';
			for ( $index = 0; $index < 9; ++$index ) {
				$params[] = $like;
			}
		}

		$assignee = trim( (string) ( $filters['assignee'] ?? '' ) );
		if ( '' !== $assignee ) {
			// Dropdown stores normalized key ("leo"); match pin/API variants via LIKE.
			$clauses[] = 'LOWER(p.assignee_json) LIKE %s';
			$params[]  = '%' . $this->wpdb->esc_like( strtolower( $assignee ) ) . '%';
		}

		$palette = (string) ( $filters['palette'] ?? '' );
		if ( 'none' === $palette ) {
			$clauses[] = 'c.id IS NULL';
		} elseif ( in_array( $palette, array( 'waiting', 'pending', 'approved' ), true ) ) {
			$clauses[] = 'c.status = %s';
			$params[]  = $palette;
		}

		$website = (string) ( $filters['website'] ?? '' );
		if ( 'yes' === $website ) {
			$clauses[] = "p.website_url IS NOT NULL AND p.website_url <> ''";
		} elseif ( 'no' === $website ) {
			$clauses[] = "(p.website_url IS NULL OR p.website_url = '')";
		}

		$layout = trim( (string) ( $filters['layout'] ?? '' ) );
		if ( 'none' === $layout ) {
			$clauses[] = "(p.layout_url IS NULL OR p.layout_url = '')";
		} elseif ( 'external' === $layout ) {
			$clauses[] = "(p.layout_url IS NOT NULL AND p.layout_url <> '')
				AND LOWER(p.layout_url) NOT REGEXP 'demo[-_]?[fsgp][0-9]+'";
		} elseif ( preg_match( '/^([FSGP])(\d+)$/i', $layout, $match ) ) {
			$letter    = strtolower( $match[1] );
			$digits    = $match[2];
			$clauses[] = 'LOWER(p.layout_url) REGEXP %s';
			$params[]  = 'demo[-_]?' . $letter . $digits . '([^0-9]|$)';
		}

		$action_task = (string) ( $filters['action_task'] ?? '' );
		if ( 'yes' === $action_task ) {
			$clauses[] = "p.record_kind = 'action_design'";
		} elseif ( 'no' === $action_task ) {
			$clauses[] = "p.record_kind IN ('domain', 'csv_pin')";
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * @param int $wpm_project_id WPM project ID.
	 * @return bool
	 */
	public function is_pinned( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		if ( $wpm_project_id <= 0 ) {
			return false;
		}
		$found = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT wpm_project_id FROM {$this->pins_table} WHERE wpm_project_id = %d LIMIT 1",
				$wpm_project_id
			)
		);
		return $found > 0;
	}

	/**
	 * @return int
	 */
	public function count_pins() {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->pins_table}" );
	}

	/**
	 * All pinned WPM project IDs (for sync backfill when list API omits Cancel rows).
	 *
	 * @return int[]
	 */
	public function list_pinned_project_ids() {
		$rows = $this->wpdb->get_col(
			"SELECT wpm_project_id FROM {$this->pins_table} ORDER BY wpm_project_id ASC"
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * @param int $wpm_project_id WPM project ID.
	 * @return array|null
	 */
	public function get_pin( $wpm_project_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->pins_table} WHERE wpm_project_id = %d LIMIT 1",
				(int) $wpm_project_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Upsert pin registry + csv_pin baseline snapshot (Website/Layout/Assignee only).
	 *
	 * @param array $row Parsed pin row.
	 * @return array|WP_Error
	 */
	public function upsert_pin_baseline( array $row ) {
		$wpm_id = (int) ( $row['wpm_project_id'] ?? 0 );
		if ( $wpm_id <= 0 ) {
			return new WP_Error( 'mac_tracker_pin_id', 'project_id is required.' );
		}

		$now          = current_time( 'mysql', true );
		$website      = MAC_Tracker_Normalize::normalize_website_url_value( (string) ( $row['website_url'] ?? '' ) );
		$layout       = MAC_Tracker_Normalize::normalize_layout_value( (string) ( $row['layout_url'] ?? '' ) );
		$assignee     = (string) ( $row['assignee_name'] ?? '' );
		$projects_raw = (string) ( $row['projects_raw'] ?? '' );
		$pin_position = max( 0, (int) ( $row['pin_position'] ?? 0 ) );
		$existing_pin = $this->get_pin( $wpm_id );
		$from_csv     = MAC_Tracker_Normalize::parse_projects_label( $projects_raw );
		$csv_zip      = (string) ( $from_csv['zip'] ?? '' );
		$csv_name     = (string) ( $from_csv['store_name'] ?? '' );
		$csv_package  = (string) ( $from_csv['package'] ?? '' );
		$completed_at = MAC_Tracker_Time::bangkok_parts_to_utc(
			(string) ( $row['completed_date'] ?? '' ),
			(string) ( $row['completed_time'] ?? '' )
		);

		// CSV often omits Layout/Date: never blank values sync/CSV already filled.
		$keep_layout  = '' === $layout && $existing_pin && '' !== trim( (string) ( $existing_pin['layout_url'] ?? '' ) );
		$keep_website = '' === $website && $existing_pin && '' !== trim( (string) ( $existing_pin['website_url'] ?? '' ) );
		if ( $keep_layout ) {
			$layout = MAC_Tracker_Normalize::normalize_layout_value( (string) $existing_pin['layout_url'] );
		}
		if ( $keep_website ) {
			$website = MAC_Tracker_Normalize::normalize_website_url_value( (string) $existing_pin['website_url'] );
		}
		if ( null === $completed_at && $existing_pin && ! empty( $existing_pin['task_completed_at'] ) ) {
			$completed_at = (string) $existing_pin['task_completed_at'];
		}

		$pin_data = array(
			'wpm_project_id'     => $wpm_id,
			'website_url'        => $website,
			'layout_url'         => $layout,
			'assignee_name'      => $assignee,
			'projects_raw'       => $projects_raw,
			'pin_position'       => $pin_position,
			'task_completed_at'  => $completed_at,
			'updated_at'         => $now,
		);

		if ( $existing_pin ) {
			$pin_ok = $this->wpdb->update(
				$this->pins_table,
				$pin_data,
				array( 'wpm_project_id' => $wpm_id )
			);
			if ( false === $pin_ok ) {
				return new WP_Error( 'mac_tracker_pin_update_failed', $this->wpdb->last_error );
			}
		} else {
			$pin_data['created_at'] = $now;
			$pin_ok = $this->wpdb->insert( $this->pins_table, $pin_data );
			if ( false === $pin_ok ) {
				return new WP_Error( 'mac_tracker_pin_insert_failed', $this->wpdb->last_error );
			}
		}

		$snapshot = array(
			'wpm_project_id'          => $wpm_id,
			'record_kind'             => 'csv_pin',
			'wpm_action_task_id'      => 0,
			'name'                    => $csv_name,
			'zip_code'                => $csv_zip,
			'package'                 => '' !== $csv_package ? $csv_package : null,
			'account_manager'         => null,
			'assignee'                => '' !== $assignee ? array( 'name' => $assignee ) : null,
			'status'                  => '',
			'notes'                   => '',
			'domain'                  => MAC_Tracker_Normalize::normalize_host_value( $website ),
			'layout_url'              => $layout,
			'website_url'             => $website,
			'has_website_action_task' => 0,
			'template_color_raw'      => '',
			'content_url'             => '',
			'menu_url'                => '',
			'wpm_updated_at'          => null,
			'task_completed_at'       => $completed_at,
			'pin_position'            => $pin_position,
			'sync_source'             => 'pin',
		);

		$existing_snapshot = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id, name, zip_code, layout_url, website_url, task_completed_at FROM {$this->projects_table}
				WHERE wpm_project_id = %d AND record_kind = 'csv_pin' AND wpm_action_task_id = 0
				LIMIT 1",
				$wpm_id
			),
			ARRAY_A
		);

		if ( $existing_snapshot ) {
			$snapshot_layout  = $layout;
			$snapshot_website = $website;
			$snapshot_done    = $completed_at;
			if ( '' === $snapshot_layout && '' !== trim( (string) ( $existing_snapshot['layout_url'] ?? '' ) ) ) {
				$snapshot_layout = MAC_Tracker_Normalize::normalize_layout_value( (string) $existing_snapshot['layout_url'] );
			}
			if ( '' === $snapshot_website && '' !== trim( (string) ( $existing_snapshot['website_url'] ?? '' ) ) ) {
				$snapshot_website = MAC_Tracker_Normalize::normalize_website_url_value( (string) $existing_snapshot['website_url'] );
			}
			if ( null === $snapshot_done || '' === $snapshot_done ) {
				$existing_done = trim( (string) ( $existing_snapshot['task_completed_at'] ?? '' ) );
				$snapshot_done = '' !== $existing_done ? $existing_done : null;
			}

			$update = array(
				'website_url'   => $snapshot_website,
				'layout_url'    => $snapshot_layout,
				'assignee_json' => $this->encode_json( '' !== $assignee ? array( 'name' => $assignee ) : null ),
				'domain'        => MAC_Tracker_Normalize::normalize_host_value( $snapshot_website ),
				'pin_position'  => $pin_position,
				'sync_source'   => 'pin',
				'updated_at'    => $now,
			);
			// CSV Date/Time locks pin completed_at; sync must not overwrite later.
			if ( null !== $snapshot_done && '' !== $snapshot_done ) {
				$update['task_completed_at'] = $snapshot_done;
			}
			// CSV Projects column is baseline label — always refresh when present.
			if ( '' !== $csv_name ) {
				$update['name'] = $csv_name;
			}
			if ( '' !== $csv_zip ) {
				$update['zip_code'] = $csv_zip;
			}
			if ( '' !== $csv_package ) {
				$update['package_json'] = $this->encode_json( $csv_package );
			}

			$updated = $this->wpdb->update(
				$this->projects_table,
				$update,
				array( 'id' => (int) $existing_snapshot['id'] )
			);
			if ( false === $updated ) {
				return new WP_Error( 'mac_tracker_pin_snapshot_update_failed', $this->wpdb->last_error );
			}
			return array(
				'created' => false,
				'updated' => true,
				'id'      => (int) $existing_snapshot['id'],
			);
		}

		$saved = $this->ensure_project_snapshot( $snapshot, true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'created' => ! empty( $saved['created'] ),
			'updated' => empty( $saved['created'] ),
			'id'      => (int) ( $saved['id'] ?? 0 ),
		);
	}

	/**
	 * Fill empty csv_pin name/zip/package from pin.projects_raw (CSV Projects).
	 * Covers pinned IDs missing from the current WPM sync payload.
	 *
	 * @return int Rows updated.
	 */
	public function apply_pin_csv_labels_to_empty_snapshots() {
		$rows = $this->wpdb->get_results(
			"SELECT p.id, pin.projects_raw
			FROM {$this->projects_table} p
			INNER JOIN {$this->pins_table} pin ON pin.wpm_project_id = p.wpm_project_id
			WHERE p.record_kind = 'csv_pin'
			AND p.wpm_action_task_id = 0
			AND pin.projects_raw IS NOT NULL
			AND pin.projects_raw <> ''
			AND (
				p.name IS NULL OR p.name = ''
				OR p.zip_code IS NULL OR p.zip_code = ''
				OR p.package_json IS NULL OR p.package_json = '' OR p.package_json = 'null'
			)",
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$now     = current_time( 'mysql', true );
		$updated = 0;
		foreach ( $rows as $row ) {
			$parsed = MAC_Tracker_Normalize::parse_projects_label( (string) ( $row['projects_raw'] ?? '' ) );
			$data   = array( 'updated_at' => $now );
			if ( '' !== (string) ( $parsed['store_name'] ?? '' ) ) {
				$data['name'] = (string) $parsed['store_name'];
			}
			if ( '' !== (string) ( $parsed['zip'] ?? '' ) ) {
				$data['zip_code'] = (string) $parsed['zip'];
			}
			if ( '' !== (string) ( $parsed['package'] ?? '' ) ) {
				$data['package_json'] = $this->encode_json( (string) $parsed['package'] );
			}
			if ( count( $data ) <= 1 ) {
				continue;
			}
			$result = $this->wpdb->update(
				$this->projects_table,
				$data,
				array( 'id' => (int) $row['id'] )
			);
			if ( false !== $result && $result > 0 ) {
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Local id of the csv_pin baseline row for a WPM project.
	 *
	 * @param int $wpm_project_id WPM project ID.
	 * @return int
	 */
	public function get_csv_pin_snapshot_id( $wpm_project_id ) {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->projects_table}
				WHERE wpm_project_id = %d AND record_kind = 'csv_pin' AND wpm_action_task_id = 0
				LIMIT 1",
				(int) $wpm_project_id
			)
		);
	}

	/**
	 * Remove Action Design rows for a pinned project (baseline stays as csv_pin only).
	 *
	 * @param int $wpm_project_id WPM project ID.
	 * @return int Number of snapshot rows removed.
	 */
	public function delete_action_design_snapshots_for_project( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		if ( $wpm_project_id <= 0 ) {
			return 0;
		}

		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->projects_table}
				WHERE wpm_project_id = %d AND record_kind = 'action_design'",
				$wpm_project_id
			)
		);
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		if ( empty( $ids ) ) {
			return 0;
		}

		$id_list = implode( ',', $ids );
		$this->wpdb->query( "DELETE FROM {$this->colors_table} WHERE project_id IN ({$id_list})" );
		$deleted = (int) $this->wpdb->query(
			"DELETE FROM {$this->projects_table} WHERE id IN ({$id_list})"
		);

		return max( 0, $deleted );
	}

	/**
	 * Rewrite stored website_url values to https://host/ for one WPM project.
	 * Fixes dup display like toppolishnailsnc.com vs https://toppolishnailsnc.com/.
	 *
	 * @param int $wpm_project_id WPM project ID.
	 * @return int Number of rows updated.
	 */
	public function canonicalize_website_urls_for_project( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		if ( $wpm_project_id <= 0 ) {
			return 0;
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, website_url, domain FROM {$this->projects_table} WHERE wpm_project_id = %d",
				$wpm_project_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$changed = 0;
		$now     = current_time( 'mysql', true );
		foreach ( $rows as $row ) {
			$website = (string) ( $row['website_url'] ?? '' );
			$canon   = MAC_Tracker_Normalize::normalize_website_url_value( $website );
			$host    = MAC_Tracker_Normalize::normalize_host_value( $website );
			if ( '' === $canon ) {
				continue;
			}
			$update = array();
			if ( $canon !== $website ) {
				$update['website_url'] = $canon;
			}
			if ( '' !== $host && $host !== (string) ( $row['domain'] ?? '' ) ) {
				$update['domain'] = $host;
			}
			if ( empty( $update ) ) {
				continue;
			}
			$update['updated_at'] = $now;
			$result = $this->wpdb->update(
				$this->projects_table,
				$update,
				array( 'id' => (int) $row['id'] )
			);
			if ( false !== $result && $result > 0 ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * @param int    $snapshot_id Local snapshot id.
	 * @param string $completed_at MySQL datetime.
	 * @return bool
	 */
	public function set_task_completed_at_if_empty( $snapshot_id, $completed_at ) {
		$snapshot_id  = (int) $snapshot_id;
		$completed_at = trim( (string) $completed_at );
		if ( $snapshot_id <= 0 || '' === $completed_at ) {
			return false;
		}
		$current = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT task_completed_at FROM {$this->projects_table} WHERE id = %d LIMIT 1",
				$snapshot_id
			)
		);
		if ( null !== $current && '' !== (string) $current ) {
			return false;
		}
		$result = $this->wpdb->update(
			$this->projects_table,
			array(
				'task_completed_at' => $completed_at,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $snapshot_id )
		);
		return false !== $result && $result > 0;
	}

	/**
	 * Fill empty csv_pin layout once (snapshot + pin registry). Locked after set.
	 *
	 * @param int    $wpm_project_id WPM project ID.
	 * @param string $layout_url Layout from project web_layout or AD task.
	 * @return bool
	 */
	public function fill_empty_csv_pin_layout( $wpm_project_id, $layout_url ) {
		$wpm_project_id = (int) $wpm_project_id;
		$layout_url     = MAC_Tracker_Normalize::normalize_layout_value( $layout_url );
		if ( $wpm_project_id <= 0 || '' === $layout_url ) {
			return false;
		}

		$now     = current_time( 'mysql', true );
		$changed = false;

		$snapshot_id = $this->get_csv_pin_snapshot_id( $wpm_project_id );
		if ( $snapshot_id > 0 ) {
			$current = (string) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT layout_url FROM {$this->projects_table} WHERE id = %d LIMIT 1",
					$snapshot_id
				)
			);
			if ( '' === trim( $current ) ) {
				$result = $this->wpdb->update(
					$this->projects_table,
					array(
						'layout_url' => $layout_url,
						'updated_at' => $now,
					),
					array( 'id' => $snapshot_id )
				);
				if ( false !== $result && $result > 0 ) {
					$changed = true;
				}
			}
		}

		$pin = $this->get_pin( $wpm_project_id );
		if ( $pin && '' === trim( (string) ( $pin['layout_url'] ?? '' ) ) ) {
			$pin_result = $this->wpdb->update(
				$this->pins_table,
				array(
					'layout_url' => $layout_url,
					'updated_at' => $now,
				),
				array( 'wpm_project_id' => $wpm_project_id )
			);
			if ( false !== $pin_result && $pin_result > 0 ) {
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * @param int $project_id Local project ID.
	 * @return array|null
	 */
	public function get_project( $project_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->projects_table} WHERE id = %d LIMIT 1",
				(int) $project_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Ensure each project with a color source has one tracking record.
	 * Approved records are immutable.
	 *
	 * @param int    $project_id Local project ID.
	 * @param string $source_type Detected source type.
	 * @param string $source_url Original raw value.
	 * @return int|WP_Error
	 */
	public function upsert_color_source( $project_id, $source_type, $source_url ) {
		$project_id = (int) $project_id;
		$existing   = $this->get_color_by_project( $project_id );
		$now        = current_time( 'mysql', true );

		if ( $existing && 'approved' === $existing['status'] ) {
			return (int) $existing['id'];
		}

		$data = array(
			'project_id'  => $project_id,
			'source_type' => (string) $source_type,
			'source_url'  => (string) $source_url,
			'updated_at'  => $now,
		);

		if ( $existing ) {
			$source_changed = (string) $existing['source_type'] !== (string) $source_type
				|| (string) $existing['source_url'] !== (string) $source_url;

			if ( $source_changed ) {
				$data['tool_colors']    = null;
				$data['gemini_colors']  = null;
				$data['final_colors']   = null;
				$data['color_count']    = 0;
				$data['status']         = 'waiting';
				$data['confidence']     = null;
				$data['error_message']  = null;
			}

			$result = $this->wpdb->update(
				$this->colors_table,
				$data,
				array( 'id' => (int) $existing['id'] )
			);

			if ( false === $result ) {
				return new WP_Error( 'mac_tracker_color_source_update_failed', $this->wpdb->last_error );
			}

			return (int) $existing['id'];
		}

		$data['status']     = 'waiting';
		$data['created_at'] = $now;
		$result             = $this->wpdb->insert( $this->colors_table, $data );

		if ( false === $result ) {
			return new WP_Error( 'mac_tracker_color_source_insert_failed', $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Clear stale proposed colors when the selected action task has no source.
	 * Approved palettes remain immutable.
	 *
	 * @param int $project_id Local project ID.
	 * @return int|WP_Error|null
	 */
	public function clear_unapproved_palette( $project_id ) {
		$existing = $this->get_color_by_project( $project_id );
		if ( ! $existing || 'approved' === $existing['status'] ) {
			return $existing ? (int) $existing['id'] : null;
		}

		$result = $this->wpdb->update(
			$this->colors_table,
			array(
				'source_type'  => 'unknown',
				'source_url'   => '',
				'tool_colors'  => null,
				'gemini_colors'=> null,
				'final_colors' => null,
				'color_count'  => 0,
				'status'       => 'waiting',
				'confidence'   => null,
				'error_message'=> null,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $existing['id'] )
		);

		if ( false === $result ) {
			return new WP_Error( 'mac_tracker_palette_clear_failed', $this->wpdb->last_error );
		}

		return (int) $existing['id'];
	}

	/**
	 * Save 4-6 proposed colors for approval.
	 *
	 * @param int   $project_id Local project ID.
	 * @param array $tool_colors Tool/OCR candidates.
	 * @param array $gemini_colors Gemini candidates.
	 * @param array $final_colors Mapped final candidates.
	 * @param float $confidence Optional confidence.
	 * @return int|WP_Error
	 */
	public function save_pending_palette( $project_id, array $tool_colors, array $gemini_colors, array $final_colors, $confidence = null ) {
		$count = count( $final_colors );
		if ( $count < 4 || $count > 6 ) {
			return new WP_Error( 'mac_tracker_invalid_color_count', 'A palette must contain between 4 and 6 colors.' );
		}

		$existing = $this->get_color_by_project( $project_id );
		if ( $existing && 'approved' === $existing['status'] ) {
			return (int) $existing['id'];
		}

		$now  = current_time( 'mysql', true );
		$data = array(
			'tool_colors'    => $this->encode_json( array_values( $tool_colors ) ),
			'gemini_colors' => $this->encode_json( array_values( $gemini_colors ) ),
			'final_colors'  => $this->encode_json( array_values( $final_colors ) ),
			'color_count'   => $count,
			'status'        => 'pending',
			'confidence'    => null === $confidence ? null : max( 0, min( 1, (float) $confidence ) ),
			'error_message' => null,
			'updated_at'    => $now,
		);

		if ( $existing ) {
			$result = $this->wpdb->update(
				$this->colors_table,
				$data,
				array( 'id' => (int) $existing['id'] )
			);
			if ( false === $result ) {
				return new WP_Error( 'mac_tracker_palette_update_failed', $this->wpdb->last_error );
			}

			return (int) $existing['id'];
		}

		$data['project_id']  = (int) $project_id;
		$data['source_type'] = 'hex_text';
		$data['created_at']  = $now;
		$result              = $this->wpdb->insert( $this->colors_table, $data );

		if ( false === $result ) {
			return new WP_Error( 'mac_tracker_palette_insert_failed', $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param int $project_id Local project ID.
	 * @return array|null
	 */
	public function get_color_by_project( $project_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->colors_table} WHERE project_id = %d LIMIT 1",
				(int) $project_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * @param string $status Optional status filter.
	 * @param int    $limit Maximum number of records.
	 * @return array
	 */
	public function list_color_records( $status = '', $limit = 200 ) {
		$limit = max( 1, min( 500, (int) $limit ) );

		if ( '' !== $status ) {
			return $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT c.*, p.name AS project_name, p.zip_code, p.package_json, p.domain,
						p.wpm_project_id, p.record_kind, p.wpm_action_task_id
					FROM {$this->colors_table} c
					INNER JOIN {$this->projects_table} p ON p.id = c.project_id
					WHERE c.status = %s
					AND p.sync_source = 'real'
					AND p.wpm_project_id >= %d
					ORDER BY c.updated_at DESC
					LIMIT %d",
					$status,
					MAC_TRACKER_MIN_WPM_PROJECT_ID,
					$limit
				),
				ARRAY_A
			);
		}

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT c.*, p.name AS project_name, p.zip_code, p.package_json, p.domain,
					p.wpm_project_id, p.record_kind, p.wpm_action_task_id
				FROM {$this->colors_table} c
				INNER JOIN {$this->projects_table} p ON p.id = c.project_id
				WHERE p.sync_source = 'real'
				AND p.wpm_project_id >= %d
				ORDER BY c.updated_at DESC
				LIMIT %d",
				MAC_TRACKER_MIN_WPM_PROJECT_ID,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * @param string $status Status to count.
	 * @return int
	 */
	public function count_colors( $status = '' ) {
		if ( '' === $status ) {
			return (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$this->colors_table} c
					INNER JOIN {$this->projects_table} p ON p.id = c.project_id
					WHERE p.sync_source = 'real'
					AND p.wpm_project_id >= %d",
					MAC_TRACKER_MIN_WPM_PROJECT_ID
				)
			);
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$this->colors_table} c
				INNER JOIN {$this->projects_table} p ON p.id = c.project_id
				WHERE c.status = %s
				AND p.sync_source = 'real'
				AND p.wpm_project_id >= %d",
				$status,
				MAC_TRACKER_MIN_WPM_PROJECT_ID
			)
		);
	}

	/**
	 * Approve a pending palette once. Approved rows are not changed by sync jobs.
	 *
	 * @param int $record_id Color record ID.
	 * @param int $user_id Approving WordPress user ID.
	 * @return bool|WP_Error
	 */
	public function approve_palette( $record_id, $user_id ) {
		$record = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->colors_table} WHERE id = %d LIMIT 1",
				(int) $record_id
			),
			ARRAY_A
		);

		if ( ! $record ) {
			return new WP_Error( 'mac_tracker_palette_not_found', 'Palette not found.' );
		}

		if ( 'approved' === $record['status'] ) {
			return true;
		}

		$colors = json_decode( (string) $record['final_colors'], true );
		if ( ! is_array( $colors ) || count( $colors ) < 4 || count( $colors ) > 6 ) {
			return new WP_Error( 'mac_tracker_palette_not_ready', 'Palette must have 4-6 colors before approval.' );
		}

		$now    = current_time( 'mysql', true );
		$result = $this->wpdb->update(
			$this->colors_table,
			array(
				'status'      => 'approved',
				'approved_by' => (int) $user_id,
				'approved_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => (int) $record_id )
		);

		if ( false === $result ) {
			return new WP_Error( 'mac_tracker_palette_approve_failed', $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Wipe sync snapshots/palettes/logs but keep the CSV pin baseline.
	 *
	 * @return array|WP_Error Counts of deleted rows.
	 */
	public function clear_snapshots() {
		$snapshot_ids = $this->wpdb->get_col(
			"SELECT id FROM {$this->projects_table} WHERE record_kind <> 'csv_pin'"
		);
		$snapshot_ids = is_array( $snapshot_ids ) ? array_map( 'intval', $snapshot_ids ) : array();

		$colors = 0;
		if ( ! empty( $snapshot_ids ) ) {
			$id_list = implode( ',', $snapshot_ids );
			$colors  = (int) $this->wpdb->query(
				"DELETE FROM {$this->colors_table} WHERE project_id IN ({$id_list})"
			);
			if ( false === $colors ) {
				return new WP_Error( 'mac_tracker_clear_colors_failed', $this->wpdb->last_error );
			}
		} else {
			// Also drop orphan color rows.
			$colors = (int) $this->wpdb->query( "DELETE FROM {$this->colors_table}" );
		}

		$projects = (int) $this->wpdb->query(
			"DELETE FROM {$this->projects_table} WHERE record_kind <> 'csv_pin'"
		);
		if ( false === $projects ) {
			return new WP_Error( 'mac_tracker_clear_projects_failed', $this->wpdb->last_error );
		}

		$logs = (int) $this->wpdb->query( "DELETE FROM {$this->logs_table}" );
		if ( false === $logs ) {
			return new WP_Error( 'mac_tracker_clear_logs_failed', $this->wpdb->last_error );
		}

		delete_option( 'mac_tracker_last_sync_cursor' );
		delete_option( 'mac_tracker_last_sync_at' );
		delete_option( 'mac_tracker_sync_lock' );
		delete_option( 'mac_tracker_last_wpm_probe' );
		delete_option( 'mac_tracker_last_sheet_compare' );
		delete_transient( 'mac_tracker_sync_lock' );

		return array(
			'projects' => max( 0, $projects ),
			'colors'   => max( 0, $colors ),
			'logs'     => max( 0, $logs ),
		);
	}

	/**
	 * @deprecated Use clear_snapshots().
	 * @return array|WP_Error
	 */
	public function clear_local_data() {
		return $this->clear_snapshots();
	}

	/**
	 * Remove pin registry and csv_pin baseline rows. Sync snapshots stay.
	 *
	 * @return array|WP_Error
	 */
	public function reset_pin_list() {
		$pin_ids = $this->wpdb->get_col(
			"SELECT id FROM {$this->projects_table} WHERE record_kind = 'csv_pin'"
		);
		$pin_ids = is_array( $pin_ids ) ? array_map( 'intval', $pin_ids ) : array();

		$colors = 0;
		if ( ! empty( $pin_ids ) ) {
			$id_list = implode( ',', $pin_ids );
			$colors  = (int) $this->wpdb->query(
				"DELETE FROM {$this->colors_table} WHERE project_id IN ({$id_list})"
			);
			if ( false === $colors ) {
				return new WP_Error( 'mac_tracker_reset_pin_colors_failed', $this->wpdb->last_error );
			}
		}

		$baselines = (int) $this->wpdb->query(
			"DELETE FROM {$this->projects_table} WHERE record_kind = 'csv_pin'"
		);
		if ( false === $baselines ) {
			return new WP_Error( 'mac_tracker_reset_pin_baselines_failed', $this->wpdb->last_error );
		}

		$pins = (int) $this->wpdb->query( "DELETE FROM {$this->pins_table}" );
		if ( false === $pins ) {
			return new WP_Error( 'mac_tracker_reset_pins_failed', $this->wpdb->last_error );
		}

		return array(
			'pins'      => max( 0, $pins ),
			'baselines' => max( 0, $baselines ),
			'colors'    => max( 0, $colors ),
		);
	}

	/**
	 * @param string $status Log status.
	 * @param int    $items_processed Number of processed projects.
	 * @param string $message Log message.
	 * @param string $source Log source.
	 */
	public function add_sync_log( $status, $items_processed, $message, $source = 'wpm' ) {
		$message = (string) $message;
		if ( strlen( $message ) > 4000 ) {
			$message = substr( $message, 0, 3997 ) . '...';
		}

		$this->wpdb->insert(
			$this->logs_table,
			array(
				'source'          => (string) $source,
				'status'          => (string) $status,
				'items_processed' => max( 0, (int) $items_processed ),
				'message'         => $message,
				'created_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Permanently remove a WPM project from local tables and block future sync inserts.
	 *
	 * @param int $wpm_project_id WPM project ID.
	 * @return array|WP_Error
	 */
	public function purge_project_permanently( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		if ( $wpm_project_id <= 0 ) {
			return new WP_Error( 'mac_tracker_purge_id', 'Project ID is required.' );
		}

		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->projects_table} WHERE wpm_project_id = %d",
				$wpm_project_id
			)
		);
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

		$colors = 0;
		if ( ! empty( $ids ) ) {
			$id_list = implode( ',', $ids );
			$colors  = (int) $this->wpdb->query(
				"DELETE FROM {$this->colors_table} WHERE project_id IN ({$id_list})"
			);
			if ( false === $colors ) {
				return new WP_Error( 'mac_tracker_purge_colors_failed', $this->wpdb->last_error );
			}
		}

		$snapshots = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->projects_table} WHERE wpm_project_id = %d",
				$wpm_project_id
			)
		);
		if ( false === $snapshots ) {
			return new WP_Error( 'mac_tracker_purge_snapshots_failed', $this->wpdb->last_error );
		}

		$pins = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->pins_table} WHERE wpm_project_id = %d",
				$wpm_project_id
			)
		);
		if ( false === $pins ) {
			return new WP_Error( 'mac_tracker_purge_pins_failed', $this->wpdb->last_error );
		}

		$blocked = $this->block_purged_project_id( $wpm_project_id );

		return array(
			'snapshots' => max( 0, $snapshots ),
			'colors'    => max( 0, $colors ),
			'pins'      => max( 0, $pins ),
			'blocked'   => $blocked,
		);
	}

	/**
	 * @param int $wpm_project_id WPM project ID.
	 * @return bool True when newly blocked.
	 */
	public function block_purged_project_id( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		$list           = $this->list_purged_project_ids();
		if ( in_array( $wpm_project_id, $list, true ) ) {
			return false;
		}
		$list[] = $wpm_project_id;
		sort( $list );
		update_option( 'mac_tracker_purged_project_ids', array_values( $list ), false );
		return true;
	}

	/**
	 * @return int[]
	 */
	public function list_purged_project_ids() {
		$raw = get_option( 'mac_tracker_purged_project_ids', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * @param int $wpm_project_id WPM project ID.
	 * @return bool
	 */
	public function is_purged_project( $wpm_project_id ) {
		return in_array( (int) $wpm_project_id, $this->list_purged_project_ids(), true );
	}

	/**
	 * @param int $limit Maximum number of logs.
	 * @return array
	 */
	public function list_sync_logs( $limit = 20 ) {
		$limit = max( 1, min( 100, (int) $limit ) );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->logs_table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Encode without escaping URLs or Unicode characters.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|null
	 */
	private function encode_json( $value ) {
		if ( null === $value ) {
			return null;
		}

		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? null : $encoded;
	}
}
