<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Activator {

	/**
	 * Create plugin tables and schedule the fallback WordPress cron event.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$projects        = $wpdb->prefix . 'mac_tracker_projects';
		$colors          = $wpdb->prefix . 'mac_tracker_color_records';
		$logs            = $wpdb->prefix . 'mac_tracker_sync_logs';
		$pins            = $wpdb->prefix . 'mac_tracker_pinned_projects';
		$installed       = (string) get_option( 'mac_tracker_db_version', '' );
		$projects_exist  = $projects === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $projects ) )
		);
		$has_snapshot_schema = false;

		if ( $projects_exist ) {
			$has_snapshot_schema = 'record_kind' === $wpdb->get_var(
				"SHOW COLUMNS FROM {$projects} LIKE 'record_kind'"
			);
		}

		// Version 0.2 introduces multiple immutable snapshot rows per WPM project.
		// Existing 0.1.x rows were display-only test data and cannot be mapped
		// safely to a task snapshot, so reset these two data tables once.
		if (
			( '' !== $installed && version_compare( $installed, '0.2.0', '<' ) )
			|| ( $projects_exist && ! $has_snapshot_schema )
		) {
			$wpdb->query( "DROP TABLE IF EXISTS {$colors}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$projects}" );
			delete_option( 'mac_tracker_last_sync_cursor' );
			delete_option( 'mac_tracker_last_sync_at' );
			delete_option( 'mac_tracker_sync_lock' );
			update_option( 'mac_tracker_snapshot_reset_at', current_time( 'mysql', true ), false );
		}

		$sql_projects = "CREATE TABLE {$projects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wpm_project_id bigint(20) unsigned NOT NULL,
			record_kind varchar(32) NOT NULL DEFAULT 'domain',
			wpm_action_task_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(255) NOT NULL DEFAULT '',
			zip_code varchar(32) NOT NULL DEFAULT '',
			package_json longtext NULL,
			account_manager_json longtext NULL,
			assignee_json longtext NULL,
			status varchar(100) NOT NULL DEFAULT '',
			notes longtext NULL,
			domain varchar(255) NOT NULL DEFAULT '',
			layout_url text NULL,
			website_url text NULL,
			has_website_action_task tinyint(1) unsigned NOT NULL DEFAULT 0,
			sync_source varchar(20) NOT NULL DEFAULT 'unknown',
			template_color_raw longtext NULL,
			content_url text NULL,
			menu_url text NULL,
			wpm_updated_at datetime NULL,
			task_completed_at datetime NULL,
			pin_position int(10) unsigned NOT NULL DEFAULT 0,
			is_archived tinyint(1) unsigned NOT NULL DEFAULT 0,
			raw_payload longtext NULL,
			synced_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY project_snapshot (wpm_project_id, record_kind, wpm_action_task_id),
			KEY wpm_project_id (wpm_project_id),
			KEY wpm_action_task_id (wpm_action_task_id),
			KEY project_status (status),
			KEY wpm_updated_at (wpm_updated_at),
			KEY task_completed_at (task_completed_at),
			KEY pin_position (pin_position),
			KEY has_website_action_task (has_website_action_task),
			KEY sync_source (sync_source),
			KEY is_archived (is_archived)
		) {$charset_collate};";

		$sql_colors = "CREATE TABLE {$colors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			source_type varchar(50) NOT NULL DEFAULT 'unknown',
			source_url longtext NULL,
			stored_attachment_id bigint(20) unsigned NULL,
			stored_image_path text NULL,
			image_sha256 char(64) NULL,
			tool_colors longtext NULL,
			gemini_colors longtext NULL,
			final_colors longtext NULL,
			color_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			status varchar(30) NOT NULL DEFAULT 'waiting',
			confidence decimal(5,4) NULL,
			approved_by bigint(20) unsigned NULL,
			approved_at datetime NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY project_id (project_id),
			KEY color_status (status)
		) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(50) NOT NULL DEFAULT 'wpm',
			status varchar(30) NOT NULL,
			items_processed int(10) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY log_source_status (source, status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_pins = "CREATE TABLE {$pins} (
			wpm_project_id bigint(20) unsigned NOT NULL,
			website_url text NULL,
			layout_url text NULL,
			assignee_name varchar(255) NOT NULL DEFAULT '',
			projects_raw text NULL,
			pin_position int(10) unsigned NOT NULL DEFAULT 0,
			task_completed_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (wpm_project_id),
			KEY pin_position (pin_position),
			KEY task_completed_at (task_completed_at)
		) {$charset_collate};";

		dbDelta( $sql_projects );
		dbDelta( $sql_colors );
		dbDelta( $sql_logs );
		dbDelta( $sql_pins );

		self::maybe_add_column( $projects, 'task_completed_at', 'datetime NULL' );
		self::maybe_add_column( $projects, 'pin_position', 'int(10) unsigned NOT NULL DEFAULT 0' );
		self::maybe_add_column( $pins, 'pin_position', 'int(10) unsigned NOT NULL DEFAULT 0' );
		self::maybe_add_column( $pins, 'task_completed_at', 'datetime NULL' );

		self::repair_project_snapshot_indexes( $projects );

		$settings = get_option( 'mac_tracker_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$settings = wp_parse_args(
			$settings,
			array(
				'wpm_api_url'   => '',
				'wpm_auth_type' => 'api_key',
				'gemini_model'  => 'gemini-3-flash-preview',
			)
		);
		$settings['wpm_api_key_header'] = 'Tracking-Template-Header';
		unset( $settings['wpm_mode'] );
		if ( ! in_array( (string) ( $settings['wpm_auth_type'] ?? '' ), array( 'none', 'bearer', 'api_key' ), true ) ) {
			$settings['wpm_auth_type'] = 'api_key';
		}
		update_option( 'mac_tracker_settings', $settings, false );

		// Legacy incremental cursor used invalid ISO forms and caused HTTP 422.
		delete_option( 'mac_tracker_last_sync_cursor' );

		self::ensure_dev_project_purged( 3018 );

		update_option( 'mac_tracker_db_version', MAC_TRACKER_VERSION, false );

		if ( ! wp_next_scheduled( 'mac_tracker_sync_projects' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mac_tracker_sync_projects' );
		}
	}

	/**
	 * Remove leftover local data for a known test WPM project and block re-sync.
	 *
	 * @param int $wpm_project_id WPM project ID.
	 */
	private static function ensure_dev_project_purged( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		if ( $wpm_project_id <= 0 || ! class_exists( 'MAC_Tracker_Repository' ) ) {
			return;
		}

		$repository = new MAC_Tracker_Repository();
		$repository->purge_project_permanently( $wpm_project_id );
	}

	/**
	 * Remove only the scheduled event. Project data is intentionally retained.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mac_tracker_sync_projects' );
		wp_clear_scheduled_hook( 'mac_tracker_sync_manual' );
		delete_option( 'mac_tracker_sync_lock' );
		delete_transient( 'mac_tracker_sync_lock' );
	}

	/**
	 * Add a missing column without failing when it already exists.
	 *
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @param string $definition Column definition SQL.
	 */
	private static function maybe_add_column( $table, $column, $definition ) {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				$column
			)
		);
		if ( $exists ) {
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
	}

	/**
	 * dbDelta often leaves a legacy UNIQUE(wpm_project_id) behind. That blocks
	 * multiple Action Design snapshots for the same WPM project.
	 *
	 * @param string $projects_table Full projects table name.
	 */
	private static function repair_project_snapshot_indexes( $projects_table ) {
		global $wpdb;

		$exists = $projects_table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $projects_table ) )
		);
		if ( ! $exists ) {
			return;
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$projects_table}", ARRAY_A );
		if ( ! is_array( $indexes ) ) {
			return;
		}

		$unique_maps = array();
		foreach ( $indexes as $index ) {
			if ( empty( $index['Non_unique'] ) ) {
				$name = (string) $index['Key_name'];
				if ( 'PRIMARY' === $name ) {
					continue;
				}
				if ( ! isset( $unique_maps[ $name ] ) ) {
					$unique_maps[ $name ] = array();
				}
				$unique_maps[ $name ][] = (string) $index['Column_name'];
			}
		}

		$has_composite = false;
		foreach ( $unique_maps as $columns ) {
			if ( array( 'wpm_project_id', 'record_kind', 'wpm_action_task_id' ) === $columns ) {
				$has_composite = true;
				break;
			}
		}

		foreach ( $unique_maps as $name => $columns ) {
			// Drop the old one-row-per-project unique key.
			if ( array( 'wpm_project_id' ) === $columns ) {
				$wpdb->query( "ALTER TABLE {$projects_table} DROP INDEX `{$name}`" );
			}
		}

		if ( ! $has_composite ) {
			// Ignore failure if the index already exists under another name.
			$wpdb->query(
				"ALTER TABLE {$projects_table}
				ADD UNIQUE KEY project_snapshot (wpm_project_id, record_kind, wpm_action_task_id)"
			);
		}
	}
}
