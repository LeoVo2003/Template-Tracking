<?php

defined( 'ABSPATH' ) || exit;

/**
 * Import the curated CSV pin baseline (project_id + Website + Layout + Assignee + Date/Time).
 */
class MAC_Tracker_Pin_Import {

	/** @var MAC_Tracker_Repository */
	private $repository;

	public function __construct( MAC_Tracker_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @param string $csv_raw Raw CSV contents.
	 * @return array|WP_Error Summary counts.
	 */
	public function import_csv( $csv_raw ) {
		$rows = $this->parse_csv( (string) $csv_raw );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$imported = 0;
		$updated  = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$result = $this->repository->upsert_pin_baseline( $row );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['created'] ) ) {
				++$imported;
			} elseif ( ! empty( $result['updated'] ) ) {
				++$updated;
			} else {
				++$skipped;
			}
		}

		return array(
			'rows'     => count( $rows ),
			'imported' => $imported,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'message'  => sprintf(
				'Pin import: %d rows (%d new, %d updated).',
				count( $rows ),
				$imported,
				$updated
			),
		);
	}

	/**
	 * @param string $csv_raw Raw CSV.
	 * @return array|WP_Error
	 */
	public function parse_csv( $csv_raw ) {
		$csv_raw = (string) $csv_raw;
		if ( '' === trim( $csv_raw ) ) {
			return new WP_Error( 'mac_tracker_pin_empty', 'CSV file is empty.' );
		}
		if ( 0 === strpos( $csv_raw, "\xEF\xBB\xBF" ) ) {
			$csv_raw = substr( $csv_raw, 3 );
		}

		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			return new WP_Error( 'mac_tracker_pin_io', 'Unable to read CSV.' );
		}
		fwrite( $handle, $csv_raw );
		rewind( $handle );

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) || empty( $header ) ) {
			fclose( $handle );
			return new WP_Error( 'mac_tracker_pin_header', 'CSV is missing a header row.' );
		}

		$map = $this->map_headers( $header );
		if ( ! isset( $map['project_id'] ) ) {
			fclose( $handle );
			return new WP_Error( 'mac_tracker_pin_columns', 'CSV needs a project_id column.' );
		}

		$rows     = array();
		$seen     = array();
		$index    = 0;
		$position = 0;
		while ( ( $cols = fgetcsv( $handle ) ) !== false ) {
			++$index;
			if ( $this->row_is_empty( $cols ) ) {
				continue;
			}

			$project_id = absint( $this->cell( $cols, $map['project_id'] ) );
			if ( $project_id <= 0 ) {
				continue;
			}

			++$position;

			// Last row wins when the sheet has redesign duplicates for one project.
			// pin_position follows CSV order (1 = top of file, N = bottom → list DESC).
			$seen[ $project_id ] = array(
				'wpm_project_id' => $project_id,
				'website_url'    => $this->cell( $cols, $map['website'] ?? null ),
				'layout_url'     => $this->cell( $cols, $map['layout'] ?? null ),
				'assignee_name'  => $this->cell( $cols, $map['member'] ?? null ),
				'projects_raw'   => $this->cell( $cols, $map['projects'] ?? null ),
				'completed_date' => $this->cell( $cols, $map['date'] ?? null ),
				'completed_time' => $this->cell( $cols, $map['time'] ?? null ),
				'pin_position'   => $position,
				'sheet_row'      => $index + 1,
			);
		}
		fclose( $handle );

		$rows = array_values( $seen );
		if ( empty( $rows ) ) {
			return new WP_Error( 'mac_tracker_pin_no_rows', 'CSV has no rows with project_id.' );
		}

		return $rows;
	}

	/**
	 * @param array $header Header cells.
	 * @return array
	 */
	private function map_headers( array $header ) {
		$map = array();
		foreach ( $header as $i => $label ) {
			$key = preg_replace( '/[^a-z0-9]+/', '', strtolower( trim( (string) $label ) ) );
			if ( '' === $key ) {
				continue;
			}
			if ( in_array( $key, array( 'projectid', 'wpmprojectid', 'wpmid', 'id' ), true ) ) {
				$map['project_id'] = (int) $i;
			} elseif ( in_array( $key, array( 'website', 'web', 'domain', 'site' ), true ) ) {
				$map['website'] = (int) $i;
			} elseif ( in_array( $key, array( 'layout', 'layoutweb', 'web_layout' ), true ) ) {
				$map['layout'] = (int) $i;
			} elseif ( in_array( $key, array( 'member', 'assignee' ), true ) ) {
				$map['member'] = (int) $i;
			} elseif ( in_array( $key, array( 'projects', 'project' ), true ) ) {
				$map['projects'] = (int) $i;
			} elseif ( in_array( $key, array( 'date', 'completeddate', 'donedate', 'completed_at_date' ), true ) ) {
				$map['date'] = (int) $i;
			} elseif ( in_array( $key, array( 'time', 'completedtime', 'donetime', 'completed_at_time' ), true ) ) {
				$map['time'] = (int) $i;
			}
		}

		// Fallback: Website, Projects, (empty), Member, project_id, Date, Time.
		if ( ! isset( $map['project_id'] ) && count( $header ) >= 5 ) {
			$map['project_id'] = 4;
		}
		if ( ! isset( $map['website'] ) && isset( $header[0] ) ) {
			$map['website'] = 0;
		}
		if ( ! isset( $map['projects'] ) && isset( $header[1] ) ) {
			$map['projects'] = 1;
		}
		if ( ! isset( $map['member'] ) && isset( $header[3] ) ) {
			$map['member'] = 3;
		}
		if ( ! isset( $map['date'] ) && isset( $header[5] ) ) {
			$map['date'] = 5;
		}
		if ( ! isset( $map['time'] ) && isset( $header[6] ) ) {
			$map['time'] = 6;
		}

		return $map;
	}

	/**
	 * @param array    $cols Row.
	 * @param int|null $index Column index.
	 * @return string
	 */
	private function cell( array $cols, $index ) {
		if ( null === $index || ! isset( $cols[ $index ] ) ) {
			return '';
		}
		return trim( (string) $cols[ $index ] );
	}

	/**
	 * @param array $cols Row.
	 * @return bool
	 */
	private function row_is_empty( array $cols ) {
		foreach ( $cols as $col ) {
			if ( '' !== trim( (string) $col ) ) {
				return false;
			}
		}
		return true;
	}
}
