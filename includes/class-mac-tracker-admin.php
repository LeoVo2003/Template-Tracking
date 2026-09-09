<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Admin {

	/** @var MAC_Tracker_Repository */
	private $repository;

	/** @var MAC_Tracker_Sync_Service */
	private $sync_service;

	public function __construct(
		MAC_Tracker_Repository $repository,
		MAC_Tracker_Sync_Service $sync_service
	) {
		$this->repository   = $repository;
		$this->sync_service = $sync_service;
	}

	/**
	 * Register admin menus and protected form handlers.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mac_tracker_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_mac_tracker_clear_data', array( $this, 'handle_clear_data' ) );
		add_action( 'admin_post_mac_tracker_reset_pins', array( $this, 'handle_reset_pins' ) );
		add_action( 'admin_post_mac_tracker_import_pins', array( $this, 'handle_import_pins' ) );
		add_action( 'admin_post_mac_tracker_purge_projects', array( $this, 'handle_purge_projects' ) );
		add_action( 'admin_post_mac_tracker_approve_palette', array( $this, 'handle_approve_palette' ) );
		add_action( 'admin_post_mac_tracker_save_settings', array( $this, 'handle_save_settings' ) );
	}

	public function register_menu() {
		add_menu_page(
			'MAC Project Tracker',
			'MAC Tracker',
			'manage_options',
			'mac-project-tracker',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			30
		);

		add_submenu_page(
			'mac-project-tracker',
			'Projects',
			'Projects',
			'manage_options',
			'mac-project-tracker-projects',
			array( $this, 'render_projects' )
		);

		add_submenu_page(
			'mac-project-tracker',
			'Pin import',
			'Pin import',
			'manage_options',
			'mac-project-tracker-pins',
			array( $this, 'render_pins' )
		);

		add_submenu_page(
			'mac-project-tracker',
			'Color Review',
			'Color Review',
			'manage_options',
			'mac-project-tracker-colors',
			array( $this, 'render_colors' )
		);

		add_submenu_page(
			'mac-project-tracker',
			'Settings',
			'Settings',
			'manage_options',
			'mac-project-tracker-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'mac-project-tracker' ) ) {
			return;
		}

		wp_enqueue_style(
			'mac-tracker-fonts',
			'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=IBM+Plex+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'mac-project-tracker-admin',
			MAC_TRACKER_URL . 'assets/admin.css',
			array( 'mac-tracker-fonts' ),
			MAC_TRACKER_VERSION
		);
	}

	public function handle_sync_now() {
		$this->require_admin_request( 'mac_tracker_sync_now' );

		$result = $this->sync_service->queue_background_sync();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice(
				'mac-project-tracker',
				$result->get_error_message(),
				'error'
			);
		}

		$this->redirect_with_notice(
			'mac-project-tracker',
			'Full sync đã xếp hàng chạy nền (WP-Cron). Xem Sync activity để biết thời gian Bangkok GMT+7.',
			'success'
		);
	}

	/**
	 * Clear sync snapshots / colors / logs. Keeps CSV pin baseline.
	 */
	public function handle_clear_data() {
		$this->require_admin_request( 'mac_tracker_clear_data' );

		$redirect = isset( $_POST['redirect_to'] ) && is_scalar( $_POST['redirect_to'] )
			? sanitize_key( wp_unslash( $_POST['redirect_to'] ) )
			: 'mac-project-tracker';
		if ( ! in_array( $redirect, array( 'mac-project-tracker', 'mac-project-tracker-projects', 'mac-project-tracker-pins' ), true ) ) {
			$redirect = 'mac-project-tracker';
		}

		$result = $this->repository->clear_snapshots();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $redirect, $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice(
			$redirect,
			sprintf(
				'Snapshots cleared (%d rows). Pin list kept.',
				(int) ( $result['projects'] ?? 0 )
			),
			'success'
		);
	}

	/**
	 * Remove pin registry + csv_pin baselines only.
	 */
	public function handle_reset_pins() {
		$this->require_admin_request( 'mac_tracker_reset_pins' );

		$result = $this->repository->reset_pin_list();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'mac-project-tracker-pins', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice(
			'mac-project-tracker-pins',
			sprintf( 'Pin list reset (%d pins).', (int) ( $result['pins'] ?? 0 ) ),
			'success'
		);
	}

	/**
	 * Permanently delete local rows for WPM project IDs and block re-sync.
	 */
	public function handle_purge_projects() {
		$this->require_admin_request( 'mac_tracker_purge_projects' );

		$raw = isset( $_POST['purge_ids'] ) && is_scalar( $_POST['purge_ids'] )
			? (string) wp_unslash( $_POST['purge_ids'] )
			: '';
		$parts = preg_split( '/[\s,;]+/', $raw );
		$ids   = array();
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				$id = absint( $part );
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}
		if ( empty( $ids ) ) {
			$this->redirect_with_notice( 'mac-project-tracker-pins', 'Enter at least one project ID.', 'error' );
		}

		$purged = 0;
		foreach ( $ids as $id ) {
			$result = $this->repository->purge_project_permanently( $id );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( 'mac-project-tracker-pins', $result->get_error_message(), 'error' );
			}
			++$purged;
		}

		$this->redirect_with_notice(
			'mac-project-tracker-pins',
			sprintf( 'Purged %d project ID(s): %s', $purged, implode( ', ', $ids ) ),
			'success'
		);
	}

	/**
	 * Import curated CSV pin baseline.
	 */
	public function handle_import_pins() {
		$this->require_admin_request( 'mac_tracker_import_pins' );

		if ( empty( $_FILES['pin_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pin_csv']['tmp_name'] ) ) {
			$this->redirect_with_notice( 'mac-project-tracker-pins', 'Choose a CSV file.', 'error' );
		}

		$size = isset( $_FILES['pin_csv']['size'] ) ? (int) $_FILES['pin_csv']['size'] : 0;
		if ( $size <= 0 || $size > 5 * 1024 * 1024 ) {
			$this->redirect_with_notice( 'mac-project-tracker-pins', 'CSV must be under 5 MB.', 'error' );
		}

		$raw = file_get_contents( (string) $_FILES['pin_csv']['tmp_name'] );
		if ( false === $raw ) {
			$this->redirect_with_notice( 'mac-project-tracker-pins', 'Unable to read CSV.', 'error' );
		}

		$importer = new MAC_Tracker_Pin_Import( $this->repository );
		$result   = $importer->import_csv( $raw );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'mac-project-tracker-pins', $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice(
			'mac-project-tracker-pins',
			(string) ( $result['message'] ?? 'Pin import done.' ),
			'success'
		);
	}

	/**
	 * Upload Google Sheet CSV and store compare result for the Compare screen.
	 */
	public function handle_approve_palette() {
		$this->require_admin_request( 'mac_tracker_approve_palette' );

		$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;
		$result    = $this->repository->approve_palette( $record_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice(
				'mac-project-tracker-colors',
				$result->get_error_message(),
				'error'
			);
		}

		$this->redirect_with_notice( 'mac-project-tracker-colors', 'Approved.', 'success' );
	}

	public function handle_save_settings() {
		$this->require_admin_request( 'mac_tracker_save_settings' );

		$current = get_option( 'mac_tracker_settings', array() );
		$current = is_array( $current ) ? $current : array();

		$auth = isset( $_POST['wpm_auth_type'] ) && is_scalar( $_POST['wpm_auth_type'] )
			? sanitize_key( wp_unslash( $_POST['wpm_auth_type'] ) )
			: 'api_key';

		if ( ! in_array( $auth, array( 'none', 'bearer', 'api_key' ), true ) ) {
			$auth = 'api_key';
		}

		$api_url = isset( $_POST['wpm_api_url'] ) && is_scalar( $_POST['wpm_api_url'] )
			? esc_url_raw( wp_unslash( $_POST['wpm_api_url'] ) )
			: '';
		if ( 'https' !== strtolower( (string) wp_parse_url( $api_url, PHP_URL_SCHEME ) ) ) {
			$this->redirect_with_notice(
				'mac-project-tracker-settings',
				'WPM endpoint requires HTTPS.',
				'error'
			);
		}

		$settings = array(
			'wpm_api_url'        => $api_url,
			'wpm_auth_type'      => $auth,
			'wpm_api_key_header' => MAC_Tracker_WPM_Client::API_KEY_HEADER,
			'gemini_model'       => isset( $_POST['gemini_model'] ) && is_scalar( $_POST['gemini_model'] )
				? sanitize_key( wp_unslash( $_POST['gemini_model'] ) )
				: ( $current['gemini_model'] ?? 'gemini-3-flash-preview' ),
		);

		update_option( 'mac_tracker_settings', $settings, false );

		$secret_result = $this->save_encrypted_secret(
			'mac_tracker_wpm_secret',
			'wpm_secret',
			'clear_wpm_secret'
		);
		if ( is_wp_error( $secret_result ) ) {
			$this->redirect_with_notice( 'mac-project-tracker-settings', $secret_result->get_error_message(), 'error' );
		}

		$gemini_result = $this->save_encrypted_secret(
			'mac_tracker_gemini_api_key',
			'gemini_api_key',
			'clear_gemini_api_key'
		);
		if ( is_wp_error( $gemini_result ) ) {
			$this->redirect_with_notice( 'mac-project-tracker-settings', $gemini_result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( 'mac-project-tracker-settings', 'Settings saved.', 'success' );
	}

	public function render_dashboard() {
		$this->require_capability();

		$project_count  = $this->repository->count_projects();
		$pin_count      = $this->repository->count_pins();
		$pending_count  = $this->repository->count_colors( 'pending' );
		$approved_count = $this->repository->count_colors( 'approved' );
		$waiting_count  = $this->repository->count_colors( 'waiting' );
		$last_sync      = (string) get_option( 'mac_tracker_last_sync_at', '' );
		$queued_at      = (string) get_option( 'mac_tracker_sync_queued_at', '' );
		$logs           = $this->repository->list_sync_logs( 10 );
		$lock           = (int) get_option( MAC_Tracker_Sync_Service::LOCK_KEY, 0 );

		$this->page_start( 'MAC Project Tracker' );
		$this->render_notice();
		?>
		<div class="mac-tracker-toolbar">
			<div>
				<strong>WPM REST API</strong>
				<?php if ( $lock ) : ?>
					<span> · Sync đang chạy…</span>
				<?php elseif ( $queued_at ) : ?>
					<span> · Queued <?php echo esc_html( MAC_Tracker_Time::format( $queued_at ) ); ?> (Bangkok)</span>
				<?php elseif ( $last_sync ) : ?>
					<span> · Last <?php echo esc_html( MAC_Tracker_Time::format( $last_sync ) ); ?> (Bangkok GMT+7)</span>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mac_tracker_sync_now">
				<?php wp_nonce_field( 'mac_tracker_sync_now' ); ?>
				<?php submit_button( 'Sync WPM Now', 'primary', 'submit', false ); ?>
			</form>
		</div>

		<div class="mac-tracker-cards">
			<?php $this->summary_card( 'Projects', $project_count, 'dashicons-portfolio' ); ?>
			<?php $this->summary_card( 'CSV pins', $pin_count, 'dashicons-paperclip' ); ?>
			<?php $this->summary_card( 'Waiting', $waiting_count, 'dashicons-format-image' ); ?>
			<?php $this->summary_card( 'Pending', $pending_count, 'dashicons-visibility' ); ?>
			<?php $this->summary_card( 'Approved', $approved_count, 'dashicons-yes-alt' ); ?>
		</div>

		<div class="mac-tracker-panel mac-tracker-sync-activity">
			<div class="mac-tracker-panel-head">
				<h2>Sync activity</h2>
				<?php $this->render_clear_data_form( 'mac-project-tracker' ); ?>
			</div>
			<p class="mac-tracker-meta">Auto sync: mỗi giờ <strong>10:00–17:00</strong> Bangkok; ngoài khung giờ bỏ qua. <strong>Sync WPM Now</strong> chạy nền bất kỳ lúc nào (không bị gate giờ). Full sync API.</p>
			<?php if ( empty( $logs ) ) : ?>
				<p>No sync yet.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Time (Bangkok)</th><th>Status</th><th>Scanned</th><th>Message</th></tr></thead>
					<tbody>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( MAC_Tracker_Time::format( $log['created_at'] ) ); ?></td>
							<td><?php $this->status_badge( $log['status'] ); ?></td>
							<td><?php echo esc_html( (string) $log['items_processed'] ); ?></td>
							<td><?php echo esc_html( $log['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		$this->page_end();
	}

	/**
	 * Shared destructive clear control for Dashboard + Projects.
	 *
	 * @param string $redirect_to Admin page slug after clear.
	 */
	private function render_clear_data_form( $redirect_to ) {
		?>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="mac-tracker-clear-form"
			onsubmit="return confirm('Clear sync snapshots and logs? CSV pin list will be kept.');"
		>
			<input type="hidden" name="action" value="mac_tracker_clear_data">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
			<?php wp_nonce_field( 'mac_tracker_clear_data' ); ?>
			<?php submit_button( 'Clear snapshots', 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_reset_pins_form() {
		?>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="mac-tracker-clear-form"
			onsubmit="return confirm('Reset the entire CSV pin list and baseline rows? Sync Action Design rows stay.');"
		>
			<input type="hidden" name="action" value="mac_tracker_reset_pins">
			<?php wp_nonce_field( 'mac_tracker_reset_pins' ); ?>
			<?php submit_button( 'Reset pin list', 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	public function render_projects() {
		$this->require_capability();

		$filters = array(
			'search'      => $this->query_value( 's' ),
			'assignee'    => $this->query_value( 'assignee' ),
			'palette'     => $this->query_value( 'palette' ),
			'website'     => $this->query_value( 'website' ),
			'layout'      => $this->query_value( 'layout' ),
			'action_task' => $this->query_value( 'action_task' ),
			'sort_by'     => $this->query_value( 'sort_by' ),
			'sort_order'  => $this->query_value( 'sort_order' ),
		);
		if ( ! in_array( $filters['palette'], array( '', 'none', 'waiting', 'pending', 'approved' ), true ) ) {
			$filters['palette'] = '';
		}
		foreach ( array( 'website', 'action_task' ) as $binary_filter ) {
			if ( ! in_array( $filters[ $binary_filter ], array( '', 'yes', 'no' ), true ) ) {
				$filters[ $binary_filter ] = '';
			}
		}
		if ( ! $this->is_valid_layout_filter( $filters['layout'] ) ) {
			$filters['layout'] = '';
		}
		if ( ! in_array( $filters['sort_by'], array( 'timeline', 'id', 'task', 'project', 'assignee', 'website', 'layout', 'palette', 'completed', 'time' ), true ) ) {
			$filters['sort_by'] = 'timeline';
		}
		if ( ! in_array( $filters['sort_order'], array( 'asc', 'desc' ), true ) ) {
			$filters['sort_order'] = 'desc';
		}

		// Default: load toàn bộ 1 lần (không phân trang). 50/100/200 vẫn chọn được.
		$per_page_raw = $this->query_value( 'per_page' );
		$show_all     = ( 'all' === $per_page_raw || '0' === $per_page_raw );
		$per_page     = $show_all ? 0 : absint( $per_page_raw );
		if ( '' === $per_page_raw ) {
			$show_all = true;
			$per_page = 0;
		} elseif ( ! $show_all && ! in_array( $per_page, array( 50, 100, 200 ), true ) ) {
			$show_all = true;
			$per_page = 0;
		}
		$total_items  = $this->repository->count_projects( $filters );
		$current_page = 1;
		$total_pages  = 1;
		$offset       = 0;
		if ( ! $show_all && $per_page > 0 ) {
			$current_page = max( 1, absint( $this->query_value( 'paged' ) ) );
			$total_pages  = max( 1, (int) ceil( $total_items / $per_page ) );
			$current_page = min( $current_page, $total_pages );
			$offset       = ( $current_page - 1 ) * $per_page;
		}
		$projects     = $this->repository->list_projects( $per_page, $offset, $filters );
		$assignees    = $this->repository->list_assignee_options();
		$layout_groups = $this->repository->list_layout_group_options();
		$range_start  = $total_items > 0 ? $offset + 1 : 0;
		$range_end    = min( $offset + count( $projects ), $total_items );
		$rows_param   = $show_all ? 'all' : $per_page;

		$this->page_start( 'Projects' );
		$this->render_notice();
		?>
		<section class="mac-tracker-projects-intro" aria-labelledby="mac-tracker-projects-heading">
			<div>
				<h2 id="mac-tracker-projects-heading">Projects</h2>
			</div>
			<?php $this->render_clear_data_form( 'mac-project-tracker-projects' ); ?>
		</section>
		<div class="mac-tracker-panel mac-tracker-projects-panel">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="mac-tracker-filters">
				<input type="hidden" name="page" value="mac-project-tracker-projects">
				<input type="hidden" name="sort_by" value="<?php echo esc_attr( $filters['sort_by'] ); ?>">
				<input type="hidden" name="sort_order" value="<?php echo esc_attr( $filters['sort_order'] ); ?>">
				<div class="mac-tracker-filter mac-tracker-filter--wide">
					<label for="mac-tracker-search">Search</label>
					<input type="search" id="mac-tracker-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Project ID, task ID, name, package, URL…">
				</div>
				<div class="mac-tracker-filter">
					<label for="mac-tracker-assignee-filter">Assignee</label>
					<select id="mac-tracker-assignee-filter" name="assignee">
						<option value="">All</option>
						<?php foreach ( $assignees as $person ) : ?>
							<option value="<?php echo esc_attr( $person['key'] ); ?>" <?php selected( $filters['assignee'], $person['key'] ); ?>>
								<?php echo esc_html( $person['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mac-tracker-filter">
					<label for="mac-tracker-palette-filter">Palette</label>
					<select id="mac-tracker-palette-filter" name="palette">
						<option value="">All</option>
						<option value="none" <?php selected( $filters['palette'], 'none' ); ?>>None</option>
						<option value="waiting" <?php selected( $filters['palette'], 'waiting' ); ?>>Waiting</option>
						<option value="pending" <?php selected( $filters['palette'], 'pending' ); ?>>Pending</option>
						<option value="approved" <?php selected( $filters['palette'], 'approved' ); ?>>Approved</option>
					</select>
				</div>
				<div class="mac-tracker-filter">
					<label for="mac-tracker-website-filter">Website</label>
					<select id="mac-tracker-website-filter" name="website">
						<option value="">All</option>
						<option value="yes" <?php selected( $filters['website'], 'yes' ); ?>>Has website</option>
						<option value="no" <?php selected( $filters['website'], 'no' ); ?>>Missing</option>
					</select>
				</div>
				<div class="mac-tracker-filter">
					<label for="mac-tracker-layout-filter">Layout</label>
					<select id="mac-tracker-layout-filter" name="layout">
						<option value="">All</option>
						<?php foreach ( $layout_groups as $group ) : ?>
							<option value="<?php echo esc_attr( $group['key'] ); ?>" <?php selected( $filters['layout'], $group['key'] ); ?>>
								<?php echo esc_html( $group['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mac-tracker-filter">
					<label for="mac-tracker-task-filter">Action task</label>
					<select id="mac-tracker-task-filter" name="action_task">
						<option value="">All</option>
						<option value="yes" <?php selected( $filters['action_task'], 'yes' ); ?>>Action Design</option>
						<option value="no" <?php selected( $filters['action_task'], 'no' ); ?>>CSV pin / Domain</option>
					</select>
				</div>
				<div class="mac-tracker-filter">
					<label for="mac-tracker-per-page">Rows</label>
					<select id="mac-tracker-per-page" name="per_page">
						<option value="all" <?php selected( $show_all ); ?>>All</option>
						<?php foreach ( array( 50, 100, 200 ) as $row_count ) : ?>
							<option value="<?php echo esc_attr( (string) $row_count ); ?>" <?php selected( ! $show_all && $per_page === $row_count ); ?>><?php echo esc_html( (string) $row_count ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mac-tracker-filter-actions">
					<?php submit_button( 'Apply filters', 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mac-project-tracker-projects' ) ); ?>">Clear</a>
				</div>
			</form>

			<div class="mac-tracker-results-summary">
				<strong><?php echo esc_html( sprintf( '%d snapshots', $total_items ) ); ?></strong>
				<?php if ( ! $show_all ) : ?>
					<span><?php echo esc_html( sprintf( '%d–%d', $range_start, $range_end ) ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( empty( $projects ) ) : ?>
				<p>No projects.</p>
			<?php else : ?>
				<div class="mac-tracker-table-scroll">
				<table class="widefat striped mac-tracker-projects">
					<thead>
					<tr>
						<th scope="col" class="mac-tracker-stt-column"><span class="mac-tracker-sort-label">#</span></th>
						<?php $this->render_project_sort_header( 'ID', 'id', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Task', 'task', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Project', 'project', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Website', 'website', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Layout', 'layout', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Assignee', 'assignee', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Date', 'completed', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Time', 'time', $filters, $rows_param ); ?>
						<?php $this->render_project_sort_header( 'Palette', 'palette', $filters, $rows_param ); ?>
					</tr>
					</thead>
					<tbody>
					<?php
					$stt = $offset + 1;
					foreach ( $projects as $project ) :
						?>
						<tr>
							<td class="mac-tracker-stt"><?php echo esc_html( (string) $stt ); ?></td>
							<td class="mac-tracker-project-id">
								<?php $this->render_wpm_project_link( (int) ( $project['wpm_project_id'] ?? 0 ), (string) ( $project['wpm_project_id'] ?? '' ), true ); ?>
							</td>
							<td class="mac-tracker-task"><?php $this->render_snapshot_identity( $project ); ?></td>
							<td class="mac-tracker-project-name">
								<?php $this->render_wpm_project_link( (int) ( $project['wpm_project_id'] ?? 0 ), $this->project_label( $project ), true ); ?>
							</td>
							<td class="mac-tracker-website"><?php $this->render_website( $project['website_url'] ?? '' ); ?></td>
							<td class="mac-tracker-layout"><?php $this->render_layout( $project['layout_url'] ?? '' ); ?></td>
							<td class="mac-tracker-assignee"><strong><?php echo esc_html( $this->assignee_label( $project['assignee_json'] ?? '' ) ); ?></strong></td>
							<td class="mac-tracker-completed-date"><?php echo esc_html( MAC_Tracker_Time::date_part( $project['task_completed_at'] ?? '' ) ?: '—' ); ?></td>
							<td class="mac-tracker-completed-time"><?php echo esc_html( MAC_Tracker_Time::time_part( $project['task_completed_at'] ?? '' ) ?: '—' ); ?></td>
							<td class="mac-tracker-palette-cell">
								<?php $this->status_badge( $project['color_status'] ?: 'none' ); ?>
								<?php $this->render_color_chips( $project['final_colors'] ); ?>
							</td>
						</tr>
						<?php
						++$stt;
					endforeach;
					?>
					</tbody>
				</table>
				</div>
				<?php
				if ( ! $show_all ) {
					$this->render_project_pagination( $current_page, $total_pages, $rows_param, $filters );
				}
				?>
			<?php endif; ?>
		</div>
		<?php
		$this->page_end();
	}

	/**
	 * Render one accessible sortable table heading. The first click on an
	 * inactive column uses descending order; the next click reverses it.
	 *
	 * @param string     $label Header label.
	 * @param string     $key Sort key.
	 * @param array      $filters Active filters.
	 * @param int|string $per_page Rows per page, or "all".
	 */
	private function render_project_sort_header( $label, $key, array $filters, $per_page ) {
		$is_active = $key === $filters['sort_by'];
		$current   = $is_active ? $filters['sort_order'] : '';
		$next      = $is_active && 'desc' === $current ? 'asc' : 'desc';
		$query     = $this->project_query_args( $filters, $per_page );
		$query['sort_by']    = $key;
		$query['sort_order'] = $next;
		$query['paged']      = 1;
		$url        = add_query_arg( $query, admin_url( 'admin.php' ) );
		$aria_sort  = $is_active ? ( 'asc' === $current ? 'ascending' : 'descending' ) : 'none';
		$dir        = $is_active ? $current : 'none';
		$class_name = 'mac-tracker-sort-link' . ( $is_active ? ' is-active' : '' );

		printf(
			'<th scope="col" class="mac-tracker-sort-column%1$s" aria-sort="%2$s"><span class="mac-tracker-sort-inner"><span class="mac-tracker-sort-label">%3$s</span><a class="%4$s" href="%5$s" title="Sort"><span class="mac-tracker-sort-indicator mac-tracker-sort-indicator--%6$s" aria-hidden="true"></span><span class="screen-reader-text">Sort by %3$s</span></a></span></th>',
			$is_active ? ' is-sorted' : '',
			esc_attr( $aria_sort ),
			esc_html( $label ),
			esc_attr( $class_name ),
			esc_url( $url ),
			esc_attr( $dir )
		);
	}

	/**
	 * Build the shared Projects query string used by header sorting/pagination.
	 *
	 * @param array      $filters Active filters.
	 * @param int|string $per_page Rows per page, or "all".
	 * @return array
	 */
	private function project_query_args( array $filters, $per_page ) {
		$query = array(
			'page'        => 'mac-project-tracker-projects',
			'per_page'    => ( 'all' === $per_page || 0 === (int) $per_page ) ? 'all' : (int) $per_page,
			's'           => $filters['search'],
			'assignee'    => $filters['assignee'],
			'palette'     => $filters['palette'],
			'website'     => $filters['website'],
			'layout'      => $filters['layout'],
			'action_task' => $filters['action_task'],
			'sort_by'     => $filters['sort_by'],
			'sort_order'  => $filters['sort_order'],
		);

		return array_filter(
			$query,
			function ( $value ) {
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * Compact Task column label (AD #id / Pin / Domain).
	 *
	 * @param array $project Project snapshot row.
	 */
	private function render_snapshot_identity( array $project ) {
		$kind    = (string) ( $project['record_kind'] ?? '' );
		$task_id = (int) ( $project['wpm_action_task_id'] ?? 0 );

		if ( 'action_design' === $kind ) {
			$label    = '#' . $task_id;
			$modifier = 'action';
		} elseif ( 'csv_pin' === $kind ) {
			$label    = 'Pin';
			$modifier = 'pin';
		} else {
			$label    = 'Domain';
			$modifier = 'domain';
		}

		printf(
			'<span class="mac-tracker-snapshot-kind mac-tracker-snapshot-kind--%1$s">%2$s</span>',
			esc_attr( $modifier ),
			esc_html( $label )
		);
	}

	/**
	 * @param int   $current_page Current page.
	 * @param int   $total_pages Total pages.
	 * @param int   $per_page Rows per page.
	 * @param array $filters Active filters.
	 */
	private function render_project_pagination( $current_page, $total_pages, $per_page, array $filters ) {
		if ( $total_pages <= 1 ) {
			return;
		}

		$query    = $this->project_query_args( $filters, $per_page );
		$big      = 999999999;
		$base_url = add_query_arg( array_merge( $query, array( 'paged' => $big ) ), admin_url( 'admin.php' ) );
		$links    = paginate_links(
			array(
				'base'      => str_replace( (string) $big, '%#%', esc_url( $base_url ) ),
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'mid_size'  => 2,
				'end_size'  => 1,
				'prev_text' => '‹ Previous',
				'next_text' => 'Next ›',
			)
		);

		if ( $links ) {
			echo '<div class="tablenav mac-tracker-pagination"><div class="tablenav-pages">';
			echo wp_kses_post( $links );
			echo '</div></div>';
		}
	}

	/**
	 * Read one scalar GET value safely.
	 *
	 * @param string $key Query-string key.
	 * @return string
	 */
	private function query_value( $key ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * @param string $value Layout filter GET value.
	 * @return bool
	 */
	private function is_valid_layout_filter( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}
		if ( in_array( $value, array( 'none', 'external' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/^[FSGP]\d+$/i', $value );
	}

	public function render_pins() {
		$this->require_capability();
		$pin_count   = $this->repository->count_pins();
		$purged_ids  = $this->repository->list_purged_project_ids();

		$this->page_start( 'Pin import' );
		$this->render_notice();
		?>
		<div class="mac-tracker-panel">
			<p>
				Import CSV đã map (<code>project_id</code>, Website, Layout, Member).
				Pin = list đã xong (1 dòng baseline). Sync: sửa Project name/zip/package trên pin;
				thêm dòng Task khi AD <code>done</code> với
				<code>completed_at &gt;= <?php echo esc_html( MAC_TRACKER_AD_COMPLETED_CUTOFF ); ?></code>
				(kể cả project đã pin, kể cả trùng URL).
				Sort mặc định: AD mới (≥ cutoff) trên cùng (mới → hôm nay), rồi pin theo CSV từ dưới lên.
			</p>
			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				enctype="multipart/form-data"
				class="mac-tracker-upload-form"
			>
				<input type="hidden" name="action" value="mac_tracker_import_pins">
				<?php wp_nonce_field( 'mac_tracker_import_pins' ); ?>
				<label>
					Mapped CSV
					<input type="file" name="pin_csv" accept=".csv,text/csv" required>
				</label>
				<?php submit_button( 'Import pin list', 'primary', 'submit', false ); ?>
			</form>
			<p class="mac-tracker-meta">Pinned projects: <?php echo esc_html( (string) $pin_count ); ?></p>
			<div class="mac-tracker-toolbar mac-tracker-toolbar--spaced">
				<?php $this->render_clear_data_form( 'mac-project-tracker-pins' ); ?>
				<?php $this->render_reset_pins_form(); ?>
			</div>
		</div>

		<div class="mac-tracker-panel">
			<h2>Purge project IDs</h2>
			<p>Xóa vĩnh viễn snapshot/pin/palette local và chặn sync tạo lại (vd. dev test <code>3018</code>).</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mac-tracker-upload-form"
				onsubmit="return confirm('Purge these project IDs permanently? Sync will not recreate them.');">
				<input type="hidden" name="action" value="mac_tracker_purge_projects">
				<?php wp_nonce_field( 'mac_tracker_purge_projects' ); ?>
				<label>
					WPM project IDs
					<input type="text" name="purge_ids" class="regular-text" placeholder="3018, 3020" required>
				</label>
				<?php submit_button( 'Purge permanently', 'delete', 'submit', false ); ?>
			</form>
			<?php if ( ! empty( $purged_ids ) ) : ?>
				<p class="mac-tracker-meta">Blocked: <?php echo esc_html( implode( ', ', $purged_ids ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		$this->page_end();
	}

	public function render_colors() {
		$this->require_capability();
		$records = $this->repository->list_color_records( '', 200 );

		$this->page_start( 'Color Review' );
		$this->render_notice();
		?>
		<section class="mac-tracker-projects-intro" aria-labelledby="mac-tracker-colors-heading">
			<div>
				<h2 id="mac-tracker-colors-heading">Palettes</h2>
				<p class="mac-tracker-meta">Duyệt palette chờ duyệt từ template color. Approve khóa màu cuối.</p>
			</div>
		</section>
		<div class="mac-tracker-color-grid">
		<?php if ( empty( $records ) ) : ?>
			<div class="mac-tracker-panel">
				<p>Chưa có palette. Sync project có color template để tạo record.</p>
			</div>
		<?php else : ?>
			<?php foreach ( $records as $record ) : ?>
				<?php
				$source_url  = trim( (string) ( $record['source_url'] ?? '' ) );
				$source_type = trim( (string) ( $record['source_type'] ?? '' ) );
				$source_type = '' !== $source_type ? $source_type : 'unknown';
				$status      = (string) ( $record['status'] ?? '' );
				$pin_domain  = trim( (string) ( $record['domain'] ?? '' ) );
				?>
				<article class="mac-tracker-palette-card mac-tracker-palette-card--<?php echo esc_attr( sanitize_html_class( $status ) ); ?>">
					<div class="mac-tracker-palette-card__header">
						<div class="mac-tracker-palette-card__title">
							<h2><?php echo esc_html( $this->project_label( $record, 'project_name' ) ); ?></h2>
							<div class="mac-tracker-palette-meta">
								<span>#<?php echo esc_html( $record['wpm_project_id'] ); ?></span>
								<?php $this->render_snapshot_identity( $record ); ?>
								<?php if ( '' !== $pin_domain ) : ?>
									<span class="mac-tracker-palette-domain"><?php echo esc_html( $pin_domain ); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<?php $this->status_badge( $status ); ?>
					</div>
					<div class="mac-tracker-source-box">
						<span class="mac-tracker-source-type"><?php echo esc_html( $source_type ); ?></span>
						<?php if ( '' !== $source_url ) : ?>
							<?php if ( preg_match( '#^https?://#i', $source_url ) ) : ?>
								<a class="mac-tracker-source-link" href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $source_url ); ?>">
									<?php echo esc_html( $this->truncate( $source_url, 72 ) ); ?>
								</a>
							<?php else : ?>
								<span class="mac-tracker-source-link" title="<?php echo esc_attr( $source_url ); ?>"><?php echo esc_html( $this->truncate( $source_url, 72 ) ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<span class="mac-tracker-source-link mac-tracker-source-link--empty">No source URL</span>
						<?php endif; ?>
					</div>
					<div class="mac-tracker-palette-card__swatches">
						<span class="mac-tracker-palette-card__swatches-label">Palette</span>
						<?php $this->render_color_chips( $record['final_colors'], true ); ?>
					</div>
					<?php if ( 'pending' === $status ) : ?>
						<div class="mac-tracker-palette-card__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="mac_tracker_approve_palette">
								<input type="hidden" name="record_id" value="<?php echo esc_attr( $record['id'] ); ?>">
								<?php wp_nonce_field( 'mac_tracker_approve_palette' ); ?>
								<?php submit_button( 'Approve', 'primary', 'submit', false ); ?>
							</form>
						</div>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		<?php endif; ?>
		</div>
		<?php
		$this->page_end();
	}

	public function render_settings() {
		$this->require_capability();
		$settings       = get_option( 'mac_tracker_settings', array() );
		$settings       = is_array( $settings ) ? $settings : array();
		$wpm_has_secret = '' !== (string) get_option( 'mac_tracker_wpm_secret', '' );
		$gemini_has_key = '' !== (string) get_option( 'mac_tracker_gemini_api_key', '' );

		$this->page_start( 'Tracker Settings' );
		$this->render_notice();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mac-tracker-settings">
			<input type="hidden" name="action" value="mac_tracker_save_settings">
			<?php wp_nonce_field( 'mac_tracker_save_settings' ); ?>

			<div class="mac-tracker-panel">
				<h2>WPM REST API</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="wpm_api_url">Projects endpoint</label></th>
						<td><input class="regular-text code" type="url" name="wpm_api_url" id="wpm_api_url" value="<?php echo esc_attr( $settings['wpm_api_url'] ?? '' ); ?>" placeholder="https://wpm.example.com/api/projects"></td>
					</tr>
					<tr>
						<th><label for="wpm_auth_type">Authentication</label></th>
						<td>
							<select name="wpm_auth_type" id="wpm_auth_type">
								<option value="api_key" <?php selected( $settings['wpm_auth_type'] ?? 'api_key', 'api_key' ); ?>>Tracking-Template-Header</option>
								<option value="bearer" <?php selected( $settings['wpm_auth_type'] ?? 'api_key', 'bearer' ); ?>>Bearer token / JWT</option>
								<option value="none" <?php selected( $settings['wpm_auth_type'] ?? 'api_key', 'none' ); ?>>None</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wpm_api_key_header">API key header</label></th>
						<td>
							<input class="regular-text code" type="text" id="wpm_api_key_header" value="<?php echo esc_attr( MAC_Tracker_WPM_Client::API_KEY_HEADER ); ?>" readonly>
						</td>
					</tr>
					<tr>
						<th><label for="wpm_secret">Token/API key</label></th>
						<td>
							<input class="regular-text" type="password" name="wpm_secret" id="wpm_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $wpm_has_secret ? 'Configured — leave blank to keep' : 'Not configured' ); ?>">
							<?php if ( $wpm_has_secret ) : ?><label><input type="checkbox" name="clear_wpm_secret" value="1"> Remove stored credential</label><?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<div class="mac-tracker-panel">
				<h2>Gemini</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="gemini_model">Model</label></th>
						<td><input class="regular-text code" type="text" name="gemini_model" id="gemini_model" value="<?php echo esc_attr( $settings['gemini_model'] ?? 'gemini-3-flash-preview' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="gemini_api_key">API key</label></th>
						<td>
							<input class="regular-text" type="password" name="gemini_api_key" id="gemini_api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $gemini_has_key ? 'Configured — leave blank to keep' : 'Not configured' ); ?>">
							<?php if ( $gemini_has_key ) : ?><label><input type="checkbox" name="clear_gemini_api_key" value="1"> Remove stored API key</label><?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( 'Save settings' ); ?>
		</form>
		<?php
		$this->page_end();
	}

	/**
	 * @param string $option_name Option containing encrypted data.
	 * @param string $field_name Submitted password field.
	 * @param string $clear_field Submitted clear checkbox.
	 * @return true|WP_Error
	 */
	private function save_encrypted_secret( $option_name, $field_name, $clear_field ) {
		if ( ! empty( $_POST[ $clear_field ] ) ) {
			delete_option( $option_name );
			return true;
		}

		$value = isset( $_POST[ $field_name ] ) && is_scalar( $_POST[ $field_name ] )
			? trim( (string) wp_unslash( $_POST[ $field_name ] ) )
			: '';
		if ( '' === $value ) {
			return true;
		}

		$encrypted = MAC_Tracker_Crypto::encrypt( $value );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		update_option( $option_name, $encrypted, false );
		return true;
	}

	/**
	 * @param string $nonce_action Nonce action.
	 */
	private function require_admin_request( $nonce_action ) {
		$this->require_capability();
		check_admin_referer( $nonce_action );
	}

	private function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this tracker.', 'mac-project-tracker' ) );
		}
	}

	/**
	 * @param string $page Admin page slug.
	 * @param string $message User-facing notice.
	 * @param string $type Notice type.
	 */
	private function redirect_with_notice( $page, $message, $type ) {
		$message = $this->truncate( sanitize_text_field( (string) $message ), 500 );
		$url = add_query_arg(
			array(
				'page'               => $page,
				'mac_tracker_notice' => $message,
				'mac_tracker_type'   => 'error' === $type ? 'error' : 'success',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function render_notice() {
		if ( empty( $_GET['mac_tracker_notice'] ) || ! is_scalar( $_GET['mac_tracker_notice'] ) ) {
			return;
		}

		$message = sanitize_text_field( wp_unslash( $_GET['mac_tracker_notice'] ) );
		$type    = isset( $_GET['mac_tracker_type'] ) && 'error' === $_GET['mac_tracker_type']
			? 'notice-error'
			: 'notice-success';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * @param string $title Page title.
	 */
	private function page_start( $title ) {
		?>
		<div class="wrap mac-tracker-wrap">
			<header class="mac-tracker-page-head">
				<p class="mac-tracker-brand">MAC Tracker</p>
				<h1><?php echo esc_html( $title ); ?></h1>
			</header>
		<?php
	}

	private function page_end() {
		echo '</div>';
	}

	/**
	 * @param string $label Card label.
	 * @param int    $value Card value.
	 * @param string $icon Dashicon name.
	 */
	private function summary_card( $label, $value, $icon ) {
		?>
		<div class="mac-tracker-card">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
			<div><strong><?php echo esc_html( (string) $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
		</div>
		<?php
	}

	/**
	 * @param string $status Status text.
	 */
	private function status_badge( $status ) {
		$status = (string) $status;
		printf(
			'<span class="mac-tracker-status mac-tracker-status--%1$s">%2$s</span>',
			esc_attr( sanitize_html_class( strtolower( $status ) ) ),
			esc_html( $status )
		);
	}

	/**
	 * @param string $json Stored object/string JSON.
	 * @return string
	 */
	private function json_name( $json ) {
		$raw = trim( (string) $json );
		if ( '' === $raw || 'null' === strtolower( $raw ) ) {
			return '—';
		}

		$data = json_decode( $raw, true );
		if ( is_string( $data ) ) {
			// package_json is often a JSON string: "SE1 PROX3".
			$label = trim( $data );
			return '' !== $label ? $label : '—';
		}
		if ( is_array( $data ) ) {
			return (string) ( $data['name'] ?? $data['label'] ?? $data['id'] ?? '—' );
		}
		if ( null === $data && '"' !== substr( $raw, 0, 1 ) && '{' !== substr( $raw, 0, 1 ) && '[' !== substr( $raw, 0, 1 ) ) {
			// Plain non-JSON fallback.
			return $raw;
		}

		return '—';
	}

	/**
	 * Normalized assignee display (Leo 🦁 → Leo).
	 *
	 * @param string $json Assignee JSON.
	 * @return string
	 */
	private function assignee_label( $json ) {
		$raw = $this->json_name( $json );
		if ( '—' === $raw || '' === $raw ) {
			return '—';
		}
		$label = MAC_Tracker_Normalize::display_person_name( $raw );
		return '' !== $label ? $label : '—';
	}

	/**
	 * WPM project edit URL: https://wpm.macusaone.com/projects/{id}/edit
	 *
	 * @param int $wpm_project_id WPM project ID.
	 * @return string
	 */
	private function wpm_project_edit_url( $wpm_project_id ) {
		$wpm_project_id = (int) $wpm_project_id;
		if ( $wpm_project_id <= 0 ) {
			return '';
		}
		return sprintf( 'https://wpm.macusaone.com/projects/%d/edit', $wpm_project_id );
	}

	/**
	 * Render label as link to WPM project edit when ID is known.
	 *
	 * @param int    $wpm_project_id WPM project ID.
	 * @param string $label Visible text.
	 * @param bool   $strong Wrap in <strong>.
	 */
	private function render_wpm_project_link( $wpm_project_id, $label, $strong = false ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			$label = '—';
		}
		$url = $this->wpm_project_edit_url( $wpm_project_id );
		if ( '' === $url ) {
			if ( $strong ) {
				echo '<strong>' . esc_html( $label ) . '</strong>';
			} else {
				echo esc_html( $label );
			}
			return;
		}

		$inner = $strong ? '<strong>' . esc_html( $label ) . '</strong>' : esc_html( $label );
		printf(
			'<a class="mac-tracker-wpm-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $url ),
			$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	/**
	 * Build the agreed display name: zipcode + package + project name.
	 *
	 * @param array  $project Project row.
	 * @param string $name_key Name field in the row.
	 * @return string
	 */
	private function project_label( array $project, $name_key = 'name' ) {
		$zip     = trim( (string) ( $project['zip_code'] ?? '' ) );
		$package = $this->json_name( $project['package_json'] ?? '' );
		$name    = trim( (string) ( $project[ $name_key ] ?? '' ) );

		if ( '—' === $package ) {
			$package = '';
		}

		$prefix = $zip . $package;
		$label  = trim( $prefix . ( '' !== $prefix && '' !== $name ? ' ' : '' ) . $name );
		if ( '' !== $label ) {
			return $label;
		}

		// Pin CSV "Projects" column when snapshot labels were never filled (WPM missing).
		$pin_raw = trim( (string) ( $project['pin_projects_raw'] ?? '' ) );
		return '' !== $pin_raw ? $pin_raw : '—';
	}

	/**
	 * Render valid HTTP(S) sites as links and retain non-URL WPM text safely.
	 *
	 * @param string $website Website value.
	 */
	private function render_website( $website ) {
		$website = trim( (string) $website );
		if ( '' === $website ) {
			echo '—';
			return;
		}

		if ( preg_match( '~^https?://~i', $website ) && wp_http_validate_url( $website ) ) {
			printf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $website ),
				esc_html( $this->truncate( $website, 55 ) )
			);
			return;
		}

		echo esc_html( $this->truncate( $website, 55 ) );
	}

	/**
	 * Extract a URL from layout text. Template URLs receive the compact
	 * "demo-xx - home xx" label; other URLs display as themselves.
	 *
	 * @param string $layout_url WPM layout URL.
	 */
	private function render_layout( $layout_url ) {
		$layout_url = MAC_Tracker_Normalize::normalize_layout_value( $layout_url );
		if ( '' === $layout_url ) {
			echo '—';
			return;
		}

		$urls = $this->extract_http_urls( $layout_url );
		if ( empty( $urls ) ) {
			echo esc_html( $this->truncate( $layout_url, 45 ) );
			return;
		}

		$selected_url = $urls[0];
		$is_template  = false;
		foreach ( $urls as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( preg_match( '/(^|\.)templates\.macusaone\.com$/i', $host ) ) {
				$selected_url = $url;
				$is_template  = true;
				break;
			}
		}

		$label = $is_template
			? $this->template_layout_label( $selected_url )
			: $this->truncate( $selected_url, 45 );
		$href = $is_template
			? rtrim( (string) preg_replace( '/[?#].*$/', '', $selected_url ), '/' ) . '/'
			: $selected_url;

		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $href ),
			esc_html( $label )
		);
	}

	/**
	 * Build a short label for every templates.macusaone.com URL, including
	 * paths whose demo/home segments contain extra hyphens or suffixes.
	 *
	 * @param string $url Template URL.
	 * @return string
	 */
	private function template_layout_label( $url ) {
		$path     = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		$demo     = '';
		$home     = '';

		foreach ( $segments as $segment ) {
			$segment = strtolower( trim( (string) $segment ) );
			if ( '' === $demo && preg_match( '/^demo(?:[-_].+)?$/i', $segment ) ) {
				$demo = str_replace( '_', '-', $segment );
			}
			if ( '' === $home && preg_match( '/^home(?:[-_].*)?$/i', $segment ) ) {
				$home = trim( (string) preg_replace( '/[-_]+/', ' ', $segment ) );
			}
		}

		if ( '' === $demo && isset( $segments[0] ) ) {
			$demo = strtolower( trim( (string) $segments[0] ) );
		}
		if ( '' === $home && isset( $segments[1] ) ) {
			$home = trim( (string) preg_replace( '/[-_]+/', ' ', strtolower( (string) $segments[1] ) ) );
		}

		$parts = array_values( array_filter( array( $demo, $home ) ) );
		return ! empty( $parts ) ? implode( ' - ', $parts ) : 'Template layout';
	}

	/**
	 * @param string $text Arbitrary WPM text which may contain one or more URLs.
	 * @return array Valid unique HTTP(S) URLs.
	 */
	private function extract_http_urls( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		if ( ! preg_match_all( '~https?://[^\s<>"\x27\[\]\(\)]+~i', $text, $matches ) ) {
			return array();
		}

		$urls = array();
		foreach ( $matches[0] as $candidate ) {
			$candidate = rtrim( $candidate, " \t\n\r\0\x0B.,;:!?)]}\"'" );
			$candidate = esc_url_raw( $candidate, array( 'http', 'https' ) );
			if ( '' !== $candidate && wp_http_validate_url( $candidate ) ) {
				$urls[] = $candidate;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * @param string $json Stored color JSON.
	 * @param bool   $large Render detailed labels.
	 */
	private function render_color_chips( $json, $large = false ) {
		$colors = json_decode( (string) $json, true );
		if ( ! is_array( $colors ) || empty( $colors ) ) {
			return;
		}

		echo '<div class="mac-tracker-colors' . ( $large ? ' mac-tracker-colors--large' : '' ) . '">';
		foreach ( $colors as $color ) {
			$hex = is_array( $color ) ? ( $color['hex'] ?? '' ) : $color;
			$hex = strtoupper( (string) $hex );
			if ( ! preg_match( '/^#[0-9A-F]{6}$/', $hex ) ) {
				continue;
			}
			printf(
				'<span class="mac-tracker-color" title="%1$s"><i style="background:%1$s"></i>%2$s</span>',
				esc_attr( $hex ),
				$large ? esc_html( $hex ) : ''
			);
		}
		echo '</div>';
	}

	/**
	 * @param string $value Text to shorten.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private function truncate( $value, $length ) {
		$value = (string) $value;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $length ) {
			return mb_substr( $value, 0, $length - 1 ) . '…';
		}

		if ( strlen( $value ) > $length ) {
			return substr( $value, 0, $length - 3 ) . '...';
		}

		return $value;
	}
}
