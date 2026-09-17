<?php

defined( 'ABSPATH' ) || exit;

/** WordPress-admin UI. All project rows come from local snapshot tables. */
class MAC_Tracker_Admin {

	private $repository;
	private $sync;
	private $colors;

	public function __construct( MAC_Tracker_Repository $repository, MAC_Tracker_Sync_Service $sync, MAC_Tracker_Elementor_Color_Service $colors ) {
		$this->repository = $repository;
		$this->sync       = $sync;
		$this->colors     = $colors;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
		add_action( 'wp_ajax_mac_tracker_run_visual_workflow', array( $this, 'handle_run_visual_workflow_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_action', array( $this, 'handle_visual_action_ajax' ) );
		add_action( 'wp_ajax_mac_tracker_visual_status', array( $this, 'handle_visual_status_ajax' ) );
	}

	public function register_menu() {
		add_menu_page( 'Projects', 'MAC Tracker', 'manage_options', 'mac-project-tracker', array( $this, 'render_projects' ), 'dashicons-chart-area', 30 );
		add_submenu_page( 'mac-project-tracker', 'Projects', 'Projects', 'manage_options', 'mac-project-tracker', array( $this, 'render_projects' ) );
		add_submenu_page( 'mac-project-tracker', 'Control room', 'Control room', 'manage_options', 'mac-project-tracker-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'mac-project-tracker', 'Color Review', 'Color Review', 'manage_options', 'mac-project-tracker-colors', array( $this, 'render_color_review' ) );
		add_submenu_page( 'mac-project-tracker', 'Visual Tone', 'Visual Tone', 'manage_options', 'mac-project-tracker-visuals', array( $this, 'render_visuals' ) );
		add_submenu_page( 'mac-project-tracker', 'Pin import', 'Pin import', 'manage_options', 'mac-project-tracker-pins', array( $this, 'render_pins' ) );
		add_submenu_page( 'mac-project-tracker', 'Settings', 'Settings', 'manage_options', 'mac-project-tracker-settings', array( $this, 'render_settings' ) );
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'mac-project-tracker' ) ) {
			return;
		}
		wp_enqueue_style( 'mac-tracker-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap', array(), null );
		wp_enqueue_style( 'mac-project-tracker-admin', MAC_TRACKER_URL . 'assets/admin.css', array( 'mac-tracker-fonts' ), MAC_TRACKER_VERSION );
		wp_enqueue_script( 'mac-project-tracker-admin', MAC_TRACKER_URL . 'assets/admin.js', array(), MAC_TRACKER_VERSION, true );
		wp_localize_script( 'mac-project-tracker-admin', 'macTrackerVisual', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'actionNonce' => wp_create_nonce( 'mac_tracker_requeue_visuals' ),
			'statusNonce' => wp_create_nonce( 'mac_tracker_visual_status' ),
			'runNonce'    => wp_create_nonce( 'mac_tracker_run_visual_workflow' ),
		) );
		wp_localize_script( 'mac-project-tracker-admin', 'macTrackerColors', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'extractNonce' => wp_create_nonce( 'mac_tracker_extract_elementor_colors' ),
			'extractAllNonce' => wp_create_nonce( 'mac_tracker_extract_all_elementor_colors' ),
			'approveNonce' => wp_create_nonce( 'mac_tracker_approve_colors' ),
		) );
	}

	public function render_dashboard() {
		$this->require_capability();
		$stats = $this->repository->dashboard_stats();
		$logs  = $this->repository->recent_logs();
		$this->page_start( 'Control room', 'A local cache for the websites your team has already delivered.', 'dashboard' );
		?>
		<section class="mac-tracker-overview" aria-label="Tracker summary">
			<div class="mac-tracker-overview__lead"><p class="mac-tracker-eyebrow">Snapshot ledger</p><h2>What is already safe to use</h2><p>Every count below comes from the local tracker database. Opening a page never waits for WPM.</p></div>
			<div class="mac-tracker-summary-grid">
				<?php $this->stat_card( 'Snapshots', (int) ( $stats['snapshots'] ?? 0 ), 'dashicons-media-spreadsheet' ); ?>
				<?php $this->stat_card( 'WPM projects', (int) ( $stats['projects'] ?? 0 ), 'dashicons-networking' ); ?>
				<?php $this->stat_card( 'Action Design', (int) ( $stats['action_design'] ?? 0 ), 'dashicons-admin-tools' ); ?>
				<?php $this->stat_card( 'CSV rows read', (int) get_option( 'mac_tracker_roster_source_rows', $this->repository->pin_count() ), 'dashicons-admin-links' ); ?>
			</div>
		</section>

		<section class="mac-tracker-workbench">
			<div class="mac-tracker-workbench__copy"><p class="mac-tracker-eyebrow">WPM → local cache</p><h2>Refresh the cache</h2><p>Manual sync completes in this request; existing cached rows remain intact.</p></div>
			<?php $this->sync_buttons(); ?>
		</section>

		<section class="mac-tracker-ledger">
			<div class="mac-tracker-ledger__head"><div><p class="mac-tracker-eyebrow">Recent activity</p><h2>Sync log</h2></div><span>Newest first</span></div>
			<?php $this->notices(); ?>
			<?php if ( empty( $logs ) ) : ?>
				<div class="mac-tracker-empty"><span class="dashicons dashicons-chart-line"></span><strong>No comparison yet</strong><p>Save the WPM connection, then run Compare baseline once.</p></div>
			<?php else : ?>
				<div class="mac-tracker-log-list">
					<?php foreach ( $logs as $log ) : ?>
						<div class="mac-tracker-log-row"><span class="mac-tracker-status mac-tracker-status--<?php echo esc_attr( $this->status_class( $log['status'] ) ); ?>"><?php echo esc_html( ucfirst( $log['status'] ) ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $log['processed'] ) ); ?> processed</strong><span><?php echo esc_html( $log['message'] ); ?></span><time><?php echo esc_html( MAC_Tracker_Time::bangkok_label( $log['started_at'] ) ); ?></time></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php $this->page_end();
	}

	public function render_projects() {
		$this->require_capability();
		$filters = $this->project_filters();
		$page    = $this->repository->project_page( $filters );
		$this->page_start( 'Project snapshots', 'Explore the full local cache. Sorting and filters never call WPM.', 'projects' );
		?>
		<section class="mac-tracker-project-intro">
			<div><p class="mac-tracker-eyebrow">Local cache</p><h2><?php echo esc_html( number_format_i18n( $page['total'] ) ); ?> snapshot<?php echo 1 === (int) $page['total'] ? '' : 's'; ?></h2><p>Showing <?php echo 0 === (int) $page['per_page'] ? 'all cached rows' : 'one page of cached rows'; ?>. Syncing never adds rows one at a time in this screen.</p></div>
			<?php $this->sync_buttons(); ?>
		</section>
		<?php $this->notices(); ?>

		<form class="mac-tracker-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="mac-project-tracker">
			<label><span>Search</span><input name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Project, website, ZIP or WPM ID"></label>
			<label><span>Assignee</span><select name="assignee"><option value="">All assignees</option><?php foreach ( $this->repository->list_assignees() as $name ) : ?><option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['assignee'], $name ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label>
			<label><span>Snapshot type</span><select name="kind"><option value="">All types</option><option value="action_design" <?php selected( $filters['kind'], 'action_design' ); ?>>Action Design</option><option value="csv_pin" <?php selected( $filters['kind'], 'csv_pin' ); ?>>CSV pin</option></select></label>
			<label><span>Website</span><select name="website"><option value="">Any</option><option value="yes" <?php selected( $filters['website'], 'yes' ); ?>>Has website</option><option value="no" <?php selected( $filters['website'], 'no' ); ?>>Missing</option></select></label>
			<label><span>Layout</span><select name="layout"><option value="">Any</option><option value="yes" <?php selected( $filters['layout'], 'yes' ); ?>>Has layout</option><option value="no" <?php selected( $filters['layout'], 'no' ); ?>>Missing</option></select></label>
			<label><span>AI tone</span><select name="tone"><option value="">All tones</option><option value="pending" <?php selected( $filters['tone'], 'pending' ); ?>>Waiting for AI</option><?php foreach ( $this->tone_options() as $tone ) : ?><option value="<?php echo esc_attr( $tone ); ?>" <?php selected( $filters['tone'], $tone ); ?>><?php echo esc_html( $tone ); ?></option><?php endforeach; ?></select></label>
			<label><span>From month</span><input type="month" name="month_from" value="<?php echo esc_attr( $filters['month_from'] ); ?>"></label>
			<label><span>To month</span><input type="month" name="month_to" value="<?php echo esc_attr( $filters['month_to'] ); ?>"></label>
			<label><span>Rows</span><select name="per_page"><option value="50" <?php selected( $filters['per_page'], 50 ); ?>>50</option><option value="100" <?php selected( $filters['per_page'], 100 ); ?>>100</option><option value="150" <?php selected( $filters['per_page'], 150 ); ?>>150</option><option value="200" <?php selected( $filters['per_page'], 200 ); ?>>200</option><option value="0" <?php selected( $filters['per_page'], 0 ); ?>>All</option></select></label>
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $filters['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $filters['order'] ); ?>">
			<div class="mac-tracker-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mac-project-tracker' ) ); ?>">Clear</a></div>
		</form>

		<section class="mac-tracker-table-shell">
			<?php if ( empty( $page['rows'] ) ) : ?>
				<div class="mac-tracker-empty"><span class="dashicons dashicons-archive"></span><strong>No project snapshots yet</strong><p>Import the pin baseline or save WPM Settings and run a background sync.</p></div>
			<?php else : ?>
				<div class="mac-tracker-table-scroll"><table class="widefat striped mac-tracker-project-table"><thead><tr>
					<th scope="col" class="mac-tracker-col--index">#</th><?php $this->sort_header( 'id', 'ID', $filters ); ?><?php $this->sort_header( 'project', 'Project', $filters ); ?><?php $this->sort_header( 'website', 'Website', $filters ); ?><?php $this->sort_header( 'layout', 'Layout', $filters ); ?><?php $this->sort_header( 'assignee', 'Assignee', $filters ); ?><?php $this->sort_header( 'date', 'Date', $filters, 'mac-tracker-col--date' ); ?><?php $this->sort_header( 'palette', 'Palette', $filters ); ?><?php $this->sort_header( 'tone', 'AI tone', $filters ); ?>
				</tr></thead><tbody>
					<?php $row_number = 1 + ( 0 === (int) $page['per_page'] ? 0 : ( (int) $page['paged'] - 1 ) * (int) $page['per_page'] ); foreach ( $page['rows'] as $row ) : ?>
						<tr>
							<td class="mac-tracker-row-index"><?php echo esc_html( $row_number++ ); ?></td>
							<td class="mac-tracker-id"><?php $this->project_id_link( $row ); ?><?php $this->task_id_link( $row ); ?></td>
			<td><?php $this->project_link( $row ); ?><?php $this->confidence_badge( $row ); ?><?php $this->baseline_override_badge( $row ); ?><button class="mac-tracker-edit-link" type="button" title="Edit project" aria-label="Edit project" data-edit-target="edit-<?php echo (int) $row['id']; ?>"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button></td>
							<td><?php $this->url_link( $row['website_url'], $this->website_label( $row['website_url'] ) ); ?></td>
							<td><?php $this->url_link( $this->layout_url( $row['layout_url'] ), $this->layout_label( $row['layout_url'] ) ); ?></td>
							<td><?php echo esc_html( $this->person_name( $row['assignee_json'] ) ?: '—' ); ?></td>
							<td class="mac-tracker-date mac-tracker-date--day"><?php echo esc_html( MAC_Tracker_Time::bangkok_date( $row['task_completed_at'] ) ); ?></td>
							<td><?php $this->palette_cell( $row ); ?></td>
							<td><?php $this->tone_cell( $row ); ?></td>
						</tr>
		<tr id="edit-<?php echo (int) $row['id']; ?>" class="mac-tracker-edit-row" hidden><td colspan="9"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_edit_project' ); ?><input type="hidden" name="action" value="mac_tracker_edit_project"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><label>Project ID<input type="number" min="1" name="project_id" value="<?php echo (int) $row['wpm_project_id']; ?>" required></label><label>Project name<input type="text" name="project_name" value="<?php echo esc_attr( $row['name'] ); ?>" required></label><label>Date & time<input type="datetime-local" name="date_time" value="<?php echo esc_attr( MAC_Tracker_Time::bangkok_input( $row['task_completed_at'] ) ); ?>"></label><button class="button button-primary" type="submit">Save project</button><button class="button" type="button" data-edit-target="edit-<?php echo (int) $row['id']; ?>">Cancel</button></form></td></tr>
					<?php endforeach; ?>
				</tbody></table></div>
				<?php $this->pagination( $page, $filters ); ?>
			<?php endif; ?>
		</section>
		<?php $this->page_end();
	}

	/** Review and approve only extracted Elementor Global Color variables. */
	public function render_color_review() {
		$this->require_capability();
		$rows = $this->repository->color_review_rows();
		$extract_state = $this->colors->extraction_state();
		$this->page_start( 'Color Review', 'Read Elementor Global Color variables from each website. Images, ordinary CSS, OCR and OneDrive are not used here.', 'colors' );
		?>
		<section class="mac-tracker-color-intro">
			<div><p class="mac-tracker-eyebrow">Elementor only</p><h2>Global color variables, ready for review</h2><p>Extract reads <code>--e-global-color-*</code> from the website HTML and Elementor stylesheets. Approving locks the palette so a later extraction cannot replace it.</p></div>
			<div class="mac-tracker-color-intro__count"><strong><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></strong><span>websites available</span></div>
			<form class="mac-tracker-color-extract-all" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_extract_all_elementor_colors' ); ?><input type="hidden" name="action" value="mac_tracker_extract_all_elementor_colors"><button class="button button-primary" type="submit"><span class="dashicons dashicons-art"></span>Extract all</button><small>Only websites with no prior extraction. Runs in background batches.</small></form>
		</section>
		<?php if ( ! empty( $extract_state ) ) : ?><p class="mac-tracker-color-progress"><span class="mac-tracker-status mac-tracker-status--<?php echo esc_attr( 'complete' === ( $extract_state['status'] ?? '' ) ? 'success' : 'waiting' ); ?>"><?php echo esc_html( $extract_state['status'] ?? 'queued' ); ?></span><?php echo esc_html( sprintf( '%d processed · %d palettes found · %d failed · %d remaining', (int) ( $extract_state['processed'] ?? 0 ), (int) ( $extract_state['found'] ?? 0 ), (int) ( $extract_state['failed'] ?? 0 ), (int) ( $extract_state['remaining'] ?? 0 ) ) ); ?></p><?php endif; ?>
		<?php $this->notices(); ?>
		<nav class="mac-tracker-color-tabs" aria-label="Color review status"><button type="button" class="is-active" data-color-tab="pending">Chưa duyệt <span><?php echo esc_html( count( array_filter( $rows, function ( $row ) { return empty( $row['color_locked'] ); } ) ) ); ?></span></button><button type="button" data-color-tab="approved">Đã duyệt <span><?php echo esc_html( count( array_filter( $rows, function ( $row ) { return ! empty( $row['color_locked'] ); } ) ) ); ?></span></button></nav>
		<section class="mac-tracker-color-review" aria-label="Color review queue">
			<?php if ( empty( $rows ) ) : ?>
				<div class="mac-tracker-empty"><span class="dashicons dashicons-art"></span><strong>No websites available</strong><p>Color Review only lists current project rows that have a website URL.</p></div>
			<?php else : foreach ( $rows as $row ) : $colors = $this->row_colors( $row ); ?>
				<article class="mac-tracker-color-card <?php echo ! empty( $row['color_locked'] ) ? 'is-approved' : ''; ?>" data-color-card="<?php echo (int) $row['id']; ?>" data-color-status="<?php echo ! empty( $row['color_locked'] ) ? 'approved' : 'pending'; ?>">
					<div class="mac-tracker-color-card__head"><div><p class="mac-tracker-eyebrow"><?php echo esc_html( ! empty( $row['color_locked'] ) ? 'Approved palette' : ( $colors ? 'Awaiting review' : 'Not extracted' ) ); ?></p><h2><?php $this->project_link( $row ); ?></h2><p><?php $this->url_link( $row['website_url'], $this->website_label( $row['website_url'] ) ); ?></p></div><span class="mac-tracker-status mac-tracker-status--<?php echo esc_attr( ! empty( $row['color_locked'] ) ? 'success' : ( $colors ? 'waiting' : 'muted' ) ); ?>"><?php echo esc_html( ! empty( $row['color_locked'] ) ? 'Approved' : ( $colors ? 'Review' : 'New' ) ); ?></span></div>
					<?php if ( $colors ) : ?><div class="mac-tracker-color-swatches" aria-label="Extracted colors"><?php foreach ( $colors as $color ) : ?><span title="<?php echo esc_attr( $color ); ?>" style="--mac-tracker-swatch: <?php echo esc_attr( $color ); ?>"><i></i><code><?php echo esc_html( $color ); ?></code></span><?php endforeach; ?></div><?php endif; ?>
					<?php $this->color_variables( $row ); ?>
					<div class="mac-tracker-color-card__actions">
						<?php if ( empty( $row['color_locked'] ) ) : ?>
							<form class="mac-tracker-color-extract" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_extract_elementor_colors' ); ?><input type="hidden" name="action" value="mac_tracker_extract_elementor_colors"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><button class="button" type="submit"><span class="dashicons dashicons-art"></span><?php echo $colors ? 'Extract again' : 'Extract Elementor colors'; ?></button></form>
							<?php if ( $colors ) : ?><form class="mac-tracker-color-approve" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_approve_colors' ); ?><input type="hidden" name="action" value="mac_tracker_approve_colors"><input type="hidden" name="snapshot_id" value="<?php echo (int) $row['id']; ?>"><label><span>Palette</span><input name="colors" value="<?php echo esc_attr( implode( ', ', $colors ) ); ?>"></label><button class="button button-primary" type="submit">Approve palette</button></form><?php endif; ?>
						<?php else : ?><span class="mac-tracker-color-lock"><span class="dashicons dashicons-lock"></span>Đã khóa sau khi duyệt</span><?php endif; ?>
					</div>
				</article>
			<?php endforeach; endif; ?>
		</section>
		<?php $this->page_end();
	}

	public function render_pins() {
		$this->require_capability();
		$this->page_start( 'Pin baseline', 'Import the approved manual project list. Pins remain if WPM later removes a project.', 'pins' );
		$this->notices();
		?>
		<section class="mac-tracker-panel mac-tracker-panel--narrow"><div class="mac-tracker-panel__head"><div><p class="mac-tracker-eyebrow">Master roster import</p><h2><?php echo esc_html( number_format_i18n( $this->repository->pin_count() ) ); ?> saved CSV pins</h2><p>Import the original CSV baseline. Run Compare baseline once to overlay matching WPM Action Design records; the source CSV rows stay preserved locally.</p></div></div>
		<form class="mac-tracker-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mac_tracker_import_pins' ); ?><input type="hidden" name="action" value="mac_tracker_import_pins"><label><span>CSV file</span><input type="file" name="pin_csv" accept=".csv,text/csv" required></label><button class="button button-primary" type="submit">Import pins</button>
		</form></section>
		<section class="mac-tracker-danger-zone" aria-label="Reset local tracker data"><div><p class="mac-tracker-eyebrow">Start over</p><h2>Clear local tracker data</h2><p>Deletes local snapshots, imported pins, color records and sync logs. WPM connection settings and your CSV file are kept.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Clear all local tracker data? This cannot be undone.');"><?php wp_nonce_field( 'mac_tracker_clear_data' ); ?><input type="hidden" name="action" value="mac_tracker_clear_data"><button class="button mac-tracker-button--danger" type="submit">Clear all data</button></form></section>
		<?php $this->page_end();
	}

	public function render_visuals() {
		$this->require_capability();
		$stats = $this->repository->visual_stats();
		$rows  = $this->repository->visual_review_rows();
		$visual_mode = 'auto' === get_option( 'mac_tracker_visual_mode', 'manual' ) ? 'auto' : 'manual';
		$ai_strategy = in_array( get_option( 'mac_tracker_visual_ai_strategy', 'smart' ), array( 'off', 'qwen', 'gemini', 'smart' ), true ) ? get_option( 'mac_tracker_visual_ai_strategy', 'smart' ) : 'smart';
		$auto_accept = max( 0.75, min( 0.99, (float) get_option( 'mac_tracker_visual_auto_accept', 0.85 ) ) );
		$max_retries = max( 0, min( 3, absint( get_option( 'mac_tracker_visual_max_capture_retries', 3 ) ) ) );
		$queue_count = 0;
		$review_count = 0;
		$classified_count = 0;
		foreach ( $rows as $visual_row ) {
			$is_final_tone = 'classified' === (string) ( $visual_row['tone_status'] ?? '' ) && in_array( (string) ( $visual_row['tone'] ?? '' ), $this->tone_options(), true ) && 'Cần duyệt' !== (string) ( $visual_row['tone'] ?? '' );
			if ( $is_final_tone ) {
				++$classified_count;
			} elseif ( 'needs_review' === (string) ( $visual_row['pipeline_status'] ?? '' ) ) {
				++$review_count;
			} else {
				++$queue_count;
			}
		}
		$this->page_start( 'Visual Tone', 'Manual review is active while Visual Tone V2 is rebuilt. Opening this page only reads saved data.', 'visuals' );
		?>
		<section class="mac-tracker-overview mac-tracker-visual-overview"><div class="mac-tracker-overview__lead"><p class="mac-tracker-eyebrow">Visual Tone V2 · Control room</p><h2>Manual-first, evidence-led review</h2><p>Saved captures and deterministic UI evidence are reviewed by the free-only AI chain. Cards only poll while a live worker lease is active.</p></div><div class="mac-tracker-summary-grid"><?php $this->stat_card( 'Captured', (int) ( $stats['captured'] ?? 0 ), 'dashicons-format-image' ); ?><?php $this->stat_card( 'Completed', (int) ( $stats['classified'] ?? 0 ), 'dashicons-yes-alt' ); ?><?php $this->stat_card( 'Needs review', $review_count, 'dashicons-visibility' ); ?></div></section>
		<section class="mac-tracker-visual-auto"><div><p class="mac-tracker-eyebrow">Visual Tone Automation</p><h2><?php echo 'auto' === $visual_mode ? 'Auto processing enabled' : 'Manual review first'; ?></h2><p>Mode: <strong><?php echo 'auto' === $visual_mode ? 'AUTO' : 'MANUAL'; ?></strong> · AI: <strong><?php echo esc_html( 'smart' === $ai_strategy ? 'Qwen → Gemini judge' : strtoupper( $ai_strategy ) ); ?></strong>. Manual buttons always remain available.</p></div><div class="mac-tracker-visual-auto__actions"><form class="mac-tracker-visual-mode" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_save_visual_mode' ); ?><input type="hidden" name="action" value="mac_tracker_save_visual_mode"><fieldset><legend class="screen-reader-text">Visual Tone mode</legend><label><input type="radio" name="visual_mode" value="manual"<?php checked( 'manual', $visual_mode ); ?>> <span>Manual</span></label><label><input type="radio" name="visual_mode" value="auto"<?php checked( 'auto', $visual_mode ); ?>> <span>Auto</span></label></fieldset><label class="screen-reader-text" for="mac-tracker-ai-strategy">AI strategy</label><select id="mac-tracker-ai-strategy" name="ai_strategy"><option value="off"<?php selected( 'off', $ai_strategy ); ?>>AI off</option><option value="qwen"<?php selected( 'qwen', $ai_strategy ); ?>>Qwen only</option><option value="gemini"<?php selected( 'gemini', $ai_strategy ); ?>>Gemini only</option><option value="smart"<?php selected( 'smart', $ai_strategy ); ?>>Smart auto</option></select><label>Accept ≥ <input type="number" name="auto_accept" min="0.75" max="0.99" step="0.01" value="<?php echo esc_attr( $auto_accept ); ?>"></label><label>Retries <input type="number" name="max_capture_retries" min="0" max="3" step="1" value="<?php echo esc_attr( $max_retries ); ?>"></label><button class="button" type="submit">Save controls</button></form><?php if ( get_option( 'mac_tracker_github_dispatch_token', '' ) ) : ?><form class="mac-tracker-visual-run" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_run_visual_workflow' ); ?><input type="hidden" name="action" value="mac_tracker_run_visual_workflow"><button class="button button-primary" type="submit"><span class="dashicons dashicons-controls-play"></span>Run batch now</button></form><?php else : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mac-project-tracker-settings#mac-tracker-github-dispatch' ) ); ?>">Enable manual run</a><?php endif; ?><span class="mac-tracker-visual-auto__signal <?php echo 'auto' === $visual_mode ? '' : 'is-manual'; ?>"><i></i><?php echo 'auto' === $visual_mode ? 'Scheduled processing on' : 'Scheduled processing off'; ?></span></div></section>
		<form class="mac-tracker-visual-work" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mac_tracker_requeue_visuals' ); ?>
			<input type="hidden" name="action" value="mac_tracker_requeue_visuals">
			<section class="mac-tracker-visual-tools" aria-label="Visual review actions"><div><p class="mac-tracker-eyebrow">Targeted retry</p><strong>Use saved screenshots first</strong><span>Analyze again costs no new capture. Capture again refreshes only sites whose design changed.</span></div><div class="mac-tracker-visual-tools__actions"><label class="mac-tracker-select-all"><input type="checkbox" data-visual-select-all><span>Select all in this tab</span></label><button class="button" type="submit" name="visual_action" value="reanalyze_selected">Analyze selected</button><button class="button" type="submit" name="visual_action" value="recapture_selected">Capture selected again</button><button class="button" type="submit" name="visual_action" value="retry_failed">Retry failed</button><button class="button" type="submit" name="visual_action" value="reanalyze_all">Analyze all stored</button></div></section>
			<nav class="mac-tracker-visual-tabs" aria-label="Visual tone status"><button type="button" class="is-active" data-visual-tab="queue">Queue &amp; processing <span><?php echo esc_html( $queue_count ); ?></span></button><button type="button" data-visual-tab="review">Cần duyệt <span><?php echo esc_html( $review_count ); ?></span></button><button type="button" data-visual-tab="completed">Completed <span><?php echo esc_html( $classified_count ); ?></span></button></nav>
			<section class="mac-tracker-visual-gallery" aria-label="Visual tone gallery">
				<?php if ( empty( $rows ) ) : ?>
					<div class="mac-tracker-empty"><span class="dashicons dashicons-format-image"></span><strong>No screenshots yet</strong><p>Use a manual capture action when you are ready to create the first card.</p></div>
				<?php else : foreach ( $rows as $row ) : $visual_status = ( 'classified' === (string) ( $row['tone_status'] ?? '' ) && in_array( (string) ( $row['tone'] ?? '' ), $this->tone_options(), true ) && 'Cần duyệt' !== (string) ( $row['tone'] ?? '' ) ) ? 'completed' : ( 'needs_review' === (string) ( $row['pipeline_status'] ?? '' ) ? 'review' : 'queue' ); ?>
					<article class="mac-tracker-visual-card<?php echo $this->visual_is_manual( $row ) ? ' is-manual-tone' : ''; ?>" id="visual-<?php echo (int) $row['id']; ?>" data-visual-card="<?php echo (int) $row['id']; ?>" data-visual-status="<?php echo esc_attr( $visual_status ); ?>">
						<label class="mac-tracker-visual-card__select"><input type="checkbox" name="snapshot_ids[]" value="<?php echo (int) $row['id']; ?>" data-visual-select><span>Select</span></label><a class="mac-tracker-visual-card__image" href="<?php echo esc_url( $row['screenshot_url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $row['screenshot_url'] ); ?>" alt="<?php echo esc_attr( $row['name'] . ' homepage screenshot' ); ?>" loading="lazy"><span>Open full capture <span class="dashicons dashicons-external"></span></span></a><div class="mac-tracker-visual-card__body"><p class="mac-tracker-eyebrow">#<?php echo esc_html( $row['wpm_project_id'] ); ?> · <?php echo esc_html( MAC_Tracker_Time::bangkok_date( $row['task_completed_at'] ) ); ?></p><?php $this->project_link( $row ); ?><div class="mac-tracker-visual-card__tone"><?php $this->tone_cell( $row, false ); ?></div><?php $this->visual_status_cell( $row ); ?><?php if ( ! $this->visual_is_manual( $row ) ) : ?><p data-visual-reason><?php echo esc_html( $row['tone_reason'] ?: ( 'classified' === $row['tone_status'] ? 'AI classified the rendered UI.' : 'Awaiting a manual analysis action.' ) ); ?></p><?php endif; ?><?php $this->visual_debug_panel( $row ); ?><div class="mac-tracker-visual-card__actions"><?php if ( $this->visual_is_manual( $row ) ) : ?><button type="submit" class="button-link" name="visual_action" value="unlock_tone_<?php echo (int) $row['id']; ?>" title="Allow a future AI result to update this card">Unlock AI</button><?php else : ?><button type="submit" class="button-link" name="visual_action" value="reanalyze_one_<?php echo (int) $row['id']; ?>" title="Use this stored screenshot again">Analyze again</button><button type="submit" class="button-link" name="visual_action" value="recapture_one_<?php echo (int) $row['id']; ?>" title="Take a fresh full-page screenshot">Capture again</button><?php endif; ?></div></div>
					</article>
				<?php endforeach; endif; ?>
			</section>
		</form>
		<section class="mac-tracker-danger-zone" aria-label="Reset Visual Tone data"><div><p class="mac-tracker-eyebrow">Reset Visual Tone</p><h2>Clear all Visual Tone data</h2><p>Deletes every Visual Tone capture, tone result, queue state and stored screenshot. Projects, pins, Color Review and WPM sync data are kept. Mode returns to Manual.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Clear all Visual Tone data and screenshots? This cannot be undone.');"><?php wp_nonce_field( 'mac_tracker_clear_visual_data' ); ?><input type="hidden" name="action" value="mac_tracker_clear_visual_data"><button class="button mac-tracker-button--danger" type="submit">Clear Visual Tone data</button></form></section>
		<?php $this->page_end();
	}

	public function render_settings() {
		$this->require_capability();
		$settings     = (array) get_option( 'mac_tracker_settings', array() );
		$this->page_start( 'Connection settings', 'The API header value is encrypted in this WordPress database and is never displayed again.', 'settings' );
		$this->notices();
		?>
		<section class="mac-tracker-panel mac-tracker-panel--narrow"><div class="mac-tracker-panel__head"><div><p class="mac-tracker-eyebrow">WPM REST API</p><h2>Connect the tracker</h2><p>Requests use the fixed <code>Tracking-Template-Header</code> header. Sync runs through WP-Cron after saving.</p></div></div>
		<form class="mac-tracker-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mac_tracker_save_settings' ); ?><input type="hidden" name="action" value="mac_tracker_save_settings">
			<label><span>WPM endpoint</span><input type="url" name="wpm_endpoint" required placeholder="https://wpm.macusaone.com/api/v1/tracking-template/projects" value="<?php echo esc_attr( $settings['wpm_endpoint'] ?? '' ); ?>"><small>Use exactly <code>https://wpm.macusaone.com/api/v1/tracking-template/projects</code>. The list endpoint returns projects with tasks.</small></label>
			<label><span>Tracking-Template-Header value</span><input type="password" name="wpm_secret" autocomplete="new-password" placeholder="<?php echo get_option( 'mac_tracker_wpm_secret', '' ) ? 'Saved — leave blank to keep it' : 'Paste API value'; ?>"><small>Leave blank when editing other settings to keep the saved value.</small></label>
			<label><span>Automation shared secret</span><input type="password" name="visual_secret" autocomplete="new-password" placeholder="<?php echo get_option( 'mac_tracker_visual_secret', '' ) ? 'Saved — leave blank to keep it' : 'Paste a long random value'; ?>"><small>Use the exact same value for GitHub Secret <code>MAC_TRACKER_AUTOMATION_SECRET</code>. It protects the private screenshot queue and upload endpoint.</small></label>
			<label id="mac-tracker-github-dispatch"><span>GitHub Run now token</span><input type="password" name="github_dispatch_token" autocomplete="new-password" placeholder="<?php echo get_option( 'mac_tracker_github_dispatch_token', '' ) ? 'Saved — leave blank to keep it' : 'Fine-grained token'; ?>"><small>Optional. Enables the Visual Tone <strong>Run now</strong> button. Create a fine-grained GitHub token scoped only to <code>LeoVo2003/Template-Tracking</code> with repository permission <code>Actions: Write</code>.</small></label>
			<div class="mac-tracker-settings-note"><span class="dashicons dashicons-shield"></span><p>Credentials are encrypted at rest with this WordPress site's authentication salt. They are not rendered in this page or written to sync logs.</p></div>
			<div class="mac-tracker-settings-actions"><button class="button button-primary" type="submit">Save connection</button></div>
		</form></section>
		<section class="mac-tracker-connection-check" aria-label="Connection test"><div><p class="mac-tracker-eyebrow">Safe check</p><h2>Test before syncing</h2><p>Checks one WPM response only. It does not create, update, or remove project snapshots.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mac_tracker_test_connection' ); ?><input type="hidden" name="action" value="mac_tracker_test_connection"><button class="button" type="submit">Test connection</button></form></section>
		<?php $this->page_end();
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
		update_option( 'mac_tracker_visual_mode', $mode, false );
		update_option( 'mac_tracker_visual_ai_strategy', $strategy, false );
		update_option( 'mac_tracker_visual_auto_accept', $auto_accept, false );
		update_option( 'mac_tracker_visual_max_capture_retries', $max_retries, false );
		$this->redirect( 'mac-project-tracker-visuals', sprintf( 'Visual Tone controls saved: %s mode, %s AI strategy.', strtoupper( $mode ), strtoupper( $strategy ) ), 'success' );
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
	private function dispatch_visual_workflow( $limit = 10, $run_mode = 'batch', $stage = 'full', array $target_ids = array() ) {
		$limit = max( 1, min( 25, absint( $limit ) ) );
		$run_mode = 'targeted' === $run_mode ? 'targeted' : 'batch';
		$stage = in_array( $stage, array( 'capture', 'tone', 'full', 'auto' ), true ) ? $stage : 'full';
		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );
		$target_ids = array_slice( $target_ids, 0, 25 );
		if ( 'targeted' === $run_mode && empty( $target_ids ) ) {
			return new WP_Error( 'mac_tracker_visual_targets', 'A targeted Visual Tone action needs at least one valid selected card.' );
		}
		if ( 'targeted' === $run_mode ) { $limit = min( $limit, count( $target_ids ) ); }
		$token = MAC_Tracker_Crypto::decrypt( get_option( 'mac_tracker_github_dispatch_token', '' ) );
		if ( '' === $token ) {
			return new WP_Error( 'mac_tracker_github_token', 'Queued, but GitHub could not start because the manual Run now token is missing.' );
		}
		$response = wp_remote_post(
			'https://api.github.com/repos/LeoVo2003/Template-Tracking/actions/workflows/capture-visual-tone.yml/dispatches',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10', 'User-Agent' => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'ref' => 'main', 'inputs' => array( 'run_mode' => $run_mode, 'stage' => $stage, 'target_ids' => wp_json_encode( $target_ids ), 'limit' => (string) $limit ) ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mac_tracker_github_request', 'Queued, but GitHub could not start now: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'mac_tracker_github_response', 'Queued, but GitHub rejected the immediate run (HTTP ' . $status . '). Check Actions: Write.' );
		}
		return true;
	}

	/** Shared queue operation used by the normal form and the in-page AJAX controls. */
	private function process_visual_action( $action, array $ids, array $manual_tones = array() ) {
		if ( preg_match( '/^save_tone_(\d+)$/', $action, $tone_match ) ) {
			$result = $this->repository->save_manual_visual_tone( absint( $tone_match[1] ), $manual_tones[ absint( $tone_match[1] ) ] ?? '' );
			return array( 'result' => $result, 'message' => 'Visual tone reviewed and saved.', 'dispatch' => false );
		}
		if ( preg_match( '/^unlock_tone_(\d+)$/', $action, $unlock_match ) ) {
			$result = $this->repository->unlock_manual_visual_tone( absint( $unlock_match[1] ) );
			return array( 'result' => $result, 'message' => 'Manual tone lock removed. The next AI analysis may update this card.', 'dispatch' => false );
		}
		if ( preg_match( '/^(reanalyze_one|recapture_one)_(\d+)$/', $action, $one_match ) ) {
			$action = $one_match[1];
			$ids = array( absint( $one_match[2] ) );
		}
		if ( 'reanalyze_all' === $action ) {
			$result = $this->repository->requeue_visual_tones();
			$message = sprintf( '%d stored screenshot(s) queued for AI analysis.', (int) $result );
		} elseif ( 'retry_failed' === $action ) {
			$result = $this->repository->requeue_failed_visual_items();
			$message = sprintf( '%d failed visual job(s) queued again.', (int) $result );
		} else {
			$mode = in_array( $action, array( 'reanalyze_selected', 'reanalyze_one' ), true ) ? 'reanalyze' : ( in_array( $action, array( 'recapture_selected', 'recapture_one' ), true ) ? 'recapture' : '' );
			$result = $this->repository->requeue_visual_items( $ids, $mode );
			$message = sprintf( '%d selected screenshot(s) queued to %s.', is_wp_error( $result ) ? 0 : (int) $result, 'recapture' === $mode ? 'capture again' : 'analyze again' );
		}
		if ( is_wp_error( $result ) || (int) $result <= 0 ) { return array( 'result' => $result, 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'No eligible screenshot changed. Check the selected card status, then try again.', 'dispatch' => false ); }
		$run_mode = 'batch';
		$stage = 'full';
		$dispatch_ids = array();
		if ( in_array( $action, array( 'reanalyze_selected', 'reanalyze_one' ), true ) ) {
			$run_mode = 'targeted';
			$stage = 'tone';
			$dispatch_ids = $ids;
		} elseif ( in_array( $action, array( 'recapture_selected', 'recapture_one' ), true ) ) {
			$run_mode = 'targeted';
			$stage = 'capture';
			$dispatch_ids = $ids;
		}
		$dispatch = $this->dispatch_visual_workflow( (int) $result, $run_mode, $stage, $dispatch_ids );
		$message .= is_wp_error( $dispatch ) ? ' ' . $dispatch->get_error_message() : ' GitHub batch started now.';
		return array( 'result' => $result, 'message' => $message, 'dispatch' => ! is_wp_error( $dispatch ) );
	}

	public function handle_visual_action_ajax() {
		check_ajax_referer( 'mac_tracker_requeue_visuals', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to run visual actions.' ), 403 ); }
		$action = sanitize_key( wp_unslash( $_POST['visual_action'] ?? '' ) );
		$ids = isset( $_POST['snapshot_ids'] ) && is_array( $_POST['snapshot_ids'] ) ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['snapshot_ids'] ) ) ) ) : array();
		$manual = isset( $_POST['manual_tone'] ) && is_array( $_POST['manual_tone'] ) ? wp_unslash( $_POST['manual_tone'] ) : array();
		$processed = $this->process_visual_action( $action, $ids, $manual );
		if ( is_wp_error( $processed['result'] ) || (int) $processed['result'] <= 0 ) { wp_send_json_error( array( 'message' => $processed['message'] ) ); }
		wp_send_json_success( array( 'message' => $processed['message'], 'ids' => $ids, 'stats' => $this->repository->visual_stats() ) );
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
			$statuses[] = array( 'id' => (int) $row['id'], 'pipeline_status' => (string) ( $row['pipeline_status'] ?? 'idle' ), 'capture_status' => (string) ( $row['capture_status'] ?? '' ), 'tone_status' => (string) ( $row['tone_status'] ?? '' ), 'tone' => (string) ( $row['tone'] ?? '' ), 'tone_reason' => $manual ? '' : (string) ( $row['tone_reason'] ?? '' ), 'manual' => $manual, 'provider' => (string) ( $row['ai_provider'] ?? '' ), 'model' => (string) ( $row['ai_model'] ?? '' ), 'last_error_code' => (string) ( $row['last_error_code'] ?? '' ), 'last_error_message' => (string) ( $row['last_error_message'] ?? '' ), 'next_retry_at' => (string) ( $row['next_retry_at'] ?? '' ), 'screenshot_url' => esc_url_raw( $row['screenshot_url'] ?? '' ), 'claimed_at' => (string) ( $row['claimed_at'] ?? '' ), 'lease_until' => (string) ( $row['lease_until'] ?? '' ) );
		}
		wp_send_json_success( array( 'statuses' => $statuses, 'stats' => $this->repository->visual_stats() ) );
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
		if ( preg_match( '/^(reanalyze_one|recapture_one)_(\d+)$/', $action, $one_match ) ) {
			$action = $one_match[1];
			$ids = array( absint( $one_match[2] ) );
		}
		if ( 'reanalyze_all' === $action ) {
			$result = $this->repository->requeue_visual_tones();
			$message = sprintf( '%d stored screenshot(s) queued for AI analysis.', (int) $result );
		} elseif ( 'retry_failed' === $action ) {
			$result = $this->repository->requeue_failed_visual_items();
			$message = sprintf( '%d failed visual job(s) queued again.', (int) $result );
		} else {
			$mode = in_array( $action, array( 'reanalyze_selected', 'reanalyze_one' ), true ) ? 'reanalyze' : ( in_array( $action, array( 'recapture_selected', 'recapture_one' ), true ) ? 'recapture' : '' );
			$result = $this->repository->requeue_visual_items( $ids, $mode );
			$message = sprintf( '%d selected screenshot(s) queued to %s.', is_wp_error( $result ) ? 0 : (int) $result, 'recapture' === $mode ? 'capture again' : 'analyze again' );
		}
		if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker-visuals', $result->get_error_message(), 'error' ); }
		if ( (int) $result <= 0 ) { $this->redirect( 'mac-project-tracker-visuals', 'No eligible screenshot changed. Check the selected card status, then try again.', 'warning' ); }
		$run_mode = 'batch';
		$stage = 'full';
		$dispatch_ids = array();
		if ( in_array( $action, array( 'reanalyze_selected', 'reanalyze_one' ), true ) ) {
			$run_mode = 'targeted';
			$stage = 'tone';
			$dispatch_ids = $ids;
		} elseif ( in_array( $action, array( 'recapture_selected', 'recapture_one' ), true ) ) {
			$run_mode = 'targeted';
			$stage = 'capture';
			$dispatch_ids = $ids;
		}
		$dispatch = $this->dispatch_visual_workflow( (int) $result, $run_mode, $stage, $dispatch_ids );
		$message .= is_wp_error( $dispatch ) ? ' ' . $dispatch->get_error_message() : ' GitHub batch started now.';
		$this->redirect( 'mac-project-tracker-visuals', $message, is_wp_error( $dispatch ) ? 'warning' : 'success' );
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

	private function page_start( $title, $description, $screen ) {
		$latest = $this->repository->latest_log();
		$state  = $this->sync->is_running() ? 'syncing' : ( $this->sync->is_queued() ? 'queued' : 'ready' );
		?>
		<div class="wrap mac-tracker-wrap mac-tracker-wrap--<?php echo esc_attr( sanitize_html_class( $screen ) ); ?>"><header class="mac-tracker-masthead"><div class="mac-tracker-masthead__mark" aria-hidden="true">M</div><div class="mac-tracker-masthead__title"><p class="mac-tracker-brand">MAC / PROJECT TRACKER</p><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></div><div class="mac-tracker-sync-strip mac-tracker-sync-strip--<?php echo esc_attr( $state ); ?>"><div class="mac-tracker-sync-route" aria-hidden="true"><i></i><i></i><i></i><i></i></div><div><strong><?php echo esc_html( 'syncing' === $state ? 'Syncing cache' : ( 'queued' === $state ? 'Sync queued' : 'Cache ready' ) ); ?></strong><span><?php echo esc_html( $latest ? 'Last run ' . MAC_Tracker_Time::bangkok_label( $latest['started_at'] ) : 'No sync run yet' ); ?></span></div></div></header>
		<?php
	}

	private function page_end() { echo '</div>'; }

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

	private function notices() {
		if ( empty( $_GET['mac_tracker_notice'] ) ) { return; }
		$type = sanitize_key( $_GET['mac_tracker_notice_type'] ?? 'success' );
		$type = in_array( $type, array( 'success', 'error' ), true ) ? $type : 'success';
		echo '<div class="mac-tracker-notice mac-tracker-notice--' . esc_attr( $type ) . '" role="status"><p>' . esc_html( wp_unslash( $_GET['mac_tracker_notice'] ) ) . '</p></div>';
	}

	private function project_filters() {
		$get = wp_unslash( $_GET );
		return array(
			'search'   => isset( $get['search'] ) ? sanitize_text_field( $get['search'] ) : '',
			'assignee' => isset( $get['assignee'] ) ? sanitize_text_field( $get['assignee'] ) : '',
			'kind'     => isset( $get['kind'] ) ? sanitize_key( $get['kind'] ) : '',
			'website'  => isset( $get['website'] ) ? sanitize_key( $get['website'] ) : '',
			'layout'   => isset( $get['layout'] ) ? sanitize_key( $get['layout'] ) : '',
			'tone'     => isset( $get['tone'] ) ? sanitize_text_field( $get['tone'] ) : '',
			'month_from' => isset( $get['month_from'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $get['month_from'] ) ? (string) $get['month_from'] : '',
			'month_to'   => isset( $get['month_to'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $get['month_to'] ) ? (string) $get['month_to'] : '',
			'per_page' => isset( $get['per_page'] ) ? absint( $get['per_page'] ) : 150,
			'orderby'  => isset( $get['orderby'] ) ? sanitize_key( $get['orderby'] ) : 'date',
			'order'    => isset( $get['order'] ) ? sanitize_key( $get['order'] ) : 'desc',
			'paged'    => isset( $get['paged'] ) ? absint( $get['paged'] ) : 1,
		);
	}

	private function sort_header( $field, $label, array $filters, $class = '' ) {
		$current = $filters['orderby'] === $field;
		$order   = $current && 'desc' === strtolower( $filters['order'] ) ? 'asc' : 'desc';
		$args    = array_merge( $filters, array( 'orderby' => $field, 'order' => $order, 'paged' => 1, 'page' => 'mac-project-tracker' ) );
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		echo '<th scope="col" class="mac-tracker-sort ' . esc_attr( $class ) . ( $current ? ' is-active' : '' ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<span aria-hidden="true" class="dashicons ' . ( $current && 'asc' === strtolower( $filters['order'] ) ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' ) . '"></span></a></th>';
	}

	private function pagination( array $page, array $filters ) {
		if ( (int) $page['total_pages'] <= 1 ) { return; }
		$base_args = array_merge( $filters, array( 'page' => 'mac-project-tracker', 'paged' => '%#%' ) );
		$base      = str_replace( '%25%23%25', '%#%', add_query_arg( $base_args, admin_url( 'admin.php' ) ) );
		$links = paginate_links( array( 'base' => $base, 'format' => '', 'current' => (int) $page['paged'], 'total' => (int) $page['total_pages'], 'type' => 'list', 'prev_text' => '‹', 'next_text' => '›' ) );
		if ( $links ) { echo '<nav class="mac-tracker-pagination" aria-label="Project pages">' . wp_kses_post( $links ) . '</nav>'; }
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
			echo '<a class="mac-tracker-palette-link" href="' . esc_url( admin_url( 'admin.php?page=mac-project-tracker-colors' ) ) . '" title="Open Color Review">';
			foreach ( array_slice( $colors, 0, 4 ) as $color ) {
				echo '<i style="--mac-tracker-swatch: ' . esc_attr( $color ) . '"></i>';
			}
			echo '<span>' . esc_html( ! empty( $row['color_locked'] ) ? 'Approved' : 'Review' ) . '</span></a>';
			return;
		}
		echo '<a class="mac-tracker-palette-link mac-tracker-palette-link--empty" href="' . esc_url( admin_url( 'admin.php?page=mac-project-tracker-colors' ) ) . '">Color review</a>';
	}

	private function tone_options() {
		return $this->repository->visual_tones();
	}

	private function visual_is_manual( array $row ) {
		if ( ! empty( $row['manual_locked'] ) ) { return true; }
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
		$title = trim( (string) ( $row['tone_reason'] ?? '' ) );
		$content = '<i aria-hidden="true"></i><span>' . esc_html( $tone ) . '</span>';
		if ( $link ) {
			echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( admin_url( 'admin.php?page=mac-project-tracker-visuals#visual-' . (int) $row['id'] ) ) . '" title="' . esc_attr( $title ?: 'Open stored screenshot' ) . '">' . $content . '</a>';
			return;
		}
		$manual = $this->visual_is_manual( $row );
		echo '<span class="' . esc_attr( $classes ) . '" title="' . esc_attr( $title ) . '">' . $content . '</span>';
		if ( $manual ) { echo '<span class="mac-tracker-tone-manual">Đã sửa tay</span>'; }
		$snapshot_id = (int) $row['id'];
		$open = 'Cần duyệt' === $tone ? ' open' : '';
		$summary = 'Cần duyệt' === $tone ? 'Chọn tone đúng' : 'Sửa tone';
		echo '<details class="mac-tracker-tone-review"' . $open . '><summary>' . esc_html( $summary ) . '</summary><div><select id="manual-tone-' . $snapshot_id . '" name="manual_tone[' . $snapshot_id . ']">';
		foreach ( array_diff( $this->tone_options(), array( 'Cần duyệt' ) ) as $option ) {
			echo '<option value="' . esc_attr( $option ) . '"' . selected( $tone, $option, false ) . '>' . esc_html( $option ) . '</option>';
		}
		echo '</select><button type="submit" class="button button-small" name="visual_action" value="save_tone_' . $snapshot_id . '">Lưu tone</button></div></details>';
	}

	/** Collapsible, sanitized evidence for diagnosing an AI result without exposing secrets. */
	private function visual_debug_panel( array $row ) {
		$raw = json_decode( (string) ( $row['ai_raw'] ?? '' ), true );
		if ( ! is_array( $raw ) ) { return; }
		$deterministic = (array) ( $raw['deterministic']['semantic_model'] ?? array() );
		$canvas = (array) ( $deterministic['canvas'] ?? array() );
		$accent = (array) ( $deterministic['primary_accent'] ?? array() );
		$outcome = (array) ( $raw['result'] ?? array() );
		echo '<details class="mac-tracker-visual-debug"><summary>Evidence &amp; provider trace</summary><dl>';
		echo '<dt>Canvas</dt><dd>' . esc_html( trim( (string) ( $canvas['family'] ?? '' ) . ' / ' . (string) ( $canvas['mode'] ?? '' ) ) ?: 'Not available' ) . '</dd>';
		echo '<dt>Primary accent</dt><dd>' . esc_html( trim( (string) ( $accent['family'] ?? '' ) . ' ' . (string) ( $accent['hex'] ?? '' ) ) ?: 'Not available' ) . '</dd>';
		echo '<dt>Final resolver</dt><dd>' . esc_html( (string) ( $outcome['result']['reason'] ?? $row['tone_reason'] ?? 'Pending' ) ) . '</dd>';
		echo '<dt>Provider errors</dt><dd>' . esc_html( wp_json_encode( (array) ( $raw['errors'] ?? array() ) ) ) . '</dd></dl></details>';
	}

	private function visual_status_cell( array $row ) {
		$pipeline = (string) ( $row['pipeline_status'] ?? '' );
		$provider = (string) ( $row['ai_provider'] ?? '' );
		$model = (string) ( $row['ai_model'] ?? '' );
		if ( in_array( $pipeline, array( 'capturing', 'analyzing' ), true ) && ! empty( $row['lease_until'] ) && strtotime( (string) $row['lease_until'] . ' UTC' ) < time() ) {
			$pipeline = 'retry_wait';
		}
		$canonical = array(
			'idle'           => array( 'queued', 'dashicons-minus', 'Idle · chưa chạy' ),
			'capture_queued' => array( 'queued', 'dashicons-clock', 'Capture queued · chờ chạy batch' ),
			'capturing'      => array( 'working', 'dashicons-camera', 'Capturing homepage · GitHub đang chạy' ),
			'captured'       => array( 'queued', 'dashicons-format-image', 'Screenshot saved · chờ phân tích' ),
			'analysis_queued'=> array( 'queued', 'dashicons-clock', 'Analysis queued · chờ chạy batch' ),
			'analyzing'      => array( 'working', 'dashicons-admin-appearance', $provider ? 'Analyzing with ' . trim( $provider . ( $model ? ' · ' . $model : '' ) ) : 'Analyzing with Qwen 3.8' ),
			'classified'     => array( 'complete', 'dashicons-yes-alt', 'Completed · đã phân loại' ),
			'needs_review'   => array( 'queued', 'dashicons-visibility', 'Needs review · cần duyệt tone' ),
			'retry_wait'     => array( 'queued', 'dashicons-update', 'Retry scheduled' . ( ! empty( $row['next_retry_at'] ) ? ' · ' . MAC_Tracker_Time::bangkok_label( $row['next_retry_at'] ) : '' ) ),
			'blocked'        => array( 'failed', 'dashicons-lock', 'Blocked · ' . ( $row['last_error_message'] ?: 'cần xử lý thủ công' ) ),
			'failed'         => array( 'failed', 'dashicons-warning', 'Failed · ' . ( $row['last_error_message'] ?: 'cần retry' ) ),
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
		echo '<p class="mac-tracker-color-variables">';
		foreach ( $variables as $name => $value ) {
			echo '<code>' . esc_html( $name ) . '</code>';
		}
		echo '</p>';
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
