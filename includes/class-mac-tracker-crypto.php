<?php

defined( 'ABSPATH' ) || exit;

/** Encrypt credentials at rest with this WordPress site's authentication salt. */
class MAC_Tracker_Crypto {

	const CIPHER = 'aes-256-gcm';

	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
			return new WP_Error( 'mac_tracker_crypto_unavailable', 'OpenSSL is required to save the WPM credential.' );
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return new WP_Error( 'mac_tracker_crypto_random', 'Unable to generate secure credential storage.' );
		}

		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			return new WP_Error( 'mac_tracker_crypto_encrypt', 'Unable to encrypt the WPM credential.' );
		}

		return base64_encode( $iv . $tag . $ciphertext );
	}

	public static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$payload = base64_decode( $encoded, true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			return '';
		}

		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$plaintext  = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : $plaintext;
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
