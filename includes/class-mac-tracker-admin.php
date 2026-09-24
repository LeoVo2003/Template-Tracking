<?php

defined( 'ABSPATH' ) || exit;

/** WordPress-admin UI. All project rows come from local snapshot tables. */
class MAC_Tracker_Admin {

	private $repository;
	private $sync;
	private $colors;
	private $github_actions;
	private $current_screen = '';
	private $standalone = false;
	private $public_view = false;

	public function __construct( MAC_Tracker_Repository $repository, MAC_Tracker_Sync_Service $sync, MAC_Tracker_Elementor_Color_Service $colors ) {
		$this->repository = $repository;
		$this->sync       = $sync;
		$this->colors     = $colors;
		$this->github_actions = new MAC_Tracker_GitHub_Actions();
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_post_mac_tracker_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_mac_tracker_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_mac_tracker_queue_sync', array( $this, 'handle_queue_sync' ) );
		add_action( 'admin_post_mac_tracker_compare_wpm', array( $this, 'handle_compare_wpm' ) );
		add_action( 'admin_post_mac_tracker_edit_project', array( $this, 'handle_edit_project' ) );
		add_action( 'admin_post_mac_tracker_import_pins', array( $this, 'handle_import_pins' ) );
		add_action( 'admin_post_mac_tracker_clear_data', array( $this, 'handle_clear_data' ) );
		add_action( 'admin_post_mac_tracker_extract_elementor_colors', array( $this, 'handle_extract_elementor_colors' ) );
		add_action( 'admin_post_mac_tracker_extract_all_elementor_colors', array( $this, 'handle_extract_all_elementor_colors' ) );
		add_action( 'admin_post_mac_tracker_approve_colors', array( $this, 'handle_approve_colors' ) );
		add_action( 'wp_ajax_mac_tracker_extract_colors', array( $this, 'handle_extract_colors_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_extract_all_colors', array( $this, 'handle_extract_all_colors_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_approve_colors', array( $this, 'handle_approve_colors_ajax' ) );
		add_action( 'admin_post_mac_tracker_save_visual_mode', array( $this, 'handle_save_visual_mode' ) );
		add_action( 'admin_post_mac_tracker_clear_visual_data', array( $this, 'handle_clear_visual_data' ) );
		add_action( 'admin_post_mac_tracker_run_visual_workflow', array( $this, 'handle_run_visual_workflow' ) );
		add_action( 'admin_post_mac_tracker_requeue_visuals', array( $this, 'handle_requeue_visuals' ) );
		add_action( 'admin_post_mac_tracker_approve_visual_tone', array( $this, 'handle_approve_visual_tone' ) );
		add_action( 'admin_post_mac_tracker_skip_project', array( $this, 'handle_skip_project' ) );
		add_action( 'admin_post_mac_tracker_restore_exclusion', array( $this, 'handle_restore_exclusion' ) );
		add_action( 'wp_ajax_mac_tracker_save_visual_controls', array( $this, 'handle_save_visual_controls_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_run_visual_workflow', array( $this, 'handle_run_visual_workflow_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_action', array( $this, 'handle_visual_action_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_status', array( $this, 'handle_visual_status_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_runs', array( $this, 'handle_visual_runs_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_run_detail', array( $this, 'handle_visual_run_detail_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_run_log', array( $this, 'handle_visual_run_log_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_run_action', array( $this, 'handle_visual_run_action_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_load_fragment', array( $this, 'handle_load_fragment_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_load_project_rows', array( $this, 'handle_load_project_rows_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_save_ui_theme', array( $this, 'handle_save_ui_theme_ajax' ) );
	}

	public function set_standalone( $standalone ) {
		$this->standalone = (bool) $standalone;
	}

	public function set_public_view( $public_view ) {
		$this->public_view = (bool) $public_view;
	}

	public function register_menu() {
		add_menu_page( 'Projects', 'MAC Tracker', 'manage_options', 'mac-project-tracker', array( $this, 'render_projects' ), 'dashicons-chart-area', 30 );
		add_submenu_page( 'mac-project-tracker', 'Projects', 'Projects', 'manage_options', 'mac-project-tracker', array( $this, 'render_projects' ) );
		add_submenu_page( 'mac-project-tracker', 'Dashboard', 'Dashboard', 'manage_options', 'mac-project-tracker-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'mac-project-tracker', 'AI Analysis', 'AI Analysis', 'manage_options', 'mac-project-tracker-visuals', array( $this, 'render_visuals' ) );
		add_submenu_page( 'mac-project-tracker', 'Skipped projects', 'Skipped projects', 'manage_options', 'mac-project-tracker-skipped', array( $this, 'render_skipped_projects' ) );
		add_submenu_page( 'mac-project-tracker', 'Settings', 'Settings', 'manage_options', 'mac-project-tracker-settings', array( $this, 'render_settings' ) );

		// Keep legacy URLs callable without exposing extra application navigation items.
		add_submenu_page( null, 'Color Review', 'Color Review', 'manage_options', 'mac-project-tracker-colors', array( $this, 'render_color_review' ) );
		add_submenu_page( null, 'Pin import', 'Pin import', 'manage_options', 'mac-project-tracker-pins', array( $this, 'render_pins' ) );
	}

	/** Scope the standalone app canvas to MAC Tracker routes only. */
	public function admin_body_class( $classes ) {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		$pages = array( 'mac-project-tracker', 'mac-project-tracker-dashboard', 'mac-project-tracker-visuals', 'mac-project-tracker-skipped', 'mac-project-tracker-settings', 'mac-project-tracker-colors', 'mac-project-tracker-pins' );
		return in_array( $page, $pages, true ) ? trim( $classes . ' mac-tracker-app-page' ) : $classes;
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'mac-project-tracker' ) ) {
			return;
		}
		$this->enqueue_common_assets( false );
	}

	/** Standalone routes intentionally do not load the legacy wp-admin stylesheet. */
	public function enqueue_standalone_assets() {
		$this->enqueue_common_assets( true );
	}

	private function enqueue_common_assets( $standalone ) {
		wp_enqueue_style( 'mac-tracker-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap', array(), null );
		if ( $standalone ) {
			wp_enqueue_style( 'mac-project-tracker-standalone', MAC_TRACKER_URL . 'assets/standalone.css', array( 'mac-tracker-fonts', 'dashicons' ), MAC_TRACKER_VERSION );
		} else {
			wp_enqueue_style( 'mac-project-tracker-admin', MAC_TRACKER_URL . 'assets/admin.css', array( 'mac-tracker-fonts' ), MAC_TRACKER_VERSION );
			wp_enqueue_style( 'mac-project-tracker-botanical', MAC_TRACKER_URL . 'assets/botanical.css', array( 'mac-project-tracker-admin' ), MAC_TRACKER_VERSION );
		}
		wp_enqueue_script( 'mac-project-tracker-app-ui', MAC_TRACKER_URL . 'assets/app-ui.js', array(), MAC_TRACKER_VERSION, true );
		wp_localize_script( 'mac-project-tracker-app-ui', 'macTrackerApp', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'fragmentNonce' => wp_create_nonce( 'mac_tracker_load_fragment' ),
			'projectRowsNonce' => wp_create_nonce( 'mac_tracker_load_project_rows' ),
			'themeNonce'    => wp_create_nonce( 'mac_tracker_save_ui_theme' ),
			'theme'         => $this->ui_theme(),
			'standalone'    => (bool) $this->standalone,
		) );
		if ( $this->public_view ) {
			return;
		}
		wp_enqueue_script( 'mac-project-tracker-admin-actions', MAC_TRACKER_URL . 'assets/admin-actions.js', array(), MAC_TRACKER_VERSION, true );
		wp_enqueue_script( 'mac-tracker-visual-workflow-monitor', MAC_TRACKER_URL . 'assets/visual-workflow-monitor.js', array(), MAC_TRACKER_VERSION, true );
		wp_localize_script( 'mac-project-tracker-admin-actions', 'macTrackerVisual', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'actionNonce' => wp_create_nonce( 'mac_tracker_requeue_visuals' ),
			'statusNonce' => wp_create_nonce( 'mac_tracker_visual_status' ),
			'runNonce'    => wp_create_nonce( 'mac_tracker_run_visual_workflow' ),
			'workflowNonce' => wp_create_nonce( 'mac_tracker_visual_workflows' ),
			'controlsNonce' => wp_create_nonce( 'mac_tracker_save_visual_controls' ),
			'palette'     => $this->repository->visual_tone_palette(),
		) );
		wp_localize_script( 'mac-tracker-visual-workflow-monitor', 'macTrackerWorkflow', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'mac_tracker_visual_workflows' ),
		) );
		wp_localize_script( 'mac-project-tracker-admin-actions', 'macTrackerColors', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'extractNonce' => wp_create_nonce( 'mac_tracker_extract_elementor_colors' ),
			'extractAllNonce' => wp_create_nonce( 'mac_tracker_extract_all_elementor_colors' ),
			'approveNonce' => wp_create_nonce( 'mac_tracker_approve_colors' ),
		) );
	}

	public function render_dashboard() {
		$this->require_capability();
		$get = wp_unslash( $_GET );
		$range = isset( $get['range'] ) && in_array( $get['range'], array( 'month', '30', '90', 'all', 'custom' ), true ) ? (string) $get['range'] : 'all';
		$custom_from = isset( $get['custom_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $get['custom_from'] ) ? (string) $get['custom_from'] : '';
		$custom_to = isset( $get['custom_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $get['custom_to'] ) ? (string) $get['custom_to'] : '';
		$dates = $this->date_range_values( $range, $custom_from, $custom_to );
		$project_page = $this->repository->project_page( array( 'date_from' => $dates['from'], 'date_to' => $dates['to'], 'per_page' => 0, 'orderby' => 'date', 'order' => 'desc' ) );
		$insights = $this->dashboard_insights( (array) $project_page['rows'] );
		$visual_stats = $this->repository->visual_stats();
		$visual_counts = $this->repository->visual_review_counts();
		$logs = $this->repository->recent_logs( 6 );
		$recent_projects = array_slice( (array) $project_page['rows'], 0, 5 );
		$review_count = (int) ( $visual_counts['review'] ?? 0 );
		$locked_count = (int) ( $visual_counts['locked'] ?? 0 );
		$settings = (array) get_option( 'mac_tracker_settings', array() );
		$this->page_start( 'Dashboard', 'Track project volume, visual trends and automation health.', 'dashboard' );
		?>
		<section class="mac-tracker-dashboard-heading" aria-label="Dashboard date range">
			<div><p class="mac-tracker-eyebrow">Design operations overview</p><h2>Overview</h2><p>Track progress, analyze visual tone and keep delivery work visible.</p></div>
			<form class="mac-tracker-date-range" method="get" action="<?php echo esc_url( $this->app_url( 'dashboard' ) ); ?>"><label><span class="screen-reader-text">Date preset</span><select name="range" onchange="this.form.submit()"><option value="month" <?php selected( $range, 'month' ); ?>>This month</option><option value="30" <?php selected( $range, '30' ); ?>>Last 30 days</option><option value="90" <?php selected( $range, '90' ); ?>>Last 90 days</option><option value="all" <?php selected( $range, 'all' ); ?>>All time</option><option value="custom" <?php selected( $range, 'custom' ); ?>>Custom range</option></select></label><details<?php echo 'custom' === $range ? ' open' : ''; ?>><summary>Custom</summary><div><label><span>From</span><input type="date" name="custom_from" value="<?php echo esc_attr( $custom_from ); ?>"></label><label><span>To</span><input type="date" name="custom_to" value="<?php echo esc_attr( $custom_to ); ?>"></label><button class="button" type="submit">Apply</button></div></details></form>
		</section>
		<?php $this->notices(); ?>
		<section class="mac-tracker-metric-grid" aria-label="Key metrics">
			<?php $this->metric_card( 'Total Projects', (int) $insights['project_count'], 'Distinct WPM projects in range', 'dashicons-portfolio' ); ?>
			<?php $this->metric_card( 'Captured', (int) ( $visual_stats['captured'] ?? 0 ), 'Current Visual Tone snapshot', 'dashicons-camera' ); ?>
			<?php $this->metric_card( 'Analyzed', (int) ( $visual_stats['classified'] ?? 0 ), 'AI classification complete', 'dashicons-admin-appearance' ); ?>
			<?php $this->metric_card( 'Need Review', $review_count, 'Awaiting human decision', 'dashicons-visibility' ); ?>
		</section>
		<div class="mac-tracker-dashboard-primary">
			<section class="mac-tracker-analytics-card mac-tracker-recent-projects"><header><div><p class="mac-tracker-eyebrow">Recent activity</p><h2>Latest projects</h2></div><a href="<?php echo esc_url( $this->app_url( 'projects' ) ); ?>">View all</a></header><?php if ( ! $recent_projects ) : ?><div class="mac-tracker-empty mac-tracker-empty--compact"><strong>No project activity in this range</strong></div><?php else : ?><ol><?php foreach ( $recent_projects as $row ) : ?><li><?php if ( ! empty( $row['screenshot_url'] ) ) : ?><img src="<?php echo esc_url( $row['screenshot_url'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="mac-tracker-project-thumb is-empty"></span><?php endif; ?><div><?php $this->project_link( $row ); ?><small><?php echo esc_html( MAC_Tracker_Time::bangkok_label( $row['task_completed_at'] ) ); ?></small></div><?php $this->project_status_badge( $row ); ?></li><?php endforeach; ?></ol><?php endif; ?></section>
			<section class="mac-tracker-analytics-card mac-tracker-tone-distribution"><header><div><p class="mac-tracker-eyebrow">AI tone distribution</p><h2>True color signals</h2></div><a href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'review' ) ) ); ?>">Review</a></header><?php $this->distribution_summary( $insights['colors'] ); ?></section>
		</div>
		<div class="mac-tracker-analytics-grid mac-tracker-analytics-grid--secondary">
			<section class="mac-tracker-analytics-card"><header><div><p class="mac-tracker-eyebrow">Template usage</p><h2>Top templates</h2></div><span><?php echo esc_html( number_format_i18n( count( $insights['templates'] ) ) ); ?> found</span></header><?php $this->ranked_list( $insights['templates'], max( 1, (int) $insights['project_count'] ) ); ?></section>
			<section class="mac-tracker-analytics-card"><header><div><p class="mac-tracker-eyebrow">Cross-section</p><h2>Template × color</h2></div><span>Top combinations</span></header><div class="mac-tracker-metric-table"><table><thead><tr><th>Template</th><th>Top color</th><th>Projects</th></tr></thead><tbody><?php if ( empty( $insights['combinations'] ) ) : ?><tr><td colspan="3">No classified template/color pairs in this range.</td></tr><?php else : foreach ( $insights['combinations'] as $item ) : ?><tr><td><?php echo esc_html( $item['template'] ); ?></td><td><span class="mac-tracker-color-key mac-tracker-color-key--<?php echo esc_attr( sanitize_title( $item['color'] ) ); ?>"><i></i><?php echo esc_html( $item['color'] ); ?></span></td><td><?php echo esc_html( number_format_i18n( $item['count'] ) ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
		</div>
		<section class="mac-tracker-pipeline-card" aria-label="Visual Tone pipeline summary"><div><p class="mac-tracker-eyebrow">Visual Tone pipeline</p><h2>Current processing snapshot</h2></div><dl><div><dt>Captured</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $visual_stats['captured'] ?? 0 ) ) ); ?></dd></div><div><dt>Analyzed</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $visual_stats['classified'] ?? 0 ) ) ); ?></dd></div><div><dt>Review</dt><dd><?php echo esc_html( number_format_i18n( $review_count ) ); ?></dd></div><div><dt>Locked</dt><dd><?php echo esc_html( number_format_i18n( $locked_count ) ); ?></dd></div><div><dt>Failed</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $visual_stats['failed'] ?? 0 ) ) ); ?></dd></div></dl></section>
		<div class="mac-tracker-dashboard-lower">
			<section class="mac-tracker-analytics-card"><header><div><p class="mac-tracker-eyebrow">Sync summary</p><h2>Recent WPM activity</h2></div><span>Newest first</span></header><?php if ( empty( $logs ) ) : ?><div class="mac-tracker-empty"><strong>No comparison yet</strong><p>Connect WPM, then run the first baseline comparison.</p></div><?php else : ?><ol class="mac-tracker-timeline"><?php foreach ( $logs as $log ) : ?><li><i class="is-<?php echo esc_attr( $this->status_class( $log['status'] ) ); ?>"></i><div><strong><?php echo esc_html( ucfirst( (string) $log['status'] ) . ' · ' . number_format_i18n( (int) $log['processed'] ) . ' projects processed' ); ?></strong><span><?php echo esc_html( MAC_Tracker_Time::bangkok_label( $log['started_at'] ) ); ?></span><details><summary>View technical details</summary><p><?php echo esc_html( $log['message'] ); ?></p></details></div></li><?php endforeach; ?></ol><?php endif; ?></section>
			<div class="mac-tracker-dashboard-lower__side"><section class="mac-tracker-health-card"><header><div><p class="mac-tracker-eyebrow">System health</p><h2>Connections &amp; automation</h2></div></header><dl><div><dt>WPM</dt><dd class="<?php echo ! empty( $settings['wpm_endpoint'] ) && get_option( 'mac_tracker_wpm_secret', '' ) ? 'is-good' : 'is-muted'; ?>"><?php echo ! empty( $settings['wpm_endpoint'] ) && get_option( 'mac_tracker_wpm_secret', '' ) ? 'Configured' : 'Needs setup'; ?></dd></div><div><dt>GitHub</dt><dd class="<?php echo get_option( 'mac_tracker_github_dispatch_token', '' ) ? 'is-good' : 'is-muted'; ?>"><?php echo get_option( 'mac_tracker_github_dispatch_token', '' ) ? 'Configured' : 'Needs setup'; ?></dd></div><div><dt>Automation</dt><dd class="<?php echo 'auto' === get_option( 'mac_tracker_visual_mode', 'manual' ) ? 'is-good' : 'is-muted'; ?>"><?php echo 'auto' === get_option( 'mac_tracker_visual_mode', 'manual' ) ? 'Auto' : 'Manual'; ?></dd></div><div><dt>AI strategy</dt><dd><?php echo esc_html( strtoupper( (string) get_option( 'mac_tracker_visual_ai_strategy', 'smart' ) ) ); ?></dd></div></dl><footer class="mac-tracker-health-card__action"><?php $this->sync_buttons(); ?></footer></section><?php $this->editorial_footer_band( 'dashboard', 'side' ); ?></div>
		</div>
		<?php $this->page_end( false );
	}

	public function render_projects() {
		$this->require_capability();
		$filters = $this->project_filters();
		$page    = $this->repository->project_page( $filters );
		$tone_filter_groups = $this->repository->visual_tone_filter_groups();
		$layout_groups = $this->repository->list_layout_groups();
		$legacy_tone_filter = in_array( $filters['tone'], $this->tone_options(), true ) ? $filters['tone'] : '';
		$this->page_start( 'Projects', 'Browse delivered projects and visual metadata.', 'projects' );
		?>
		<section class="mac-tracker-project-intro">
			<div><p class="mac-tracker-eyebrow">Project ledger</p><h2><?php echo esc_html( number_format_i18n( $page['total'] ) ); ?> delivered project<?php echo 1 === (int) $page['total'] ? '' : 's'; ?></h2><p>Search, filter and review the current local snapshot.</p></div>
			<?php $this->sync_buttons(); ?>
		</section>
		<?php $this->notices(); ?>

		<form class="mac-tracker-filters" method="get" action="<?php echo esc_url( $this->app_url( 'projects' ) ); ?>">
			<?php if ( ! $this->standalone ) : ?><input type="hidden" name="page" value="mac-project-tracker"><?php endif; ?>
			<label class="mac-tracker-filter-search"><span>Search</span><input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Project, domain, ZIP or WPM ID"></label>
			<label><span>Assignee</span><select name="assignee"><option value="">All assignees</option><?php foreach ( $this->repository->list_assignees() as $name ) : ?><option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['assignee'], $name ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label>
			<label><span>Layout</span><select name="layout"><option value="">All layouts</option><?php if ( $layout_groups ) : ?><optgroup label="Templates"><?php foreach ( $layout_groups as $group ) : ?><option value="<?php echo esc_attr( $group['value'] ); ?>" <?php selected( $filters['layout'], $group['value'] ); ?>><?php echo esc_html( $group['label'] ); ?></option><?php endforeach; ?></optgroup><?php endif; ?><optgroup label="Other"><option value="external" <?php selected( $filters['layout'], 'external' ); ?>>External layout</option><option value="missing" <?php selected( $filters['layout'], 'missing' ); ?>>Missing layout</option></optgroup></select></label>
			<label><span>AI tone</span><select name="tone"><option value="">All tones</option><option value="pending" <?php selected( $filters['tone'], 'pending' ); ?>>Waiting for AI</option><?php if ( $legacy_tone_filter ) : ?><option value="<?php echo esc_attr( $legacy_tone_filter ); ?>" selected><?php echo esc_html( 'Exact saved tone: ' . $legacy_tone_filter ); ?></option><?php endif; ?><?php foreach ( $tone_filter_groups as $group ) : ?><optgroup label="<?php echo esc_attr( $group['label'] ); ?>"><option value="<?php echo esc_attr( $group['value'] ); ?>" <?php selected( $filters['tone'], $group['value'] ); ?>><?php echo esc_html( $group['label'] . ' — all variants' ); ?></option><?php foreach ( $group['children'] as $child ) : ?><option value="<?php echo esc_attr( $child['value'] ); ?>" <?php selected( $filters['tone'], $child['value'] ); ?>><?php echo esc_html( '— ' . $child['label'] ); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
			<label><span>Date range</span><select name="range"><option value="month" <?php selected( $filters['range'], 'month' ); ?>>This month</option><option value="30" <?php selected( $filters['range'], '30' ); ?>>Last 30 days</option><option value="90" <?php selected( $filters['range'], '90' ); ?>>Last 90 days</option><option value="all" <?php selected( $filters['range'], 'all' ); ?>>All time</option><option value="custom" <?php selected( $filters['range'], 'custom' ); ?>>Custom</option></select></label>
			<details class="mac-tracker-filter-more"<?php echo 'custom' === $filters['range'] || $filters['kind'] ? ' open' : ''; ?>><summary>More filters</summary><div><label><span>From</span><input type="date" name="custom_from" value="<?php echo esc_attr( $filters['custom_from'] ); ?>"></label><label><span>To</span><input type="date" name="custom_to" value="<?php echo esc_attr( $filters['custom_to'] ); ?>"></label><label><span>Source</span><select name="kind"><option value="">All sources</option><option value="action_design" <?php selected( $filters['kind'], 'action_design' ); ?>>Action Design</option><option value="csv_pin" <?php selected( $filters['kind'], 'csv_pin' ); ?>>CSV pin</option></select></label></div></details>
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $filters['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $filters['order'] ); ?>">
			<div class="mac-tracker-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="<?php echo esc_url( $this->app_url( 'projects' ) ); ?>">Clear</a></div>
		</form>

		<section class="mac-tracker-table-shell">
			<?php if ( empty( $page['rows'] ) ) : ?>
				<div class="mac-tracker-empty"><span class="dashicons dashicons-archive"></span><strong>No project snapshots yet</strong><p>Import the pin baseline or save WPM Settings and run a background sync.</p></div>
			<?php else : ?>
				<div class="mac-tracker-table-scroll"><table class="widefat mac-tracker-project-table"><thead><tr>
					<th scope="col" class="mac-tracker-col--thumbnail">Thumbnail</th><?php $this->sort_header( 'project', 'Project', $filters ); ?><?php $this->sort_header( 'layout', 'Template', $filters ); ?><?php $this->sort_header( 'palette', 'Color', $filters ); ?><?php $this->sort_header( 'tone', 'Tone', $filters ); ?><th scope="col">Status</th><?php $this->sort_header( 'assignee', 'User', $filters ); ?><?php $this->sort_header( 'date', 'Time', $filters, 'mac-tracker-col--date' ); ?><th scope="col" class="mac-tracker-col--options"><span class="screen-reader-text">Options</span></th>
				</tr></thead><tbody>
					<?php foreach ( $page['rows'] as $row ) : $this->render_project_row( $row ); endforeach; ?>
				</tbody></table></div>
				<?php if ( ! empty( $filters['all_mode'] ) && (int) $page['total_pages'] > 1 ) : ?>
					<div class="mac-tracker-project-lazy-load" data-mac-project-lazy-load data-mac-project-page="1" data-mac-project-total="<?php echo esc_attr( $page['total'] ); ?>" data-mac-project-filters="<?php echo esc_attr( wp_json_encode( $this->project_fragment_filters( $filters ) ) ); ?>"><p>Showing the first <?php echo esc_html( number_format_i18n( count( $page['rows'] ) ) ); ?> of <?php echo esc_html( number_format_i18n( $page['total'] ) ); ?> records. More records load as you reach this point.</p><button type="button" class="button" data-mac-project-load-more>Load next 100</button></div>
				<?php endif; ?>
				<?php $this->pagination( $page, $filters ); ?>
			<?php endif; ?>
		</section>
		<?php $this->page_end();
	}

	/** Shared project row renderer for the initial table and bounded All-mode AJAX chunks. */
	private function render_project_row( array $row ) {
		?>
		<tr>
			<td><?php if ( ! empty( $row['screenshot_url'] ) ) : ?><a class="mac-tracker-project-thumb" href="<?php echo esc_url( $row['screenshot_url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $row['screenshot_url'] ); ?>" alt="<?php echo esc_attr( $row['name'] . ' website capture' ); ?>" loading="lazy"></a><?php else : ?><span class="mac-tracker-project-thumb is-empty" aria-label="No capture available"><span class="dashicons dashicons-format-image"></span></span><?php endif; ?></td>
			<td class="mac-tracker-project-cell"><?php $this->project_link( $row ); ?><div class="mac-tracker-project-domain"><?php $this->url_link( $row['website_url'], $this->website_label( $row['website_url'] ) ); ?></div><span class="mac-tracker-project-cell__meta"><?php $this->project_id_link( $row ); ?></span><?php $this->confidence_badge( $row ); ?><?php $this->baseline_override_badge( $row ); ?></td>
			<td><?php $this->url_link( $this->layout_url( $row['layout_url'] ), $this->layout_label( $row['layout_url'] ) ); ?></td>
			<td><?php $this->palette_cell( $row ); ?></td>
			<td><?php $this->tone_cell( $row ); ?></td>
			<td><?php $this->project_status_badge( $row ); ?></td>
			<td><?php echo esc_html( $this->person_name( $row['assignee_json'] ) ?: '—' ); ?></td>
			<td class="mac-tracker-date mac-tracker-date--day"><?php echo esc_html( MAC_Tracker_Time::bangkok_date( $row['task_completed_at'] ) ); ?></td>
			<td class="mac-tracker-row-options"><button type="button" class="mac-tracker-options-trigger" aria-haspopup="true" aria-expanded="false" aria-label="Project options" data-mac-menu-trigger>⋮</button><div class="mac-tracker-options-menu" data-mac-menu hidden><?php if ( $this->first_url( $row['website_url'] ) ) : ?><a href="<?php echo esc_url( $this->first_url( $row['website_url'] ) ); ?>" target="_blank" rel="noopener">Open website</a><?php endif; ?><button type="button" data-edit-target="edit-<?php echo (int) $row['id']; ?>">Edit project</button><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Skip this project from Visual Tone and Color Review?');"><?php wp_nonce_field( 'mac_tracker_skip_project' ); ?><input type="hidden" name="action" value="mac_tracker_skip_project"><input type="hidden" name="return_screen" value="projects"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><button type="submit" class="is-danger">Skip project</button></form></div></td>
		</tr>
		<tr id="edit-<?php echo (int) $row['id']; ?>" class="mac-tracker-edit-row" hidden><td colspan="9"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_edit_project' ); ?><input type="hidden" name="action" value="mac_tracker_edit_project"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><label>Project ID<input type="number" min="1" name="project_id" value="<?php echo (int) $row['wpm_project_id']; ?>" required></label><label>Project name<input type="text" name="project_name" value="<?php echo esc_attr( $row['name'] ); ?>" required></label><label>Date &amp; time<input type="datetime-local" name="date_time" value="<?php echo esc_attr( MAC_Tracker_Time::bangkok_input( $row['task_completed_at'] ) ); ?>"></label><button class="button button-primary" type="submit">Save project</button><button class="button" type="button" data-edit-target="edit-<?php echo (int) $row['id']; ?>">Cancel</button></form></td></tr>
		<?php
	}

	/** Review and approve only extracted Elementor Global Color variables. */
	public function render_color_review() {
		$this->require_capability();
		$this->page_start( 'AI Analysis', 'Capture, analyze, review and approve visual tone.', 'colors' );
		$this->notices();
		$this->render_color_review_content();
		$this->page_end();
	}

	/** Existing Color Review presentation, reusable inside the consolidated AI page. */
	private function render_color_review_content( $status = null, $page_number = null, $search = null, $per_page = null ) {
		$status = null === $status ? ( isset( $_GET['color_status'] ) && 'approved' === sanitize_key( wp_unslash( $_GET['color_status'] ) ) ? 'approved' : 'pending' ) : ( 'approved' === $status ? 'approved' : 'pending' );
		$page_number = null === $page_number ? max( 1, absint( $_GET['color_page'] ?? 1 ) ) : max( 1, absint( $page_number ) );
		$search = null === $search ? sanitize_text_field( wp_unslash( $_GET['color_search'] ?? '' ) ) : sanitize_text_field( $search );
		$per_page = null === $per_page ? $this->presentation_page_size( $_GET['color_per_page'] ?? 100 ) : $this->presentation_page_size( $per_page );
		$page = $this->repository->color_review_page( $status, $page_number, $per_page, $search );
		$rows = $page['rows'];
		$counts = $this->repository->color_review_counts();
		$extract_state = $this->colors->extraction_state();
		?>
		<section class="mac-tracker-color-intro">
			<div><p class="mac-tracker-eyebrow">Elementor only</p><h2>Global color variables, ready for review</h2><p>Extract reads <code>--e-global-color-*</code> from the website HTML and Elementor stylesheets. Approving locks the palette so a later extraction cannot replace it.</p></div>
			<div class="mac-tracker-color-intro__count"><strong><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong><span>websites available</span></div>
			<form class="mac-tracker-color-extract-all" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_extract_all_elementor_colors' ); ?><input type="hidden" name="action" value="mac_tracker_extract_all_elementor_colors"><button class="button button-primary" type="submit"><span class="dashicons dashicons-art"></span>Extract all</button><small>Only websites with no prior extraction. Runs in background batches.</small></form>
		</section>
		<?php if ( ! empty( $extract_state ) ) : ?><section class="mac-tracker-color-progress"><div><span class="mac-tracker-status mac-tracker-status--<?php echo esc_attr( 'complete' === ( $extract_state['status'] ?? '' ) ? 'success' : 'waiting' ); ?>"><?php echo esc_html( $extract_state['status'] ?? 'queued' ); ?></span><strong>Elementor extraction</strong></div><dl><div><dt>Processed</dt><dd><?php echo esc_html( (int) ( $extract_state['processed'] ?? 0 ) ); ?></dd></div><div><dt>Palettes found</dt><dd><?php echo esc_html( (int) ( $extract_state['found'] ?? 0 ) ); ?></dd></div><div><dt>Failed</dt><dd><?php echo esc_html( (int) ( $extract_state['failed'] ?? 0 ) ); ?></dd></div><div><dt>Remaining</dt><dd><?php echo esc_html( (int) ( $extract_state['remaining'] ?? 0 ) ); ?></dd></div></dl></section><?php endif; ?>
		<div class="mac-tracker-color-toolbar"><nav class="mac-tracker-color-tabs" aria-label="Color review status"><a class="<?php echo 'pending' === $status ? 'is-active' : ''; ?>" data-mac-fragment-target="ai-panel" data-mac-fragment-section="action" data-mac-color-status="pending" data-mac-page-size="<?php echo esc_attr( $this->presentation_page_request( $_GET['color_per_page'] ?? 100 ) ); ?>" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'action', 'color_status' => 'pending' ) ) ); ?>">Pending <span><?php echo esc_html( $counts['pending'] ); ?></span></a><a class="<?php echo 'approved' === $status ? 'is-active' : ''; ?>" data-mac-fragment-target="ai-panel" data-mac-fragment-section="action" data-mac-color-status="approved" data-mac-page-size="<?php echo esc_attr( $this->presentation_page_request( $_GET['color_per_page'] ?? 100 ) ); ?>" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'action', 'color_status' => 'approved' ) ) ); ?>">Approved <span><?php echo esc_html( $counts['approved'] ); ?></span></a></nav><form method="get" action="<?php echo esc_url( $this->app_url( 'analysis' ) ); ?>"><input type="hidden" name="section" value="action"><input type="hidden" name="color_status" value="<?php echo esc_attr( $status ); ?>"><label><span class="screen-reader-text">Search Color Review</span><input type="search" name="color_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search project or domain"></label><button class="button" type="submit">Search</button></form></div>
		<section class="mac-tracker-color-review" aria-label="Color review queue">
			<?php if ( empty( $rows ) ) : ?>
				<div class="mac-tracker-empty"><span class="dashicons dashicons-art"></span><strong>No websites available</strong><p>Color Review only lists current project rows that have a website URL.</p></div>
			<?php else : $initial_rows = array_slice( $rows, 0, 24 ); $deferred_rows = array_slice( $rows, 24 ); foreach ( $initial_rows as $row ) : $this->render_color_card( $row ); endforeach; if ( $deferred_rows ) : ob_start(); foreach ( $deferred_rows as $row ) : $this->render_color_card( $row ); endforeach; $deferred_markup = ob_get_clean(); ?><template data-mac-lazy-cards><?php echo $deferred_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template><button type="button" class="button mac-tracker-lazy-load" data-mac-lazy-load>Load <?php echo esc_html( min( 24, count( $deferred_rows ) ) ); ?> more cards</button><?php endif; endif; ?>
		</section>
		<footer class="mac-tracker-analysis-footer"><?php $this->analysis_page_size_control( 'color', 'action', $per_page ); ?><?php $this->analysis_pagination( $page, array( 'section' => 'action', 'color_status' => $status, 'color_search' => $search, 'color_per_page' => $this->presentation_page_request( $_GET['color_per_page'] ?? 100 ) ), 'color_page' ); ?></footer>
		<?php
	}

	/** One compact Color Review card, reused for deferred visual rendering. */
	private function render_color_card( array $row ) {
		$colors = $this->row_colors( $row );
		$approved = ! empty( $row['color_locked'] );
		?>
		<article class="mac-tracker-color-card <?php echo $approved ? 'is-approved' : ''; ?>" data-color-card="<?php echo (int) $row['id']; ?>">
			<div class="mac-tracker-color-card__head"><div><p class="mac-tracker-eyebrow"><?php echo esc_html( $approved ? 'Approved palette' : ( $colors ? 'Awaiting review' : 'Not extracted' ) ); ?></p><h2><?php $this->project_link( $row ); ?></h2><p><?php $this->url_link( $row['website_url'], $this->website_label( $row['website_url'] ) ); ?></p></div><span class="mac-tracker-status mac-tracker-status--<?php echo esc_attr( $approved ? 'success' : ( $colors ? 'waiting' : 'muted' ) ); ?> mac-tracker-status--inline"><?php echo esc_html( $approved ? 'Approved' : ( $colors ? 'Review' : 'New' ) ); ?></span></div>
			<?php if ( $colors ) : ?><div class="mac-tracker-color-swatches" aria-label="Extracted colors"><?php foreach ( $colors as $color ) : ?><span title="<?php echo esc_attr( $color ); ?>" style="--mac-tracker-swatch: <?php echo esc_attr( $color ); ?>"><i></i><code><?php echo esc_html( $color ); ?></code></span><?php endforeach; ?></div><?php endif; ?>
			<?php $this->color_variables( $row ); ?>
			<div class="mac-tracker-color-card__actions">
				<?php if ( ! $approved ) : ?>
					<form class="mac-tracker-color-extract" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_extract_elementor_colors' ); ?><input type="hidden" name="action" value="mac_tracker_extract_elementor_colors"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><button class="button" type="submit"><?php echo $colors ? 'Extract again' : 'Extract Elementor colors'; ?></button></form>
					<?php if ( $colors ) : ?><form class="mac-tracker-color-approve mac-tracker-palette-editor" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_approve_colors' ); ?><input type="hidden" name="action" value="mac_tracker_approve_colors"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><label><span>Palette values</span><input name="colors" value="<?php echo esc_attr( implode( ', ', $colors ) ); ?>"></label><button class="button button-primary" type="submit">Approve palette</button></form><?php endif; ?>
				<?php else : ?><span class="mac-tracker-color-lock">Human approved</span><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Bỏ qua project này khỏi Visual Tone và Color Review?');"><?php wp_nonce_field( 'mac_tracker_skip_project' ); ?><input type="hidden" name="action" value="mac_tracker_skip_project"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><button type="submit" class="button button-small mac-tracker-button--skip">Skip project</button></form>
			</div>
		</article>
		<?php
	}

	public function render_pins() {
		$this->require_capability();
		$this->page_start( 'Settings', 'Connections, imports, automation and data controls.', 'pins' );
		$this->notices();
		$this->render_pin_import_content();
		$this->render_data_management_content();
		$this->page_end();
	}

	/** Existing CSV import handler and fields, reused by Settings. */
	private function render_pin_import_content() {
		?>
		<section class="mac-tracker-panel mac-tracker-panel--narrow"><div class="mac-tracker-panel__head"><div><p class="mac-tracker-eyebrow">Master roster import</p><h2><?php echo esc_html( number_format_i18n( $this->repository->pin_count() ) ); ?> saved CSV pins</h2><p>Import the original CSV baseline. Run Compare baseline once to overlay matching WPM Action Design records; the source CSV rows stay preserved locally.</p></div></div>
		<form class="mac-tracker-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mac_tracker_import_pins' ); ?><input type="hidden" name="action" value="mac_tracker_import_pins"><span class="dashicons dashicons-upload" aria-hidden="true"></span><strong>Drop the master CSV here</strong><p>or choose a file from this computer</p><label class="mac-tracker-file-control"><span>Choose CSV</span><input type="file" name="pin_csv" accept=".csv,text/csv" required data-file-input></label><small data-file-name>No file selected</small><button class="button button-primary" type="submit">Import pins</button>
		</form></section>
		<?php
	}

	public function render_visuals() {
		$this->require_capability();
		$section = sanitize_key( wp_unslash( $_GET['section'] ?? '' ) );
		$section = in_array( $section, array( 'action', 'processing', 'review', 'locked', 'workflow' ), true ) ? $section : 'processing';
		$visual_counts = $this->repository->visual_review_counts();
		$color_counts = $this->repository->color_review_counts();
		$queue_count = (int) ( $visual_counts['processing'] ?? 0 );
		$review_count = (int) ( $visual_counts['review'] ?? 0 );
		$locked_count = (int) ( $visual_counts['locked'] ?? 0 );
		$action_count = (int) ( $color_counts['pending'] ?? 0 );
		$this->page_start( 'AI Analysis', 'Capture, analyze, review and approve visual tone.', 'visuals' );
		?>
		<?php $this->notices(); ?>
		<nav class="mac-tracker-ai-tabs" aria-label="AI Analysis sections" data-mac-fragment-tabs="ai-panel">
			<a class="<?php echo 'processing' === $section ? 'is-active' : ''; ?>" aria-selected="<?php echo 'processing' === $section ? 'true' : 'false'; ?>" data-mac-fragment-target="ai-panel" data-mac-fragment-section="processing" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'processing' ) ) ); ?>">Processing <span><?php echo esc_html( $queue_count ); ?></span></a>
			<a class="<?php echo 'review' === $section ? 'is-active' : ''; ?>" aria-selected="<?php echo 'review' === $section ? 'true' : 'false'; ?>" data-mac-fragment-target="ai-panel" data-mac-fragment-section="review" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'review' ) ) ); ?>">Review <span><?php echo esc_html( $review_count ); ?></span></a>
			<a class="<?php echo 'locked' === $section ? 'is-active' : ''; ?>" aria-selected="<?php echo 'locked' === $section ? 'true' : 'false'; ?>" data-mac-fragment-target="ai-panel" data-mac-fragment-section="locked" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'locked' ) ) ); ?>">Locked <span><?php echo esc_html( $locked_count ); ?></span></a>
			<a class="<?php echo 'action' === $section ? 'is-active' : ''; ?>" aria-selected="<?php echo 'action' === $section ? 'true' : 'false'; ?>" data-mac-fragment-target="ai-panel" data-mac-fragment-section="action" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'action' ) ) ); ?>">Action <span><?php echo esc_html( $action_count ); ?></span></a>
			<a class="<?php echo 'workflow' === $section ? 'is-active' : ''; ?>" aria-selected="<?php echo 'workflow' === $section ? 'true' : 'false'; ?>" data-mac-fragment-target="ai-panel" data-mac-fragment-section="workflow" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'workflow' ) ) ); ?>">Workflow Log</a>
		</nav>
		<section class="mac-tracker-ai-panel" data-mac-fragment-panel="ai-panel"><?php $this->render_ai_fragment( $section ); ?></section>
		<?php $this->page_end();
	}

	/** Authenticated HTML fragment used by the persistent AI Analysis shell. */
	private function render_ai_fragment( $section ) {
		$section = in_array( $section, array( 'action', 'processing', 'review', 'locked', 'workflow' ), true ) ? $section : 'processing';
		if ( 'action' === $section ) {
			$this->render_color_review_content();
			return;
		}
		if ( 'workflow' === $section ) {
			$this->render_visual_workflow_monitor();
			return;
		}
		$stats = $this->repository->visual_stats();
		$counts = $this->repository->visual_review_counts();
		$mode = 'auto' === get_option( 'mac_tracker_visual_mode', 'manual' ) ? 'auto' : 'manual';
		$strategy = in_array( get_option( 'mac_tracker_visual_ai_strategy', 'smart' ), array( 'off', 'qwen', 'gemini', 'smart' ), true ) ? get_option( 'mac_tracker_visual_ai_strategy', 'smart' ) : 'smart';
		$classifier = in_array( get_option( 'mac_tracker_visual_classifier_mode', 'direct_vision' ), array( 'legacy', 'benchmark_only', 'direct_vision' ), true ) ? get_option( 'mac_tracker_visual_classifier_mode', 'direct_vision' ) : 'direct_vision';
		$automation = $this->repository->visual_automation_observability();
		$per_page = $this->presentation_page_size( $_GET['visual_per_page'] ?? 100 );
		$page_number = max( 1, absint( $_GET['visual_page'] ?? 1 ) );
		$page = $this->repository->visual_review_page( $section, $page_number, $per_page );
		?>
		<section class="mac-tracker-overview mac-tracker-visual-overview"><div class="mac-tracker-overview__lead"><p class="mac-tracker-eyebrow">Visual Tone · <?php echo esc_html( ucfirst( $section ) ); ?></p><h2><?php echo 'processing' === $section ? 'Visual Tone processing' : ( 'review' === $section ? 'Ready for human review' : 'Human-approved tone library' ); ?></h2><p><?php echo 'processing' === $section ? 'Capture and AI work is tracked here. Operational controls stay compact; details remain available on demand.' : ( 'review' === $section ? 'Approve a clear AI result or adjust its tone before locking it.' : 'Approved tone remains protected until an administrator explicitly unlocks it.' ); ?></p></div><div class="mac-tracker-summary-grid"><?php $this->stat_card( 'Captured', (int) ( $stats['captured'] ?? 0 ), 'dashicons-format-image' ); ?><?php $this->stat_card( 'Review', (int) ( $counts['review'] ?? 0 ), 'dashicons-visibility' ); ?><?php $this->stat_card( 'Locked', (int) ( $counts['locked'] ?? 0 ), 'dashicons-lock' ); ?></div></section>
		<?php if ( 'processing' === $section ) : ?>
		<section class="mac-tracker-visual-auto mac-tracker-visual-auto--v4"><div><p class="mac-tracker-eyebrow">Automation control room</p><h2><?php echo 'auto' === $mode ? 'Automation is active' : 'Manual review first'; ?></h2><div class="mac-tracker-automation-state"><strong><?php echo esc_html( strtoupper( $mode ) ); ?></strong><span><?php echo esc_html( 'smart' === $strategy ? 'Smart Auto' : strtoupper( $strategy ) ); ?></span><span><?php echo esc_html( 'direct_vision' === $classifier ? 'Direct Vision' : 'Legacy classifier' ); ?></span></div><dl class="mac-tracker-automation-observability" aria-label="Automation observations"><div><dt>Last scheduled</dt><dd><?php echo esc_html( ! empty( $automation['last_scheduled_at'] ) ? MAC_Tracker_Time::bangkok_label( $automation['last_scheduled_at'] ) : 'Not recorded' ); ?></dd></div><div><dt>Last success</dt><dd><?php echo esc_html( ! empty( $automation['last_success_at'] ) ? MAC_Tracker_Time::bangkok_label( $automation['last_success_at'] ) : 'Not recorded' ); ?></dd></div><div><dt>Processed / 60m</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $automation['processed_60m'] ?? 0 ) ) ); ?> projects</dd></div><div><dt>Queue</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $automation['queue_size'] ?? 0 ) ) ); ?> projects</dd></div></dl></div><div class="mac-tracker-visual-auto__actions"><?php if ( get_option( 'mac_tracker_github_dispatch_token', '' ) ) : ?><form class="mac-tracker-visual-run" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=mac-project-tracker-visuals' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_run_visual_workflow' ); ?><input type="hidden" name="action" value="mac_tracker_run_visual_workflow"><button class="button button-primary" type="submit">Run batch now</button></form><?php endif; ?><details class="mac-tracker-advanced-controls"><summary>Advanced controls</summary><form class="mac-tracker-visual-mode" data-visual-controls method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_save_visual_mode' ); ?><input type="hidden" name="action" value="mac_tracker_save_visual_mode"><fieldset><legend class="screen-reader-text">Visual Tone mode</legend><label><input type="radio" name="visual_mode" value="manual"<?php checked( 'manual', $mode ); ?>> <span>Manual</span></label><label><input type="radio" name="visual_mode" value="auto"<?php checked( 'auto', $mode ); ?>> <span>Auto</span></label></fieldset><label><span>AI strategy</span><select name="ai_strategy"><option value="off"<?php selected( 'off', $strategy ); ?>>AI off</option><option value="qwen"<?php selected( 'qwen', $strategy ); ?>>Qwen only</option><option value="gemini"<?php selected( 'gemini', $strategy ); ?>>Gemini only</option><option value="smart"<?php selected( 'smart', $strategy ); ?>>Smart auto</option></select></label><label><span>Classifier</span><select name="classifier_mode"><option value="direct_vision"<?php selected( 'direct_vision', $classifier ); ?>>Direct Vision</option><option value="legacy"<?php selected( 'legacy', $classifier ); ?>>Legacy evidence</option><option value="benchmark_only"<?php selected( 'benchmark_only', $classifier ); ?>>Benchmark only</option></select></label><label>Accept ≥ <input type="number" name="auto_accept" min="0.75" max="0.99" step="0.01" value="<?php echo esc_attr( max( 0.75, min( 0.99, (float) get_option( 'mac_tracker_visual_auto_accept', 0.85 ) ) ) ); ?>"></label><label>Retries <input type="number" name="max_capture_retries" min="0" max="3" step="1" value="<?php echo esc_attr( max( 0, min( 3, absint( get_option( 'mac_tracker_visual_max_capture_retries', 3 ) ) ) ) ); ?>"></label><span class="mac-tracker-auto-save-status" data-visual-controls-status aria-live="polite">Saved</span></form></details></div></section>
		<?php endif; ?>
		<form class="mac-tracker-visual-work" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_requeue_visuals' ); ?><input type="hidden" name="action" value="mac_tracker_requeue_visuals"><?php if ( 'locked' !== $section ) : ?><section class="mac-tracker-visual-tools" aria-label="Visual review actions"><div><p class="mac-tracker-eyebrow">Actions for this view</p><strong>Select cards, or leave empty to apply to this page</strong><span>Analyze reuses a stored capture. Capture + analyze only continues after a fresh capture succeeds.</span></div><div class="mac-tracker-visual-tools__actions"><label class="mac-tracker-select-all"><input type="checkbox" data-visual-select-all><span>Select page</span></label><?php if ( 'review' === $section ) : ?><button class="button button-primary" type="submit" name="visual_action" value="approve_selected" data-visual-bulk data-visual-label="Approve">Approve page</button><?php endif; ?><button class="button" type="submit" name="visual_action" value="reanalyze_selected" data-visual-bulk data-visual-label="Analyze">Analyze page</button><button class="button" type="submit" name="visual_action" value="recapture_selected" data-visual-bulk data-visual-label="Capture">Capture page</button><?php if ( 'processing' === $section ) : ?><button class="button" type="submit" name="visual_action" value="local_retry_selected" data-visual-bulk data-visual-label="Capture with local">Capture with local</button><?php endif; ?><button class="button button-primary" type="submit" name="visual_action" value="capture_analyze_selected" data-visual-bulk data-visual-label="Capture + analyze">Capture + analyze page</button><?php if ( 'processing' === $section ) : ?><button class="button" type="submit" name="visual_action" value="retry_failed">Retry failed</button><?php endif; ?></div></section><?php endif; ?><section class="mac-tracker-visual-gallery" aria-label="Visual tone gallery"><?php if ( empty( $page['rows'] ) ) : ?><div class="mac-tracker-empty"><strong>No cards in this view</strong><p>When a relevant capture or AI result is ready, it will appear here.</p></div><?php else : $initial_rows = array_slice( $page['rows'], 0, 24 ); $deferred_rows = array_slice( $page['rows'], 24 ); foreach ( $initial_rows as $row ) : $this->render_visual_card( $row ); endforeach; if ( $deferred_rows ) : ob_start(); foreach ( $deferred_rows as $row ) : $this->render_visual_card( $row ); endforeach; $deferred_markup = ob_get_clean(); ?><template data-mac-lazy-cards><?php echo $deferred_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template><button class="button mac-tracker-lazy-load" type="button" data-mac-lazy-load>Load <?php echo esc_html( min( 24, count( $deferred_rows ) ) ); ?> more cards</button><?php endif; endif; ?></section></form>
		<footer class="mac-tracker-analysis-footer"><?php $this->analysis_page_size_control( 'visual', $section, $per_page ); ?><?php $this->analysis_pagination( $page, array( 'section' => $section, 'visual_per_page' => $this->presentation_page_request( $_GET['visual_per_page'] ?? 100 ) ), 'visual_page' ); ?></footer>
		<?php
	}

	private function render_visual_card( array $row ) {
		$valid_tone = 'classified' === (string) ( $row['tone_status'] ?? '' ) && in_array( (string) ( $row['tone'] ?? '' ), $this->tone_options(), true ) && 'Cần duyệt' !== (string) ( $row['tone'] ?? '' );
		$is_locked = ! empty( $row['human_locked'] );
		$diagnostic_url = ! empty( $row['diagnostic_screenshot_url'] ) ? (string) $row['diagnostic_screenshot_url'] : '';
		$image_url = ! empty( $row['screenshot_url'] ) ? (string) $row['screenshot_url'] : $diagnostic_url;
		$local_retry = in_array( (string) ( $row['last_error_code'] ?? '' ), array( 'HTTP_401', 'HTTP_403', 'CF_CHALLENGE', 'CAPTCHA' ), true ) && empty( $row['manual_locked'] ) && ! $is_locked;
		$blocked_capture = ! $image_url && in_array( (string) ( $row['pipeline_status'] ?? '' ), array( 'blocked', 'failed', 'retry_wait' ), true );
		?>
		<article class="mac-tracker-visual-card<?php echo $this->visual_is_manual( $row ) ? ' is-manual-tone' : ''; ?><?php echo $blocked_capture ? ' is-blocked-capture' : ''; ?>" id="visual-<?php echo (int) $row['id']; ?>" data-visual-card="<?php echo (int) $row['id']; ?>" data-visual-local-retry="<?php echo $local_retry ? '1' : '0'; ?>" data-visual-error-code="<?php echo esc_attr( (string) ( $row['last_error_code'] ?? '' ) ); ?>">
			<?php if ( ! $is_locked ) : ?><label class="mac-tracker-visual-card__select"><input type="checkbox" name="snapshot_ids[]" value="<?php echo (int) $row['id']; ?>" data-visual-select><span>Select</span></label><?php endif; ?>
			<?php if ( $image_url ) : ?><a class="mac-tracker-visual-card__image<?php echo $diagnostic_url && empty( $row['screenshot_url'] ) ? ' is-diagnostic' : ''; ?>" href="<?php echo esc_url( $image_url ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $row['name'] . ( $diagnostic_url && empty( $row['screenshot_url'] ) ? ' diagnostic screenshot' : ' homepage screenshot' ) ); ?>" loading="lazy"><span><?php echo $diagnostic_url && empty( $row['screenshot_url'] ) ? 'Open diagnostic capture' : 'Open full capture'; ?></span></a><?php elseif ( $blocked_capture ) : ?><div class="mac-tracker-visual-card__placeholder mac-tracker-visual-card__placeholder--blocked"><strong>Capture blocked</strong><span><?php echo esc_html( $row['last_error_code'] ?: 'Capture failed' ); ?></span><small><?php echo $local_retry ? 'Local retry advised' : 'Retry is available when the site responds.'; ?></small></div><?php else : ?><div class="mac-tracker-visual-card__placeholder"><span class="dashicons dashicons-format-image"></span><span>Capture not available</span></div><?php endif; ?>
			<div class="mac-tracker-visual-card__body"><p class="mac-tracker-eyebrow">#<?php echo esc_html( $row['wpm_project_id'] ); ?> · <?php echo esc_html( MAC_Tracker_Time::bangkok_date( $row['task_completed_at'] ) ); ?></p><?php $this->project_link( $row ); ?><div class="mac-tracker-visual-domain"><?php $this->url_link( $row['website_url'], $this->website_label( $row['website_url'] ) ); ?></div><?php if ( $diagnostic_url && empty( $row['screenshot_url'] ) ) : ?><p class="mac-tracker-visual-diagnostic-label">Blocked · <?php echo esc_html( $row['last_error_code'] ); ?><?php echo ! empty( $row['runner_type'] ) ? ' · ' . esc_html( $row['runner_type'] ) : ''; ?></p><?php endif; ?><div class="mac-tracker-visual-card__tone"><?php $this->tone_cell( $row, false ); ?></div><?php $this->visual_status_cell( $row ); ?><?php if ( ! $this->visual_is_manual( $row ) ) : ?><p class="mac-tracker-visual-reason" data-visual-reason><?php echo esc_html( $row['tone_reason'] ?: ( 'analysis_queued' === $row['pipeline_status'] ? 'Queued for AI analysis.' : ( 'classified' === $row['tone_status'] ? 'AI classified the rendered UI.' : 'Awaiting a manual analysis action.' ) ) ); ?></p><?php endif; ?><?php $this->visual_debug_panel( $row ); ?></div>
			<div class="mac-tracker-visual-card__quick-actions"><?php if ( $is_locked ) : ?><button type="submit" class="button button-small" name="visual_action" value="unlock_tone_<?php echo (int) $row['id']; ?>">Unlock AI</button><?php else : ?><?php if ( ! $is_locked && $valid_tone ) : ?><button type="submit" class="button button-primary button-small" name="visual_action" value="approve_one_<?php echo (int) $row['id']; ?>">Approve</button><?php endif; ?><button type="submit" class="button button-small" name="visual_action" value="reanalyze_one_<?php echo (int) $row['id']; ?>">Analyze again</button><button type="submit" class="button button-small" name="visual_action" value="<?php echo $local_retry ? 'local_retry_one_' : 'recapture_one_'; ?><?php echo (int) $row['id']; ?>"><?php echo $local_retry ? 'Capture with local' : 'Capture again'; ?></button><button type="submit" class="button button-small mac-tracker-button--skip" name="visual_action" value="skip_one_<?php echo (int) $row['id']; ?>">Skip project</button><?php endif; ?></div>
		</article>
		<?php
	}

	private function analysis_page_size_control( $kind, $section, $per_page ) {
		$kind = 'color' === $kind ? 'color' : 'visual';
		$all_selected = 'all' === $this->presentation_page_request( $_GET[ $kind . '_per_page' ] ?? 100 );
		?>
		<label class="mac-tracker-analysis-page-size"><span>Rows</span><select aria-label="Rows per page" data-mac-fragment-page-size data-mac-fragment-target="ai-panel" data-mac-fragment-section="<?php echo esc_attr( $section ); ?>"<?php echo 'color' === $kind ? ' data-mac-color-status="' . esc_attr( isset( $_GET['color_status'] ) && 'approved' === $_GET['color_status'] ? 'approved' : 'pending' ) . '"' : ''; ?>>
			<?php foreach ( array( 25, 50, 100, 200 ) as $size ) : ?><option value="<?php echo esc_attr( $size ); ?>" <?php selected( (int) $per_page, $size ); ?>><?php echo esc_html( $size ); ?></option><?php endforeach; ?>
			<option value="all"<?php selected( $all_selected, true ); ?>>All</option>
		</select></label>
		<?php
	}

	private function analysis_pagination( array $page, array $args, $page_key ) {
		if ( empty( $page ) || (int) ( $page['total_pages'] ?? 1 ) <= 1 ) { return; }
		$current = (int) $page['paged'];
		$total_pages = (int) $page['total_pages'];
		$start = ( ( $current - 1 ) * (int) $page['per_page'] ) + 1;
		$end = min( (int) $page['total'], $current * (int) $page['per_page'] );
		echo '<nav class="mac-tracker-analysis-pagination" aria-label="Analysis pages"><span>Showing ' . esc_html( number_format_i18n( $start ) ) . '–' . esc_html( number_format_i18n( $end ) ) . ' of ' . esc_html( number_format_i18n( $page['total'] ) ) . '</span><div class="mac-tracker-analysis-pagination__controls">';
		$section = sanitize_key( $args['section'] ?? 'processing' );
		$requested_size = 'action' === $section ? ( $args['color_per_page'] ?? 100 ) : ( $args['visual_per_page'] ?? 100 );
		$fragment_attrs = ' data-mac-fragment-target="ai-panel" data-mac-fragment-section="' . esc_attr( $section ) . '" data-mac-page-size="' . esc_attr( $this->presentation_page_request( $requested_size ) ) . '"';
		if ( 'action' === $section ) {
			$fragment_attrs .= ' data-mac-color-status="' . esc_attr( 'approved' === ( $args['color_status'] ?? '' ) ? 'approved' : 'pending' ) . '"';
		}
		if ( $current > 1 ) { echo '<a class="mac-tracker-page-arrow"' . $fragment_attrs . ' data-mac-fragment-page="' . esc_attr( $current - 1 ) . '" href="' . esc_url( $this->app_url( 'analysis', array_merge( $args, array( $page_key => $current - 1 ) ) ) ) . '" aria-label="Previous page">‹</a>'; }
		foreach ( array_unique( array_filter( array( 1, $current - 1, $current, $current + 1, $total_pages ), static function ( $value ) use ( $total_pages ) { return $value >= 1 && $value <= $total_pages; } ) ) as $number ) {
			if ( $number > 1 && $number < $total_pages && abs( $number - $current ) > 1 ) { continue; }
			if ( 1 !== $number && $number !== $total_pages && 1 === abs( $number - $current ) && $current > 3 && $number === $current - 1 ) { echo '<i aria-hidden="true">…</i>'; }
			echo $number === $current ? '<strong aria-current="page">' . esc_html( $number ) . '</strong>' : '<a' . $fragment_attrs . ' data-mac-fragment-page="' . esc_attr( $number ) . '" href="' . esc_url( $this->app_url( 'analysis', array_merge( $args, array( $page_key => $number ) ) ) ) . '">' . esc_html( $number ) . '</a>';
			if ( $number === $current + 1 && $number < $total_pages - 1 ) { echo '<i aria-hidden="true">…</i>'; }
		}
		if ( $current < $total_pages ) { echo '<a class="mac-tracker-page-arrow"' . $fragment_attrs . ' data-mac-fragment-page="' . esc_attr( $current + 1 ) . '" href="' . esc_url( $this->app_url( 'analysis', array_merge( $args, array( $page_key => $current + 1 ) ) ) ) . '" aria-label="Next page">›</a>'; }
		echo '</div></nav>';
	}

	public function render_settings() {
		$this->require_capability();
		$settings = (array) get_option( 'mac_tracker_settings', array() );
		$visual_mode = 'auto' === get_option( 'mac_tracker_visual_mode', 'manual' ) ? 'auto' : 'manual';
		$ai_strategy = in_array( get_option( 'mac_tracker_visual_ai_strategy', 'smart' ), array( 'off', 'qwen', 'gemini', 'smart' ), true ) ? get_option( 'mac_tracker_visual_ai_strategy', 'smart' ) : 'smart';
		$classifier_mode = in_array( get_option( 'mac_tracker_visual_classifier_mode', 'direct_vision' ), array( 'legacy', 'benchmark_only', 'direct_vision' ), true ) ? get_option( 'mac_tracker_visual_classifier_mode', 'direct_vision' ) : 'direct_vision';
		$automation = $this->repository->visual_automation_observability();
		$this->page_start( 'Settings', 'Connections, imports, automation and data controls.', 'settings' );
		$this->notices();
		?>
		<nav class="mac-tracker-settings-tabs" aria-label="Settings sections" role="tablist"><button type="button" class="is-active" role="tab" aria-selected="true" data-settings-tab="appearance">Appearance</button><button type="button" role="tab" aria-selected="false" data-settings-tab="import">Import</button><button type="button" role="tab" aria-selected="false" data-settings-tab="connections">Connections</button><button type="button" role="tab" aria-selected="false" data-settings-tab="automation">Automation</button><button type="button" role="tab" aria-selected="false" data-settings-tab="data">Data Management</button></nav>
		<section class="mac-tracker-settings-panel" data-settings-panel="appearance" role="tabpanel"><?php $this->render_appearance_content(); ?></section>
		<section class="mac-tracker-settings-panel" data-settings-panel="import" role="tabpanel" hidden><?php $this->render_pin_import_content(); ?></section>
		<section class="mac-tracker-settings-panel" data-settings-panel="connections" role="tabpanel" hidden>
			<div class="mac-tracker-panel"><div class="mac-tracker-panel__head"><div><p class="mac-tracker-eyebrow">Private connections</p><h2>Connection credentials</h2><p>Secrets stay encrypted in this WordPress database and are never displayed again.</p></div></div>
			<form class="mac-tracker-settings-form mac-tracker-connection-grid" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mac_tracker_save_settings' ); ?><input type="hidden" name="action" value="mac_tracker_save_settings">
				<section class="mac-tracker-connection-card"><header><span class="dashicons dashicons-database"></span><div><h3>WPM</h3><p>Project snapshot source</p></div><strong class="<?php echo ! empty( $settings['wpm_endpoint'] ) && get_option( 'mac_tracker_wpm_secret', '' ) ? 'is-connected' : ''; ?>"><?php echo ! empty( $settings['wpm_endpoint'] ) && get_option( 'mac_tracker_wpm_secret', '' ) ? 'Configured' : 'Needs setup'; ?></strong></header><label><span>Endpoint</span><input type="url" name="wpm_endpoint" required placeholder="https://wpm.macusaone.com/api/v1/tracking-template/projects" value="<?php echo esc_attr( $settings['wpm_endpoint'] ?? '' ); ?>"></label><label><span>Tracking header secret</span><input type="password" name="wpm_secret" autocomplete="new-password" placeholder="<?php echo get_option( 'mac_tracker_wpm_secret', '' ) ? 'Saved — leave blank to keep it' : 'Paste API value'; ?>"></label><small>Blank secrets keep the saved encrypted value.</small></section>
				<section class="mac-tracker-connection-card" id="mac-tracker-github-dispatch"><header><span class="dashicons dashicons-cloud"></span><div><h3>GitHub</h3><p>Workflow monitor and dispatch</p></div><strong class="<?php echo get_option( 'mac_tracker_github_dispatch_token', '' ) ? 'is-connected' : ''; ?>"><?php echo get_option( 'mac_tracker_github_dispatch_token', '' ) ? 'Configured' : 'Needs setup'; ?></strong></header><label><span>Visual Tone token</span><input type="password" name="github_dispatch_token" autocomplete="new-password" placeholder="<?php echo get_option( 'mac_tracker_github_dispatch_token', '' ) ? 'Saved — leave blank to keep it' : 'Fine-grained token'; ?>"></label><small>Requires Actions read/write for monitor, run, cancel and re-run.</small></section>
				<section class="mac-tracker-connection-card"><header><span class="dashicons dashicons-shield"></span><div><h3>Automation Secret</h3><p>Private worker authentication</p></div><strong class="<?php echo get_option( 'mac_tracker_visual_secret', '' ) ? 'is-connected' : ''; ?>"><?php echo get_option( 'mac_tracker_visual_secret', '' ) ? 'Configured' : 'Needs setup'; ?></strong></header><label><span>Shared secret</span><input type="password" name="visual_secret" autocomplete="new-password" placeholder="<?php echo get_option( 'mac_tracker_visual_secret', '' ) ? 'Saved — leave blank to keep it' : 'Paste a long random value'; ?>"></label><small>Must match <code>MAC_TRACKER_AUTOMATION_SECRET</code>.</small></section>
				<div class="mac-tracker-settings-note"><span class="dashicons dashicons-shield"></span><p>Credentials are encrypted at rest with this WordPress site's authentication salt. They are not rendered in this page or written to sync logs.</p></div>
				<div class="mac-tracker-settings-actions"><button class="button button-primary" type="submit">Save connection</button></div>
			</form></div>
			<section class="mac-tracker-connection-check" aria-label="Connection test"><div><p class="mac-tracker-eyebrow">Safe check</p><h2>Test before syncing</h2><p>Checks one WPM response only. It does not create, update, or remove project snapshots.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_test_connection' ); ?><input type="hidden" name="action" value="mac_tracker_test_connection"><button class="button" type="submit">Test connection</button></form></section>
		</section>
		<section class="mac-tracker-settings-panel" data-settings-panel="automation" role="tabpanel" hidden><div class="mac-tracker-automation-summary"><div><p class="mac-tracker-eyebrow">Automation control room</p><h2>Visual Tone automation</h2><p><strong><?php echo esc_html( strtoupper( $visual_mode ) ); ?></strong> · <?php echo esc_html( 'smart' === $ai_strategy ? 'Qwen → Llama → Gemini' : strtoupper( $ai_strategy ) ); ?> · <?php echo esc_html( 'direct_vision' === $classifier_mode ? 'Direct Vision' : 'Legacy classifier' ); ?></p><p>Scheduled every 15 minutes. Controls remain in AI Analysis so this page stays a concise operational summary.</p></div><dl><div><dt>Last scheduled</dt><dd><?php echo esc_html( ! empty( $automation['last_scheduled_at'] ) ? MAC_Tracker_Time::bangkok_label( $automation['last_scheduled_at'] ) : 'Not recorded' ); ?></dd></div><div><dt>Last successful</dt><dd><?php echo esc_html( ! empty( $automation['last_success_at'] ) ? MAC_Tracker_Time::bangkok_label( $automation['last_success_at'] ) : 'Not recorded' ); ?></dd></div><div><dt>Processed / 60m</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $automation['processed_60m'] ?? 0 ) ) ); ?> projects</dd></div><div><dt>Queue</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $automation['queue_size'] ?? 0 ) ) ); ?> projects</dd></div><div><dt>Retries</dt><dd><?php echo esc_html( max( 0, min( 3, absint( get_option( 'mac_tracker_visual_max_capture_retries', 3 ) ) ) ) ); ?></dd></div><div><dt>Schedule</dt><dd>Every 15 minutes</dd></div></dl><a class="button button-primary" href="<?php echo esc_url( $this->app_url( 'analysis', array( 'section' => 'processing' ) ) ); ?>">Open AI Analysis controls</a></div></section>
		<section class="mac-tracker-settings-panel" id="data-management" data-settings-panel="data" role="tabpanel" hidden><?php $this->render_data_management_content(); ?></section>
		<?php $this->page_end();
	}

	public function render_skipped_projects() {
		$this->require_capability();
		$rows = $this->repository->excluded_projects();
		$this->page_start( 'Skipped Projects', 'Projects excluded from automated visual processing.', 'skipped' );
		$this->notices();
		?>
		<section class="mac-tracker-skipped-toolbar"><div><p class="mac-tracker-eyebrow">Exclusion registry</p><h2><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?> skipped project<?php echo 1 === count( $rows ) ? '' : 's'; ?></h2></div><label><span class="screen-reader-text">Search skipped projects</span><span class="dashicons dashicons-search"></span><input type="search" placeholder="Search project or website" data-skipped-search></label></section>
		<section class="mac-tracker-table-shell">
			<table class="widefat mac-tracker-skipped-table" data-skipped-table>
				<thead><tr><th>Project</th><th>WPM ID</th><th>Website</th><th>Reason</th><th>Skipped by</th><th>Skipped at</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?><tr><td colspan="7">No skipped projects.</td></tr>
				<?php else : foreach ( $rows as $row ) : ?><tr data-skipped-row>
					<td><a href="<?php echo esc_url( $this->app_url( 'projects', array( 'search' => (string) $row['wpm_project_id'] ) ) ); ?>"><?php echo esc_html( $row['project_name'] ); ?></a></td>
					<td><?php echo (int) $row['wpm_project_id']; ?></td>
					<td><?php $this->url_link( $row['website_url'], $this->website_label( $row['website_url'] ) ); ?></td>
					<td><?php echo esc_html( $row['reason'] ); ?></td>
					<td><?php echo esc_html( $row['created_by_name'] ?: ( $row['created_by'] ? '#' . (int) $row['created_by'] : 'System' ) ); ?></td>
					<td><?php echo esc_html( MAC_Tracker_Time::bangkok_label( $row['created_at'] ) ); ?></td>
					<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_restore_exclusion' ); ?><input type="hidden" name="action" value="mac_tracker_restore_exclusion"><input type="hidden" name="wpm_project_id" value="<?php echo (int) $row['wpm_project_id']; ?>"><button class="button" type="submit">Khôi phục</button></form></td>
				</tr><?php endforeach; endif; ?>
				</tbody>
			</table>
		</section><?php
		$this->page_end();
	}

	/** Shell only: JavaScript reads GitHub/local run data after the admin page has rendered. */
	private function render_visual_workflow_monitor() {
		?>
		<section class="mac-tracker-workflow-monitor" aria-label="Visual Tone workflow monitor" data-workflow-monitor>
			<div class="mac-tracker-workflow-monitor__head"><div><p class="mac-tracker-eyebrow">Workflow monitor</p><h2>Visual Tone workflow runs</h2><p>Live execution from GitHub Actions with local Visual Tone progress.</p><p class="mac-tracker-workflow-monitor__refreshed" data-workflow-refreshed>Last refresh: —</p></div><div class="mac-tracker-workflow-monitor__head-actions"><button type="button" class="button" data-workflow-refresh><span class="dashicons dashicons-update"></span>Refresh</button><button type="button" class="button" data-workflow-view-all aria-expanded="true">Hide workflows ▴</button></div></div>
			<div class="mac-tracker-workflow-summary" data-workflow-summary aria-live="polite"><span class="is-running" data-workflow-metric="running_batches"><i></i>Running <strong>—</strong><small>batches</small></span><span class="is-queued" data-workflow-metric="queued_batches"><i></i>Queued <strong>—</strong><small>batches</small></span><span class="is-success" data-workflow-metric="success_projects"><i></i>Done / 60m <strong>—</strong><small>projects</small></span><span class="is-review" data-workflow-metric="needs_review_projects"><i></i>Review / 60m <strong>—</strong><small>projects</small></span><span class="is-failed" data-workflow-metric="failed_projects"><i></i>Failed / 60m <strong>—</strong><small>projects</small></span></div>
			<p class="mac-tracker-workflow-monitor__scope" data-workflow-scope>Project totals use the rolling last 60 minutes. Running/Queued are active workflow batches.</p>
			<p class="mac-tracker-workflow-monitor__status" data-workflow-notice hidden></p>
			<div class="mac-tracker-workflow-list" data-workflow-list></div>
			<div class="mac-tracker-workflow-pagination" data-workflow-pagination hidden><span data-workflow-showing></span><div class="mac-tracker-workflow-pagination__controls"><button type="button" class="button button-small" data-workflow-prev>‹ Previous</button><span data-workflow-page></span><button type="button" class="button button-small" data-workflow-next>Next ›</button></div></div>
		</section>
		<div class="mac-tracker-workflow-detail" data-workflow-detail hidden role="dialog" aria-modal="true" aria-labelledby="mac-tracker-workflow-detail-title"><div class="mac-tracker-workflow-detail__panel"><button type="button" class="mac-tracker-workflow-detail__close" data-workflow-close aria-label="Close workflow details"><span class="dashicons dashicons-no-alt"></span></button><div data-workflow-detail-content></div></div></div>
		<?php
	}

	public function handle_save_settings() {
		$this->require_request( 'mac_tracker_save_settings' );
		$endpoint = isset( $_POST['wpm_endpoint'] ) ? trim( (string) wp_unslash( $_POST['wpm_endpoint'] ) ) : '';
		$endpoint = MAC_Tracker_WPM_Client::normalize_endpoint( $endpoint );
		if ( is_wp_error( $endpoint ) ) {
			$this->redirect( 'mac-project-tracker-settings', $endpoint->get_error_message(), 'error' );
		}
		update_option( 'mac_tracker_settings', array( 'wpm_endpoint' => $endpoint ), false );
		$secret = isset( $_POST['wpm_secret'] ) ? trim( (string) wp_unslash( $_POST['wpm_secret'] ) ) : '';
		if ( '' !== $secret ) {
			$encrypted = MAC_Tracker_Crypto::encrypt( $secret );
			if ( is_wp_error( $encrypted ) ) { $this->redirect( 'mac-project-tracker-settings', $encrypted->get_error_message(), 'error' ); }
			update_option( 'mac_tracker_wpm_secret', $encrypted, false );
		}
		$visual_secret = isset( $_POST['visual_secret'] ) ? trim( (string) wp_unslash( $_POST['visual_secret'] ) ) : '';
		if ( '' !== $visual_secret ) {
			$encrypted = MAC_Tracker_Crypto::encrypt( $visual_secret );
			if ( is_wp_error( $encrypted ) ) { $this->redirect( 'mac-project-tracker-settings', $encrypted->get_error_message(), 'error' ); }
			update_option( 'mac_tracker_visual_secret', $encrypted, false );
		}
		$github_token = isset( $_POST['github_dispatch_token'] ) ? trim( (string) wp_unslash( $_POST['github_dispatch_token'] ) ) : '';
		if ( '' !== $github_token ) {
			$encrypted = MAC_Tracker_Crypto::encrypt( $github_token );
			if ( is_wp_error( $encrypted ) ) { $this->redirect( 'mac-project-tracker-settings', $encrypted->get_error_message(), 'error' ); }
			update_option( 'mac_tracker_github_dispatch_token', $encrypted, false );
		}
		$this->sync->ensure_hourly_schedule();
		$this->redirect( 'mac-project-tracker-settings', 'Connection saved. Background sync can now be queued.', 'success' );
	}

	/** Persist the UI palette only; this endpoint intentionally has no operational side effects. */
	public function handle_save_ui_theme_ajax() {
		check_ajax_referer( 'mac_tracker_save_ui_theme', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to change appearance.' ), 403 );
		}
		$theme = $this->sanitize_ui_theme( wp_unslash( $_POST['theme'] ?? '' ) );
		update_option( 'mac_tracker_ui_theme', $theme, false );
		wp_send_json_success( array( 'theme' => $theme, 'message' => 'Appearance saved.' ) );
	}

	/**
	 * Protected presentation fragments. They deliberately reuse the normal
	 * renderers and never process an operational action or mutate stored data.
	 */
	public function handle_load_fragment_ajax() {
		check_ajax_referer( 'mac_tracker_load_fragment', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to load this view.' ), 403 );
		}
		$target = sanitize_key( wp_unslash( $_POST['target'] ?? '' ) );
		if ( ! in_array( $target, array( 'ai-panel' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown view fragment.' ), 400 );
		}
		if ( ! empty( $_POST['standalone'] ) ) {
			$this->set_standalone( true );
		}
		$section = sanitize_key( wp_unslash( $_POST['section'] ?? 'processing' ) );
		$section = in_array( $section, array( 'action', 'processing', 'review', 'locked', 'workflow' ), true ) ? $section : 'processing';
		$_GET['visual_page'] = max( 1, absint( wp_unslash( $_POST['visual_page'] ?? 1 ) ) );
		$_GET['visual_per_page'] = $this->presentation_page_request( wp_unslash( $_POST['visual_per_page'] ?? 100 ) );
		$_GET['color_page'] = max( 1, absint( wp_unslash( $_POST['color_page'] ?? 1 ) ) );
		$_GET['color_per_page'] = $this->presentation_page_request( wp_unslash( $_POST['color_per_page'] ?? 100 ) );
		$_GET['color_status'] = 'approved' === sanitize_key( wp_unslash( $_POST['color_status'] ?? '' ) ) ? 'approved' : 'pending';
		$_GET['color_search'] = sanitize_text_field( wp_unslash( $_POST['color_search'] ?? '' ) );
		ob_start();
		$this->render_ai_fragment( $section );
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html, 'section' => $section ) );
	}

	/**
	 * Return one bounded project-table chunk for the presentation-only All mode.
	 * The same local query and row markup are used as the initial Projects page;
	 * this endpoint never writes a snapshot or changes a project.
	 */
	public function handle_load_project_rows_ajax() {
		check_ajax_referer( 'mac_tracker_load_project_rows', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to load project records.' ), 403 );
		}
		$raw_filters = json_decode( wp_unslash( $_POST['filters'] ?? '' ), true );
		if ( ! is_array( $raw_filters ) ) {
			wp_send_json_error( array( 'message' => 'The project filter state is invalid.' ), 400 );
		}
		$filters = $this->project_filters_from_values( $raw_filters );
		$filters['per_page'] = 100;
		$filters['all_mode'] = true;
		$filters['paged'] = max( 1, absint( wp_unslash( $_POST['paged'] ?? 1 ) ) );
		$page = $this->repository->project_page( $filters );
		ob_start();
		foreach ( (array) $page['rows'] as $row ) {
			$this->render_project_row( $row );
		}
		$html = ob_get_clean();
		wp_send_json_success( array(
			'html' => $html,
			'paged' => (int) $page['paged'],
			'next_page' => (int) $page['paged'] + 1,
			'has_more' => (int) $page['paged'] < (int) $page['total_pages'],
			'shown' => min( (int) $page['total'], (int) $page['paged'] * (int) $page['per_page'] ),
			'total' => (int) $page['total'],
		) );
	}

	public function handle_run_visual_workflow() {
		$this->require_request( 'mac_tracker_run_visual_workflow' );
		$result = $this->dispatch_visual_workflow();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'mac-project-tracker-visuals', $result->get_error_message(), 'error' );
		}
		$this->redirect( 'mac-project-tracker-visuals', 'Visual Tone workflow started. The gallery will update after GitHub finishes the batch.', 'success' );
	}

	/** Return the GitHub dispatch result directly to the in-page Run button. */
	public function handle_run_visual_workflow_ajax() {
		check_ajax_referer( 'mac_tracker_run_visual_workflow', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to start a Visual Tone workflow.' ), 403 ); }
		$result = $this->dispatch_visual_workflow();
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 502 ); }
		wp_send_json_success( array( 'message' => 'GitHub workflow dispatched. Queued cards will change only after a worker claims them.' ) );
	}

	/** Save Visual Tone controls. The scheduled worker reads this config before claiming work. */
	public function handle_save_visual_mode() {
		$this->require_request( 'mac_tracker_save_visual_mode' );
		$mode = isset( $_POST['visual_mode'] ) && 'auto' === sanitize_key( wp_unslash( $_POST['visual_mode'] ) ) ? 'auto' : 'manual';
		$strategy = isset( $_POST['ai_strategy'] ) ? sanitize_key( wp_unslash( $_POST['ai_strategy'] ) ) : 'smart';
		if ( ! in_array( $strategy, array( 'off', 'qwen', 'gemini', 'smart' ), true ) ) { $strategy = 'smart'; }
		$auto_accept = isset( $_POST['auto_accept'] ) ? (float) wp_unslash( $_POST['auto_accept'] ) : 0.85;
		$auto_accept = max( 0.75, min( 0.99, $auto_accept ) );
		$max_retries = isset( $_POST['max_capture_retries'] ) ? max( 0, min( 3, absint( wp_unslash( $_POST['max_capture_retries'] ) ) ) ) : 3;
		$classifier_mode = isset( $_POST['classifier_mode'] ) ? sanitize_key( wp_unslash( $_POST['classifier_mode'] ) ) : get_option( 'mac_tracker_visual_classifier_mode', 'direct_vision' );
		if ( ! in_array( $classifier_mode, array( 'legacy', 'benchmark_only', 'direct_vision' ), true ) ) { $classifier_mode = 'direct_vision'; }
		update_option( 'mac_tracker_visual_mode', $mode, false );
		update_option( 'mac_tracker_visual_ai_strategy', $strategy, false );
		update_option( 'mac_tracker_visual_auto_accept', $auto_accept, false );
		update_option( 'mac_tracker_visual_max_capture_retries', $max_retries, false );
		update_option( 'mac_tracker_visual_classifier_mode', $classifier_mode, false );
		update_option( 'mac_tracker_visual_classifier_mode_explicit', 1, false );
		$this->redirect( 'mac-project-tracker-visuals', sprintf( 'Visual Tone controls saved: %s mode, %s AI strategy.', strtoupper( $mode ), strtoupper( $strategy ) ), 'success' );
	}

	public function handle_save_visual_controls_ajax() {
		check_ajax_referer( 'mac_tracker_save_visual_controls', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to change Visual Tone controls.' ), 403 ); }
		$mode = 'auto' === sanitize_key( wp_unslash( $_POST['visual_mode'] ?? '' ) ) ? 'auto' : 'manual';
		$strategy = sanitize_key( wp_unslash( $_POST['ai_strategy'] ?? 'smart' ) );
		if ( ! in_array( $strategy, array( 'off', 'qwen', 'gemini', 'smart' ), true ) ) { $strategy = 'smart'; }
		$threshold = max( 0.75, min( 0.99, (float) wp_unslash( $_POST['auto_accept'] ?? 0.85 ) ) );
		$retries = max( 0, min( 3, absint( $_POST['max_capture_retries'] ?? 3 ) ) );
		$classifier_mode = sanitize_key( wp_unslash( $_POST['classifier_mode'] ?? get_option( 'mac_tracker_visual_classifier_mode', 'direct_vision' ) ) );
		if ( ! in_array( $classifier_mode, array( 'legacy', 'benchmark_only', 'direct_vision' ), true ) ) { $classifier_mode = 'direct_vision'; }
		update_option( 'mac_tracker_visual_mode', $mode, false ); update_option( 'mac_tracker_visual_ai_strategy', $strategy, false ); update_option( 'mac_tracker_visual_auto_accept', $threshold, false ); update_option( 'mac_tracker_visual_max_capture_retries', $retries, false ); update_option( 'mac_tracker_visual_classifier_mode', $classifier_mode, false ); update_option( 'mac_tracker_visual_classifier_mode_explicit', 1, false );
		wp_send_json_success( array( 'message' => 'Saved' ) );
	}

	public function handle_clear_visual_data() {
		$this->require_request( 'mac_tracker_clear_visual_data' );
		$result = $this->repository->clear_visual_data();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'mac-project-tracker-visuals', $result->get_error_message(), 'error' );
		}
		$this->redirect( 'mac-project-tracker-visuals', sprintf( 'Visual Tone reset complete. %d record(s) and their stored screenshots were removed.', (int) $result ), 'success' );
	}

	/** Start one cloud batch for a manual visual action, without holding the browser open. */
	private function dispatch_visual_workflow( $limit = 11, $run_mode = 'batch', $stage = 'full', array $target_ids = array(), $source_action = 'run_batch_now' ) {
		$limit = max( 1, min( 25, absint( $limit ) ) );
		$run_mode = 'targeted' === $run_mode ? 'targeted' : 'batch';
		$stage = in_array( $stage, array( 'capture', 'tone', 'full', 'auto' ), true ) ? $stage : 'full';
		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );
		$target_ids = array_slice( $target_ids, 0, 25 );
		if ( 'targeted' === $run_mode && empty( $target_ids ) ) {
			return new WP_Error( 'mac_tracker_visual_targets', 'A targeted Visual Tone action needs at least one valid selected card.' );
		}
		if ( 'targeted' === $run_mode ) { $limit = min( $limit, count( $target_ids ) ); }
		$result = $this->github_actions->dispatch( array( 'run_mode' => $run_mode, 'stage' => $stage, 'target_ids' => wp_json_encode( $target_ids ), 'source_action' => sanitize_key( $source_action ), 'limit' => (string) $limit ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$this->github_actions->clear_cache();
		return true;
	}

	/** Shared queue operation used by the normal form and the in-page AJAX controls. */
	private function process_visual_action( $action, array $ids, array $manual_tones = array() ) {
		if ( 'approve_selected' === $action ) {
			$result = $this->repository->approve_visual_tones( $ids );
			if ( $result <= 0 ) {
				return array( 'result' => new WP_Error( 'mac_tracker_visual_approve_empty', 'No eligible AI review cards were selected.' ), 'message' => 'No eligible AI review cards were selected.', 'dispatch' => false );
			}
			return array( 'result' => $result, 'message' => sprintf( '%d review card(s) approved and locked.', (int) $result ), 'dispatch' => false );
		}
		if ( preg_match( '/^approve_one_(\d+)$/', $action, $approve_match ) ) {
			$result = $this->repository->approve_visual_tone( absint( $approve_match[1] ) );
			return array( 'result' => $result, 'message' => 'AI tone approved and locked.', 'dispatch' => false );
		}
		if ( preg_match( '/^skip_one_(\d+)$/', $action, $skip_match ) ) {
			$result = $this->repository->exclude_project( absint( $skip_match[1] ), 'Skipped from Visual Tone review.' );
			return array( 'result' => $result, 'message' => 'Project skipped from Visual Tone and Color Review.', 'dispatch' => false );
		}
		if ( preg_match( '/^save_tone_(\d+)$/', $action, $tone_match ) ) {
			$result = $this->repository->save_manual_visual_tone( absint( $tone_match[1] ), $manual_tones[ absint( $tone_match[1] ) ] ?? '' );
			return array( 'result' => $result, 'message' => 'Visual tone reviewed and saved.', 'dispatch' => false );
		}
		if ( preg_match( '/^unlock_tone_(\d+)$/', $action, $unlock_match ) ) {
			$result = $this->repository->unlock_manual_visual_tone( absint( $unlock_match[1] ) );
			return array( 'result' => $result, 'message' => 'Manual tone lock removed. The next AI analysis may update this card.', 'dispatch' => false );
		}
		if ( 'local_retry_selected' === $action || preg_match( '/^local_retry_one_(\d+)$/', $action, $local_match ) ) {
			$local_ids = isset( $local_match[1] ) ? array( absint( $local_match[1] ) ) : array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 25 );
			if ( empty( $local_ids ) ) {
				$error = new WP_Error( 'mac_tracker_local_retry_empty', 'Select at least one blocked security card first.' );
				return array( 'result' => $error, 'message' => $error->get_error_message(), 'dispatch' => false );
			}
			$runner = $this->github_actions->preflight_local_runner();
			if ( is_wp_error( $runner ) ) {
				return array( 'result' => $runner, 'message' => $runner->get_error_message(), 'dispatch' => false, 'dispatch_error' => true );
			}
			$queued = $this->repository->queue_local_visual_retry( $local_ids );
			if ( is_wp_error( $queued ) || empty( $queued ) ) {
				$error = is_wp_error( $queued ) ? $queued : new WP_Error( 'mac_tracker_local_retry_ineligible', 'This card is not eligible for a local security-block retry.' );
				return array( 'result' => $error, 'message' => $error->get_error_message(), 'dispatch' => false );
			}
			$dispatch = $this->github_actions->dispatch_local( array( 'run_mode' => 'targeted', 'stage' => 'full', 'target_ids' => wp_json_encode( $queued ), 'source_action' => 'local_retry', 'limit' => (string) count( $queued ) ) );
			if ( is_wp_error( $dispatch ) ) {
				$this->repository->recover_local_retry_dispatch( $queued, $dispatch->get_error_message() );
				return array( 'result' => $dispatch, 'message' => 'Local runner dispatch failed: ' . $dispatch->get_error_message(), 'dispatch' => false, 'dispatch_error' => true );
			}
			$this->github_actions->clear_cache();
			return array( 'result' => count( $queued ), 'message' => sprintf( '%d blocked card(s) queued for local capture and analysis.', count( $queued ) ), 'dispatch' => true );
		}
		if ( preg_match( '/^(reanalyze_one|recapture_one)_(\d+)$/', $action, $one_match ) ) {
			$action = $one_match[1];
			$ids = array( absint( $one_match[2] ) );
		}
		if ( 'reanalyze_all' === $action ) {
			$queued_ids = $this->repository->requeue_visual_tones_with_ids();
			$result = count( $queued_ids );
			$message = sprintf( '%d stored screenshot(s) queued for AI analysis.', (int) $result );
		} elseif ( 'retry_failed' === $action ) {
			$retry = $this->repository->requeue_failed_visual_items_by_stage();
			$capture_ids = (array) $retry['capture']; $tone_ids = (array) $retry['tone'];
			$result = count( $capture_ids ) + count( $tone_ids );
			if ( ! $result ) { return array( 'result' => 0, 'message' => 'No failed Visual Tone stage is eligible to retry. Ambiguous legacy records require review.', 'dispatch' => false ); }
			$capture_dispatch = empty( $capture_ids ) ? true : $this->dispatch_visual_workflow( count( $capture_ids ), 'targeted', 'capture', $capture_ids, 'retry_failed_capture' );
			$tone_dispatch = empty( $tone_ids ) ? true : $this->dispatch_visual_workflow( count( $tone_ids ), 'targeted', 'tone', $tone_ids, 'retry_failed_analysis' );
			$message = sprintf( 'Retry failed: Capture retries: %d. Analysis retries: %d.', count( $capture_ids ), count( $tone_ids ) );
			if ( is_wp_error( $capture_dispatch ) || is_wp_error( $tone_dispatch ) ) { $message .= ' GitHub could not start one retry scope.'; }
			return array( 'result' => $result, 'message' => $message, 'dispatch' => ! is_wp_error( $capture_dispatch ) && ! is_wp_error( $tone_dispatch ) );
		} else {
			$mode = in_array( $action, array( 'reanalyze_selected', 'reanalyze_one' ), true ) ? 'reanalyze' : ( in_array( $action, array( 'recapture_selected', 'recapture_one', 'capture_analyze_selected' ), true ) ? 'recapture' : '' );
			if ( 'reanalyze' === $mode ) {
				$queued_ids = $this->repository->requeue_visual_analysis_targets( $ids );
				$result = count( $queued_ids );
			} else {
				$result = $this->repository->requeue_visual_items( $ids, $mode );
			}
			$message = sprintf( '%d selected screenshot(s) queued to %s.', is_wp_error( $result ) ? 0 : (int) $result, 'recapture' === $mode ? 'capture again' : 'analyze again' );
		}
		if ( is_wp_error( $result ) || (int) $result <= 0 ) { return array( 'result' => $result, 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'No eligible screenshot changed. Check the selected card status, then try again.', 'dispatch' => false ); }
		$run_mode = 'batch';
		$stage = 'full';
		$dispatch_ids = array();
		if ( in_array( $action, array( 'reanalyze_selected', 'reanalyze_one' ), true ) ) {
			$run_mode = 'targeted';
			$stage = 'tone';
			$dispatch_ids = $queued_ids ?? array();
		} elseif ( in_array( $action, array( 'recapture_selected', 'recapture_one', 'capture_analyze_selected' ), true ) ) {
			$run_mode = 'targeted';
			$stage = 'capture_analyze_selected' === $action ? 'full' : 'capture';
			$dispatch_ids = $ids;
		}
		$source = 'reanalyze_all' === $action ? 'analyze_all_stored' : ( 'tone' === $stage ? 'analyze_selected' : ( 'full' === $stage ? 'capture_and_analyze_selected' : 'capture_selected_again' ) );
		if ( 'analyze_all_stored' === $source ) {
			$dispatch = true;
			$chunks = array_chunk( $queued_ids, 10 );
			foreach ( $chunks as $index => $chunk ) {
				$dispatch = $this->dispatch_visual_workflow( count( $chunk ), 'targeted', 'tone', $chunk, $source );
				if ( is_wp_error( $dispatch ) ) {
					$recover = array_merge( $chunk, ...array_slice( $chunks, $index + 1 ) );
					$this->repository->recover_visual_analysis_dispatch( $recover, $dispatch->get_error_message() );
					break;
				}
			}
		} else {
			$dispatch = $this->dispatch_visual_workflow( (int) $result, $run_mode, $stage, $dispatch_ids, $source );
			if ( is_wp_error( $dispatch ) && 'tone' === $stage ) {
				$this->repository->recover_visual_analysis_dispatch( $dispatch_ids, $dispatch->get_error_message() );
			}
		}
		$message .= is_wp_error( $dispatch ) ? ' ' . $dispatch->get_error_message() : ' GitHub batch started now.';
		return array( 'result' => $result, 'message' => $message, 'dispatch' => ! is_wp_error( $dispatch ), 'dispatch_error' => is_wp_error( $dispatch ) );
	}

	public function handle_visual_action_ajax() {
		check_ajax_referer( 'mac_tracker_requeue_visuals', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to run visual actions.' ), 403 ); }
		$action = sanitize_key( wp_unslash( $_POST['visual_action'] ?? '' ) );
		$ids = isset( $_POST['snapshot_ids'] ) && is_array( $_POST['snapshot_ids'] ) ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['snapshot_ids'] ) ) ) ) : array();
		$manual = isset( $_POST['manual_tone'] ) && is_array( $_POST['manual_tone'] ) ? wp_unslash( $_POST['manual_tone'] ) : array();
		$processed = $this->process_visual_action( $action, $ids, $manual );
		if ( is_wp_error( $processed['result'] ) || (int) $processed['result'] <= 0 || ! empty( $processed['dispatch_error'] ) ) { wp_send_json_error( array( 'message' => $processed['message'] ) ); }
		wp_send_json_success( array( 'message' => $processed['message'], 'ids' => $ids, 'changed' => absint( $processed['result'] ), 'stats' => $this->repository->visual_stats() ) );
	}

	public function handle_visual_status_ajax() {
		check_ajax_referer( 'mac_tracker_visual_status', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to read visual status.' ), 403 ); }
		$wanted = isset( $_POST['snapshot_ids'] ) && is_array( $_POST['snapshot_ids'] ) ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['snapshot_ids'] ) ) ) ) : array();
		$map = array_fill_keys( $wanted, true );
		$statuses = array();
		foreach ( $this->repository->visual_review_rows( 300 ) as $row ) {
			if ( $wanted && empty( $map[ (int) $row['id'] ] ) ) { continue; }
			$manual = $this->visual_is_manual( $row );
			$statuses[] = array( 'id' => (int) $row['id'], 'pipeline_status' => (string) ( $row['pipeline_status'] ?? 'idle' ), 'capture_status' => (string) ( $row['capture_status'] ?? '' ), 'tone_status' => (string) ( $row['tone_status'] ?? '' ), 'tone' => (string) ( $row['tone'] ?? '' ), 'tone_group' => (string) ( $row['tone_group'] ?? '' ), 'precise_tone' => (string) ( $row['precise_tone'] ?? '' ), 'tone_reason' => $manual ? '' : (string) ( $row['tone_reason'] ?? '' ), 'manual' => $manual, 'manual_locked' => ! empty( $row['manual_locked'] ), 'human_locked' => ! empty( $row['human_locked'] ), 'provider' => (string) ( $row['ai_provider'] ?? '' ), 'model' => (string) ( $row['ai_model'] ?? '' ), 'last_error_code' => (string) ( $row['last_error_code'] ?? '' ), 'last_error_message' => (string) ( $row['last_error_message'] ?? '' ), 'next_retry_at' => (string) ( $row['next_retry_at'] ?? '' ), 'screenshot_url' => esc_url_raw( $row['screenshot_url'] ?? '' ), 'diagnostic_screenshot_url' => esc_url_raw( $row['diagnostic_screenshot_url'] ?? '' ), 'runner_type' => sanitize_key( $row['runner_type'] ?? '' ), 'claimed_at' => (string) ( $row['claimed_at'] ?? '' ), 'lease_until' => (string) ( $row['lease_until'] ?? '' ) );
		}
		wp_send_json_success( array( 'statuses' => $statuses, 'stats' => $this->repository->visual_stats() ) );
	}

	/** Async monitor fetch. Rendering Visual Tone never waits for GitHub. */
	public function handle_visual_runs_ajax() {
		check_ajax_referer( 'mac_tracker_visual_workflows', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to read workflow runs.' ), 403 ); }
		$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$requested_per_page = absint( wp_unslash( $_POST['per_page'] ?? 100 ) );
		$per_page = in_array( $requested_per_page, array( 25, 50, 100 ), true ) ? $requested_per_page : 100;
		$force = ! empty( $_POST['force'] );
		$github_page = $this->github_actions->list_visual_runs( $page, $per_page, $force );
		if ( is_wp_error( $github_page ) ) {
			$local_page = $this->repository->visual_runs_page( $page, $per_page );
			$summary = $this->repository->visual_run_summary();
			$summary = array_merge( $summary, array( 'scope' => 'stale_local', 'summary_scope' => 'stale_local', 'stale_count' => $local_page['total'], 'stale' => true ) );
			wp_send_json_success( array( 'runs' => $local_page['runs'], 'summary' => $summary, 'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $local_page['total'], 'total_pages' => $local_page['total_pages'], 'scope' => 'local_history_fallback' ), 'github_error' => $github_page->get_error_message(), 'permissions' => array( 'read' => false, 'write' => false ) ) );
		}
		$this->repository->reconcile_visual_runs( (array) ( $github_page['runs'] ?? array() ) );
		$local_page = $this->repository->visual_runs_for_github_page( (array) ( $github_page['runs'] ?? array() ), $page, $per_page, (int) ( $github_page['total'] ?? 0 ) );
		$total = (int) ( $github_page['total'] ?? 0 );
		if ( empty( $local_page ) ) {
			$fallback_page = $this->repository->visual_runs_page( $page, $per_page );
			if ( ! empty( $fallback_page['runs'] ) ) {
				$local_page = $fallback_page['runs'];
				$total = max( $total, (int) $fallback_page['total'] );
			}
		}
		$display_runs = ! empty( $local_page ) ? $local_page : (array) ( $github_page['runs'] ?? array() );
		$summary = (array) ( $github_page['summary'] ?? array( 'active' => array( 'running_batches' => 0, 'queued_batches' => 0 ), 'scope' => 'github_fresh_page' ) );
		$local_summary = $this->repository->visual_run_summary();
		$summary['active'] = $local_summary['active'];
		$summary['last_60m'] = $local_summary['last_60m'];
		wp_send_json_success( array( 'runs' => $display_runs, 'summary' => $summary, 'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max( 1, (int) ceil( $total / $per_page ) ), 'scope' => 'github_workflow_history' ), 'permissions' => array( 'read' => true, 'write' => true ) ) );
	}

	public function handle_visual_run_detail_ajax() {
		check_ajax_referer( 'mac_tracker_visual_workflows', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to read workflow details.' ), 403 ); }
		$run_id = absint( wp_unslash( $_POST['run_id'] ?? 0 ) );
		$local = $this->repository->visual_run_detail( $run_id );
		$github = $this->github_actions->get_visual_jobs( $run_id );
		if ( is_wp_error( $github ) ) {
			if ( ! $local ) { wp_send_json_error( array( 'message' => $github->get_error_message() ), 404 ); }
			wp_send_json_success( array( 'local' => $local, 'github_error' => $github->get_error_message() ) );
		}
		$this->repository->reconcile_visual_runs( array( $github['run'] ) );
		wp_send_json_success( array( 'local' => $this->repository->visual_run_detail( $run_id ), 'github' => $github ) );
	}

	public function handle_visual_run_log_ajax() {
		check_ajax_referer( 'mac_tracker_visual_workflows', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to read workflow logs.' ), 403 ); }
		$run_id = absint( wp_unslash( $_POST['run_id'] ?? 0 ) );
		$log = $this->github_actions->get_visual_job_log( $run_id );
		if ( is_wp_error( $log ) ) { wp_send_json_error( array( 'message' => $log->get_error_message() ), 502 ); }
		wp_send_json_success( $log );
	}

	public function handle_visual_run_action_ajax() {
		check_ajax_referer( 'mac_tracker_visual_workflows', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to control workflow runs.' ), 403 ); }
		$run_id = absint( wp_unslash( $_POST['run_id'] ?? 0 ) );
		$action = sanitize_key( wp_unslash( $_POST['run_action'] ?? '' ) );
		$result = $this->github_actions->action( $run_id, $action );
		if ( is_wp_error( $result ) ) { $error_data = (array) $result->get_error_data(); wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), (int) ( $error_data['status'] ?? 502 ) ); }
		if ( 'cancel' === $action ) { $this->repository->mark_visual_run_cancelling( $run_id ); }
		wp_send_json_success( array( 'message' => 'cancel' === $action ? 'Cancellation requested. GitHub will confirm the final result.' : ( 'rerun_failed' === $action ? 'GitHub is re-running failed jobs from this execution.' : 'GitHub is re-running the same workflow execution.' ) ) );
	}

	public function handle_requeue_visuals() {
		$this->require_request( 'mac_tracker_requeue_visuals' );
		$action = sanitize_key( wp_unslash( $_POST['visual_action'] ?? '' ) );
		$ids = isset( $_POST['snapshot_ids'] ) && is_array( $_POST['snapshot_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['snapshot_ids'] ) ) : array();
		if ( preg_match( '/^save_tone_(\d+)$/', $action, $tone_match ) ) {
			$snapshot_id = absint( $tone_match[1] );
			$manual_tones = isset( $_POST['manual_tone'] ) && is_array( $_POST['manual_tone'] ) ? wp_unslash( $_POST['manual_tone'] ) : array();
			$result = $this->repository->save_manual_visual_tone( $snapshot_id, $manual_tones[ $snapshot_id ] ?? '' );
			if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker-visuals', $result->get_error_message(), 'error' ); }
			$this->redirect( 'mac-project-tracker-visuals', 'Visual tone reviewed and saved.', 'success' );
		}
		if ( preg_match( '/^unlock_tone_(\d+)$/', $action, $unlock_match ) ) {
			$result = $this->repository->unlock_manual_visual_tone( absint( $unlock_match[1] ) );
			if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker-visuals', $result->get_error_message(), 'error' ); }
			$this->redirect( 'mac-project-tracker-visuals', 'Manual tone lock removed. You can queue AI again now.', 'success' );
		}
		$manual = isset( $_POST['manual_tone'] ) && is_array( $_POST['manual_tone'] ) ? wp_unslash( $_POST['manual_tone'] ) : array();
		$processed = $this->process_visual_action( $action, $ids, $manual );
		if ( is_wp_error( $processed['result'] ) ) { $this->redirect( 'mac-project-tracker-visuals', $processed['message'], 'error' ); }
		$this->redirect( 'mac-project-tracker-visuals', $processed['message'], ! empty( $processed['dispatch'] ) ? 'success' : 'warning' );
	}

	public function handle_approve_visual_tone() {
		$this->require_request( 'mac_tracker_approve_visual_tone' );
		$result = $this->repository->approve_visual_tone( absint( $_POST['snapshot_id'] ?? 0 ) );
		$this->redirect( 'mac-project-tracker-visuals', is_wp_error( $result ) ? $result->get_error_message() : 'AI tone approved and locked.', is_wp_error( $result ) ? 'error' : 'success' );
	}

	public function handle_skip_project() {
		$this->require_request( 'mac_tracker_skip_project' );
		$result = $this->repository->exclude_project( absint( $_POST['snapshot_id'] ?? 0 ), 'Skipped from Visual Tone review.' );
		$screen = 'projects' === sanitize_key( $_POST['return_screen'] ?? '' ) ? 'mac-project-tracker' : 'mac-project-tracker-visuals';
		$this->redirect( $screen, is_wp_error( $result ) ? $result->get_error_message() : 'Project skipped from Visual Tone and Color Review.', is_wp_error( $result ) ? 'error' : 'success' );
	}

	public function handle_restore_exclusion() {
		$this->require_request( 'mac_tracker_restore_exclusion' );
		$result = $this->repository->restore_project_exclusion( absint( $_POST['wpm_project_id'] ?? 0 ) );
		$this->redirect( 'mac-project-tracker-skipped', is_wp_error( $result ) ? $result->get_error_message() : 'Project restored. No automatic processing was started.', is_wp_error( $result ) ? 'error' : 'success' );
	}

	public function handle_test_connection() {
		$this->require_request( 'mac_tracker_test_connection' );
		$result = $this->sync->test_connection();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'mac-project-tracker-settings', $result->get_error_message(), 'error' );
		}
		$this->redirect( 'mac-project-tracker-settings', 'Connection verified. WPM returned a valid project response.', 'success' );
	}

	public function handle_queue_sync() {
		$this->require_request( 'mac_tracker_queue_sync' );
		$result = $this->sync->run_sync( 'sync' );
		if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker', $result->get_error_message(), 'error' ); }
		$this->redirect( 'mac-project-tracker', sprintf( 'Sync complete: %d Action Design task(s) processed; %d new snapshot(s) created.', (int) $result['processed'], (int) $result['created'] ), 'success' );
	}

	public function handle_compare_wpm() {
		$this->require_request( 'mac_tracker_compare_wpm' );
		$result = $this->sync->run_sync( 'compare' );
		if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker', $result->get_error_message(), 'error' ); }
		$message = sprintf( 'Baseline comparison complete: %d Action Design task(s) checked; %d CSV rows now show their WPM Action Design overlay.', (int) $result['processed'], (int) $result['created'] );
		if ( empty( $result['comparison_completed'] ) ) { $message .= ' Comparison remains available because some WPM records could not be processed.'; }
		$this->redirect( 'mac-project-tracker', $message, 'success' );
	}

	public function handle_edit_project() {
		$this->require_request( 'mac_tracker_edit_project' );
		$result = $this->repository->edit_project_identity( $_POST['snapshot_id'] ?? 0, $_POST['project_id'] ?? 0, wp_unslash( $_POST['project_name'] ?? '' ), wp_unslash( $_POST['date_time'] ?? '' ) );
		if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker', $result->get_error_message(), 'error' ); }
		$this->redirect( 'mac-project-tracker', 'Project ID and name updated.', 'success' );
	}

	public function handle_import_pins() {
		$this->require_request( 'mac_tracker_import_pins' );
		if ( empty( $_FILES['pin_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pin_csv']['tmp_name'] ) ) {
			$this->redirect( 'mac-project-tracker-pins', 'Choose a CSV file to import.', 'error' );
		}
		if ( (int) $_FILES['pin_csv']['size'] > 5 * 1024 * 1024 ) {
			$this->redirect( 'mac-project-tracker-pins', 'CSV must be smaller than 5 MB.', 'error' );
		}
		$result = ( new MAC_Tracker_Pin_Import( $this->repository ) )->import_file( $_FILES['pin_csv']['tmp_name'] );
		if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker-pins', $result->get_error_message(), 'error' ); }
		$message = sprintf( 'Read %d CSV rows; saved %d unique CSV pins in a %d-project baseline. Run Compare baseline once to apply matching WPM Action Design snapshots.', (int) ( $result['rows_read'] ?? $result['imported'] ), (int) $result['imported'], (int) ( $result['roster'] ?? 0 ) );
		if ( ! empty( $result['duplicates'] ) ) {
			$message .= sprintf( ' %d duplicate WPM project ID(s) were skipped.', (int) $result['duplicates'] );
		}
		if ( ! empty( $result['errors'] ) ) { $message .= ' ' . count( $result['errors'] ) . ' row(s) were skipped.'; }
		$this->redirect( 'mac-project-tracker-pins', $message, 'success' );
	}

	public function handle_clear_data() {
		$this->require_request( 'mac_tracker_clear_data' );
		if ( $this->sync->is_running() ) {
			$this->redirect( 'mac-project-tracker-pins', 'Wait for the running sync to finish before clearing data.', 'error' );
		}
		wp_clear_scheduled_hook( MAC_Tracker_Sync_Service::MANUAL_CRON_HOOK );
		$result = $this->repository->clear_local_data();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'mac-project-tracker-pins', $result->get_error_message(), 'error' );
		}
		$this->redirect( 'mac-project-tracker-pins', 'Local tracker data cleared. Import the CSV pin file, then queue a WPM sync.', 'success' );
	}

	public function handle_extract_elementor_colors() {
		$this->require_request( 'mac_tracker_extract_elementor_colors' );
		$result = $this->colors->extract_for_snapshot( $_POST['snapshot_id'] ?? 0 );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'mac-project-tracker-colors', $result->get_error_message(), 'error' );
		}
		$this->redirect( 'mac-project-tracker-colors', sprintf( 'Extracted %d Elementor Global Color value(s). Review and approve when ready.', count( $result['colors'] ) ), 'success' );
	}

	public function handle_extract_all_elementor_colors() {
		$this->require_request( 'mac_tracker_extract_all_elementor_colors' );
		$result = $this->colors->queue_all();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'mac-project-tracker-colors', $result->get_error_message(), 'error' );
		}
		$message = empty( $result['remaining'] ) ? 'No unextracted websites remain in the queue.' : sprintf( 'Elementor color extraction queued for %d website(s). It runs in background batches.', (int) $result['remaining'] );
		$this->redirect( 'mac-project-tracker-colors', $message, 'success' );
	}

	public function handle_approve_colors() {
		$this->require_request( 'mac_tracker_approve_colors' );
		$colors = $this->sanitize_palette( wp_unslash( $_POST['colors'] ?? '' ) );
		$result = $this->repository->approve_color_record( $_POST['snapshot_id'] ?? 0, $colors );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'mac-project-tracker-colors', $result->get_error_message(), 'error' );
		}
		$this->redirect( 'mac-project-tracker-colors', 'Palette approved and locked.', 'success' );
	}

	public function handle_extract_colors_ajax() {
		check_ajax_referer( 'mac_tracker_extract_elementor_colors', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to extract colors.' ), 403 ); }
		$result = $this->colors->extract_for_snapshot( absint( $_POST['snapshot_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => sprintf( 'Extracted %d Elementor Global Color value(s).', count( $result['colors'] ) ), 'colors' => array_values( $result['colors'] ) ) );
	}

	public function handle_extract_all_colors_ajax() {
		check_ajax_referer( 'mac_tracker_extract_all_elementor_colors', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to queue color extraction.' ), 403 ); }
		$result = $this->colors->queue_all();
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => empty( $result['remaining'] ) ? 'No unextracted websites remain in the queue.' : sprintf( 'Elementor color extraction queued for %d website(s).', (int) $result['remaining'] ) ) );
	}

	public function handle_approve_colors_ajax() {
		check_ajax_referer( 'mac_tracker_approve_colors', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to approve colors.' ), 403 ); }
		$colors = $this->sanitize_palette( wp_unslash( $_POST['colors'] ?? '' ) );
		$result = $this->repository->approve_color_record( absint( $_POST['snapshot_id'] ?? 0 ), $colors );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => 'Palette approved and locked.' ) );
	}

	private function sanitize_ui_theme( $theme ) {
		$theme = sanitize_key( (string) $theme );
		return in_array( $theme, array( 'warm-ivory', 'champagne', 'fresh-botanical', 'quiet-olive' ), true ) ? $theme : 'warm-ivory';
	}

	private function ui_theme() {
		return $this->sanitize_ui_theme( get_option( 'mac_tracker_ui_theme', 'warm-ivory' ) );
	}

	private function page_start( $title, $description, $screen ) {
		$this->current_screen = sanitize_key( $screen );
		$user   = wp_get_current_user();
		$nav_screen = in_array( $this->current_screen, array( 'colors', 'visuals' ), true ) ? 'visuals' : ( in_array( $this->current_screen, array( 'pins', 'settings' ), true ) ? 'settings' : $this->current_screen );
		?>
		<div class="<?php echo esc_attr( $this->standalone ? 'mac-tracker-root' : 'wrap mac-tracker-wrap' ); ?> mac-tracker-wrap--<?php echo esc_attr( sanitize_html_class( $screen ) ); ?><?php echo $this->public_view ? ' is-public-view' : ''; ?>" data-mac-theme="<?php echo esc_attr( $this->ui_theme() ); ?>">
			<div class="mac-tracker-app">
				<aside class="mac-tracker-sidebar" id="mac-tracker-sidebar" aria-label="MAC Tracker navigation">
					<div class="mac-tracker-sidebar__identity"><span class="mac-tracker-leaf-mark" aria-hidden="true"><i></i><i></i><i></i></span><div><strong>MAC</strong><span>Project Tracker</span></div></div>
					<nav class="mac-tracker-sidebar__nav"><?php $this->app_navigation( $nav_screen ); ?></nav>
					<div class="mac-tracker-sidebar__utility"><a href="<?php echo esc_url( $this->public_view ? wp_login_url( $this->app_url( $screen ) ) : admin_url() ); ?>"><span class="dashicons dashicons-arrow-left-alt"></span><?php echo esc_html( $this->public_view ? 'Admin sign in' : 'WordPress Admin' ); ?></a><span>Version <?php echo esc_html( MAC_TRACKER_VERSION ); ?></span></div>
				</aside>
				<div class="mac-tracker-app__workspace">
					<header class="mac-tracker-topbar">
						<button class="mac-tracker-sidebar-toggle" type="button" aria-controls="mac-tracker-sidebar" aria-expanded="false" data-mac-sidebar-toggle><span class="dashicons dashicons-menu-alt"></span><span class="screen-reader-text">Open navigation</span></button>
						<div class="mac-tracker-topbar__context"><span>MAC Project Tracker</span><strong><?php echo esc_html( $title ); ?></strong></div>
						<form class="mac-tracker-global-search" method="get" action="<?php echo esc_url( $this->app_url( 'projects' ) ); ?>"><?php if ( ! $this->standalone ) : ?><input type="hidden" name="page" value="mac-project-tracker"><?php endif; ?><label><span class="screen-reader-text">Search projects</span><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" name="search" placeholder="Search projects…"></label></form>
						<div class="mac-tracker-topbar__user"><?php echo get_avatar( $user->ID, 32 ); ?><span><strong><?php echo esc_html( $user->display_name ); ?></strong><small>Administrator</small></span></div>
					</header>
					<main class="mac-tracker-main" id="mac-tracker-main">
						<header class="mac-tracker-masthead"><div class="mac-tracker-masthead__title"><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></div></header>
						<div class="mac-tracker-page-content">
		<?php
	}

	private function page_end( $with_editorial = true ) {
		echo '</div>';
		if ( $with_editorial ) {
			$this->editorial_footer_band( $this->current_screen );
		}
		echo '</main></div></div></div>';
	}

	/** Five product destinations; legacy screens map to their consolidated parent. */
	private function app_navigation( $active ) {
		$items = array(
			'dashboard' => array( 'Dashboard', 'dashicons-chart-bar', 'mac-project-tracker-dashboard' ),
			'projects'  => array( 'Projects', 'dashicons-index-card', 'mac-project-tracker' ),
			'visuals'   => array( 'AI Analysis', 'dashicons-admin-appearance', 'mac-project-tracker-visuals' ),
			'skipped'   => array( 'Skipped Projects', 'dashicons-hidden', 'mac-project-tracker-skipped' ),
			'settings'  => array( 'Settings', 'dashicons-admin-generic', 'mac-project-tracker-settings' ),
		);
		foreach ( $items as $key => $item ) {
			printf( '<a class="%1$s" href="%2$s"%3$s><span class="dashicons %4$s" aria-hidden="true"></span><span>%5$s</span></a>', esc_attr( $key === $active ? 'is-active' : '' ), esc_url( $this->app_url( $key ) ), $key === $active ? ' aria-current="page"' : '', esc_attr( $item[1] ), esc_html( $item[0] ) );
		}
	}

	/** Reusable final page component. It must remain after all operational content. */
	private function editorial_footer_band( $screen, $variant = 'full' ) {
		$screen = in_array( $screen, array( 'colors', 'visuals' ), true ) ? 'visuals' : ( in_array( $screen, array( 'pins', 'settings' ), true ) ? 'settings' : $screen );
		$quotes = array(
			'dashboard' => 'Good websites grow businesses.',
			'projects'  => 'Good systems make good work visible.',
			'visuals'   => 'Turn websites into insights.',
			'skipped'   => 'Keep the signal. Remove the noise.',
			'settings'  => 'Better tools create better work.',
		);
		$quote = $quotes[ $screen ] ?? $quotes['dashboard'];
		?>
		<footer class="mac-tracker-editorial-band mac-tracker-editorial-band--<?php echo esc_attr( $screen ); ?> mac-tracker-editorial-band--<?php echo esc_attr( in_array( $variant, array( 'full', 'compact', 'side' ), true ) ? $variant : 'full' ); ?>" aria-label="MAC Project Tracker editorial note"><div><p>MAC / DESIGN OPERATIONS</p><blockquote><?php echo esc_html( $quote ); ?></blockquote><span>Built for calm, visible progress.</span></div></footer>
		<?php
	}

	private function app_url( $screen, array $args = array(), $fragment = '' ) {
		$screen = sanitize_key( $screen );
		if ( $this->standalone && class_exists( 'MAC_Tracker_App' ) ) {
			$url = MAC_Tracker_App::url( $screen, $args );
		} else {
			$pages = array( 'dashboard' => 'mac-project-tracker-dashboard', 'projects' => 'mac-project-tracker', 'analysis' => 'mac-project-tracker-visuals', 'visuals' => 'mac-project-tracker-visuals', 'colors' => 'mac-project-tracker-visuals', 'skipped' => 'mac-project-tracker-skipped', 'settings' => 'mac-project-tracker-settings', 'pins' => 'mac-project-tracker-settings' );
			$url = add_query_arg( array_merge( array( 'page' => $pages[ $screen ] ?? 'mac-project-tracker-dashboard' ), $args ), admin_url( 'admin.php' ) );
		}
		return $fragment ? $url . '#' . sanitize_key( $fragment ) : $url;
	}

	private function sync_buttons() {
		$compared = $this->repository->baseline_is_compared();
		?>
		<div class="mac-tracker-sync-actions">
			<?php if ( ! $compared ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_compare_wpm' ); ?><input type="hidden" name="action" value="mac_tracker_compare_wpm"><button class="button button-primary" type="submit" <?php disabled( $this->sync->is_running() || $this->sync->is_queued() ); ?>>Compare baseline</button></form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_queue_sync' ); ?><input type="hidden" name="action" value="mac_tracker_queue_sync"><button class="button button-primary" type="submit" <?php disabled( $this->sync->is_running() || $this->sync->is_queued() ); ?>><span class="dashicons dashicons-update"></span><?php echo esc_html( $this->sync->is_running() ? 'Syncing…' : ( $this->sync->is_queued() ? 'Queued' : 'Sync new Action Design' ) ); ?></button></form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function stat_card( $label, $value, $icon ) {
		?>
		<div class="mac-tracker-stat-card"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span><div><strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></div></div>
		<?php
	}

	/** Reusable read-only Dashboard metric contract. */
	private function metric_card( $label, $value, $helper, $icon ) {
		?><article class="mac-tracker-metric-card"><div><p><?php echo esc_html( $label ); ?></p><strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong><span><?php echo esc_html( $helper ); ?></span></div><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span></article><?php
	}

	/** Derive Dashboard presentation from fields already returned by project_page(). */
	private function dashboard_insights( array $rows ) {
		$templates = array();
		$colors = array();
		$pairs = array();
		$projects = array();
		foreach ( $rows as $row ) {
			$project_id = absint( $row['wpm_project_id'] ?? 0 );
			if ( $project_id && isset( $projects[ $project_id ] ) ) { continue; }
			if ( $project_id ) { $projects[ $project_id ] = true; }
			$template = $this->layout_label( $row['layout_url'] ?? '' );
			$template = '—' === $template || 'Open layout' === $template ? '' : $template;
			$tone = 'classified' === (string) ( $row['tone_status'] ?? '' ) ? trim( (string) ( $row['tone'] ?? '' ) ) : '';
			$color = $this->brand_color_label( $tone );
			if ( $template ) { $templates[ $template ] = ( $templates[ $template ] ?? 0 ) + 1; }
			if ( $color ) { $colors[ $color ] = ( $colors[ $color ] ?? 0 ) + 1; }
			if ( $template && $color ) {
				$key = $template . '|' . $color;
				$pairs[ $key ] = array( 'template' => $template, 'color' => $color, 'count' => ( $pairs[ $key ]['count'] ?? 0 ) + 1 );
			}
		}
		arsort( $templates );
		arsort( $colors );
		usort( $pairs, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
		return array( 'project_count' => count( $projects ), 'templates' => array_slice( $templates, 0, 6, true ), 'colors' => array_slice( $colors, 0, 8, true ), 'combinations' => array_slice( $pairs, 0, 6 ) );
	}

	private function brand_color_label( $tone ) {
		foreach ( array( 'Vàng', 'Hồng', 'Đỏ', 'Xanh', 'Tím', 'Cam', 'Nâu' ) as $brand ) {
			if ( 0 === stripos( (string) $tone, $brand ) ) { return $brand; }
		}
		return '' !== trim( (string) $tone ) && 'Cần duyệt' !== $tone ? 'Neutral / Other' : '';
	}

	private function ranked_list( array $items, $total ) {
		if ( empty( $items ) ) { echo '<div class="mac-tracker-empty mac-tracker-empty--compact"><strong>No template data in this range</strong><p>Template usage appears when a saved layout is available.</p></div>'; return; }
		echo '<ol class="mac-tracker-ranked-list">';
		foreach ( $items as $label => $count ) {
			$percentage = min( 100, round( 100 * (int) $count / max( 1, (int) $total ) ) );
			echo '<li><div><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( number_format_i18n( $count ) ) . ' projects · ' . esc_html( $percentage ) . '%</span></div><i><b style="width:' . esc_attr( $percentage ) . '%"></b></i></li>';
		}
		echo '</ol>';
	}

	private function distribution_list( array $items ) {
		if ( empty( $items ) ) { echo '<div class="mac-tracker-empty mac-tracker-empty--compact"><strong>No classified tones yet</strong><p>Brand distribution uses current stored AI tone values.</p></div>'; return; }
		$max = max( $items );
		echo '<ol class="mac-tracker-distribution-list">';
		foreach ( $items as $label => $count ) {
			$width = round( 100 * (int) $count / max( 1, (int) $max ) );
			echo '<li><span class="mac-tracker-color-key mac-tracker-color-key--' . esc_attr( sanitize_title( $label ) ) . '"><i></i>' . esc_html( $label ) . '</span><b><i style="width:' . esc_attr( $width ) . '%"></i></b><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong></li>';
		}
		echo '</ol>';
	}

	private function distribution_summary( array $items ) {
		if ( empty( $items ) ) { echo '<div class="mac-tracker-empty mac-tracker-empty--compact"><strong>No classified tones yet</strong><p>Distribution appears after AI tone classification.</p></div>'; return; }
		$colors = array( 'Vàng' => '#B9A15B', 'Hồng' => '#B89591', 'Đỏ' => '#A56E68', 'Xanh' => '#707963', 'Tím' => '#887989', 'Cam' => '#B88765', 'Nâu' => '#927A67', 'Neutral / Other' => '#AAA398' );
		$total = max( 1, array_sum( $items ) );
		$stops = array();
		$cursor = 0;
		foreach ( $items as $label => $count ) {
			$next = $cursor + ( 100 * (int) $count / $total );
			$color = $colors[ $label ] ?? '#AAA398';
			$stops[] = $color . ' ' . round( $cursor, 2 ) . '% ' . round( $next, 2 ) . '%';
			$cursor = $next;
		}
		echo '<div class="mac-tracker-distribution"><div class="mac-tracker-distribution__chart" style="--mac-chart:conic-gradient(' . esc_attr( implode( ',', $stops ) ) . ')"><span><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>tones</span></div><ol>';
		foreach ( $items as $label => $count ) {
			$percentage = round( 100 * (int) $count / $total );
			echo '<li style="--signal:' . esc_attr( $colors[ $label ] ?? '#AAA398' ) . '"><i></i><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $percentage ) . '%</strong></li>';
		}
		echo '</ol></div>';
	}

	/** Existing destructive handlers, consolidated visually into Settings. */
	private function render_data_management_content() {
		?>
		<section class="mac-tracker-data-management"><div><p class="mac-tracker-eyebrow">Stored data</p><h2>Data management</h2><p>Use the supported reset actions below. No export action is shown because the plugin has no existing export handler.</p></div></section>
		<section class="mac-tracker-danger-zone" aria-label="Danger Zone"><div><p class="mac-tracker-eyebrow">Danger Zone</p><h2>Clear Visual Tone data</h2><p>Deletes every Visual Tone capture, tone result, queue state and stored screenshot. Projects, pins, Color Review and WPM sync data are kept. Mode returns to Manual.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Clear all Visual Tone data and screenshots? This cannot be undone.');"><?php wp_nonce_field( 'mac_tracker_clear_visual_data' ); ?><input type="hidden" name="action" value="mac_tracker_clear_visual_data"><button class="button mac-tracker-button--danger" type="submit">Clear Visual Tone data</button></form></section>
		<section class="mac-tracker-danger-zone" aria-label="Reset local tracker data"><div><p class="mac-tracker-eyebrow">Danger Zone</p><h2>Clear all local tracker data</h2><p>Deletes local snapshots, imported pins, color records and sync logs. WPM connection settings and the original CSV file are kept.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Clear all local tracker data? This cannot be undone.');"><?php wp_nonce_field( 'mac_tracker_clear_data' ); ?><input type="hidden" name="action" value="mac_tracker_clear_data"><button class="button mac-tracker-button--danger" type="submit">Clear all data</button></form></section>
		<?php
	}

	/** UI-only appearance preference. The selected palette does not affect data or statuses. */
	private function render_appearance_content() {
		$current = $this->ui_theme();
		$themes = array(
			'warm-ivory' => array( 'Warm Ivory', array( '#F8F5EE', '#FFFBF5', '#F1E9DC', '#555C43', '#25261F' ), 'Warm paper and restrained olive accents.' ),
			'champagne' => array( 'Champagne Olive', array( '#FAF5EB', '#FFF9F0', '#F2E6D4', '#625F45', '#302D21' ), 'A brighter champagne paper with a softened olive.' ),
			'fresh-botanical' => array( 'Fresh Botanical', array( '#F7F6F0', '#FCFCF7', '#EEF0E6', '#52644D', '#263025' ), 'Clean botanical neutrals with a fresh leaf accent.' ),
			'quiet-olive' => array( 'Quiet Olive', array( '#F4F1EC', '#FAF8F4', '#E7E3DA', '#575743', '#26281A' ), 'The original V3 quiet-olive palette.' ),
		);
		?>
		<section class="mac-tracker-panel mac-tracker-appearance" data-mac-theme-settings>
			<div class="mac-tracker-panel__head"><div><p class="mac-tracker-eyebrow">Product appearance</p><h2>Choose the workspace palette</h2><p>Preview applies immediately. Save appearance to use this palette across Dashboard, Projects, AI Analysis, Skipped Projects and Settings.</p></div></div>
			<div class="mac-tracker-theme-grid" role="radiogroup" aria-label="MAC Tracker color theme">
				<?php foreach ( $themes as $key => $theme ) : ?>
					<button type="button" class="mac-tracker-theme-card<?php echo $current === $key ? ' is-selected' : ''; ?>" role="radio" aria-checked="<?php echo $current === $key ? 'true' : 'false'; ?>" data-mac-theme-option="<?php echo esc_attr( $key ); ?>"><span class="mac-tracker-theme-card__preview" aria-hidden="true"><?php foreach ( $theme[1] as $swatch ) : ?><i style="--mac-theme-swatch:<?php echo esc_attr( $swatch ); ?>"></i><?php endforeach; ?></span><strong><?php echo esc_html( $theme[0] ); ?></strong><small><?php echo esc_html( $theme[2] ); ?></small></button>
				<?php endforeach; ?>
			</div>
			<div class="mac-tracker-appearance__actions"><p data-mac-theme-status aria-live="polite">Previewing <?php echo esc_html( $themes[ $current ][0] ); ?>.</p><button class="button button-primary" type="button" data-mac-theme-save>Save appearance</button></div>
		</section>
		<?php
	}

	private function project_status_badge( array $row ) {
		$capture = (string) ( $row['capture_status'] ?? '' );
		$tone = (string) ( $row['tone_status'] ?? '' );
		$value = 'Pending';
		$class = 'neutral';
		if ( 'failed' === $capture || 'failed' === $tone ) { $value = 'Failed'; $class = 'failed'; }
		elseif ( 'classified' === $tone && 'Cần duyệt' === (string) ( $row['tone'] ?? '' ) ) { $value = 'Review'; $class = 'warning'; }
		elseif ( 'classified' === $tone ) { $value = 'Analyzed'; $class = 'success'; }
		elseif ( 'captured' === $capture ) { $value = 'Captured'; $class = 'info'; }
		echo '<span class="mac-tracker-project-status is-' . esc_attr( $class ) . '"><i></i>' . esc_html( $value ) . '</span>';
	}

	private function notices() {
		if ( empty( $_GET['mac_tracker_notice'] ) ) { return; }
		$type = sanitize_key( $_GET['mac_tracker_notice_type'] ?? 'success' );
		$type = in_array( $type, array( 'success', 'error' ), true ) ? $type : 'success';
		echo '<div class="mac-tracker-notice mac-tracker-notice--' . esc_attr( $type ) . '" role="status"><p>' . esc_html( wp_unslash( $_GET['mac_tracker_notice'] ) ) . '</p></div>';
	}

	private function project_filters() {
		return $this->project_filters_from_values( wp_unslash( $_GET ) );
	}

	/** Normalize both full-page and bounded AJAX project list filters. */
	private function project_filters_from_values( array $get ) {
		$range = isset( $get['range'] ) && in_array( $get['range'], array( 'month', '30', '90', 'all', 'custom' ), true ) ? (string) $get['range'] : 'all';
		$custom_from = isset( $get['custom_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $get['custom_from'] ) ? (string) $get['custom_from'] : '';
		$custom_to = isset( $get['custom_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $get['custom_to'] ) ? (string) $get['custom_to'] : '';
		$dates = $this->date_range_values( $range, $custom_from, $custom_to );
		$all_mode = isset( $get['per_page'] ) && 'all' === sanitize_key( (string) $get['per_page'] );
		$requested_per_page = isset( $get['per_page'] ) ? absint( $get['per_page'] ) : 100;
		return array(
			'search'   => isset( $get['search'] ) ? sanitize_text_field( $get['search'] ) : '',
			'assignee' => isset( $get['assignee'] ) ? sanitize_text_field( $get['assignee'] ) : '',
			'kind'     => isset( $get['kind'] ) ? sanitize_key( $get['kind'] ) : '',
			'website'  => '',
			'layout'   => isset( $get['layout'] ) ? sanitize_text_field( $get['layout'] ) : '',
			'tone'     => isset( $get['tone'] ) ? sanitize_text_field( $get['tone'] ) : '',
			'month_from' => '',
			'month_to'   => '',
			'range'       => $range,
			'custom_from' => $custom_from,
			'custom_to'   => $custom_to,
			'date_from'   => $dates['from'],
			'date_to'     => $dates['to'],
			// All mode still asks the database for a 100-row logical page. The
			// browser appends more pages on demand, preventing a 600+ row table.
			'per_page' => $all_mode ? 100 : ( in_array( $requested_per_page, array( 25, 50, 100, 200 ), true ) ? $requested_per_page : 100 ),
			'all_mode' => $all_mode,
			'orderby'  => isset( $get['orderby'] ) ? sanitize_key( $get['orderby'] ) : 'date',
			'order'    => isset( $get['order'] ) ? sanitize_key( $get['order'] ) : 'desc',
			'paged'    => isset( $get['paged'] ) ? absint( $get['paged'] ) : 1,
		);
	}

	private function date_range_values( $range, $custom_from = '', $custom_to = '' ) {
		$today = new DateTimeImmutable( 'today', new DateTimeZone( 'Asia/Bangkok' ) );
		if ( 'month' === $range ) { return array( 'from' => $today->format( 'Y-m-01' ), 'to' => $today->format( 'Y-m-t' ) ); }
		if ( '30' === $range ) { return array( 'from' => $today->modify( '-29 days' )->format( 'Y-m-d' ), 'to' => $today->format( 'Y-m-d' ) ); }
		if ( '90' === $range ) { return array( 'from' => $today->modify( '-89 days' )->format( 'Y-m-d' ), 'to' => $today->format( 'Y-m-d' ) ); }
		if ( 'custom' === $range ) { return array( 'from' => $custom_from, 'to' => $custom_to ); }
		return array( 'from' => '', 'to' => '' );
	}

	/** Shared display-size contract for operational pages. */
	private function presentation_page_size( $value ) {
		if ( 'all' === $this->presentation_page_request( $value ) ) {
			// Keep browser work bounded. The fragment loader requests subsequent
			// 100-item slices instead of appending an unbounded card grid.
			return 100;
		}
		$value = absint( $value );
		return in_array( $value, array( 25, 50, 100, 200 ), true ) ? $value : 100;
	}

	/** Keep the All selection visible while every rendered card request stays bounded. */
	private function presentation_page_request( $value ) {
		if ( 'all' === sanitize_key( (string) $value ) ) {
			return 'all';
		}
		$value = absint( $value );
		return in_array( $value, array( 25, 50, 100, 200 ), true ) ? $value : 100;
	}

	private function sort_header( $field, $label, array $filters, $class = '' ) {
		$current = $filters['orderby'] === $field;
		$order   = $current && 'desc' === strtolower( $filters['order'] ) ? 'asc' : 'desc';
		$args    = array_merge( $filters, array( 'orderby' => $field, 'order' => $order, 'paged' => 1 ) );
		if ( ! empty( $filters['all_mode'] ) ) {
			$args['per_page'] = 'all';
			unset( $args['all_mode'] );
		}
		$url = $this->app_url( 'projects', $args );
		$direction = $current && 'asc' === strtolower( $filters['order'] ) ? 'up' : 'down';
		echo '<th scope="col" class="mac-tracker-sort ' . esc_attr( $class ) . ( $current ? ' is-active' : '' ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<svg aria-hidden="true" viewBox="0 0 12 12" class="is-' . esc_attr( $direction ) . '"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4"/></svg></a></th>';
	}

	/** Only presentation filters cross the browser boundary for All-mode chunks. */
	private function project_fragment_filters( array $filters ) {
		$keys = array( 'search', 'assignee', 'kind', 'layout', 'tone', 'range', 'custom_from', 'custom_to', 'orderby', 'order', 'per_page' );
		$payload = array();
		foreach ( $keys as $key ) {
			$payload[ $key ] = $filters[ $key ] ?? '';
		}
		$payload['per_page'] = 'all';
		return $payload;
	}

	private function pagination( array $page, array $filters ) {
		$start = $page['total'] ? ( ( (int) $page['paged'] - 1 ) * (int) $page['per_page'] ) + 1 : 0;
		$end = min( (int) $page['total'], (int) $page['paged'] * (int) $page['per_page'] );
		echo '<footer class="mac-tracker-table-footer"><span>Showing ' . esc_html( number_format_i18n( $start ) ) . '–' . esc_html( number_format_i18n( $end ) ) . ' of ' . esc_html( number_format_i18n( $page['total'] ) ) . '</span>';
		if ( ! empty( $filters['all_mode'] ) ) {
			echo '<span class="mac-tracker-table-footer__all-note">All matching records load in 100-row chunks.</span>';
		} elseif ( (int) $page['total_pages'] > 1 ) {
			$base_args = array_merge( $filters, array( 'paged' => '%#%' ) );
			$base = str_replace( '%25%23%25', '%#%', $this->app_url( 'projects', $base_args ) );
			$links = paginate_links( array( 'base' => $base, 'format' => '', 'current' => (int) $page['paged'], 'total' => (int) $page['total_pages'], 'type' => 'list', 'mid_size' => 1, 'prev_text' => '‹', 'next_text' => '›' ) );
			if ( $links ) { echo '<nav class="mac-tracker-pagination" aria-label="Project pages">' . wp_kses_post( $links ) . '</nav>'; }
		}
		echo '<form class="mac-tracker-rows" method="get" action="' . esc_url( $this->app_url( 'projects' ) ) . '"><label>Rows <select name="per_page" onchange="this.form.submit()">';
		foreach ( array( 25, 50, 100, 200 ) as $size ) { echo '<option value="' . esc_attr( $size ) . '"' . selected( (int) $filters['per_page'], $size, false ) . '>' . esc_html( $size ) . '</option>'; }
		echo '<option value="all"' . selected( ! empty( $filters['all_mode'] ), true, false ) . '>All</option>';
		echo '</select></label>';
		foreach ( $filters as $key => $value ) { if ( in_array( $key, array( 'per_page', 'all_mode', 'paged', 'date_from', 'date_to', 'month_from', 'month_to', 'website' ), true ) || '' === (string) $value ) { continue; } echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '</form></footer>';
	}

	private function project_id_link( array $row ) {
		$id = (int) $row['wpm_project_id'];
		echo '<a class="mac-tracker-id-link" href="' . esc_url( 'https://wpm.macusaone.com/projects/' . $id . '/edit' ) . '" target="_blank" rel="noopener">#' . esc_html( $id ) . '</a>';
	}

	private function task_id_link( array $row ) {
		if ( 'action_design' !== $row['record_kind'] ) { echo '<span>CSV pin</span>'; return; }
		$id = (int) $row['wpm_action_task_id'];
		echo '<a class="mac-tracker-task-id-link" href="' . esc_url( 'https://wpm.macusaone.com/tasks/' . $id . '/edit?' ) . '" target="_blank" rel="noopener">#' . esc_html( $id ) . '</a>';
	}

	private function confidence_badge( array $row ) {
		$raw = json_decode( (string) ( $row['raw_payload'] ?? '' ), true );
		$confidence = is_array( $raw ) ? sanitize_key( $raw['match_confidence'] ?? 'exact' ) : 'exact';
		if ( 'exact' === $confidence ) { return; }
		echo '<span class="mac-tracker-confidence">' . esc_html( str_replace( '_', ' ', $confidence ) ) . '</span>';
	}

	private function baseline_override_badge( array $row ) {
		if ( 'action_design' !== $row['record_kind'] || 'baseline_compare' !== (string) ( $row['sync_source'] ?? '' ) ) { return; }
		echo '<span class="mac-tracker-baseline-override" title="WPM Action Design replaces the CSV display values">WPM</span>';
	}

	private function palette_cell( array $row ) {
		$colors = $this->row_colors( $row );
		if ( ! empty( $colors ) ) {
			echo '<a class="mac-tracker-palette-link" href="' . esc_url( $this->app_url( 'analysis', array( 'section' => 'action' ) ) ) . '" title="Open AI Analysis action queue">';
			foreach ( array_slice( $colors, 0, 4 ) as $color ) {
				echo '<i style="--mac-tracker-swatch: ' . esc_attr( $color ) . '"></i>';
			}
			echo '<span class="screen-reader-text">Open palette review</span></a>';
			return;
		}
		echo '<a class="mac-tracker-palette-link mac-tracker-palette-link--empty" href="' . esc_url( $this->app_url( 'analysis', array( 'section' => 'action' ) ) ) . '" aria-label="No saved palette"><i></i><i></i><i></i></a>';
	}

	private function tone_options() {
		return $this->repository->visual_tones();
	}

	private function visual_is_manual( array $row ) {
		if ( ! empty( $row['manual_locked'] ) || ! empty( $row['human_locked'] ) ) { return true; }
		$raw = json_decode( (string) ( $row['ai_raw'] ?? '' ), true );
		return is_array( $raw ) && ! empty( $raw['manual'] );
	}

	private function tone_cell( array $row, $link = true ) {
		$status = (string) ( $row['tone_status'] ?? '' );
		$tone   = (string) ( $row['tone'] ?? '' );
		if ( 'classified' === $status && ! in_array( $tone, $this->tone_options(), true ) ) {
			$tone = 'Cần duyệt';
		}
		if ( 'classified' !== $status || ! in_array( $tone, $this->tone_options(), true ) ) {
			echo '<span class="mac-tracker-tone mac-tracker-tone--pending">' . esc_html( 'failed' === $status ? 'Retry AI' : 'Waiting for AI' ) . '</span>';
			return;
		}
		$classes = 'mac-tracker-tone mac-tracker-tone--' . sanitize_title( $tone );
		$palette = $this->repository->visual_tone_palette()[ $tone ] ?? array();
		$style = '';
		foreach ( array( 'tone_a', 'tone_b', 'tone_soft', 'tone_line', 'tone_ink' ) as $key ) { if ( ! empty( $palette[ $key ] ) ) { $style .= '--' . str_replace( '_', '-', $key ) . ':' . esc_attr( $palette[ $key ] ) . ';'; } }
		$title = trim( (string) ( $row['tone_reason'] ?? '' ) );
		$precise = trim( (string) ( $row['precise_tone'] ?? '' ) );
		$display_tone = '' !== $precise && $precise !== $tone ? $precise : $tone;
		$content = '<i aria-hidden="true"></i><span>' . esc_html( $display_tone ) . '</span>';
		if ( $link ) {
			$section = ! empty( $row['human_locked'] ) ? 'locked' : ( 'classified' === $status ? 'review' : 'processing' );
			echo '<a class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '" href="' . esc_url( $this->app_url( 'analysis', array( 'section' => $section ), 'visual-' . (int) $row['id'] ) ) . '" title="' . esc_attr( $title ?: 'Open stored screenshot' ) . '">' . $content . '</a>';
			return;
		}
		$manual = ! empty( $row['manual_locked'] ) || '' !== trim( (string) ( $row['manual_tone'] ?? '' ) );
		echo '<span class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '" title="' . esc_attr( $title ) . '">' . $content . '</span>';
		if ( $manual ) { echo '<span class="mac-tracker-tone-manual">Đã sửa tay</span>'; }
		if ( ! empty( $row['human_locked'] ) ) { return; }
		if ( '' !== $precise && $precise !== $tone ) { echo '<span class="mac-tracker-tone-precise" title="Precise: ' . esc_attr( $precise ) . '">Group: ' . esc_html( $tone ) . '</span>'; }
		$snapshot_id = (int) $row['id'];
		$open = 'Cần duyệt' === $tone ? ' open' : '';
		$summary = 'Cần duyệt' === $tone ? 'Chọn tone đúng' : 'Sửa tone';
		echo '<details class="mac-tracker-tone-review"' . $open . '><summary>' . esc_html( $summary ) . '</summary><div><select id="manual-tone-' . $snapshot_id . '" name="manual_tone[' . $snapshot_id . ']">';
		foreach ( array_diff( $this->tone_options(), array( 'Cần duyệt' ) ) as $option ) {
			echo '<option value="' . esc_attr( $option ) . '"' . selected( $tone, $option, false ) . '>' . esc_html( $option ) . '</option>';
		}
		echo '</select><button type="submit" class="button button-small" name="visual_action" value="save_tone_' . $snapshot_id . '">Save &amp; approve</button></div></details>';
	}

	/** Collapsible, sanitized evidence for diagnosing an AI result without exposing secrets. */
	private function visual_debug_panel( array $row ) {
		$raw = json_decode( (string) ( $row['ai_raw'] ?? '' ), true );
		if ( ! is_array( $raw ) ) { return; }
		$bundle = json_decode( (string) ( $row['capture_bundle_json'] ?? '' ), true );
		$overlay_cleanup = is_array( $bundle ) ? (array) ( $bundle['overlay_cleanup'] ?? array() ) : array();
		$capture_quality = is_array( $bundle ) ? sanitize_key( (string) ( $bundle['capture_quality'] ?? '' ) ) : '';
		$deterministic = (array) ( $raw['deterministic']['semantic_model'] ?? array() );
		$canvas = (array) ( $deterministic['canvas'] ?? array() );
		$accent = (array) ( $deterministic['primary_accent'] ?? array() );
		$brand = (array) ( $deterministic['brand'] ?? array() );
		$outcome = (array) ( $raw['result'] ?? array() );
		$conflict = (array) ( $raw['conflict'] ?? array() );
		$is_direct = 'direct_vision' === (string) ( $raw['classifier_mode'] ?? '' ) || 'direct-vision-v1' === (string) ( $raw['classifier_version'] ?? '' );
		echo '<details class="mac-tracker-visual-debug"><summary>Evidence &amp; provider trace</summary><dl>';
		if ( $is_direct ) {
			echo '<dt>Production authority</dt><dd>Direct Vision · complete rendered screenshot</dd>';
			echo '<dt>AI decision</dt><dd>' . esc_html( trim( (string) ( $outcome['provider'] ?? $row['ai_provider'] ?? '' ) . ' / ' . (string) ( $outcome['model'] ?? $row['ai_model'] ?? '' ) . ' · brand ' . (string) ( $outcome['brand'] ?? $outcome['primary_family'] ?? '' ) . ' · canvas ' . (string) ( $outcome['canvas'] ?? $outcome['primary_surface'] ?? '' ) . ' · tone ' . (string) ( $outcome['tone_group'] ?? $outcome['tone'] ?? '' ) . ' · confidence ' . (string) ( $outcome['confidence'] ?? '' ) ) ?: 'Not available' ) . '</dd>';
			echo '<dt>Decision path</dt><dd>' . esc_html( (string) ( $raw['authority'] ?? 'manual_review' ) ) . '</dd>';
			echo '<dt>Vision input</dt><dd>' . esc_html( wp_json_encode( (array) ( $raw['vision_input'] ?? array() ) ) ) . '</dd>';
			$conflict_types = array();
			if ( ! empty( $conflict['canvas_conflict'] ) ) { $conflict_types[] = 'canvas'; }
			if ( ! empty( $conflict['brand_conflict'] ) ) { $conflict_types[] = 'brand'; }
			$conflict_label = strtoupper( (string) ( $conflict['severity'] ?? 'none' ) ) . ( $conflict_types ? ' · ' . implode( ' + ', $conflict_types ) : '' );
			echo '<dt>Sanity conflict</dt><dd>' . esc_html( $conflict_label ) . '</dd>';
			echo '<dt>Conflict reason</dt><dd>' . esc_html( ! empty( $conflict['reasons'] ) ? implode( ' ', array_map( 'strval', (array) $conflict['reasons'] ) ) : 'No material sanity conflict.' ) . '</dd>';
			if ( array_key_exists( 'resolved', $conflict ) ) { echo '<dt>Conflict resolution</dt><dd>' . esc_html( ! empty( $conflict['resolved'] ) ? 'Resolved by ' . (string) ( $conflict['resolved_by'] ?? 'Vision' ) : 'Unresolved · human review required' ) . '</dd>'; }
		}
		echo '<dt>Canvas</dt><dd>' . esc_html( trim( (string) ( $canvas['primary_surface'] ?? $canvas['family'] ?? '' ) . ' / ' . (string) ( $canvas['secondary_surface'] ?? '' ) . ' / ' . (string) ( $canvas['mode'] ?? '' ) . ' · confidence ' . (string) ( $canvas['surface_confidence'] ?? $canvas['confidence'] ?? '' ) ) ?: 'Not available' ) . '</dd>';
		echo '<dt>Deterministic sanity</dt><dd>' . esc_html( trim( (string) ( $brand['brand_primary_family'] ?? $accent['family'] ?? '' ) . ' ' . (string) ( $brand['brand_primary_score'] ?? $accent['score'] ?? '' ) . ' · sections ' . (string) ( $brand['brand_evidence'][0]['section_count'] ?? '' ) . ' · roles ' . implode( ', ', (array) ( $brand['brand_evidence'][0]['roles'] ?? array() ) ) ) ?: 'Not available' ) . '</dd>';
		echo '<dt>Primary accent</dt><dd>' . esc_html( trim( (string) ( $accent['family'] ?? '' ) . ' ' . (string) ( $accent['hex'] ?? '' ) ) ?: 'Not available' ) . '</dd>';
		if ( '' !== $capture_quality ) { echo '<dt>Capture quality</dt><dd>' . esc_html( $capture_quality ) . '</dd>'; }
		if ( $overlay_cleanup ) {
			$remaining_descriptors = array_map(
				static function( $descriptor ) {
					$descriptor = (array) $descriptor;
					return array(
						'type'     => sanitize_key( (string) ( $descriptor['type'] ?? 'generic_overlay' ) ),
						'coverage' => (float) ( $descriptor['coverage'] ?? 0 ),
						'fixed'    => ! empty( $descriptor['fixed'] ),
						'z_index'  => (int) ( $descriptor['z_index'] ?? 0 ),
					);
				},
				array_slice( (array) ( $overlay_cleanup['remaining_descriptors'] ?? array() ), 0, 8 )
			);
			echo '<dt>Overlay cleanup</dt><dd>' . esc_html( wp_json_encode( array( 'detected' => absint( $overlay_cleanup['detected'] ?? 0 ), 'closed' => absint( $overlay_cleanup['closed'] ?? 0 ), 'removed' => absint( $overlay_cleanup['removed'] ?? 0 ), 'remaining' => absint( $overlay_cleanup['remaining'] ?? 0 ), 'passes' => absint( $overlay_cleanup['passes'] ?? 1 ), 'scroll_restored' => ! empty( $overlay_cleanup['scroll_restored'] ), 'types' => array_slice( array_map( 'sanitize_key', (array) ( $overlay_cleanup['types'] ?? array() ) ), 0, 12 ), 'remaining_descriptors' => $remaining_descriptors ) ) ) . '</dd>';
		}
		echo '<dt>Provider decisions</dt><dd>' . esc_html( wp_json_encode( array_map( static function( $attempt ) { return array( 'provider' => $attempt['provider'] ?? '', 'model' => $attempt['model'] ?? '', 'brand' => $attempt['brand'] ?? $attempt['primary_family'] ?? '', 'canvas' => $attempt['canvas'] ?? $attempt['primary_surface'] ?? '', 'tone' => $attempt['tone'] ?? $attempt['tone_group'] ?? '', 'confidence' => $attempt['confidence'] ?? '', 'reason' => $attempt['reason'] ?? '', 'mode' => $attempt['canvas_mode'] ?? '' ); }, (array) ( $raw['attempts'] ?? array() ) ) ) ) . '</dd>';
		echo '<dt>Final reason</dt><dd>' . esc_html( (string) ( $outcome['reason'] ?? $row['tone_reason'] ?? 'Pending' ) ) . '</dd>';
		echo '<dt>Provider errors</dt><dd>' . esc_html( wp_json_encode( (array) ( $raw['errors'] ?? array() ) ) ) . '</dd></dl></details>';
	}

	private function visual_status_cell( array $row ) {
		$pipeline = (string) ( $row['pipeline_status'] ?? '' );
		if ( ! empty( $row['human_locked'] ) ) {
			$revision = absint( $row['approved_capture_revision'] ?? 0 );
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--complete"' . ( $revision ? ' title="Capture revision ' . esc_attr( $revision ) . '"' : '' ) . '><span class="dashicons dashicons-lock"></span>Human approved</p>';
			return;
		}
		$provider = (string) ( $row['ai_provider'] ?? '' );
		$model = (string) ( $row['ai_model'] ?? '' );
		if ( in_array( $pipeline, array( 'capturing', 'analyzing' ), true ) && ! empty( $row['lease_until'] ) && strtotime( (string) $row['lease_until'] . ' UTC' ) < time() ) {
			$pipeline = 'retry_wait';
		}
		$canonical = array(
			'idle'           => array( 'queued', 'dashicons-minus', 'Idle · chưa chạy' ),
			'capture_queued' => array( 'queued', 'dashicons-clock', 'local' === (string) ( $row['runner_type'] ?? '' ) || 'self-hosted-windows' === (string) ( $row['runner_type'] ?? '' ) ? 'LOCAL RUNNER · QUEUED · Waiting for local capture machine' : 'Capture queued · chờ chạy batch' ),
			'capturing'      => array( 'working', 'dashicons-camera', 'Capturing homepage · GitHub đang chạy' ),
			'captured'       => array( 'queued', 'dashicons-format-image', 'Screenshot saved · chờ phân tích' ),
			'analysis_queued'=> array( 'queued', 'dashicons-clock', 'Queued for AI analysis' ),
			'analyzing'      => array( 'working', 'dashicons-admin-appearance', $provider ? 'Analyzing with ' . trim( $provider . ( $model ? ' · ' . $model : '' ) ) : 'Analyzing with Qwen 3.8' ),
			'classified'     => array( 'complete', 'dashicons-yes-alt', 'AI completed · chờ duyệt' ),
			'needs_review'   => array( 'queued', 'dashicons-visibility', 'Needs review · cần duyệt tone' ),
			'retry_wait'     => array( 'queued', 'dashicons-update', 'Retry scheduled' . ( ! empty( $row['next_retry_at'] ) ? ' · ' . MAC_Tracker_Time::bangkok_label( $row['next_retry_at'] ) : '' ) ),
			'blocked'        => array( 'failed', 'dashicons-lock', 'Blocked' . ( ! empty( $row['last_error_code'] ) ? ' · ' . $row['last_error_code'] : '' ) . ' · ' . ( $row['last_error_message'] ?? 'cần xử lý thủ công' ) ),
			'failed'         => array( 'failed', 'dashicons-warning', 'Failed' . ( ! empty( $row['last_error_code'] ) ? ' · ' . $row['last_error_code'] : '' ) . ' · ' . ( $row['last_error_message'] ?? 'cần retry' ) ),
		);
		if ( isset( $canonical[ $pipeline ] ) ) {
			$entry = $canonical[ $pipeline ];
			$active = in_array( $pipeline, array( 'capturing', 'analyzing' ), true ) ? ' data-visual-active' : '';
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--' . esc_attr( $entry[0] ) . '"' . $active . '><span class="dashicons ' . esc_attr( $entry[1] ) . '"></span>' . esc_html( $entry[2] ) . '</p>';
			return;
		}
		$capture = (string) ( $row['capture_status'] ?? '' );
		$tone    = (string) ( $row['tone_status'] ?? '' );
		if ( 'failed' === $capture ) {
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--failed"><span class="dashicons dashicons-warning"></span>Capture failed — retry queued when selected</p>';
			return;
		}
		if ( 'pending' === $capture ) {
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--queued"><span class="dashicons dashicons-clock"></span>Queued for a fresh capture · start a manual batch when ready</p>';
			return;
		}
		if ( 'capturing' === $capture ) {
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--working" data-visual-active><span class="dashicons dashicons-camera"></span>GitHub is capturing this homepage now</p>';
			return;
		}
		$capture_label = MAC_Tracker_Time::bangkok_label( $row['captured_at'] ?? '' );
		if ( 'failed' === $tone ) {
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--failed"><span class="dashicons dashicons-warning"></span>Screenshot saved ' . esc_html( $capture_label ) . ' · AI failed</p>';
			return;
		}
		if ( 'analyzing' === $tone ) {
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--working" data-visual-active><span class="dashicons dashicons-admin-appearance"></span>Analyzing with ' . esc_html( $provider ?: 'Qwen 3.8' ) . '</p>';
			return;
		}
		if ( 'classified' === $tone ) {
			if ( $this->visual_is_manual( $row ) ) {
				echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--complete"><span class="dashicons dashicons-edit"></span>Tone đã được sửa tay</p>';
				return;
			}
			if ( 'Cần duyệt' === (string) ( $row['tone'] ?? '' ) ) {
				echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--queued"><span class="dashicons dashicons-visibility"></span>AI could not decide · choose the tone above</p>';
				return;
			}
			echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--complete"><span class="dashicons dashicons-yes-alt"></span>Screenshot ' . esc_html( $capture_label ) . ' · AI analyzed ' . esc_html( MAC_Tracker_Time::bangkok_label( $row['visual_updated_at'] ?? '' ) ) . '</p>';
			return;
		}
		echo '<p class="mac-tracker-visual-status mac-tracker-visual-status--queued"><span class="dashicons dashicons-update"></span>Screenshot saved ' . esc_html( $capture_label ) . ' · awaiting manual analysis</p>';
	}

	private function row_colors( array $row ) {
		$colors = json_decode( (string) ( $row['colors_json'] ?? '' ), true );
		if ( ! is_array( $colors ) ) {
			return array();
		}
		return array_slice( $this->sanitize_palette( $colors ), 0, 6 );
	}

	private function color_variables( array $row ) {
		$source = json_decode( (string) ( $row['color_source_raw'] ?? '' ), true );
		$variables = is_array( $source ) && ! empty( $source['variables'] ) && is_array( $source['variables'] ) ? $source['variables'] : array();
		if ( empty( $variables ) ) {
			return;
		}
		echo '<details class="mac-tracker-color-variables"><summary>Global variables</summary><p>';
		foreach ( $variables as $name => $value ) {
			echo '<code>' . esc_html( $name ) . '</code>';
		}
		echo '</p></details>';
	}

	private function sanitize_palette( $value ) {
		$values = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$colors = array();
		foreach ( (array) $values as $color ) {
			$color = strtoupper( trim( (string) $color ) );
			if ( preg_match( '/^#([A-F0-9]{3}|[A-F0-9]{6})$/', $color ) ) {
				if ( 4 === strlen( $color ) ) {
					$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
				}
				$colors[] = $color;
			}
		}
		return array_slice( array_values( array_unique( $colors ) ), 0, 6 );
	}

	private function project_label( array $row ) {
		$package = json_decode( $row['package_json'], true );
		$package = is_string( $package ) ? $package : '';
		$parts = array_filter( array( trim( $row['zip_code'] ), trim( $package ), trim( $row['name'] ) ) );
		return implode( ' ', $parts ) ?: 'Unnamed project';
	}

	private function project_link( array $row ) {
		$id  = (int) $row['wpm_project_id'];
		$url = 'https://wpm.macusaone.com/projects/' . $id . '/edit';
		echo '<a class="mac-tracker-project-name" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" title="Open project in WPM">' . esc_html( $this->project_label( $row ) ) . '<span class="dashicons dashicons-external"></span></a>';
	}

	private function person_name( $json ) {
		$person = json_decode( (string) $json, true );
		return is_array( $person ) ? $this->repository->canonical_person_name( $person['name'] ?? '' ) : '';
	}

	private function website_label( $url ) {
		$host = MAC_Tracker_Normalizer::host( $url );
		return '' !== $host ? $host : '—';
	}

	private function layout_label( $url ) {
		$parts = $this->layout_parts( $url );
		if ( $parts ) {
			return $parts['demo'] . ' - home' . ( '' === $parts['home_number'] ? '' : ' ' . $parts['home_number'] );
		}
		return '' === $this->first_url( $url ) ? '—' : 'Open layout';
	}

	/** Canonical public template destination; source links can be inconsistent. */
	private function layout_url( $url ) {
		$parts = $this->layout_parts( $url );
		if ( $parts ) {
			return 'https://templates.macusaone.com/' . $parts['demo'] . '/home' . ( '' === $parts['home_number'] ? '' : '-' . $parts['home_number'] ) . '/';
		}
		return $this->first_url( $url );
	}

	private function layout_parts( $value ) {
		$raw = trim( (string) $value );
		if ( preg_match( '#^(?:https?://)?(?:www\\.)?(demo-[a-z0-9]+)(?:/home(?:-([0-9]+))?)?/?$#i', $raw, $direct ) ) {
			$home = isset( $direct[2] ) ? str_pad( (string) (int) $direct[2], 2, '0', STR_PAD_LEFT ) : '';
			return array( 'demo' => strtolower( $direct[1] ), 'home_number' => '01' === $home ? '' : $home );
		}
		$value = $this->first_url( $raw );
		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		if ( preg_match( '/^(demo-[a-z0-9]+)$/i', $host, $host_match ) ) { return array( 'demo' => strtolower( $host_match[1] ), 'home_number' => '' ); }
		if ( '' === $value ) { return null; }
		if ( ! preg_match( '#(?:^|/)(demo-[a-z0-9]+)/(home(?:-([0-9]+))?)(?:/|$)#i', $value, $match ) ) {
			return preg_match( '/^demo-[a-z0-9]+$/i', $value, $host_match ) ? array( 'demo' => strtolower( $host_match[0] ), 'home_number' => '' ) : null;
		}
		$home_number = isset( $match[3] ) ? str_pad( (string) (int) $match[3], 2, '0', STR_PAD_LEFT ) : '';
		// "home" and "home-01" mean the default Home 01 in the template library.
		if ( '01' === $home_number ) { $home_number = ''; }
		return array( 'demo' => strtolower( $match[1] ), 'home_number' => $home_number );
	}

	private function url_link( $raw_url, $label ) {
		$url = $this->first_url( $raw_url );
		if ( '' === $url || '—' === $label ) { echo '—'; return; }
		if ( ! preg_match( '#^https?://#i', $url ) ) { $url = 'https://' . ltrim( $url, '/' ); }
		echo '<a class="mac-tracker-url" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '<span class="dashicons dashicons-external"></span></a>';
	}

	private function first_url( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '#https?://[^\s<>"\']+#i', $value, $match ) ) { return rtrim( $match[0], '.,)' ); }
		return preg_match( '#^(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:/[^\s<>"\']*)?$#i', $value ) ? rtrim( $value, '.,)' ) : '';
	}

	private function status_class( $status ) {
		$status = sanitize_key( $status );
		return in_array( $status, array( 'success', 'running', 'failed', 'waiting' ), true ) ? $status : 'muted';
	}

	private function require_capability() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to access MAC Tracker.' ) ); } }
	private function require_request( $action ) { $this->require_capability(); check_admin_referer( $action ); }
	private function redirect( $page, $message, $type ) { wp_safe_redirect( add_query_arg( array( 'page' => $page, 'mac_tracker_notice' => $message, 'mac_tracker_notice_type' => $type ), admin_url( 'admin.php' ) ) ); exit; }
}
