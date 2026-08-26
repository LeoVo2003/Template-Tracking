<?php
/**
 * Plugin Name: MAC Project Tracker
 * Description: Synchronizes pinned WPM project snapshots, prepares template-color reviews, and exposes approved palettes in a WordPress dashboard.
 * Version: 0.6.17
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: MAC Marketing
 * Update URI: https://github.com/LeoVo2003/Template-Tracking
 * Text Domain: mac-project-tracker
 */

defined( 'ABSPATH' ) || exit;

define( 'MAC_TRACKER_VERSION', '0.6.17' );
define( 'MAC_TRACKER_MIN_WPM_PROJECT_ID', 3006 );
define( 'MAC_TRACKER_ACTION_TASK_ERA_ID', 3707 );
/** Action Design done rows are created only on/after this date (YYYY-MM-DD, date part of completed_at). */
define( 'MAC_TRACKER_AD_COMPLETED_CUTOFF', '2026-07-01' );
define( 'MAC_TRACKER_FILE', __FILE__ );
define( 'MAC_TRACKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAC_TRACKER_URL', plugin_dir_url( __FILE__ ) );

require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-activator.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-crypto.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-time.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-normalize.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-repository.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-pin-import.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-wpm-client.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-color-service.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-gemini-client.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-sync-service.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-github-updater.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-admin.php';

register_activation_hook( __FILE__, array( 'MAC_Tracker_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MAC_Tracker_Activator', 'deactivate' ) );

/**
 * Start the plugin after WordPress and other plugins have loaded.
 */
function mac_tracker_boot() {
	$github_updater = new MAC_Tracker_GitHub_Updater();
	$github_updater->register();

	if ( (string) get_option( 'mac_tracker_db_version', '' ) !== MAC_TRACKER_VERSION ) {
		MAC_Tracker_Activator::activate();
	}

	$repository    = new MAC_Tracker_Repository();
	$color_service = new MAC_Tracker_Color_Service( $repository );
	$wpm_client    = new MAC_Tracker_WPM_Client();
	$sync_service  = new MAC_Tracker_Sync_Service( $repository, $wpm_client, $color_service );

	add_action( MAC_Tracker_Sync_Service::CRON_HOOK, array( $sync_service, 'run_cron' ) );
	add_action( MAC_Tracker_Sync_Service::CRON_MANUAL, array( $sync_service, 'run_manual' ) );

	if ( is_admin() ) {
		$admin = new MAC_Tracker_Admin( $repository, $sync_service );
		$admin->register();
	}
}
add_action( 'plugins_loaded', 'mac_tracker_boot' );
