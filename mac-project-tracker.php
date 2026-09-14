<?php
/**
 * Plugin Name: MAC Project Tracker
 * Description: Internal WPM project tracker.
 * Version: 0.7.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: MAC Marketing
 * Update URI: https://github.com/LeoVo2003/Template-Tracking
 * Text Domain: mac-project-tracker
 */

defined( 'ABSPATH' ) || exit;

define( 'MAC_TRACKER_VERSION', '0.7.1' );
define( 'MAC_TRACKER_MIN_WPM_PROJECT_ID', 3006 );
define( 'MAC_TRACKER_ACTION_TASK_ERA_ID', 3707 );
define( 'MAC_TRACKER_AD_COMPLETED_CUTOFF', '2026-07-01' );
define( 'MAC_TRACKER_FILE', __FILE__ );
define( 'MAC_TRACKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAC_TRACKER_URL', plugin_dir_url( __FILE__ ) );

require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-normalizer.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-time.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-crypto.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-activator.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-repository.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-pin-import.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-wpm-client.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-sync-service.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-admin.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-github-updater.php';

register_activation_hook( MAC_TRACKER_FILE, array( 'MAC_Tracker_Activator', 'activate' ) );
register_deactivation_hook( MAC_TRACKER_FILE, array( 'MAC_Tracker_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', 'mac_tracker_boot' );

function mac_tracker_boot() {
	MAC_Tracker_Activator::maybe_upgrade();
	$repository = new MAC_Tracker_Repository();
	$sync       = new MAC_Tracker_Sync_Service( $repository );
	$sync->register();
	( new MAC_Tracker_GitHub_Updater() )->register();
	if ( is_admin() ) {
		( new MAC_Tracker_Admin( $repository, $sync ) )->register();
	}
}

function mac_tracker_import_pin_csv( $path ) {
	return ( new MAC_Tracker_Pin_Import( new MAC_Tracker_Repository() ) )->import_file( $path );
}
