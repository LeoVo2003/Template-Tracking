<?php

// Lightweight renderer smoke: WordPress behavior is stubbed; no handlers or data are mutated.
define( 'ABSPATH', __DIR__ );
define( 'MAC_TRACKER_VERSION', 'smoke' );

function add_action() {}
function add_filter() {}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_html_class( $value ) { return sanitize_key( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function wp_unslash( $value ) { return $value; }
function wp_get_current_user() { return (object) array( 'ID' => 1, 'display_name' => 'MAC Admin' ); }
function get_avatar() { return '<img src="avatar.png" alt="">'; }
function get_option( $key, $default = false ) { $values = array( 'mac_tracker_settings' => array( 'wpm_endpoint' => 'https://wpm.example.test/api' ), 'mac_tracker_wpm_secret' => 'saved', 'mac_tracker_visual_mode' => 'manual', 'mac_tracker_visual_ai_strategy' => 'smart', 'mac_tracker_visual_classifier_mode' => 'direct_vision', 'mac_tracker_visual_max_capture_retries' => 3 ); return $values[ $key ] ?? $default; }
function number_format_i18n( $value ) { return number_format( (int) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function current_user_can() { return true; }
function wp_die( $message ) { throw new RuntimeException( (string) $message ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function selected( $left, $right, $echo = true ) { $value = (string) $left === (string) $right ? ' selected' : ''; if ( $echo ) echo $value; return $value; }
function checked( $left, $right, $echo = true ) { $value = (string) $left === (string) $right ? ' checked' : ''; if ( $echo ) echo $value; return $value; }
function disabled( $value ) { if ( $value ) echo ' disabled'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_kses_post( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function paginate_links() { return ''; }

class MAC_Tracker_Time {
	public static function bangkok_label( $value ) { return $value ? '22 Sep 2026 · 10:00' : '—'; }
	public static function bangkok_date( $value ) { return $value ? '22 Sep 2026' : '—'; }
	public static function bangkok_input() { return '2026-09-22T10:00'; }
}
class MAC_Tracker_Normalizer { public static function host( $url ) { return (string) parse_url( $url, PHP_URL_HOST ); } }
class MAC_Tracker_GitHub_Actions {}
class MAC_Tracker_Sync_Service { public function is_running() { return false; } public function is_queued() { return false; } }
class MAC_Tracker_Elementor_Color_Service { public function extraction_state() { return array(); } }
class MAC_Tracker_Repository {
	private function project() {
		return array( 'id' => 1, 'wpm_project_id' => 5025, 'wpm_action_task_id' => 9, 'name' => 'Botanical Nails', 'zip_code' => '10001', 'package_json' => '"SE1"', 'website_url' => 'https://botanical.example.test', 'layout_url' => 'https://templates.macusaone.com/demo-g18/home/', 'assignee_json' => '{"name":"Alex"}', 'task_completed_at' => '2026-09-22 03:00:00', 'record_kind' => 'action_design', 'sync_source' => '', 'raw_payload' => '{}', 'screenshot_url' => 'https://example.test/capture.jpg', 'colors_json' => '["#2F4A3A","#EDE5D8"]', 'color_locked' => 1, 'tone_status' => 'classified', 'tone' => 'Vàng trắng', 'capture_status' => 'captured', 'captured_at' => '2026-09-22 03:00:00', 'tone_reason' => 'Stored result', 'precise_tone' => '' );
	}
	public function project_page() { return array( 'rows' => array( $this->project() ), 'total' => 1, 'per_page' => 0, 'paged' => 1, 'total_pages' => 1 ); }
	public function visual_tone_filter_groups() { return array(); }
	public function list_assignees() { return array( 'Alex' ); }
	public function visual_tones() { return array( 'Vàng trắng', 'Cần duyệt' ); }
	public function visual_tone_palette() { return array( 'Vàng trắng' => array( 'tone_a' => '#C5A64A', 'tone_b' => '#FFFFFF' ) ); }
	public function visual_stats() { return array( 'captured' => 1, 'classified' => 1, 'failed' => 0 ); }
	public function visual_review_rows() { return array(); }
	public function color_review_rows() { return array(); }
	public function recent_logs() { return array( array( 'status' => 'success', 'processed' => 1, 'message' => 'Sync completed.', 'started_at' => '2026-09-22 03:00:00' ) ); }
	public function latest_log() { return $this->recent_logs()[0]; }
	public function pin_count() { return 1; }
	public function baseline_is_compared() { return true; }
	public function excluded_projects() { return array(); }
	public function canonical_person_name( $name ) { return $name; }
}

require dirname( __DIR__ ) . '/includes/class-mac-tracker-admin.php';

$admin = new MAC_Tracker_Admin( new MAC_Tracker_Repository(), new MAC_Tracker_Sync_Service(), new MAC_Tracker_Elementor_Color_Service() );
$screens = array( 'Dashboard' => 'render_dashboard', 'Projects' => 'render_projects', 'AI Analysis' => 'render_visuals', 'Skipped Projects' => 'render_skipped_projects', 'Settings' => 'render_settings' );

foreach ( $screens as $label => $method ) {
	$_GET = array();
	ob_start();
	$admin->{$method}();
	$html = ob_get_clean();
	if ( false === strpos( $html, 'mac-tracker-app' ) || false === strpos( $html, 'mac-tracker-editorial-band' ) ) { throw new RuntimeException( $label . ' did not render the complete app shell.' ); }
	if ( strrpos( $html, 'mac-tracker-editorial-band' ) < strrpos( $html, 'mac-tracker-page-content' ) ) { throw new RuntimeException( $label . ' editorial band is not at the page end.' ); }
	echo $label . " render: PASS\n";
}
