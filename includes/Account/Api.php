<?php
/**
 * The AddSynapse API (only after the manager created an account from the plugin).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Account;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * These calls exist, and nothing else ever contacts AddSynapse:
 *  - PendingOnboarding::from_claude(): `GET /api/onboarding/pending?site=<home URL>` — only for an administrator while
 *    nothing is set up (after activation / the first setup screen), at most once per 10 minutes, the site address only;
 *  - OptIn::handle_create(): `POST /api/v1/assistant/optin` — only when an administrator clicks «Create account»;
 *  - Announcements::maybe_fetch(): `POST /api/v1/assistant/announcements` — at most once a day, only after that, only while
 *    a user opens one of the plugin's own screens.
 *  - Usage::allow() / Usage::heartbeat(): `POST /api/v1/assistant/usage` — only after an administrator clicked «Allow»
 *    (anonymous usage info, off by default): once then, and at most once a week (WP-Cron).
 *  - Remote\RelayClaim::handle(): `POST /api/v1/assistant/relay-claim` — only right after an administrator approved AddSynapse
 *    on WordPress' own Authorize Application screen: once, the new application password in the JSON body (never a URL).
 * Host allowlist (guide §3.6): https://api.addsynapse.com only. Another host only on a DEVELOPMENT site and only the one a
 * developer put in wp-config.php (ADDSYNAPSE_ASSISTANT_API_URL); plain http only to an internal development host. Never
 * userinfo, never redirects, short timeouts.
 */
final class Api {

	/** The AddSynapse API. */
	public const DEFAULT_URL = 'https://api.addsynapse.com';

	/** Hosts a production site may talk to. */
	public const ALLOWED_HOSTS = array( 'api.addsynapse.com' );

	/** Hosts of the AddSynapse websites a link from the API may open (download, portal). */
	public const LINK_HOSTS = array( 'addsynapse.com', 'www.addsynapse.com', 'app.addsynapse.com', 'api.addsynapse.com' );

	/** «Add to Claude» of the setup wizard: AddSynapse remembers which site to check, then opens Claude's connector page. */
	public const CLAUDE_CONNECT_URL = 'https://app.addsynapse.com/store-assistant/connect';

	/** «Change plan» on Home (1.3.2): the AddSynapse plans page of the account (an ordinary link). */
	public const BILLING_URL = 'https://app.addsynapse.com/billing/claude';

	/**
	 * The «Add to Claude» link with this site's address (an ordinary link the administrator's browser opens; nothing is sent
	 * by the server).
	 *
	 * @param string $site This site's address (home URL).
	 */
	public static function claude_connect_url( string $site ): string {
		return add_query_arg( 'site', rawurlencode( untrailingslashit( $site ) ), self::CLAUDE_CONNECT_URL );
	}

	/**
	 * Base URL ('' = the configured development URL is not allowed here).
	 */
	public static function base_url(): string {
		if ( defined( 'ADDSYNAPSE_ASSISTANT_API_URL' ) && self::development_site() ) {
			$url = untrailingslashit( trim( (string) ADDSYNAPSE_ASSISTANT_API_URL ) );
			return self::url_allowed( $url ) ? $url : '';
		}
		return self::DEFAULT_URL;
	}

	/**
	 * Whether this is a development site.
	 */
	public static function development_site(): bool {
		return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Internal development host (plain http allowed on a development site): localhost, 127.0.0.1, *.ddev.site, ddev-*,
	 * *.test, or a one-label container name.
	 *
	 * @param string $host Host.
	 */
	public static function internal_host( string $host ): bool {
		$host = strtolower( $host );
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true )
			|| (bool) preg_match( '/^ddev-[a-z0-9-]+$/D', $host )
			|| (bool) preg_match( '/^([a-z0-9-]+\.)+(ddev\.site|test)$/D', $host )
			|| (bool) preg_match( '/^[a-z][a-z0-9-]{0,62}$/D', $host );
	}

	/**
	 * Host allowlist of the API base.
	 *
	 * @param string $url URL.
	 */
	public static function url_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) $parts['host'] );
		if ( 'https' === $scheme && in_array( $host, self::ALLOWED_HOSTS, true ) && ! isset( $parts['port'] ) ) {
			return true;
		}
		if ( ! self::development_site() ) {
			return false;
		}
		return 'https' === $scheme || ( 'http' === $scheme && self::internal_host( $host ) );
	}

	/**
	 * Whether a link may be shown (download, portal, announcement): https on an AddSynapse website only (on a development
	 * site also the host of the developer's ADDSYNAPSE_ASSISTANT_API_URL).
	 *
	 * @param string $url URL.
	 */
	public static function link_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) $parts['host'] );
		if ( 'https' === $scheme && in_array( $host, self::LINK_HOSTS, true ) ) {
			return true;
		}
		// Development only: the host of the API a developer configured in wp-config.php (ADDSYNAPSE_ASSISTANT_API_URL).
		if ( ! self::development_site() || ! defined( 'ADDSYNAPSE_ASSISTANT_API_URL' ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$dev = strtolower( (string) wp_parse_url( (string) ADDSYNAPSE_ASSISTANT_API_URL, PHP_URL_HOST ) );
		return '' !== $dev && $dev === $host;
	}

	/**
	 * Whether a failed request surely never reached the server (no name lookup / no connection).
	 *
	 * @param WP_Error $error wp_remote_post() error.
	 */
	private static function not_sent( WP_Error $error ): bool {
		return (bool) preg_match( '/cURL error (6|7):|Could not resolve|Failed to connect|Connection refused/i', $error->get_error_message() );
	}

	/**
	 * POST JSON.
	 *
	 * @param string              $path    API path (`/api/v1/…`).
	 * @param array<string,mixed> $body    Body.
	 * @param int                 $timeout Seconds.
	 * @return array{status:int,body:array<string,mixed>}|WP_Error
	 */
	public static function post( string $path, array $body, int $timeout = 10 ) {
		$base = self::base_url();
		if ( '' === $base ) {
			return new WP_Error( 'addsynapse_assistant_api_url', 'The AddSynapse address is not allowed on this site.' );
		}
		$args     = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => (string) wp_json_encode( $body ),
		);
		$response = wp_remote_post( $base . $path, $args );
		// 1.3.5 (satest 24/10: a connect POST failed on this server and never reached AddSynapse): one retry, only when
		// the request surely never left (name lookup / connect failed) — a request that may have arrived is never sent twice.
		if ( is_wp_error( $response ) && self::not_sent( $response ) ) {
			$response = wp_remote_post( $base . $path, $args );
		}
		if ( is_wp_error( $response ) ) {
			$detail = sanitize_text_field( mb_substr( $response->get_error_message(), 0, 160 ) );
			return new WP_Error(
				'addsynapse_assistant_api_unreachable',
				/* translators: %s: technical detail from WordPress, e.g. «cURL error 28: Operation timed out». */
				sprintf( __( 'This site could not reach AddSynapse (%s). Nothing was connected. Try again in a minute; if it keeps failing, ask your host whether outgoing HTTPS requests to api.addsynapse.com are allowed.', 'addsynapse-store-assistant' ), '' !== $detail ? $detail : 'no detail' )
			);
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $decoded ) ? $decoded : array(),
		);
	}
}
