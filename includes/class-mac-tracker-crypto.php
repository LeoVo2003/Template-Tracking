<?php

defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Crypto {

	const CIPHER = 'aes-256-gcm';

	/**
	 * Encrypt a secret using the site's WordPress authentication salts.
	 *
	 * @param string $plaintext Secret value.
	 * @return string|WP_Error
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'mac_tracker_no_openssl', 'OpenSSL is required to store API credentials.' );
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return new WP_Error( 'mac_tracker_random_failed', $exception->getMessage() );
		}

		$tag        = '';
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $ciphertext ) {
			return new WP_Error( 'mac_tracker_encrypt_failed', 'Unable to encrypt the API credential.' );
		}

		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a secret stored by encrypt().
	 *
	 * @param string $encoded Encrypted value.
	 * @return string
	 */
	public static function decrypt( $encoded ) {
		if ( '' === (string) $encoded || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$payload = base64_decode( (string) $encoded, true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			return '';
		}

		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? '' : $plaintext;
	}
}
