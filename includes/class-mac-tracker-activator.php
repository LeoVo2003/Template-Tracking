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
		// V2 starts safely: no scheduled visual processing until a later phase
		// explicitly enables and validates Auto mode.
		if ( false === get_option( 'mac_tracker_visual_mode', false ) ) {
			add_option( 'mac_tracker_visual_mode', 'manual', '', false );
		}
		if ( false === get_option( 'mac_tracker_visual_classifier_mode', false ) ) {
			add_option( 'mac_tracker_visual_classifier_mode', 'direct_vision', '', false );
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$projects = $wpdb->prefix . 'mac_tracker_projects';
		$colors   = $wpdb->prefix . 'mac_tracker_color_records';
		$visuals  = $wpdb->prefix . 'mac_tracker_visual_reviews';
		$visual_runs = $wpdb->prefix . 'mac_tracker_visual_runs';
		$visual_run_events = $wpdb->prefix . 'mac_tracker_visual_run_events';
		$exclusions = $wpdb->prefix . 'mac_tracker_project_exclusions';
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
				pipeline_version smallint(5) unsigned NOT NULL DEFAULT 2,
				pipeline_status varchar(32) NOT NULL DEFAULT 'idle',
				run_id varchar(64) NOT NULL DEFAULT '',
				job_token varchar(64) NOT NULL DEFAULT '',
				claimed_at datetime NULL,
				lease_until datetime NULL,
				capture_attempts int(10) unsigned NOT NULL DEFAULT 0,
				analysis_attempts int(10) unsigned NOT NULL DEFAULT 0,
				next_retry_at datetime NULL,
				final_url varchar(2048) NOT NULL DEFAULT '',
				http_status smallint(5) unsigned NOT NULL DEFAULT 0,
				page_title text NULL,
				last_error_code varchar(64) NOT NULL DEFAULT '',
				last_error_message text NULL,
				last_error_at datetime NULL,
				last_failed_stage varchar(16) NOT NULL DEFAULT '',
				capture_revision int(10) unsigned NOT NULL DEFAULT 0,
				analysis_capture_run_id varchar(64) NOT NULL DEFAULT '',
				capture_bundle_json longtext NULL,
				metrics_json longtext NULL,
				ai_provider varchar(64) NOT NULL DEFAULT '',
				ai_model varchar(128) NOT NULL DEFAULT '',
				ai_confidence decimal(4,3) NULL,
				analyzed_at datetime NULL,
				manual_locked tinyint(1) NOT NULL DEFAULT 0,
				human_locked tinyint(1) NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_at datetime NULL,
				approved_capture_revision int(10) unsigned NOT NULL DEFAULT 0,
				manual_tone varchar(64) NOT NULL DEFAULT '',
				manual_updated_at datetime NULL,
				capture_status varchar(32) NOT NULL DEFAULT 'pending',
				capture_token varchar(64) NOT NULL DEFAULT '',
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				preview_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				screenshot_url varchar(2048) NOT NULL DEFAULT '',
				diagnostic_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				diagnostic_screenshot_url varchar(2048) NOT NULL DEFAULT '',
				diagnostic_captured_at datetime NULL,
				runner_type varchar(32) NOT NULL DEFAULT '',
				tone varchar(64) NOT NULL DEFAULT '',
				tone_group varchar(64) NOT NULL DEFAULT '',
				precise_tone varchar(128) NOT NULL DEFAULT '',
				confidence varchar(16) NOT NULL DEFAULT '',
				tone_reason text NULL,
				tone_status varchar(32) NOT NULL DEFAULT 'pending',
				tone_token varchar(64) NOT NULL DEFAULT '',
				ai_raw longtext NULL,
				captured_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY project_id (project_id),
				KEY pipeline_status (pipeline_status),
				KEY lease_until (lease_until),
				KEY manual_locked (manual_locked),
				KEY human_locked (human_locked),
				KEY capture_status (capture_status),
				KEY tone_status (tone_status)
			) {$charset};",
			"CREATE TABLE {$exclusions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wpm_project_id bigint(20) unsigned NOT NULL,
				project_name varchar(255) NOT NULL DEFAULT '',
				website_url varchar(2048) NOT NULL DEFAULT '',
				scope varchar(32) NOT NULL DEFAULT 'visual_color',
				reason text NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				restored_by bigint(20) unsigned NOT NULL DEFAULT 0,
				restored_at datetime NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY wpm_project_id (wpm_project_id),
				KEY is_active (is_active)
			) {$charset};",
			"CREATE TABLE {$visual_runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				github_run_id bigint(20) unsigned NOT NULL,
				github_run_number int(10) unsigned NOT NULL DEFAULT 0,
				github_run_attempt int(10) unsigned NOT NULL DEFAULT 1,
				source_action varchar(64) NOT NULL DEFAULT 'run_batch_now',
				run_mode varchar(16) NOT NULL DEFAULT 'batch',
				stage varchar(16) NOT NULL DEFAULT 'full',
				target_count int(10) unsigned NOT NULL DEFAULT 0,
				target_ids_json longtext NULL,
				status varchar(32) NOT NULL DEFAULT 'queued',
				conclusion varchar(32) NOT NULL DEFAULT '',
				current_snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
				current_project_label varchar(255) NOT NULL DEFAULT '',
				current_step varchar(64) NOT NULL DEFAULT '',
				current_provider varchar(64) NOT NULL DEFAULT '',
				processed_count int(10) unsigned NOT NULL DEFAULT 0,
				success_count int(10) unsigned NOT NULL DEFAULT 0,
				failed_count int(10) unsigned NOT NULL DEFAULT 0,
				needs_review_count int(10) unsigned NOT NULL DEFAULT 0,
				skipped_count int(10) unsigned NOT NULL DEFAULT 0,
				capture_count int(10) unsigned NOT NULL DEFAULT 0,
				analysis_count int(10) unsigned NOT NULL DEFAULT 0,
				provider_counts_json longtext NULL,
				last_message text NULL,
				github_html_url varchar(2048) NOT NULL DEFAULT '',
				started_at datetime NULL,
				heartbeat_at datetime NULL,
				completed_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY github_run_id (github_run_id),
				KEY status (status),
				KEY updated_at (updated_at),
				KEY created_at (created_at)
			) {$charset};",
			"CREATE TABLE {$visual_run_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				github_run_id bigint(20) unsigned NOT NULL,
				snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(64) NOT NULL DEFAULT '',
				stage varchar(16) NOT NULL DEFAULT '',
				provider varchar(64) NOT NULL DEFAULT '',
				message text NULL,
				metadata_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY github_run_id (github_run_id),
				KEY created_at (created_at)
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
		if ( version_compare( $previous_version, '0.13.7', '<' ) ) {
			( new MAC_Tracker_Repository() )->repair_legacy_visual_claims();
		}
		if ( version_compare( $previous_version, '0.13.19', '<' ) ) {
			// Pair taxonomy and prompt calibration changed; keep manual reviews locked.
			( new MAC_Tracker_Repository() )->requeue_visual_tones();
		}
		if ( version_compare( $previous_version, '0.15.0', '<' ) ) {
			// Establish one canonical V2 state before a later phase changes capture transport.
			( new MAC_Tracker_Repository() )->migrate_visual_pipeline_v2();
		}
		if ( version_compare( $previous_version, '0.20.22', '<' ) ) {
			// Direct Vision is now the production authority. Legacy remains an
			// explicit operator choice, never an inherited historical default.
			if ( ! get_option( 'mac_tracker_visual_classifier_mode_explicit', false ) ) {
				update_option( 'mac_tracker_visual_classifier_mode', 'direct_vision', false );
			}
			if ( '' !== $previous_version ) {
				$requeued = ( new MAC_Tracker_Repository() )->requeue_legacy_ai_for_direct_vision();
				update_option( 'mac_tracker_visual_direct_vision_requeued_count', $requeued, false );
			}
		}
		// Split human approval from the legacy manual tone flag and preserve old locks.
		$wpdb->query( "UPDATE {$visuals} SET human_locked = 1 WHERE manual_locked = 1 AND human_locked = 0" );
		update_option( 'mac_tracker_db_version', MAC_TRACKER_VERSION, false );
	}
}
