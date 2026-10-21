<?php
/**
 * Signed approval tickets for remote writes (Connect AI).
 *
 * A remote write (apply-price-changes, apply-changes) runs only with a ticket that AddSynapse signed (Ed25519) at
 * the moment the shop owner approved or undid a change set. The ticket names this site, the change set, the action
 * and the SHA-256 of the exact write it allows; it expires after 5 minutes and is accepted once. The public keys are
 * pinned below (rotation = a new key id in a plugin update); nothing is fetched from outside. Even someone holding
 * the application password cannot write without a ticket, and a ticket cannot be reused for another write.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies approval tickets.
 */
final class RemoteTicket {

	/**
	 * Pinned AddSynapse public keys (base64, Ed25519), by key id. Old ids stay until their tickets cannot exist anymore.
	 * PoC key of 21/10/2026 — the production key replaces it before release.
	 */
	public const KEYS = array(
		'poc-20261021' => 'mQBynWpbO2F5bXxBbvjwlYDddQTx/fW/oAIaGYy/Iys=',
		// addsynapse.com hub (SA_RELAY_TICKET_KID=hub-20261021); the private key lives only on the hub, outside the web root.
		'hub-20261021' => 'xiceji7Y4kHvUnMLqM4U6ZZEkxAGq8p1aiP5LgYNPjE=',
	);

	/**
	 * Extra keys trusted only inside the WordPress test suite (never on a site: WP_TESTS_DOMAIN is not defined there).
	 *
	 * @var array<string,string>
	 */
	private static $test_keys = array();

	/**
	 * Test seam: trusts a key while the PHPUnit suite runs.
	 *
	 * @param string $kid Key id.
	 * @param string $pub Base64 public key.
	 */
	public static function trust_key_for_tests( string $kid, string $pub ): void {
		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			self::$test_keys[ $kid ] = $pub;
		}
	}

	/** Longest validity a ticket may claim (seconds) and the clock skew we tolerate. */
	public const MAX_TTL = 300;
	public const SKEW    = 60;

	/** Option prefix of used ticket nonces (value = expiry timestamp; covered by uninstall.php). */
	public const NONCE_PREFIX = 'addsynapse_assistant_tkt_';

	/**
	 * Checks the ticket of a remote write and marks its nonce used. Returns null when the write may run.
	 *
	 * @param mixed               $ticket  `ticket` of the request: {payload, sig} (base64url).
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   The exact input that will be executed.
	 * @return \WP_Error|null
	 */
	public static function verify( $ticket, string $ability, array $input ) {
		if ( ! is_array( $ticket ) || ! is_string( $ticket['payload'] ?? null ) || ! is_string( $ticket['sig'] ?? null ) ) {
			return self::refuse( 'ticket_missing', 'This change needs an approval from the shop owner on AddSynapse. Nothing was changed.' );
		}
		$raw = self::b64url_decode( $ticket['payload'] );
		$sig = self::b64url_decode( $ticket['sig'] );
		$t   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $t ) || ! is_string( $sig ) || 64 !== strlen( $sig ) ) {
			return self::refuse( 'ticket_invalid', 'The approval is not valid. Nothing was changed.' );
		}
		$kid  = (string) ( $t['kid'] ?? '' );
		$keys = self::KEYS + self::$test_keys;
		$pub  = isset( $keys[ $kid ] ) ? base64_decode( $keys[ $kid ], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- pinned public key, not code.
		if ( ! is_string( $pub ) || 32 !== strlen( $pub ) ) {
			return self::refuse( 'ticket_key', 'The approval was signed with an unknown key. Nothing was changed.' );
		}
		try {
			$valid = sodium_crypto_sign_verify_detached( $sig, $raw, $pub );
		} catch ( \Throwable $e ) {
			$valid = false;
		}
		if ( ! $valid ) {
			return self::refuse( 'ticket_signature', 'The approval signature does not match. Nothing was changed.' );
		}
		if ( 1 !== (int) ( $t['v'] ?? 0 ) || ! self::same_site( (string) ( $t['aud'] ?? '' ) ) ) {
			return self::refuse( 'ticket_site', 'The approval is for another site. Nothing was changed.' );
		}
		$action = (string) ( $input['action'] ?? '' );
		$want   = 'undo' === $action ? 'undo' : ( 'approve' === $action ? 'apply' : '' );
		if ( '' === $want || ( $t['act'] ?? '' ) !== $want || (string) ( $t['cs'] ?? '' ) !== (string) ( $input['changeset'] ?? '' )
			|| ! hash_equals( (string) ( $t['h'] ?? '' ), self::hash( $ability, $input ) ) ) {
			return self::refuse( 'ticket_mismatch', 'The approval does not match this change. Nothing was changed.' );
		}
		$now = time();
		$exp = (int) ( $t['exp'] ?? 0 );
		$iat = (int) ( $t['iat'] ?? 0 );
		if ( $exp < $now - self::SKEW || $iat > $now + self::SKEW || $exp - $iat > self::MAX_TTL ) {
			return self::refuse( 'ticket_expired', 'The approval has expired. Nothing was changed — approve it again.' );
		}
		$nonce = (string) ( $t['nonce'] ?? '' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{16,64}$/D', $nonce ) ) {
			return self::refuse( 'ticket_invalid', 'The approval is not valid. Nothing was changed.' );
		}
		self::prune( $now );
		// add_option() fails when the name exists (unique key): exactly one request can use a nonce.
		if ( ! add_option( self::NONCE_PREFIX . substr( hash( 'sha256', $nonce ), 0, 40 ), (string) ( $exp + self::SKEW ), '', false ) ) {
			return self::refuse( 'ticket_used', 'This approval was already used. Nothing more was changed.' );
		}
		return null;
	}

	/**
	 * SHA-256 of the ability + its input in a canonical JSON form (same function on AddSynapse).
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 */
	public static function hash( string $ability, array $input ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $ability, self::canonical( $input ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	/**
	 * Sorts the keys of every object (lists keep their order).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		$out  = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = self::canonical( $v );
		}
		if ( ! $list ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/**
	 * The ticket's audience is this site (home or site address, scheme and trailing slash ignored).
	 *
	 * @param string $aud Audience.
	 */
	private static function same_site( string $aud ): bool {
		$norm = static function ( string $u ): string {
			return rtrim( (string) preg_replace( '#^https?://#i', '', strtolower( trim( $u ) ) ), '/' );
		};
		return '' !== $aud && in_array( $norm( $aud ), array( $norm( home_url() ), $norm( site_url() ) ), true );
	}

	/**
	 * Forgets nonces whose ticket can no longer be valid.
	 *
	 * @param int $now Now.
	 */
	private static function prune( int $now ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( self::NONCE_PREFIX ) . '%',
				$now
			)
		);
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $s Encoded.
	 * @return string|false
	 */
	private static function b64url_decode( string $s ) {
		return base64_decode( strtr( $s, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signed ticket data, not code.
	}

	/**
	 * Refusal (403) — recorded nowhere else; the caller adds it to the approval log.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function refuse( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'addsynapse_assistant_' . $code, $message, array( 'status' => 403 ) );
	}
}
