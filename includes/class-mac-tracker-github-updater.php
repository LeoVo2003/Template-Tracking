<?php

defined( 'ABSPATH' ) || exit;

/**
 * Native updater backed by public GitHub Releases.
 *
 * The release must contain a ZIP asset named
 * mac-project-tracker-vX.Y.Z.zip with mac-project-tracker/ as its top-level
 * directory. The GitHub Actions release workflow enforces that contract.
 */
class MAC_Tracker_GitHub_Updater {

	const UPDATE_URI = 'https://github.com/LeoVo2003/Template-Tracking';
	const API_URL    = 'https://api.github.com/repos/LeoVo2003/Template-Tracking/releases/latest';
	const CACHE_KEY  = 'mac_tracker_github_release';
	const SLUG       = 'mac-project-tracker';

	/**
	 * Register update hooks after the plugin has loaded.
	 */
	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );
	}

	/**
	 * Provide WordPress with the newest public GitHub release.
	 *
	 * @param array|false $update      Existing update response.
	 * @param array       $plugin_data Parsed plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales     Installed locales.
	 * @return array|false
	 */
	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		$update_uri = isset( $plugin_data['UpdateURI'] )
			? untrailingslashit( (string) $plugin_data['UpdateURI'] )
			: '';

		if (
			plugin_basename( MAC_TRACKER_FILE ) !== $plugin_file
			|| self::UPDATE_URI !== $update_uri
		) {
			return $update;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) ) {
			return $update;
		}

		$version = $this->release_version( $release );
		$package = $this->release_package_url( $release );
		if ( '' === $version || '' === $package ) {
			return $update;
		}

		return array(
			'id'           => self::UPDATE_URI,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $version,
			'new_version'  => $version,
			'url'          => isset( $release['html_url'] ) ? esc_url_raw( (string) $release['html_url'] ) : self::UPDATE_URI,
			'package'      => $package,
			'requires_php' => '7.4',
			'autoupdate'   => true,
		);
	}

	/**
	 * Show release information in the WordPress plugin details modal.
	 *
	 * @param false|object|array $result Existing API result.
	 * @param string             $action Requested API action.
	 * @param object             $args   API request arguments.
	 * @return false|object|array
	 */
	public function filter_plugin_information( $result, $action, $args ) {
		if (
			'plugin_information' !== $action
			|| ! is_object( $args )
			|| empty( $args->slug )
			|| self::SLUG !== (string) $args->slug
		) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) ) {
			return $result;
		}

		$version = $this->release_version( $release );
		$package = $this->release_package_url( $release );
		if ( '' === $version || '' === $package ) {
			return $result;
		}

		$notes = isset( $release['body'] ) ? trim( (string) $release['body'] ) : '';
		if ( '' === $notes ) {
			$notes = 'See the GitHub release page for changes in this version.';
		}

		$info                    = new stdClass();
		$info->name              = 'MAC Project Tracker';
		$info->slug              = self::SLUG;
		$info->version           = $version;
		$info->author            = '<a href="https://macmarketing.us/">MAC Marketing</a>';
		$info->homepage          = self::UPDATE_URI;
		$info->requires          = '6.5';
		$info->requires_php      = '7.4';
		$info->download_link     = $package;
		$info->last_updated      = isset( $release['published_at'] ) ? (string) $release['published_at'] : '';
		$info->sections          = array(
			'description' => 'Synchronizes pinned WPM project snapshots and manages approved template-color palettes.',
			'changelog'   => wpautop( esc_html( $notes ) ),
		);
		$info->external          = true;

		return $info;
	}

	/**
	 * Fetch and cache the latest non-draft, non-prerelease GitHub release.
	 *
	 * @return array|false
	 */
	private function get_latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			if ( ! empty( $cached['_unavailable'] ) ) {
				return false;
			}

			return $cached;
		}

		$response = wp_remote_get(
			self::API_URL,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'MAC-Project-Tracker/' . MAC_TRACKER_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->cache_unavailable_release();
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if (
			! is_array( $release )
			|| empty( $release['tag_name'] )
			|| ! empty( $release['draft'] )
			|| ! empty( $release['prerelease'] )
		) {
			$this->cache_unavailable_release();
			return false;
		}

		set_site_transient( self::CACHE_KEY, $release, HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Convert a v-prefixed release tag to a WordPress version string.
	 *
	 * @param array $release GitHub release payload.
	 * @return string
	 */
	private function release_version( array $release ) {
		$version = isset( $release['tag_name'] )
			? ltrim( trim( (string) $release['tag_name'] ), 'vV' )
			: '';

		return preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version )
			? $version
			: '';
	}

	/**
	 * Locate the packaged WordPress ZIP in the GitHub release assets.
	 *
	 * @param array $release GitHub release payload.
	 * @return string
	 */
	private function release_package_url( array $release ) {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) ? esc_url_raw( (string) $asset['browser_download_url'] ) : '';
			if (
				! preg_match( '/^mac-project-tracker-v\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?\.zip$/i', $name )
				|| 0 !== strpos( $url, self::UPDATE_URI . '/releases/download/' )
			) {
				continue;
			}

			return $url;
		}

		return '';
	}

	/**
	 * Cache short failures so a temporary GitHub error does not slow every admin request.
	 */
	private function cache_unavailable_release() {
		set_site_transient(
			self::CACHE_KEY,
			array( '_unavailable' => true ),
			15 * MINUTE_IN_SECONDS
		);
	}
}
