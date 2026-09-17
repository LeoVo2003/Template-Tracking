<?php
/**
 * Plugin Name: MAC Project Tracker
 * Description: Internal WPM project tracker.
 * Version: 0.19.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: MAC Marketing
 * Update URI: https://github.com/LeoVo2003/Template-Tracking
 * Text Domain: mac-project-tracker
 */

defined( 'ABSPATH' ) || exit;

define( 'MAC_TRACKER_VERSION', '0.19.0' );
define( 'MAC_TRACKER_AD_COMPLETED_CUTOFF', '2026-07-01' );
// Projects outside the approved CSV roster join only from this WPM era onward.
define( 'MAC_TRACKER_PROJECT_SYNC_START', '2026-04-01 00:00:00' );
define( 'MAC_TRACKER_FILE', __FILE__ );
define( 'MAC_TRACKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAC_TRACKER_URL', plugin_dir_url( __FILE__ ) );

require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-normalizer.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-time.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-crypto.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-activator.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-repository.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-elementor-color-service.php';
require_once MAC_TRACKER_DIR . 'includes/class-mac-tracker-visual-service.php';
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
	$colors     = new MAC_Tracker_Elementor_Color_Service( $repository );
	$visuals    = new MAC_Tracker_Visual_Service( $repository );
	$sync->register();
	$colors->register();
	$visuals->register();
	( new MAC_Tracker_GitHub_Updater() )->register();
	if ( is_admin() ) {
		( new MAC_Tracker_Admin( $repository, $sync, $colors ) )->register();
	}
}

function mac_tracker_import_pin_csv( $path ) {
	return ( new MAC_Tracker_Pin_Import( new MAC_Tracker_Repository() ) )->import_file( $path );
}
