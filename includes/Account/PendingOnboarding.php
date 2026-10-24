<?php
/**
 * «Did this manager just come from Claude?» — one question to AddSynapse on the first wizard screen (1.3.5).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Someone who started in Claude (AddSynapse's «connect your site» page, then installed this plugin) should land on the
 * Claude path, not on a choice between two ways. AddSynapse knows whether a connection for this site address was started
 * there; the plugin asks once:
 *
 *   GET {api}/api/onboarding/pending?site=<home URL>  →  {"from":"claude"} | {"from":null}
 *
 * Only the site's address is sent (no user, email, key, version or store data); no cookie, no tracking id. The call is
 * made only for an administrator, while nothing is set up yet: right after they activate the plugin (the next admin page)
 * or when they open the plugin's first setup screen, with a 3-second
 * timeout and no redirects; the answer is kept for 10 minutes in a transient. Any failure, an unexpected answer or a
 * development API address that is not allowed means «no» — the wizard then shows its usual first screen.
 */
final class PendingOnboarding {

	/** API path. */
	public const PATH = '/api/onboarding/pending';

	/** Transient with the last answer: 'claude' | 'none'. Removed by uninstall.php (addsynapse_assistant_ prefix). */
	public const TRANSIENT = 'addsynapse_assistant_onboarding_from';

	/** How long an answer is kept. */
	public const TTL = 600;

	/** Seconds to wait for AddSynapse. */
	public const TIMEOUT = 3;

	/**
	 * Answer of this request (memo: the screen asks more than once while it renders).
	 *
	 * @var string|null
	 */
	private static $memo = null;

	/**
	 * Whether AddSynapse says this site's connection was started from Claude.
	 */
	public static function from_claude(): bool {
		return 'claude' === self::answer();
	}

	/**
	 * The last answer without asking again (the confirm screen and «Done — go back to Claude» after the connection).
	 */
	public static function cached_from_claude(): bool {
		return 'claude' === ( null !== self::$memo ? self::$memo : get_transient( self::TRANSIENT ) );
	}

	/**
	 * 'claude' | 'none' (cached; asks AddSynapse at most once per 10 minutes).
	 */
	private static function answer(): string {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$cached = get_transient( self::TRANSIENT );
		if ( in_array( $cached, array( 'claude', 'none' ), true ) ) {
			self::$memo = (string) $cached;
			return self::$memo;
		}
		self::$memo = self::ask();
		set_transient( self::TRANSIENT, self::$memo, self::TTL );
		return self::$memo;
	}

	/**
	 * The one GET request. Never throws; anything but a clean {"from":"claude"} is 'none'.
	 */
	private static function ask(): string {
		$base = Api::base_url();
		if ( '' === $base ) {
			return 'none';
		}
		$url      = add_query_arg( 'site', rawurlencode( untrailingslashit( home_url() ) ), $base . self::PATH );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'none';
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && isset( $body['from'] ) && 'claude' === $body['from'] ? 'claude' : 'none';
	}

	/**
	 * Keeps a «claude» answer another 10 minutes (the confirm screen is open; the «Done» screen comes after it).
	 */
	public static function keep(): void {
		if ( self::cached_from_claude() ) {
			set_transient( self::TRANSIENT, 'claude', self::TTL );
		}
	}

	/**
	 * Forgets the cached answer (tests; and after the site is connected nothing asks again anyway).
	 */
	public static function reset(): void {
		self::$memo = null;
		delete_transient( self::TRANSIENT );
		delete_transient( \Addsynapse\StoreAssistant\Admin\HomePage::AUTO_STARTED );
	}
}
