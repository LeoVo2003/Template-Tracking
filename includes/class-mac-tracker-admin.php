<?php

defined( 'ABSPATH' ) || exit;

/** WordPress-admin UI. All project rows come from local snapshot tables. */
class MAC_Tracker_Admin {

	private $repository;
	private $sync;

	public function __construct( MAC_Tracker_Repository $repository, MAC_Tracker_Sync_Service $sync ) {
		$this->repository = $repository;
		$this->sync       = $sync;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mac_tracker_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_mac_tracker_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_mac_tracker_queue_sync', array( $this, 'handle_queue_sync' ) );
		add_action( 'admin_post_mac_tracker_import_pins', array( $this, 'handle_import_pins' ) );
		add_action( 'admin_post_mac_tracker_clear_data', array( $this, 'handle_clear_data' ) );
	}

	public function register_menu() {
		add_menu_page( 'MAC Project Tracker', 'MAC Tracker', 'manage_options', 'mac-project-tracker', array( $this, 'render_dashboard' ), 'dashicons-chart-area', 30 );
		add_submenu_page( 'mac-project-tracker', 'Projects', 'Projects', 'manage_options', 'mac-project-tracker-projects', array( $this, 'render_projects' ) );
		add_submenu_page( 'mac-project-tracker', 'Pin import', 'Pin import', 'manage_options', 'mac-project-tracker-pins', array( $this, 'render_pins' ) );
		add_submenu_page( 'mac-project-tracker', 'Settings', 'Settings', 'manage_options', 'mac-project-tracker-settings', array( $this, 'render_settings' ) );
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'mac-project-tracker' ) ) {
			return;
		}
		wp_enqueue_style( 'mac-tracker-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap', array(), null );
		wp_enqueue_style( 'mac-project-tracker-admin', MAC_TRACKER_URL . 'assets/admin.css', array( 'mac-tracker-fonts' ), MAC_TRACKER_VERSION );
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
				<?php $this->stat_card( 'Pinned baseline', $this->repository->pin_count(), 'dashicons-admin-links' ); ?>
			</div>
		</section>

		<section class="mac-tracker-workbench">
			<div class="mac-tracker-workbench__copy"><p class="mac-tracker-eyebrow">WPM → local cache</p><h2>Refresh without interrupting work</h2><p>Sync runs in the background. Existing projects remain available throughout the run.</p></div>
			<?php $this->sync_button(); ?>
		</section>

		<section class="mac-tracker-ledger">
			<div class="mac-tracker-ledger__head"><div><p class="mac-tracker-eyebrow">Recent activity</p><h2>Sync log</h2></div><span>Newest first</span></div>
			<?php if ( empty( $logs ) ) : ?>
				<div class="mac-tracker-empty"><span class="dashicons dashicons-chart-line"></span><strong>No sync yet</strong><p>Save the WPM connection, then run the first background sync.</p></div>
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
			<?php $this->sync_button(); ?>
		</section>

		<form class="mac-tracker-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="mac-project-tracker-projects">
			<label><span>Search</span><input name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Project, website, ZIP or WPM ID"></label>
			<label><span>Assignee</span><select name="assignee"><option value="">All assignees</option><?php foreach ( $this->repository->list_assignees() as $name ) : ?><option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['assignee'], $name ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label>
			<label><span>Snapshot type</span><select name="kind"><option value="">All types</option><option value="action_design" <?php selected( $filters['kind'], 'action_design' ); ?>>Action Design</option><option value="csv_pin" <?php selected( $filters['kind'], 'csv_pin' ); ?>>CSV pin</option></select></label>
			<label><span>Website</span><select name="website"><option value="">Any</option><option value="yes" <?php selected( $filters['website'], 'yes' ); ?>>Has website</option><option value="no" <?php selected( $filters['website'], 'no' ); ?>>Missing</option></select></label>
			<label><span>Layout</span><select name="layout"><option value="">Any</option><option value="yes" <?php selected( $filters['layout'], 'yes' ); ?>>Has layout</option><option value="no" <?php selected( $filters['layout'], 'no' ); ?>>Missing</option></select></label>
			<label><span>Rows</span><select name="per_page"><option value="0" <?php selected( $filters['per_page'], 0 ); ?>>All</option><option value="50" <?php selected( $filters['per_page'], 50 ); ?>>50</option><option value="100" <?php selected( $filters['per_page'], 100 ); ?>>100</option><option value="200" <?php selected( $filters['per_page'], 200 ); ?>>200</option></select></label>
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $filters['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $filters['order'] ); ?>">
			<div class="mac-tracker-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mac-project-tracker-projects' ) ); ?>">Clear</a></div>
		</form>

		<section class="mac-tracker-table-shell">
			<?php if ( empty( $page['rows'] ) ) : ?>
				<div class="mac-tracker-empty"><span class="dashicons dashicons-archive"></span><strong>No project snapshots yet</strong><p>Import the pin baseline or save WPM Settings and run a background sync.</p></div>
			<?php else : ?>
				<div class="mac-tracker-table-scroll"><table class="widefat fixed striped mac-tracker-project-table"><thead><tr>
					<?php $this->sort_header( 'id', 'ID', $filters ); ?><?php $this->sort_header( 'project', 'Project', $filters ); ?><?php $this->sort_header( 'website', 'Website', $filters ); ?><?php $this->sort_header( 'layout', 'Layout', $filters ); ?><?php $this->sort_header( 'assignee', 'Assignee', $filters ); ?><?php $this->sort_header( 'date', 'Date', $filters, 'mac-tracker-col--date' ); ?><?php $this->sort_header( 'time', 'Time', $filters, 'mac-tracker-col--time' ); ?><?php $this->sort_header( 'palette', 'Palette', $filters ); ?>
				</tr></thead><tbody>
					<?php foreach ( $page['rows'] as $row ) : ?>
						<tr>
							<td class="mac-tracker-id"><strong>#<?php echo esc_html( $row['wpm_project_id'] ); ?></strong><span><?php echo esc_html( $this->record_hint( $row ) ); ?></span></td>
							<td><strong class="mac-tracker-project-name"><?php echo esc_html( $this->project_label( $row ) ); ?></strong></td>
							<td><?php $this->url_link( $row['website_url'], $this->website_label( $row['website_url'] ) ); ?></td>
							<td><?php $this->url_link( $this->layout_url( $row['layout_url'] ), $this->layout_label( $row['layout_url'] ) ); ?></td>
							<td><?php echo esc_html( $this->person_name( $row['assignee_json'] ) ?: '—' ); ?></td>
							<td class="mac-tracker-date mac-tracker-date--day"><?php echo esc_html( MAC_Tracker_Time::bangkok_date( $row['task_completed_at'] ) ); ?></td>
							<td class="mac-tracker-date mac-tracker-date--time"><?php echo esc_html( MAC_Tracker_Time::bangkok_time( $row['task_completed_at'] ) ); ?></td>
							<td><span class="mac-tracker-status mac-tracker-status--muted">Color later</span></td>
						</tr>
					<?php endforeach; ?>
				</tbody></table></div>
				<?php $this->pagination( $page, $filters ); ?>
			<?php endif; ?>
		</section>
		<?php $this->page_end();
	}

	public function render_pins() {
		$this->require_capability();
		$this->page_start( 'Pin baseline', 'Import the approved manual project list. Pins remain if WPM later removes a project.', 'pins' );
		?>
		<section class="mac-tracker-panel mac-tracker-panel--narrow"><div class="mac-tracker-panel__head"><div><p class="mac-tracker-eyebrow">Master roster import</p><h2><?php echo esc_html( number_format_i18n( $this->repository->pin_count() ) ); ?> saved pins</h2><p>Import the full hybrid CSV (466 rows). CSV pin rows are displayed immediately; Action Design rows only register the approved WPM scope and appear after WPM confirms them.</p></div></div>
		<form class="mac-tracker-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mac_tracker_import_pins' ); ?><input type="hidden" name="action" value="mac_tracker_import_pins"><label><span>CSV file</span><input type="file" name="pin_csv" accept=".csv,text/csv" required></label><button class="button button-primary" type="submit">Import pins</button>
		</form></section>
		<section class="mac-tracker-danger-zone" aria-label="Reset local tracker data"><div><p class="mac-tracker-eyebrow">Start over</p><h2>Clear local tracker data</h2><p>Deletes local snapshots, imported pins, color records and sync logs. WPM connection settings and your CSV file are kept.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Clear all local tracker data? This cannot be undone.');"><?php wp_nonce_field( 'mac_tracker_clear_data' ); ?><input type="hidden" name="action" value="mac_tracker_clear_data"><button class="button mac-tracker-button--danger" type="submit">Clear all data</button></form></section>
		<?php $this->page_end();
	}

	public function render_settings() {
		$this->require_capability();
		$settings     = (array) get_option( 'mac_tracker_settings', array() );
		$this->page_start( 'Connection settings', 'The API header value is encrypted in this WordPress database and is never displayed again.', 'settings' );
		?>
		<section class="mac-tracker-panel mac-tracker-panel--narrow"><div class="mac-tracker-panel__head"><div><p class="mac-tracker-eyebrow">WPM REST API</p><h2>Connect the tracker</h2><p>Requests use the fixed <code>Tracking-Template-Header</code> header. Sync runs through WP-Cron after saving.</p></div></div>
		<form class="mac-tracker-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mac_tracker_save_settings' ); ?><input type="hidden" name="action" value="mac_tracker_save_settings">
			<label><span>WPM endpoint</span><input type="url" name="wpm_endpoint" required placeholder="https://wpm.macusaone.com/api/v1/tracking-template/projects" value="<?php echo esc_attr( $settings['wpm_endpoint'] ?? '' ); ?>"><small>Use exactly <code>https://wpm.macusaone.com/api/v1/tracking-template/projects</code>. The list endpoint returns projects with tasks.</small></label>
			<label><span>Tracking-Template-Header value</span><input type="password" name="wpm_secret" autocomplete="new-password" placeholder="<?php echo get_option( 'mac_tracker_wpm_secret', '' ) ? 'Saved — leave blank to keep it' : 'Paste API value'; ?>"><small>Leave blank when editing other settings to keep the saved value.</small></label>
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
		$this->sync->ensure_hourly_schedule();
		$this->redirect( 'mac-project-tracker-settings', 'Connection saved. Background sync can now be queued.', 'success' );
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
		$result = $this->sync->queue_background_sync();
		if ( is_wp_error( $result ) ) { $this->redirect( 'mac-project-tracker', $result->get_error_message(), 'error' ); }
		$this->redirect( 'mac-project-tracker', 'Sync queued. Existing project rows stay available while it runs.', 'success' );
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
		$message = sprintf( 'Imported %d CSV pins and registered %d Action Design projects in a %d-project WPM scope.', (int) $result['imported'], (int) ( $result['registered'] ?? 0 ), (int) ( $result['roster'] ?? 0 ) );
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

	private function page_start( $title, $description, $screen ) {
		$latest = $this->repository->latest_log();
		$state  = $this->sync->is_running() ? 'syncing' : ( $this->sync->is_queued() ? 'queued' : 'ready' );
		?>
		<div class="wrap mac-tracker-wrap mac-tracker-wrap--<?php echo esc_attr( sanitize_html_class( $screen ) ); ?>"><header class="mac-tracker-masthead"><div class="mac-tracker-masthead__mark" aria-hidden="true">M</div><div class="mac-tracker-masthead__title"><p class="mac-tracker-brand">MAC / PROJECT TRACKER</p><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></div><div class="mac-tracker-sync-strip mac-tracker-sync-strip--<?php echo esc_attr( $state ); ?>"><div class="mac-tracker-sync-route" aria-hidden="true"><i></i><i></i><i></i><i></i></div><div><strong><?php echo esc_html( 'syncing' === $state ? 'Syncing cache' : ( 'queued' === $state ? 'Sync queued' : 'Cache ready' ) ); ?></strong><span><?php echo esc_html( $latest ? 'Last run ' . MAC_Tracker_Time::bangkok_label( $latest['started_at'] ) : 'No sync run yet' ); ?></span></div></div></header>
		<?php $this->notices(); ?>
		<?php
	}

	private function page_end() { echo '</div>'; }

	private function sync_button() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mac-tracker-sync-form"><?php wp_nonce_field( 'mac_tracker_queue_sync' ); ?><input type="hidden" name="action" value="mac_tracker_queue_sync"><button class="button button-primary" type="submit" <?php disabled( $this->sync->is_running() || $this->sync->is_queued() ); ?>><span class="dashicons dashicons-update"></span><?php echo esc_html( $this->sync->is_running() ? 'Comparing…' : ( $this->sync->is_queued() ? 'Queued' : 'Compare WPM & sync' ) ); ?></button></form>
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
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( wp_unslash( $_GET['mac_tracker_notice'] ) ) . '</p></div>';
	}

	private function project_filters() {
		$get = wp_unslash( $_GET );
		return array(
			'search'   => isset( $get['search'] ) ? sanitize_text_field( $get['search'] ) : '',
			'assignee' => isset( $get['assignee'] ) ? sanitize_text_field( $get['assignee'] ) : '',
			'kind'     => isset( $get['kind'] ) ? sanitize_key( $get['kind'] ) : '',
			'website'  => isset( $get['website'] ) ? sanitize_key( $get['website'] ) : '',
			'layout'   => isset( $get['layout'] ) ? sanitize_key( $get['layout'] ) : '',
			'per_page' => isset( $get['per_page'] ) ? absint( $get['per_page'] ) : 0,
			'orderby'  => isset( $get['orderby'] ) ? sanitize_key( $get['orderby'] ) : 'id',
			'order'    => isset( $get['order'] ) ? sanitize_key( $get['order'] ) : 'desc',
			'paged'    => isset( $get['paged'] ) ? absint( $get['paged'] ) : 1,
		);
	}

	private function sort_header( $field, $label, array $filters, $class = '' ) {
		$current = $filters['orderby'] === $field;
		$order   = $current && 'desc' === strtolower( $filters['order'] ) ? 'asc' : 'desc';
		$args    = array_merge( $filters, array( 'orderby' => $field, 'order' => $order, 'paged' => 1, 'page' => 'mac-project-tracker-projects' ) );
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		echo '<th scope="col" class="mac-tracker-sort ' . esc_attr( $class ) . ( $current ? ' is-active' : '' ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<span aria-hidden="true" class="dashicons ' . ( $current && 'asc' === strtolower( $filters['order'] ) ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' ) . '"></span></a></th>';
	}

	private function pagination( array $page, array $filters ) {
		if ( (int) $page['total_pages'] <= 1 ) { return; }
		$base_args = array_merge( $filters, array( 'page' => 'mac-project-tracker-projects', 'paged' => '%#%' ) );
		$base      = str_replace( '%25%23%25', '%#%', add_query_arg( $base_args, admin_url( 'admin.php' ) ) );
		$links = paginate_links( array( 'base' => $base, 'format' => '', 'current' => (int) $page['paged'], 'total' => (int) $page['total_pages'], 'type' => 'list', 'prev_text' => '‹', 'next_text' => '›' ) );
		if ( $links ) { echo '<nav class="mac-tracker-pagination" aria-label="Project pages">' . wp_kses_post( $links ) . '</nav>'; }
	}

	private function record_hint( array $row ) {
		if ( 'action_design' === $row['record_kind'] ) { return 'Task #' . (int) $row['wpm_action_task_id']; }
		return 'CSV pin';
	}

	private function project_label( array $row ) {
		$package = json_decode( $row['package_json'], true );
		$package = is_string( $package ) ? $package : '';
		$parts = array_filter( array( trim( $row['zip_code'] ), trim( $package ), trim( $row['name'] ) ) );
		return implode( ' ', $parts ) ?: 'Unnamed project';
	}

	private function person_name( $json ) {
		$person = json_decode( (string) $json, true );
		return is_array( $person ) ? trim( (string) ( $person['name'] ?? '' ) ) : '';
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
		$value = $this->first_url( $value );
		if ( '' === $value || ! preg_match( '#(?:^|/)(demo-[a-z0-9]+)/(home(?:-([0-9]+))?)(?:/|$)#i', $value, $match ) ) {
			return null;
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
		return $value;
	}

	private function status_class( $status ) {
		$status = sanitize_key( $status );
		return in_array( $status, array( 'success', 'running', 'failed' ), true ) ? $status : 'muted';
	}

	private function require_capability() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to access MAC Tracker.' ) ); } }
	private function require_request( $action ) { $this->require_capability(); check_admin_referer( $action ); }
	private function redirect( $page, $message, $type ) { wp_safe_redirect( add_query_arg( array( 'page' => $page, 'mac_tracker_notice' => $message, 'mac_tracker_notice_type' => $type ), admin_url( 'admin.php' ) ) ); exit; }
}
