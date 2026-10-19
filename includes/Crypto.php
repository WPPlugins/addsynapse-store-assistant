<?php
/**
 * Symmetric encryption for data at rest (libsodium secretbox, key derived from the WP salts).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * One helper for everything the plugin keeps encrypted in the database: the own AI key and the optional AddSynapse
 * account's site key. Each context derives its own key from the salts, so contexts never share a key. The key is
 * never stored: changing the WordPress salts makes old values unreadable (decrypt → '').
 */
final class Crypto {

	/**
	 * Key for a context (32 bytes).
	 *
	 * @param string $context Purpose label.
	 */
	private static function key( string $context ): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . $context . '|' . wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Encrypts for storage ("v1:" + base64(nonce . ciphertext)).
	 *
	 * @param string $plain   Plain bytes.
	 * @param string $context Purpose label.
	 */
	public static function encrypt( string $plain, string $context ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key( $context ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
	}

	/**
	 * Decrypts a stored value (null when malformed, tampered with, or the salts changed).
	 *
	 * @param string $stored  Stored value.
	 * @param string $context Purpose label.
	 */
	public static function decrypt( string $stored, string $context ): ?string {
		if ( 0 !== strpos( $stored, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext storage.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key( $context ) );
		return false === $plain ? null : $plain;
	}
}
