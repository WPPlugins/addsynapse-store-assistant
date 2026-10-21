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
 *  - first asks the administrator «Connect this site to the AddSynapse account …?» (security review 22/10/2026, P1-1: a
 *    connect link made by SOMEONE ELSE's AddSynapse account must not attach this site to that account by one click);
 *  - sends the browser on to AddSynapse WITHOUT it (303, no-store, no-referrer).
 * Between the approval and the confirmation the password waits split in two: encrypted in a 10-minute transient, its key
 * only in a short-lived HttpOnly cookie of this administrator's browser — neither alone reveals it. It is never logged or
 * printed. Until AddSynapse really uses the new password (RemoteAccess::gate → confirm_pending) it is PENDING: older
 * AddSynapse passwords stay working, and an unconfirmed one is revoked after PENDING_MAX_AGE.
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

	/** The hub endpoint that names the AddSynapse account of a pending connect (masked). */
	public const PREVIEW_PATH = '/api/v1/assistant/relay-claim/preview';

	/** Claimed, not yet used by AddSynapse: {uuid, user, time}. */
	public const PENDING_OPTION = 'addsynapse_assistant_relay_pending';

	/** A pending password AddSynapse never used is revoked after this (the hub forgets it after 10 minutes). */
	public const PENDING_MAX_AGE = 900;

	/** Cron hook of that clean-up. */
	public const PENDING_CRON = 'addsynapse_assistant_relay_pending_check';

	/** Transient prefix (+ sha256 of the state) of an approval waiting for the administrator's confirmation. */
	public const STASH_PREFIX = 'addsynapse_assistant_relay_stash_';

	/** Cookie that holds the key of that stash (HttpOnly, wp-admin path, 10 minutes). */
	public const STASH_COOKIE = 'addsynapse_assistant_relay_k';

	/** The claim protocol this plugin speaks: 2 = the hub keeps the claim pending until /relay/done. */
	public const PROTOCOL = 2;

	/**
	 * Hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'maybe_handle' ), 1 );
		add_action( 'admin_init', array( self::class, 'expire_pending' ), 20 );
		add_action( self::PENDING_CRON, array( self::class, 'expire_pending' ) );
	}

	/**
	 * Admin page with `as_relay=callback`: hand over and leave (before any output).
	 */
	public static function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WordPress core's Authorize Application screen redirects here (it cannot carry our nonce); the request is bound by the one-time state AddSynapse issued, the logged-in administrator and WordPress' own hash of the password. The confirmation step below checks its own nonce.
		if ( ! isset( $_GET['page'], $_GET['as_relay'] ) || self::PAGE !== $_GET['page'] || ! in_array( $_GET['as_relay'], array( 'callback', 'confirm' ), true ) ) {
			return;
		}
		self::no_cache_headers();
		if ( 'confirm' === $_GET['as_relay'] ) {
			// phpcs:enable
			self::confirm_step();
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- see above.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$login = isset( $_GET['user_login'] ) ? sanitize_user( wp_unslash( $_GET['user_login'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an application password (letters and digits); only compared with WordPress' hash and sent once to AddSynapse, never stored or printed.
		$password = isset( $_GET['password'] ) ? (string) preg_replace( '/[^a-z\d]/i', '', (string) wp_unslash( $_GET['password'] ) ) : '';
		$site_url = isset( $_GET['site_url'] ) ? esc_url_raw( wp_unslash( $_GET['site_url'] ) ) : '';
		// phpcs:enable

		$result = self::stash( $state, $login, $password, $site_url );
		unset( $password );
		self::leave( $result );
	}

	/**
	 * Redirect (an AddSynapse page or this site's Home) or an error page.
	 *
	 * @param string|\WP_Error $result Where to go, or why not.
	 */
	private static function leave( $result ): void {
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
	 * The approval came back: checks it, asks AddSynapse which account started this connect, keeps the password split
	 * (transient + cookie) and sends the browser to the confirmation screen. Nothing is sent to AddSynapse yet.
	 *
	 * @param string $state    One-time state from AddSynapse.
	 * @param string $login    User login WordPress returned.
	 * @param string $password The application password WordPress returned.
	 * @param string $site_url Site URL WordPress returned.
	 * @return string|\WP_Error
	 */
	public static function stash( string $state, string $login, string $password, string $site_url ) {
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
		$site_url = '' !== $site_url ? $site_url : site_url();
		$preview  = Api::post(
			self::PREVIEW_PATH,
			array(
				'state'    => $state,
				'site_url' => $site_url,
			),
			10
		);
		if ( ! is_wp_error( $preview ) && 410 === $preview['status'] ) {
			// AddSynapse no longer knows this connect (expired or already used): the new password is useless.
			self::drop_unconfirmed( (int) $user->ID, (string) $item['uuid'] );
			return self::already_connected( (int) $user->ID ) ? HomePage::url(
				array(
					'view'    => 'home',
					'welcome' => 'connected',
				)
			) : $failed;
		}
		$account = '';
		$email   = '';
		$from    = '';
		if ( ! is_wp_error( $preview ) && 200 === $preview['status'] && ! empty( $preview['body']['ok'] ) ) {
			$account = sanitize_text_field( (string) ( $preview['body']['account'] ?? '' ) );
			$email   = sanitize_text_field( (string) ( $preview['body']['email'] ?? '' ) );
			$moving  = $preview['body']['moving_from'] ?? null;
			if ( is_array( $moving ) ) {
				$from = trim( sanitize_text_field( (string) ( $moving['account'] ?? '' ) ) . ' (' . sanitize_text_field( (string) ( $moving['email'] ?? '' ) ) . ')' );
			}
		}
		$key   = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		set_transient(
			self::stash_key( $state ),
			array(
				'user'     => (int) $user->ID,
				'uuid'     => (string) $item['uuid'],
				'login'    => $login,
				'site_url' => $site_url,
				'account'  => mb_substr( $account, 0, 60 ),
				'email'    => mb_substr( $email, 0, 120 ),
				'from'     => mb_substr( $from, 0, 200 ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
				'ct'       => base64_encode( $nonce . sodium_crypto_secretbox( $password, $nonce, $key ) ),
			),
			self::MAX_AGE
		);
		unset( $password );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a random key in a cookie.
		self::set_key_cookie( rtrim( strtr( base64_encode( $key ), '+/', '-_' ), '=' ), time() + self::MAX_AGE );
		return add_query_arg(
			array(
				'page'     => self::PAGE,
				'as_relay' => 'confirm',
				'state'    => $state,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * `as_relay=confirm`: GET shows «Connect this site to the AddSynapse account …?», POST (nonce) connects or cancels.
	 */
	private static function confirm_step(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			self::leave( new \WP_Error( 'addsynapse_assistant_claim_forbidden', __( 'Only an administrator of this site can connect it to AddSynapse.', 'addsynapse-store-assistant' ) ) );
			return;
		}
		$state = isset( $_REQUEST['state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the POST below checks its nonce; the GET only shows a page.
		$stash = preg_match( '/^[A-Za-z0-9]{40}$/D', $state ) ? get_transient( self::stash_key( $state ) ) : false;
		$user  = get_current_user_id();
		if ( ! is_array( $stash ) || (int) ( $stash['user'] ?? 0 ) !== $user ) {
			self::leave( new \WP_Error( 'addsynapse_assistant_claim', __( 'The connection to AddSynapse was not completed. Nothing was sent. Start again from your AI chat.', 'addsynapse-store-assistant' ) ) );
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			self::render_confirm( $state, $stash );
			return;
		}
		check_admin_referer( 'addsynapse_assistant_relay_confirm_' . $state );
		$cancel = isset( $_POST['as_cancel'] );
		self::leave( self::confirm( $state, $cancel ) );
	}

	/**
	 * The administrator's answer: connect (hand over to AddSynapse) or cancel (the new password is revoked).
	 *
	 * @param string $state  One-time state.
	 * @param bool   $cancel «No, cancel».
	 * @return string|\WP_Error
	 */
	public static function confirm( string $state, bool $cancel ) {
		$failed = new \WP_Error( 'addsynapse_assistant_claim', __( 'The connection to AddSynapse was not completed. Nothing was sent. Start again from your AI chat.', 'addsynapse-store-assistant' ) );
		$stash  = get_transient( self::stash_key( $state ) );
		delete_transient( self::stash_key( $state ) );
		$cookie = isset( $_COOKIE[ self::STASH_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::STASH_COOKIE ] ) ) : '';
		self::set_key_cookie( '', time() - 3600 );
		$user = get_current_user_id();
		if ( ! is_array( $stash ) || (int) ( $stash['user'] ?? 0 ) !== $user ) {
			return $failed;
		}
		if ( $cancel ) {
			self::drop_unconfirmed( $user, (string) $stash['uuid'] );
			return HomePage::url( array( 'view' => 'home' ) );
		}
		$key = base64_decode( strtr( $cookie, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the random key set above.
		$raw = base64_decode( (string) ( $stash['ct'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the ciphertext stored above.
		if ( ! is_string( $key ) || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) || ! is_string( $raw ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			self::drop_unconfirmed( $user, (string) $stash['uuid'] );
			return $failed;
		}
		$password = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		if ( ! is_string( $password ) ) {
			self::drop_unconfirmed( $user, (string) $stash['uuid'] );
			return $failed;
		}
		$result = self::handle( $state, (string) $stash['login'], $password, (string) $stash['site_url'] );
		unset( $password );
		return $result;
	}

	/**
	 * «Connect this site to the AddSynapse account …?» — Connect / Cancel (POST, nonce).
	 *
	 * @param string              $state One-time state.
	 * @param array<string,mixed> $stash What the approval left.
	 */
	private static function render_confirm( string $state, array $stash ): void {
		$who  = trim( (string) ( $stash['account'] ?? '' ) . ( '' !== (string) ( $stash['email'] ?? '' ) ? ' (' . (string) $stash['email'] . ')' : '' ) );
		$out  = '<h1>' . esc_html__( 'Connect this site to AddSynapse?', 'addsynapse-store-assistant' ) . '</h1>';
		$out .= '<p data-adsa-relay-confirm>' . ( '' !== $who
			/* translators: %s: the AddSynapse account name and masked email, e.g. «Maria's shop (ma***@example.com)». */
			? sprintf( esc_html__( 'Connect this site to the AddSynapse account %s?', 'addsynapse-store-assistant' ), '<strong>' . esc_html( $who ) . '</strong>' )
			: esc_html__( 'Connect this site to the AddSynapse account that started this connection?', 'addsynapse-store-assistant' ) ) . '</p>';
		if ( '' !== (string) ( $stash['from'] ?? '' ) ) {
			$out .= '<p data-adsa-relay-move>' . sprintf(
				/* translators: 1: the AddSynapse account the site is connected to now (masked), 2: the new one. */
				esc_html__( 'This site is connected to another AddSynapse account: %1$s. Move it to %2$s? The other account loses access to this site.', 'addsynapse-store-assistant' ),
				'<strong>' . esc_html( (string) $stash['from'] ) . '</strong>',
				'<strong>' . esc_html( '' !== $who ? $who : __( 'the new account', 'addsynapse-store-assistant' ) ) . '</strong>'
			) . '</p>';
		}
		$out .= '<p>' . esc_html__( 'Only continue if it is your own account and you started this from your AI chat a moment ago. If someone sent you a link to approve, cancel.', 'addsynapse-store-assistant' ) . '</p>';
		$out .= '<form method="post" action="' . esc_url(
			add_query_arg(
				array(
					'page'     => self::PAGE,
					'as_relay' => 'confirm',
					'state'    => $state,
				),
				admin_url( 'admin.php' )
			)
		) . '">' . wp_nonce_field( 'addsynapse_assistant_relay_confirm_' . $state, '_wpnonce', true, false );
		$out .= '<p><button type="submit" name="as_connect" value="1" class="button button-primary">' . esc_html__( 'Connect', 'addsynapse-store-assistant' ) . '</button> ';
		$out .= '<button type="submit" name="as_cancel" value="1" class="button">' . esc_html__( 'Cancel', 'addsynapse-store-assistant' ) . '</button></p></form>';
		wp_die( $out, esc_html__( 'AddSynapse connection', 'addsynapse-store-assistant' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped above.
	}

	/**
	 * The stash transient of a state.
	 *
	 * @param string $state One-time state.
	 */
	private static function stash_key( string $state ): string {
		return self::STASH_PREFIX . substr( hash( 'sha256', $state ), 0, 32 );
	}

	/**
	 * The stash key cookie (HttpOnly, secure when the admin is, wp-admin only).
	 *
	 * @param string $value   Cookie value ('' to clear).
	 * @param int    $expires Expiry.
	 */
	private static function set_key_cookie( string $value, int $expires ): void {
		if ( ! headers_sent() ) {
			self::send_cookie( $value, $expires );
		}
		if ( '' === $value ) {
			unset( $_COOKIE[ self::STASH_COOKIE ] );
		} else {
			$_COOKIE[ self::STASH_COOKIE ] = $value;
		}
	}

	/**
	 * Sends the stash key cookie.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry.
	 */
	private static function send_cookie( string $value, int $expires ): void {
		setcookie(
			self::STASH_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'ADMIN_COOKIE_PATH' ) ? ADMIN_COOKIE_PATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Revokes a new AddSynapse password that was never confirmed (never the confirmed, working one).
	 *
	 * @param int    $user_id User.
	 * @param string $uuid    Password uuid.
	 */
	private static function drop_unconfirmed( int $user_id, string $uuid ): void {
		if ( '' !== $uuid && ! self::is_confirmed( $user_id, $uuid ) && class_exists( '\WP_Application_Passwords' ) ) {
			\WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		}
	}

	/**
	 * AddSynapse used the pending password for real (not the claim's check): it becomes THE connection now.
	 *
	 * @param int    $user_id User the request authenticated as.
	 * @param string $uuid    Password uuid of the request.
	 */
	public static function confirm_pending( int $user_id, string $uuid ): void {
		$pending = self::pending_list();
		if ( '' === $uuid || ! isset( $pending[ $uuid ] ) || (int) ( $pending[ $uuid ]['user'] ?? 0 ) !== $user_id ) {
			return;
		}
		// The one AddSynapse used wins; other pending ones of a parallel connect are retired with the older passwords.
		delete_option( self::PENDING_OPTION );
		wp_clear_scheduled_hook( self::PENDING_CRON );
		self::claimed( $user_id, $uuid );
	}

	/**
	 * Claimed passwords AddSynapse has not used yet, by uuid (two connect links at once are allowed).
	 *
	 * @return array<string,array{uuid:string,user:int,time:int}>
	 */
	private static function pending_list(): array {
		$raw = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		if ( isset( $raw['uuid'] ) ) {
			$raw = array( (string) $raw['uuid'] => $raw );
		}
		return array_filter(
			$raw,
			static function ( $p, $k ) {
				return is_array( $p ) && is_string( $k ) && '' !== $k;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Whether this password is still waiting for AddSynapse's first real use.
	 *
	 * @param string $uuid Password uuid.
	 */
	public static function is_pending( string $uuid ): bool {
		return '' !== $uuid && isset( self::pending_list()[ $uuid ] );
	}

	/**
	 * A pending password AddSynapse never used within PENDING_MAX_AGE (nobody completed the connection on AddSynapse) → revoked.
	 */
	public static function expire_pending(): void {
		$pending = self::pending_list();
		$keep    = array();
		foreach ( $pending as $uuid => $p ) {
			if ( time() - (int) ( $p['time'] ?? 0 ) <= self::PENDING_MAX_AGE ) {
				$keep[ $uuid ] = $p;
				continue;
			}
			self::drop_unconfirmed( (int) ( $p['user'] ?? 0 ), (string) $uuid );
		}
		if ( count( $keep ) !== count( $pending ) ) {
			$keep ? update_option( self::PENDING_OPTION, $keep, false ) : delete_option( self::PENDING_OPTION );
		}
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
				'protocol'   => self::PROTOCOL,
			),
			15
		);
		unset( $password );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$next = isset( $response['body']['next'] ) ? (string) $response['body']['next'] : '';
		if ( 410 === $response['status'] || 422 === $response['status'] ) {
			// AddSynapse refused it (expired or already used). The working, confirmed password is NEVER removed here: the
			// browser may simply have opened this page twice (owner 21/10: refresh 17 s later → 410 for the same password).
			if ( ! self::is_confirmed( (int) $user->ID, (string) $item['uuid'] ) ) {
				\WP_Application_Passwords::delete_application_password( (int) $user->ID, (string) $item['uuid'] );
			}
			if ( self::already_connected( (int) $user->ID ) ) {
				return HomePage::url(
					array(
						'view'    => 'home',
						'welcome' => 'connected',
					)
				);
			}
		}
		if ( 200 === $response['status'] && ! empty( $response['body']['ok'] ) ) {
			if ( ! empty( $response['body']['pending'] ) ) {
				// AddSynapse keeps it pending until the member who started confirms on AddSynapse: the working password stays.
				$pending                           = self::pending_list();
				$pending[ (string) $item['uuid'] ] = array(
					'uuid' => (string) $item['uuid'],
					'user' => (int) $user->ID,
					'time' => time(),
				);
				update_option( self::PENDING_OPTION, array_slice( $pending, -5, null, true ), false );
				if ( ! wp_next_scheduled( self::PENDING_CRON ) ) {
					wp_schedule_single_event( time() + self::PENDING_MAX_AGE + 60, self::PENDING_CRON );
				}
			} else {
				// An AddSynapse that activates at once (before 22/10/2026).
				self::claimed( (int) $user->ID, (string) $item['uuid'] );
			}
		}
		if ( '' !== $next && Api::link_allowed( $next ) ) {
			return $next;
		}
		return $failed;
	}

	/**
	 * Whether this password is the one AddSynapse already confirmed for this user.
	 *
	 * @param int    $user_id User.
	 * @param string $uuid    Password uuid.
	 */
	private static function is_confirmed( int $user_id, string $uuid ): bool {
		$claim = get_option( self::CLAIMED_OPTION, array() );
		return is_array( $claim ) && (int) ( $claim['user'] ?? 0 ) === $user_id && '' !== $uuid
			&& hash_equals( (string) ( $claim['uuid'] ?? '' ), $uuid );
	}

	/**
	 * Whether this user already has a working connection: AddSynapse confirmed one of their AddSynapse passwords and it
	 * still exists on this site (not revoked).
	 *
	 * @param int $user_id User.
	 */
	public static function already_connected( int $user_id ): bool {
		$claim = get_option( self::CLAIMED_OPTION, array() );
		if ( ! is_array( $claim ) || (int) ( $claim['user'] ?? 0 ) !== $user_id || empty( $claim['uuid'] ) || ! class_exists( '\WP_Application_Passwords' ) ) {
			return false;
		}
		$item = \WP_Application_Passwords::get_user_application_password( $user_id, (string) $claim['uuid'] );
		return is_array( $item ) && ( $item['app_id'] ?? '' ) === RemoteAccess::APP_ID;
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
