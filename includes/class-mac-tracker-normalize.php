<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shared string/URL/person normalizers used by sync, pin import, and admin UI.
 */
class MAC_Tracker_Normalize {

	/**
	 * @param string $value URL or host.
	 * @return string
	 */
	public static function normalize_host_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false === strpos( $value, '://' ) ) {
			$value = 'https://' . ltrim( $value, '/' );
		}
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = preg_replace( '#^[a-z]+://#i', '', (string) $value );
			$host = preg_replace( '#/.*$#', '', (string) $host );
		}
		$host = strtolower( (string) $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * @param string $value URL or host.
	 * @return string https://host/
	 */
	public static function normalize_website_url_value( $value ) {
		$host = self::normalize_host_value( $value );
		if ( '' === $host ) {
			return '';
		}
		return 'https://' . $host . '/';
	}

	/**
	 * "Build giống với website parisnailsandspa.com" → https://parisnailsandspa.com/
	 *
	 * @param string $value Raw layout value.
	 * @return string
	 */
	public static function normalize_layout_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#https?://[^\s\'"<>]+#i', $value, $match ) ) {
			$url = rtrim( $match[0], '.,);]\'"' );
			if ( preg_match( '/\s/', $value ) ) {
				$host = self::normalize_host_value( $url );
				if ( '' !== $host && preg_match( '/(^|\.)templates\.macusaone\.com$/i', $host ) ) {
					return $url;
				}
				return '' !== $host ? 'https://' . $host . '/' : $url;
			}
			return $url;
		}

		if ( preg_match( '/\b((?:[a-z0-9-]+\.)+[a-z]{2,})(?:\/[^\s\'"<>]*)?/i', $value, $match ) ) {
			$found = rtrim( $match[0], '.,);]\'"' );
			$host  = self::normalize_host_value( $found );
			if ( '' === $host ) {
				return $value;
			}
			$full = 'https://' . ltrim( $found, '/' );
			if ( preg_match( '/\s/', $value ) ) {
				if ( preg_match( '/(^|\.)templates\.macusaone\.com$/i', $host ) ) {
					return $full;
				}
				return 'https://' . $host . '/';
			}
			if ( false !== strpos( $found, '/' ) ) {
				return $full;
			}
			return 'https://' . $host . '/';
		}

		return $value;
	}

	/**
	 * Filter group for Projects Layout dropdown.
	 * MAC template → package code (F01/S06…); other URL → external; empty → none.
	 *
	 * @param string $layout_url Raw or stored layout value.
	 * @return string none|external|[FSGP]\d+
	 */
	public static function layout_group_key( $layout_url ) {
		$raw = trim( (string) $layout_url );
		if ( '' === $raw ) {
			return 'none';
		}

		$normalized = self::normalize_layout_value( $raw );
		$haystack   = strtolower( '' !== $normalized ? $normalized : $raw );
		$code       = self::extract_mac_template_code( $haystack );
		if ( '' !== $code ) {
			return $code;
		}

		$host = self::normalize_host_value( '' !== $normalized ? $normalized : $raw );
		if ( '' !== $host || preg_match( '#https?://#i', $haystack ) ) {
			return 'external';
		}

		return 'external';
	}

	/**
	 * @param string $haystack Layout URL/path (preferably lowercased).
	 * @return string Uppercase package code (S06) or empty.
	 */
	public static function extract_mac_template_code( $haystack ) {
		$haystack = strtolower( (string) $haystack );
		if ( '' === $haystack ) {
			return '';
		}
		// Full template URL or compact label already stored (demo-s06 - home 03).
		if ( preg_match( '/demo[-_]?([fsgp])(\d+)/', $haystack, $match ) ) {
			return strtoupper( $match[1] . $match[2] );
		}
		return '';
	}

	/**
	 * @param string $name Raw assignee label.
	 * @return string Normalized lowercase key without emoji/noise.
	 */
	public static function normalize_person_key( $name ) {
		$name = html_entity_decode( trim( (string) $name ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $name ) {
			return '';
		}

		$name = preg_replace( '/[\x{FE00}-\x{FE0F}\x{200D}\x{2640}-\x{2642}]/u', '', $name );
		$name = preg_replace( '/[\x{1F000}-\x{1FFFF}]/u', '', $name );
		$name = preg_replace( '/[\x{2600}-\x{27BF}]/u', '', $name );
		$name = preg_replace( '/[^\p{L}\p{N}\s.\-\']/u', ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', trim( (string) $name ) );

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $name, 'UTF-8' );
		}

		return strtolower( $name );
	}

	/**
	 * @param string $name Raw assignee label.
	 * @return string Title-cased display without emoji.
	 */
	public static function display_person_name( $name ) {
		$key = self::normalize_person_key( $name );
		if ( '' === $key ) {
			return '';
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $key, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $key );
	}

	/**
	 * Parse sheet "Projects" / WPM full_name into zip + package + store name.
	 *
	 * @param string $projects_raw Raw label.
	 * @return array{zip:string,package:string,store_name:string}
	 */
	public static function parse_projects_label( $projects_raw ) {
		$text = trim( (string) $projects_raw );
		$zip  = '';
		if ( preg_match( '/^(\d{5})/', $text, $m ) ) {
			$zip  = $m[1];
			$text = substr( $text, strlen( $zip ) );
		}

		// Sheet often glues zip+package: 32836SE1… / 7311600M… / 60543S00…
		$package = array();
		if ( preg_match( '/^(SE\d+|SEM|SCM|S\d+|F\d+|G\d+|P\d+|00M|000)(?=\s|$)/i', $text, $m ) ) {
			$package[] = strtoupper( $m[1] );
			$text      = substr( $text, strlen( $m[1] ) );
		}
		$text = trim( $text );

		$package_tokens = array(
			'SE1', 'SE2', 'SE3', 'PROX', 'PROX2', 'PROX3', 'PROX4', 'PRO',
			'BASIC', 'STANDARD', 'PREMIUM', 'PLUS', 'QR', 'MENU', 'SEM', 'SCM',
			'S00', 'F00', 'G00', 'P00', '00M', '000',
		);

		$tokens = preg_split( '/\s+/', $text );
		$kept   = array();
		if ( is_array( $tokens ) ) {
			foreach ( $tokens as $token ) {
				$token = trim( (string) $token );
				if ( '' === $token ) {
					continue;
				}
				$upper = strtoupper( $token );
				if (
					in_array( $upper, $package_tokens, true )
					|| preg_match( '/^(SE\d+|SEM|SCM|PROX\d*|PRO|BASIC|STANDARD|PREMIUM|PLUS|S\d+|F\d+|G\d+|P\d+|00M|000)$/i', $token )
				) {
					$package[] = $upper;
					continue;
				}
				$kept[] = $token;
			}
		}

		return array(
			'zip'        => $zip,
			'package'    => trim( implode( ' ', $package ) ),
			'store_name' => trim( implode( ' ', $kept ) ),
		);
	}
}
