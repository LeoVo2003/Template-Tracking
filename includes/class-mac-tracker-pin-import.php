<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Pin_Import {

	private $repository;

	public function __construct( MAC_Tracker_Repository $repository ) {
		$this->repository = $repository;
	}

	/** Import a WordPress-uploaded or trusted local CSV file. */
	public function import_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'mac_tracker_csv_unreadable', 'Pin CSV is not readable.' );
		}
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'mac_tracker_csv_open', 'Unable to open pin CSV.' );
		}

		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) ) {
			fclose( $handle );
			return new WP_Error( 'mac_tracker_csv_empty', 'Pin CSV is empty.' );
		}
		if ( isset( $headers[0] ) ) {
			$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );
		}
		$headers = array_map( array( $this, 'normalize_header' ), $headers );

		$imported = 0;
		$rows_read = 0;
		$duplicate_ids = array();
		$seen_pin_ids = array();
		$registered = 0;
		$action_imported = 0;
		$roster_ids = array();
		$latest_pin_time = '';
		$errors   = array();
		$row_no   = 1;
		while ( false !== ( $values = fgetcsv( $handle ) ) ) {
			++$row_no;
			++$rows_read;
			$row = array();
			foreach ( $headers as $index => $header ) {
				if ( '' !== $header ) {
					$row[ $header ] = isset( $values[ $index ] ) ? trim( (string) $values[ $index ] ) : '';
				}
			}
			$project_id = absint( $row['project_id'] ?? $row['wpm_project_id'] ?? 0 );
			if ( $project_id <= 0 ) {
				$errors[] = sprintf( 'Row %d: missing project_id.', $row_no );
				continue;
			}
			$roster_ids[] = $project_id;
			if ( isset( $seen_pin_ids[ $project_id ] ) ) {
				$duplicate_ids[ $project_id ] = true;
				continue;
			}
			$seen_pin_ids[ $project_id ] = true;
			$record_kind = sanitize_key( $row['record_kind'] ?? 'csv_pin' );
			if ( 'action_design' === $record_kind ) {
				$task_id = absint( $row['action_task_id'] ?? $row['wpm_action_task_id'] ?? 0 );
				if ( $task_id <= 0 ) {
					$errors[] = sprintf( 'Row %d: Action Design row is missing action_task_id.', $row_no );
					continue;
				}
				$layout = $row['layout_web'] ?? $row['layout_url'] ?? $row['layout'] ?? '';
				$done   = MAC_Tracker_Time::csv_bangkok_to_utc( $row['date'] ?? $row['due_date'] ?? '', $row['time'] ?? '' );
				if ( $done && ( '' === $latest_pin_time || $done > $latest_pin_time ) ) { $latest_pin_time = $done; }
				$snapshot = $this->repository->upsert_snapshot(
					array(
						'wpm_project_id'     => $project_id,
						'wpm_action_task_id' => $task_id,
						'record_kind'        => 'action_design',
						'name'               => $row['projects'] ?? '',
						'website_url'        => $row['website'] ?? '',
						'layout_url'         => $layout,
						'assignee'           => array( 'name' => $row['member'] ?? $row['assignee'] ?? '' ),
						'task_completed_at'  => $done,
						'sync_source'        => 'wpm_action_import',
						'raw'                => $row,
					)
				);
				if ( is_wp_error( $snapshot ) ) {
					$errors[] = sprintf( 'Row %d: Action Design snapshot could not be saved.', $row_no );
					continue;
				}
				++$action_imported;
				++$registered;
				continue;
			}

			$layout = $row['layout_web'] ?? $row['layout_url'] ?? $row['layout'] ?? '';
			$done   = MAC_Tracker_Time::csv_bangkok_to_utc( $row['date'] ?? $row['done_date'] ?? '', $row['time'] ?? '' );
			if ( $done && ( '' === $latest_pin_time || $done > $latest_pin_time ) ) { $latest_pin_time = $done; }
			$pin    = $this->repository->upsert_pin(
				array(
					'wpm_project_id'  => $project_id,
					'website_url'     => $row['website'] ?? '',
					'layout_url'      => $layout,
					'assignee_name'   => $row['member'] ?? $row['assignee'] ?? '',
					'projects_raw'    => $row['projects'] ?? '',
					'task_completed_at' => $done,
					'pin_position'    => $row_no - 1,
				)
			);
			$snapshot = $this->repository->upsert_snapshot(
				array(
					'wpm_project_id'  => $project_id,
					'record_kind'     => 'csv_pin',
					'name'            => $row['projects'] ?? '',
					'website_url'     => $row['website'] ?? '',
					'layout_url'      => $layout,
					'assignee'        => array( 'name' => $row['member'] ?? $row['assignee'] ?? '' ),
					'task_completed_at' => $done,
					'pin_position'    => $row_no - 1,
					'sync_source'     => 'csv_pin',
					'raw'             => $row,
				)
			);
			if ( is_wp_error( $pin ) || is_wp_error( $snapshot ) ) {
				$errors[] = sprintf( 'Row %d: database write failed.', $row_no );
				continue;
			}
			++$imported;
		}

		fclose( $handle );
		$this->repository->set_roster_ids( $roster_ids );
		$this->repository->set_roster_cutoff( $latest_pin_time );
		update_option( 'mac_tracker_roster_source_rows', $rows_read, false );
		return array( 'imported' => $imported, 'action_imported' => $action_imported, 'registered' => $registered, 'roster' => count( array_unique( $roster_ids ) ), 'rows_read' => $rows_read, 'duplicates' => count( $duplicate_ids ), 'errors' => $errors );
	}

	private function normalize_header( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		return trim( $value, '_' );
	}
}
