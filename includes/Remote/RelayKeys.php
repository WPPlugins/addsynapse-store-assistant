<?php
/**
 * The AddSynapse connection key (1.3.4): this plugin's own credential for AddSynapse, instead of a WordPress Application
 * Password. Many sites cannot issue Application Passwords (security plugins such as Wordfence turn them off by default),
 * and this plugin does not change that setting: it never touches Application Passwords availability, never authenticates
 * anything outside its own routes.
 *
 * A key is created only on this plugin's own «Connect this site to the AddSynapse account …?» screen, when a logged-in
 * administrator presses Connect (nonce). It is:
 *  - bound to that administrator (AddSynapse acts as them, with their capabilities, re-checked on every request) and to
 *    the AddSynapse account the administrator confirmed (shown on that screen);
 *  - limited to this plugin's `addsynapse-assistant/v1/remote/*` routes and the fixed tool allow-list (RemoteAccess::ALLOWED).
 *    It is checked only inside those routes' own permission callback — no global authentication hook: on every other
 *    route of the site (and over XML-RPC) the key means nothing;
 *  - 256 random bits; only a keyed hash (HMAC-SHA256 with a random per-site secret) is stored, compared in constant time.
 *    The plain key leaves the site once, server to server, in the JSON body of the claim to AddSynapse (never in a URL);
 *  - accepted only over HTTPS (like WordPress' Application Passwords; a `local` environment is the only exception);
 *  - revoked by «Disconnect» (wp-admin or AddSynapse), on deactivation and on uninstall, and replaced by rotation.
 * Failed attempts are rate limited per IP address; a valid key always works (security review 22/10/2026: a shared proxy IP
 * must not lock AddSynapse out) and clears the count. The key's administrator must still be one who may hold a key (may_hold)
 * on every request — demoted or removed → the key is revoked.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Store + check of AddSynapse connection keys.
 */
final class RelayKeys {

	/** Keys by id: {user, hash, created, last_used, account}. Never the key itself. */
	public const OPTION = 'addsynapse_assistant_relay_keys';

	/** When each key was last used, by id — its own option, so this write never re-saves (or brings back) a key. */
	public const USED_OPTION = 'addsynapse_assistant_relay_keys_used';

	/** Random per-site secret of the stored hashes (independent of the WordPress salts: changing salts keeps the connection). */
	public const PEPPER_OPTION = 'addsynapse_assistant_relay_key_pepper';

	/** Transient prefix (+ md5 of the IP address) of failed attempts. */
	public const FAIL_PREFIX = 'addsynapse_assistant_keyfail_';

	/** Failed attempts allowed per IP address in FAIL_WINDOW. */
	public const FAIL_LIMIT = 20;

	/** Seconds. */
	public const FAIL_WINDOW = 600;

	/** Scheme in the X-AddSynapse-Auth header: «Key <id>.<secret>». */
	public const SCHEME = 'Key';

	/** Credential reference used by RelayClaim (pending / claimed): «key:<id>». */
	public const REF_PREFIX = 'key:';

	/** At most this many keys on a site (older unconfirmed ones go first). */
	public const MAX_KEYS = 10;

	/**
	 * Hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'deleted_user', array( self::class, 'revoke_user' ) );
		add_action( 'remove_user_from_blog', array( self::class, 'revoke_user' ) );
		// A demoted administrator: their keys go at once (permit() checks may_hold() on every request too).
		add_action( 'set_user_role', array( self::class, 'revoke_if_not_allowed' ) );
		add_action( 'remove_user_role', array( self::class, 'revoke_if_not_allowed' ) );
	}

	/**
	 * Revokes the keys of a user who may no longer hold one.
	 *
	 * @param int $user_id User.
	 */
	public static function revoke_if_not_allowed( $user_id ): void {
		if ( ! self::may_hold( (int) $user_id ) ) {
			self::revoke_user( (int) $user_id );
		}
	}

