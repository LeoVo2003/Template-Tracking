<?php

defined( 'ABSPATH' ) || exit;

/**
 * Clean public read-only routes for MAC Project Tracker.
 *
 * Administrators keep the existing operational controls. Guests and users
 * without manage_options receive the same data presentation without mutation
 * scripts or controls. Mutating requests still travel through the protected
 * admin-post/admin-ajax/REST handlers.
 */
class MAC_Tracker_App {

	const QUERY_VAR = 'mac_tracker_app';

	private $admin;

	public function __construct( MAC_Tracker_Admin $admin ) {
		$this->admin = $admin;
	}

	public function register() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ), 0 );
		add_action( 'admin_init', array( $this, 'redirect_legacy_admin' ), 1 );
	}

	public static function register_rewrite_rules() {
		add_rewrite_rule( '^mac-project-tracker/?$', 'index.php?' . self::QUERY_VAR . '=dashboard', 'top' );
		add_rewrite_rule( '^mac-project-tracker/(projects|analysis|skipped|settings)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Return a clean application URL without coupling renderers to rewrite internals. */
	public static function url( $screen = 'dashboard', array $args = array() ) {
		$paths = array(
			'dashboard' => 'mac-project-tracker/',
			'projects'  => 'mac-project-tracker/projects/',
			'analysis'  => 'mac-project-tracker/analysis/',
			'visuals'   => 'mac-project-tracker/analysis/',
			'colors'    => 'mac-project-tracker/analysis/',
			'skipped'   => 'mac-project-tracker/skipped/',
			'settings'  => 'mac-project-tracker/settings/',
			'pins'      => 'mac-project-tracker/settings/',
		);
		$url = home_url( '/' . ( $paths[ sanitize_key( $screen ) ] ?? $paths['dashboard'] ) );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/** Intercept the clean path even before rewrite rules have been flushed. */
	private function requested_screen() {
		$screen = sanitize_key( (string) get_query_var( self::QUERY_VAR, '' ) );
		if ( in_array( $screen, array( 'dashboard', 'projects', 'analysis', 'skipped', 'settings' ), true ) ) {
			return $screen;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = trim( $path, '/' );
		if ( 'mac-project-tracker' === $path ) {
			return 'dashboard';
		}
		if ( preg_match( '#^mac-project-tracker/(projects|analysis|skipped|settings)$#', $path, $match ) ) {
			return $match[1];
		}
		return '';
	}

	public function render() {
		$screen = $this->requested_screen();
		if ( '' === $screen ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		$this->admin->set_standalone( true );
		$this->admin->set_public_view( ! current_user_can( 'manage_options' ) );
		$this->admin->enqueue_standalone_assets();
		$renderers = array(
			'dashboard' => 'render_dashboard',
			'projects'  => 'render_projects',
			'analysis'  => 'render_visuals',
			'skipped'   => 'render_skipped_projects',
			'settings'  => 'render_settings',
		);
		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( 'MAC Project Tracker' ); ?></title>
			<?php wp_print_styles( array( 'dashicons', 'mac-tracker-fonts', 'mac-project-tracker-standalone' ) ); ?>
		</head>
		<body class="mac-tracker-standalone">
			<a class="screen-reader-text skip-link" href="#mac-tracker-main">Skip to content</a>
			<?php $this->admin->{$renderers[ $screen ]}(); ?>
			<?php wp_print_footer_scripts(); ?>
		</body>
		</html><?php
		exit;
	}

	/** Keep bookmarks working while making the clean app URL canonical. */
	public function redirect_legacy_admin() {
		if ( 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		$map = array(
			'mac-project-tracker-dashboard' => array( 'dashboard', '' ),
			'mac-project-tracker'           => array( 'projects', '' ),
			'mac-project-tracker-visuals'   => array( 'analysis', '' ),
			'mac-project-tracker-colors'    => array( 'analysis', 'action' ),
			'mac-project-tracker-skipped'   => array( 'skipped', '' ),
			'mac-project-tracker-settings'  => array( 'settings', '' ),
			'mac-project-tracker-pins'      => array( 'settings', 'import' ),
		);
		if ( ! isset( $map[ $page ] ) ) {
			return;
		}
		$args = wp_unslash( $_GET );
		unset( $args['page'] );
		$url = self::url( $map[ $page ][0], array_map( 'sanitize_text_field', $args ) );
		if ( $map[ $page ][1] ) {
			$url .= '#' . $map[ $page ][1];
		}
		wp_safe_redirect( $url );
		exit;
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		return esc_url_raw( $scheme . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
	}
}
