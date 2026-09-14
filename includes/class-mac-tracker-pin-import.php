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
		$errors   = array();
		$row_no   = 1;
		while ( false !== ( $values = fgetcsv( $handle ) ) ) {
			++$row_no;
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

			$layout = $row['layout_web'] ?? $row['layout_url'] ?? $row['layout'] ?? '';
			$done   = MAC_Tracker_Time::csv_bangkok_to_utc( $row['date'] ?? $row['done_date'] ?? '', $row['time'] ?? '' );
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
		return array( 'imported' => $imported, 'errors' => $errors );
	}

	private function normalize_header( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		return trim( $value, '_' );
	}
}