	/**
	 * Whether keys may be created/used here: HTTPS, or a `local` development environment (same rule as Application Passwords).
	 */
	public static function transport_ok(): bool {
		return is_ssl() || ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() );
	}

	/**
	 * Creates a key for this administrator and AddSynapse account. The plain secret is returned once and never stored.
	 *
	 * @param int    $user_id Approving administrator.
	 * @param string $account The AddSynapse account the administrator confirmed (masked label, for display).
	 * @return array{id:string,secret:string,ref:string}
	 */
	public static function mint( int $user_id, string $account = '' ): array {
		$id          = bin2hex( random_bytes( 8 ) );
		$secret      = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- random bytes as text.
		$keys        = self::all();
		$keys[ $id ] = array(
			'user'      => $user_id,
			'hash'      => self::hash( $id, $secret ),
			'created'   => time(),
			'last_used' => 0,
			'account'   => mb_substr( sanitize_text_field( $account ), 0, 120 ),
		);
		self::save( self::prune( $keys ) );
		return array(
			'id'     => $id,
			'secret' => $secret,
			'ref'    => self::REF_PREFIX . $id,
		);
	}

	/**
	 * The request's key, checked. Null = the request carries no key (another way of signing in applies); WP_Error = a key that
	 * does not work; otherwise {id, user}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{id:string,user:int}|\WP_Error|null
	 */
	public static function authenticate( $request ) {
		$raw = trim( (string) $request->get_header( 'x_addsynapse_auth' ) );
		if ( 0 !== stripos( $raw, self::SCHEME . ' ' ) ) {
			return null;
		}
		if ( ! self::transport_ok() ) {
			return new \WP_Error( 'addsynapse_assistant_key_https', 'The AddSynapse connection key only works over HTTPS.', array( 'status' => 403 ) );
		}
		$refused = new \WP_Error( 'addsynapse_assistant_key_invalid', 'The AddSynapse connection key is not valid on this site.', array( 'status' => 401 ) );
		$limited = new \WP_Error( 'addsynapse_assistant_key_rate', 'Too many failed attempts. Try again later.', array( 'status' => 429 ) );
		if ( ! preg_match( '/^[a-f0-9]{16}\.[A-Za-z0-9_-]{43}$/D', substr( $raw, strlen( self::SCHEME ) + 1 ), $m ) ) {
			return self::fail( $refused, $limited );
		}
		list( $id, $secret ) = explode( '.', $m[0], 2 );
		$keys                = self::all();
		$key                 = $keys[ $id ] ?? null;
		// Same work whether the id exists or not (no «which ids exist» timing); no key on the site → no hash secret is created.
		$expected = is_array( $key ) ? (string) ( $key['hash'] ?? '' ) : str_repeat( '0', 64 );
		if ( ! hash_equals( $expected, self::hash( $id, $secret, ! empty( $keys ) ) ) || ! is_array( $key ) ) {
			return self::fail( $refused, $limited );
		}
		// A valid key (256 random bits: guessing is not the risk) always works and clears this address' failures.
		if ( self::failures() > 0 ) {
			delete_transient( self::fail_key() );
		}
		$user = get_userdata( (int) ( $key['user'] ?? 0 ) );
		if ( ! $user instanceof \WP_User || ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) || ! self::may_hold( (int) $user->ID ) ) {
			self::revoke( $id );
			return $refused;
		}
		$used = self::used();
		if ( time() - (int) ( $used[ $id ] ?? 0 ) > 60 ) {
			$used[ $id ] = time();
			update_option( self::USED_OPTION, array_intersect_key( $used, self::all() ), false );
		}
		return array(
			'id'   => $id,
			'user' => (int) $user->ID,
		);
	}

	/**
	 * Last use of each key, by id.
	 *
	 * @return array<string,int>
	 */
	private static function used(): array {
		$used = get_option( self::USED_OPTION, array() );
		return is_array( $used ) ? $used : array();
	}

	/**
	 * One key (no hash).
	 *
	 * @param string $id Key id.
	 * @return array{id:string,user:int,created:int,last_used:int,account:string}|null
	 */
	public static function get( string $id ): ?array {
		$key = self::all()[ $id ] ?? null;
		if ( ! is_array( $key ) ) {
			return null;
		}
		return array(
			'id'        => $id,
			'user'      => (int) ( $key['user'] ?? 0 ),
			'created'   => (int) ( $key['created'] ?? 0 ),
			'last_used' => max( (int) ( $key['last_used'] ?? 0 ), (int) ( self::used()[ $id ] ?? 0 ) ),
			'account'   => (string) ( $key['account'] ?? '' ),
		);
	}

	/**
	 * Every key (no hashes), newest first.
	 *
	 * @return array<int,array{id:string,user:int,created:int,last_used:int,account:string}>
	 */
	public static function listing(): array {
		$out = array();
		foreach ( array_keys( self::all() ) as $id ) {
			$key = self::get( (string) $id );
			if ( null !== $key ) {
				$out[] = $key;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);
		return $out;
	}

	/**
	 * Deletes one key.
	 *
	 * @param string $id Key id.
	 * @return bool Whether it existed.
	 */
	public static function revoke( string $id ): bool {
		$keys = self::all();
		if ( ! isset( $keys[ $id ] ) ) {
			return false;
		}
		unset( $keys[ $id ] );
		self::save( $keys );
		return true;
	}

	/**
	 * Deletes every key of a user (a deleted user, or one removed from this site), except one.
	 *
	 * @param int    $user_id User.
	 * @param string $except  Key id to keep.
	 * @return int Keys deleted.
	 */
	public static function revoke_user( $user_id, string $except = '' ): int {
		$keys = self::all();
		$n    = 0;
		foreach ( $keys as $id => $key ) {
			if ( (int) ( $key['user'] ?? 0 ) === (int) $user_id && (string) $id !== $except ) {
				unset( $keys[ $id ] );
				++$n;
			}
		}
		if ( $n > 0 ) {
			self::save( $keys );
		}
		return $n;
	}

	/**
	 * Deletes every key (Disconnect from wp-admin, deactivation). The per-site hash secret goes too.
	 *
	 * @return int Keys deleted.
	 */
	public static function revoke_all(): int {
		$n = count( self::all() );
		delete_option( self::OPTION );
		delete_option( self::PEPPER_OPTION );
		delete_option( self::USED_OPTION );
		return $n;
	}

	/**
	 * A credential reference (RelayClaim) → key id, or '' when it is not a key.
	 *
	 * @param string $ref «key:<id>» or an application password uuid.
	 */
	public static function id_of( string $ref ): string {
		return 0 === strpos( $ref, self::REF_PREFIX ) ? substr( $ref, strlen( self::REF_PREFIX ) ) : '';
	}

	/**
	 * The stored keys.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function all(): array {
		$keys = get_option( self::OPTION, array() );
		return is_array( $keys ) ? array_filter( $keys, 'is_array' ) : array();
	}

	/**
	 * Saves (never autoloaded).
	 *
	 * @param array<string,array<string,mixed>> $keys Keys.
	 */
	private static function save( array $keys ): void {
		if ( empty( $keys ) ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $keys, false );
	}

	/**
	 * At most MAX_KEYS: the oldest ones that AddSynapse never confirmed go first, then the oldest.
	 *
	 * @param array<string,array<string,mixed>> $keys Keys.
	 * @return array<string,array<string,mixed>>
	 */
	private static function prune( array $keys ): array {
		if ( count( $keys ) <= self::MAX_KEYS ) {
			return $keys;
		}
		$claim   = get_option( RelayClaim::CLAIMED_OPTION, array() );
		$claimed = is_array( $claim ) ? self::id_of( (string) ( $claim['uuid'] ?? '' ) ) : '';
		uasort(
			$keys,
			static function ( $a, $b ) {
				return (int) ( $a['created'] ?? 0 ) <=> (int) ( $b['created'] ?? 0 );
			}
		);
		foreach ( array_keys( $keys ) as $id ) {
			if ( count( $keys ) <= self::MAX_KEYS ) {
				break;
			}
			if ( (string) $id !== $claimed ) {
				unset( $keys[ $id ] );
			}
		}
		return $keys;
	}

	/**
	 * Keyed hash of a key.
	 *
	 * @param string $id     Key id.
	 * @param string $secret Secret.
	 * @param bool   $create Create the per-site secret when missing.
	 */
	private static function hash( string $id, string $secret, bool $create = true ): string {
		return hash_hmac( 'sha256', $id . '.' . $secret, $create ? self::pepper() : 'none' );
	}

	/**
	 * The per-site hash secret (created once).
	 */
	private static function pepper(): string {
		$pepper = get_option( self::PEPPER_OPTION, '' );
		if ( ! is_string( $pepper ) || strlen( $pepper ) < 32 ) {
			$pepper = bin2hex( random_bytes( 32 ) );
			update_option( self::PEPPER_OPTION, $pepper, false );
		}
		return $pepper;
	}

	/**
	 * Failed attempts of this IP address in the current window.
	 */
	private static function failures(): int {
		return (int) get_transient( self::fail_key() );
	}

	/**
	 * Counts one failed attempt; over the limit the answer is «too many attempts» (only failures are ever limited).
	 *
	 * @param \WP_Error $refused The normal refusal.
	 * @param \WP_Error $limited The rate-limit refusal.
	 */
	private static function fail( \WP_Error $refused, \WP_Error $limited ): \WP_Error {
		$n = self::failures();
		if ( $n >= self::FAIL_LIMIT ) {
			return $limited;
		}
		set_transient( self::fail_key(), $n + 1, self::FAIL_WINDOW );
		return $refused;
	}

	/**
	 * Transient name of this IP address.
	 */
	private static function fail_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return self::FAIL_PREFIX . md5( $ip );
	}

	/**
	 * Whether this user may hold a key (an administrator who can manage the store/site).
	 *
	 * @param int $user_id User.
	 */
	public static function may_hold( int $user_id ): bool {
		return user_can( $user_id, 'manage_options' ) && user_can( $user_id, Config::capability() );
	}
}
