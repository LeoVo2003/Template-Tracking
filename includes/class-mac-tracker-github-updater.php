<?php

defined( 'ABSPATH' ) || exit;

/** Native WordPress updater backed by public GitHub Release ZIP assets. */
class MAC_Tracker_GitHub_Updater {

	const UPDATE_URI = 'https://github.com/LeoVo2003/Template-Tracking';
	const API_URL    = 'https://api.github.com/repos/LeoVo2003/Template-Tracking/releases/latest';
	const CACHE_KEY  = 'mac_tracker_github_release';
	const SLUG       = 'mac-project-tracker';

	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );
	}

	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );
		$update_uri = isset( $plugin_data['UpdateURI'] ) ? untrailingslashit( (string) $plugin_data['UpdateURI'] ) : '';
		if ( plugin_basename( MAC_TRACKER_FILE ) !== $plugin_file || self::UPDATE_URI !== $update_uri ) {
			return $update;
		}

		$release = $this->get_latest_release();
		$version = is_array( $release ) ? $this->release_version( $release ) : '';
		$package = is_array( $release ) ? $this->release_package_url( $release ) : '';
		$current = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : MAC_TRACKER_VERSION;
		if ( '' === $version || '' === $package || ! version_compare( $version, $current, '>' ) ) {
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
			'requires'     => '6.5',
			'requires_php' => '7.4',
			'autoupdate'   => true,
		);
	}

	public function filter_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== (string) $args->slug ) {
			return $result;
		}
		$release = $this->get_latest_release();
		$version = is_array( $release ) ? $this->release_version( $release ) : '';
		$package = is_array( $release ) ? $this->release_package_url( $release ) : '';
		if ( '' === $version || '' === $package ) {
			return $result;
		}

		$notes = trim( (string) ( $release['body'] ?? '' ) );
		$info  = new stdClass();
		$info->name          = 'MAC Project Tracker';
		$info->slug          = self::SLUG;
		$info->version       = $version;
		$info->author        = '<a href="https://macmarketing.us/">MAC Marketing</a>';
		$info->homepage      = self::UPDATE_URI;
		$info->requires      = '6.5';
		$info->requires_php  = '7.4';
		$info->download_link = $package;
		$info->last_updated  = (string) ( $release['published_at'] ?? '' );
		$info->sections      = array(
			'description' => 'Internal WPM project snapshot tracker for MAC Marketing.',
			'changelog'   => wpautop( esc_html( '' !== $notes ? $notes : 'See the GitHub release page for changes.' ) ),
		);
		$info->external = true;

		return $info;
	}

	private function get_latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['_unavailable'] ) ? $cached : false;
		}

		$response = wp_safe_remote_get(
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
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			$this->cache_unavailable_release();
			return false;
		}

		set_site_transient( self::CACHE_KEY, $release, HOUR_IN_SECONDS );
		return $release;
	}

	private function release_version( array $release ) {
		$version = ltrim( trim( (string) ( $release['tag_name'] ?? '' ) ), 'vV' );
		return preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ? $version : '';
	}

	private function release_package_url( array $release ) {
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( ! is_array( $asset ) ) { continue; }
			$name = (string) ( $asset['name'] ?? '' );
			$url  = esc_url_raw( (string) ( $asset['browser_download_url'] ?? '' ) );
			if ( preg_match( '/^mac-project-tracker-v\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?\.zip$/i', $name ) && 0 === strpos( $url, self::UPDATE_URI . '/releases/download/' ) ) {
				return $url;
			}
		}
		return '';
	}

	private function cache_unavailable_release() {
		set_site_transient( self::CACHE_KEY, array( '_unavailable' => true ), 15 * MINUTE_IN_SECONDS );
	}
}
