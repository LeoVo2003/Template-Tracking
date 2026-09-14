<?php
/**
 * Plugin Name: MAC Project Tracker
 * Description: Internal WPM project tracker.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: MAC Marketing
 * Update URI: https://github.com/LeoVo2003/Template-Tracking
 * Text Domain: mac-project-tracker
 */

defined( 'ABSPATH' ) || exit;

define( 'MAC_TRACKER_VERSION', '0.2.0' );
define( 'MAC_TRACKER_MIN_WPM_PROJECT_ID', 3006 );
define( 'MAC_TRACKER_ACTION_TASK_ERA_ID', 3707 );
define( 'MAC_TRACKER_AD_COMPLETED_CUTOFF', '2026-07-01' );
define( 'MAC_TRACKER_FILE', __FILE__ );
define( 'MAC_TRACKER_DIR', plugin_dir_path( __FILE__ ) );

require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-normalizer.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-time.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-activator.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-repository.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-pin-import.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-wpm-client.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-sync-service.php';

register_activation_hook( MAC_TRACKER_FILE, array( 'MAC_Tracker_Activator', 'activate' ) );

function mac_tracker_run_full_sync( $endpoint, $secret = '', array $filters = array() ) {
	$repo = new MAC_Tracker_Repository();
	$client = new MAC_Tracker_WPM_Client( $endpoint, $secret );
	return ( new MAC_Tracker_Sync_Service( $repo, $client ) )->full_sync( $filters );
}

function mac_tracker_import_pin_csv( $path ) {
	return ( new MAC_Tracker_Pin_Import( new MAC_Tracker_Repository() ) )->import_file( $path );
}
