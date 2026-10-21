<?php
/**
 * Hand-over of the AddSynapse application password, right after the owner approved it on WordPress' own
 * «Authorize Application» screen.
 *
 * WordPress core returns a new application password in the QUERY STRING of the success URL (authorize-application.php
 * has no other way). So the success URL AddSynapse asks for is THIS site's own admin page
 * (wp-admin/admin.php?page=addsynapse&as_relay=callback&state=…): the password only travels back to the server that
 * created it. This page then:
 *  - accepts it only from a logged-in administrator (manage_options), for that same user, and only when it really is a
 *    current AddSynapse application password of that user (fixed app_id, checked against WordPress' own hash);
 *  - sends it ONCE to AddSynapse, server to server, in a JSON body over HTTPS (`POST /api/v1/assistant/relay-claim`,
 *    with the one-time state AddSynapse created for this connection);
 *  - sends the browser on to AddSynapse WITHOUT it (303, no-store, no-referrer).
 * It never stores, logs or prints the password (WordPress keeps only its hash, as for every application password).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Account\Api;
use Addsynapse\StoreAssistant\Admin\HomePage;

defined( 'ABSPATH' ) || exit;

/**
 * The success URL of AddSynapse's application password request.
 */
final class RelayClaim {

	/** The admin page (the plugin's Home). */
	public const PAGE = 'addsynapse';

	/** The hub endpoint. */
	public const PATH = '/api/v1/assistant/relay-claim';

	/** Set once AddSynapse confirmed a claim (uuid + user + time): the setup wizard shows «Connected» and is finished. */
	public const CLAIMED_OPTION = 'addsynapse_assistant_relay_claimed';

	/** A just-created password only (WordPress shows the approval screen, the owner clicks, back here at once). */
	public const MAX_AGE = 600;

	/**
	 * Hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'maybe_handle' ), 1 );
	}

	/**
	 * Admin page with `as_relay=callback`: hand over and leave (before any output).
	 */
	public static function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WordPress core's Authorize Application screen redirects here (it cannot carry our nonce); the request is bound by the one-time state AddSynapse issued, the logged-in administrator and WordPress' own hash of the password.
		if ( ! isset( $_GET['page'], $_GET['as_relay'] ) || self::PAGE !== $_GET['page'] || 'callback' !== $_GET['as_relay'] ) {
			return;
		}
		self::no_cache_headers();
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$login = isset( $_GET['user_login'] ) ? sanitize_user( wp_unslash( $_GET['user_login'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an application password (letters and digits); only compared with WordPress' hash and sent once to AddSynapse, never stored or printed.
		$password = isset( $_GET['password'] ) ? (string) preg_replace( '/[^a-z\d]/i', '', (string) wp_unslash( $_GET['password'] ) ) : '';
		$site_url = isset( $_GET['site_url'] ) ? esc_url_raw( wp_unslash( $_GET['site_url'] ) ) : '';
		// phpcs:enable

		$result = self::handle( $state, $login, $password, $site_url );
		unset( $password );
		if ( is_string( $result ) ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- AddSynapse's own page (Api::link_allowed: https on an AddSynapse host only).
			wp_redirect( $result, 303 );
			exit;
		}
		wp_die(
			esc_html( $result->get_error_message() ),
			esc_html__( 'AddSynapse connection', 'addsynapse-store-assistant' ),
			array(
				'response'  => 400,
				'link_url'  => esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
				'link_text' => esc_html__( 'Back to AddSynapse', 'addsynapse-store-assistant' ),
			)
		);
	}

	/**
	 * Checks and hands over. Returns the AddSynapse page to send the browser to, or an error to show.
	 *
	 * @param string $state    One-time state from AddSynapse.
	 * @param string $login    User login WordPress returned.
	 * @param string $password The application password WordPress returned.
	 * @param string $site_url Site URL WordPress returned.
	 * @return string|\WP_Error
	 */
	public static function handle( string $state, string $login, string $password, string $site_url ) {
		$failed = new \WP_Error( 'addsynapse_assistant_claim', __( 'The connection to AddSynapse was not completed. Nothing was sent. Start again from your AI chat.', 'addsynapse-store-assistant' ) );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'addsynapse_assistant_claim_forbidden', __( 'Only an administrator of this site can connect it to AddSynapse.', 'addsynapse-store-assistant' ) );
		}
		$user = wp_get_current_user();
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/D', $state ) || '' === $password || $login !== $user->user_login ) {
			return $failed;
		}
		$item = self::find_password( (int) $user->ID, $password );
		if ( null === $item ) {
			return $failed;
		}

		$response = Api::post(
			self::PATH,
			array(
				'state'      => $state,
				'site_url'   => '' !== $site_url ? $site_url : site_url(),
				'user_login' => $login,
				'password'   => $password,
			),
			15
		);
		unset( $password );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$next = isset( $response['body']['next'] ) ? (string) $response['body']['next'] : '';
		if ( 410 === $response['status'] || 422 === $response['status'] ) {
			// AddSynapse refused it (expired or already used): the password is useless — remove it at once.
			\WP_Application_Passwords::delete_application_password( (int) $user->ID, (string) $item['uuid'] );
		}
		if ( 200 === $response['status'] && ! empty( $response['body']['ok'] ) ) {
			self::claimed( (int) $user->ID, (string) $item['uuid'] );
		}
		if ( '' !== $next && Api::link_allowed( $next ) ) {
			return $next;
		}
		return $failed;
	}

	/**
	 * AddSynapse stored and verified the new password: it is the only AddSynapse password of this user from now on (older
	 * ones — e.g. a second «Authorize» — are revoked), and the setup wizard is marked finished on the «Claude or ChatGPT» way.
	 *
	 * @param int    $user_id User.
	 * @param string $uuid    The new password's uuid (kept).
	 */
	private static function claimed( int $user_id, string $uuid ): void {
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $other ) {
			if ( ( $other['app_id'] ?? '' ) === RemoteAccess::APP_ID && ! empty( $other['uuid'] ) && $uuid !== $other['uuid'] ) {
				\WP_Application_Passwords::delete_application_password( $user_id, (string) $other['uuid'] );
			}
		}
		update_option(
			self::CLAIMED_OPTION,
			array(
				'uuid' => $uuid,
				'user' => $user_id,
				'time' => time(),
			),
			false
		);
		$wizard = HomePage::state();
		update_option(
			HomePage::OPTION,
			array(
				'done' => true,
				'path' => '' !== $wizard['path'] ? $wizard['path'] : 'mcp',
			),
			false
		);
	}

	/**
	 * The current AddSynapse application password of this user that matches (WordPress' own hash), created just now.
	 *
	 * @param int    $user_id  User.
	 * @param string $password Plain password.
	 * @return array<string,mixed>|null
	 */
	private static function find_password( int $user_id, string $password ): ?array {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return null;
		}
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( ( $item['app_id'] ?? '' ) !== RemoteAccess::APP_ID || empty( $item['uuid'] ) || empty( $item['password'] )
				|| time() - (int) ( $item['created'] ?? 0 ) > self::MAX_AGE ) {
				continue;
			}
			$ok = method_exists( '\WP_Application_Passwords', 'check_password' )
				? \WP_Application_Passwords::check_password( $password, (string) $item['password'] )
				: wp_check_password( $password, (string) $item['password'], $user_id );
			if ( $ok ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Never cached, never passed on as a Referer.
	 */
	private static function no_cache_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
		header( 'Referrer-Policy: no-referrer' );
	}
}
