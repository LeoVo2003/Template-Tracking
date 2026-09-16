<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Activator {

	public static function activate() {
		self::migrate();
	}

	public static function maybe_upgrade() {
		if ( MAC_TRACKER_VERSION !== get_option( 'mac_tracker_db_version', '' ) ) {
			self::migrate();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( MAC_Tracker_Sync_Service::CRON_HOOK );
		wp_clear_scheduled_hook( MAC_Tracker_Sync_Service::MANUAL_CRON_HOOK );
		wp_clear_scheduled_hook( MAC_Tracker_Elementor_Color_Service::CRON_HOOK );
		delete_option( MAC_Tracker_Sync_Service::LOCK_OPTION );
	}

	private static function migrate() {
		global $wpdb;
		$previous_version = (string) get_option( 'mac_tracker_db_version', '' );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$projects = $wpdb->prefix . 'mac_tracker_projects';
		$colors   = $wpdb->prefix . 'mac_tracker_color_records';
		$visuals  = $wpdb->prefix . 'mac_tracker_visual_reviews';
		$pins     = $wpdb->prefix . 'mac_tracker_pinned_projects';
		$logs     = $wpdb->prefix . 'mac_tracker_sync_logs';

		$queries = array(
			"CREATE TABLE {$projects} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wpm_project_id bigint(20) unsigned NOT NULL,
				record_kind varchar(32) NOT NULL,
				wpm_action_task_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(255) NOT NULL DEFAULT '',
				zip_code varchar(32) NOT NULL DEFAULT '',
				package_json longtext NULL,
				account_manager_json longtext NULL,
				assignee_json longtext NULL,
				domain varchar(2048) NOT NULL DEFAULT '',
				layout_url varchar(2048) NOT NULL DEFAULT '',
				website_url varchar(2048) NOT NULL DEFAULT '',
				status varchar(64) NOT NULL DEFAULT '',
				template_color_raw longtext NULL,
				raw_payload longtext NULL,
				sync_source varchar(32) NOT NULL DEFAULT 'real',
				task_completed_at datetime NULL,
				pin_position int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY snapshot_key (wpm_project_id,record_kind,wpm_action_task_id),
				KEY project_id (wpm_project_id),
				KEY record_kind (record_kind),
				KEY pin_position (pin_position)
			) {$charset};",
			"CREATE TABLE {$colors} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				source_type varchar(32) NOT NULL DEFAULT '',
				source_raw longtext NULL,
				status varchar(32) NOT NULL DEFAULT 'waiting',
				colors_json longtext NULL,
				approved_at datetime NULL,
				locked tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY project_id (project_id)
			) {$charset};",
			"CREATE TABLE {$visuals} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				capture_status varchar(32) NOT NULL DEFAULT 'pending',
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				screenshot_url varchar(2048) NOT NULL DEFAULT '',
				tone varchar(64) NOT NULL DEFAULT '',
				confidence varchar(16) NOT NULL DEFAULT '',
				tone_reason text NULL,
				tone_status varchar(32) NOT NULL DEFAULT 'pending',
				ai_raw longtext NULL,
				captured_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY project_id (project_id),
				KEY capture_status (capture_status),
				KEY tone_status (tone_status)
			) {$charset};",
			"CREATE TABLE {$pins} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wpm_project_id bigint(20) unsigned NOT NULL,
				website_url varchar(2048) NOT NULL DEFAULT '',
				layout_url varchar(2048) NOT NULL DEFAULT '',
				assignee_name varchar(255) NOT NULL DEFAULT '',
				projects_raw varchar(255) NOT NULL DEFAULT '',
				task_completed_at datetime NULL,
				pin_position int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY wpm_project_id (wpm_project_id),
				KEY pin_position (pin_position)
			) {$charset};",
			"CREATE TABLE {$logs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(32) NOT NULL,
				processed int(11) NOT NULL DEFAULT 0,
				message text NULL,
				started_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};",
		);

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}
		// Early releases used an ENUM here. dbDelta does not reliably widen an
		// existing ENUM, which silently rejects the later action_design value.
		$wpdb->query( "ALTER TABLE {$projects} MODIFY record_kind varchar(32) NOT NULL" );
		// The pre-snapshot table also enforced one row per WPM project. The
		// tracker now deliberately keeps a CSV baseline plus Action Design task
		// snapshots, so remove only that obsolete unique index when present.
		$legacy_unique = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$projects} WHERE Key_name = %s", 'wpm_project_id' ) );
		if ( $legacy_unique ) {
			$wpdb->query( "ALTER TABLE {$projects} DROP INDEX wpm_project_id" );
		}
		if ( version_compare( $previous_version, '0.8.9', '<' ) ) {
			( new MAC_Tracker_Repository() )->repair_csv_pin_datetimes();
		}
		if ( version_compare( $previous_version, '0.9.2', '<' ) ) {
			( new MAC_Tracker_Repository() )->collapse_action_snapshots();
		}
		// Domain was a retired pre-0.7.2 record kind, never a valid snapshot.
		// This one-time migration preserves CSV pins and Action Design rows.
		if ( version_compare( $previous_version, '0.7.6', '<' ) ) {
			( new MAC_Tracker_Repository() )->purge_domain_snapshots();
		}
		if ( version_compare( $previous_version, '0.10.5', '<' ) ) {
			( new MAC_Tracker_Repository() )->purge_excluded_wpm_actions();
		}
		if ( version_compare( $previous_version, '0.11.2', '<' ) ) {
			( new MAC_Tracker_Repository() )->purge_unapproved_color_records();
		}
		if ( version_compare( $previous_version, '0.12.7', '<' ) ) {
			( new MAC_Tracker_Repository() )->requeue_visual_tones();
		}
		if ( version_compare( $previous_version, '0.13.5', '<' ) ) {
			( new MAC_Tracker_Repository() )->requeue_visual_tones_by_labels( array( 'Hồng trắng', 'Đỏ hồng' ) );
		}
		if ( version_compare( $previous_version, '0.13.6', '<' ) ) {
			( new MAC_Tracker_Repository() )->requeue_visual_tones_by_labels( array( 'Hồng trắng', 'Hồng xanh trắng', 'Đỏ trắng', 'Đỏ hồng' ) );
		}
		update_option( 'mac_tracker_db_version', MAC_TRACKER_VERSION, false );
	}
}
