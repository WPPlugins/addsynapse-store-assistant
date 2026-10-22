<?php
/**
 * Connecting this site to an AddSynapse account.
 *
 * 1.3.4 (protocol 4, the way AddSynapse uses for every site with this version): AddSynapse sends the administrator to this
 * plugin's own page (admin.php?page=addsynapse&as_relay=start&state=…). It names the AddSynapse account that started the
 * connect («Connect this site to the AddSynapse account …?»); only on Connect (POST, nonce, manage_options) a connection key
 * is created (RelayKeys: bound to this administrator and that account, our routes only) and handed to AddSynapse server to
 * server, in the JSON body of `POST /api/v1/assistant/relay-claim`. No WordPress Application Password is involved, so a
 * security plugin that turns those off (Wordfence does by default) does not stop the connection — and nothing here changes
 * that setting. Until AddSynapse really uses the new key it is PENDING, exactly like a password below.
 *
 * Before 1.3.4 (still accepted from an older AddSynapse): hand-over of the AddSynapse application password, right after the
 * owner approved it on WordPress' own «Authorize Application» screen.
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

	/**
	 * Cookie that holds the key of that stash (HttpOnly, wp-admin path, 10 minutes). 1.3.2: one cookie PER STATE
	 * (name + `_` + a short hash of the state), so two connect links approved in two tabs before either Connect both work.
	 */
	public const STASH_COOKIE = 'addsynapse_assistant_relay_k';

	/**
	 * The claim protocol this plugin speaks: 2 = the hub keeps the claim pending until /relay/done; 3 (1.3.3) = after
	 * /relay/done the hub sends the browser back to this wizard (admin.php?page=addsynapse&setup=mcp&as_relay=connected);
	 * 4 (1.3.4) = the connect starts on this plugin's page (as_relay=start) and hands over a connection key (RelayKeys).
	 */
	public const PROTOCOL = 4;

	/**
	 * Hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'maybe_handle' ), 1 );
		add_action( 'admin_init', array( self::class, 'expire_pending' ), 20 );
		add_action( self::PENDING_CRON, array( self::class, 'expire_pending' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'authorize_screen_style' ) );
	}

	/** Style handle of the few lines below (no file: inline only). */
	public const AUTH_STYLE = 'addsynapse-assistant-authorize';

	/**
	 * WordPress' own «Authorize Application» screen, only when the request is AddSynapse's (our fixed app_id): the
	 * «You will be sent to …» URLs are long (core adds site_url, user_login and password to our success URL) and in
	 * <code> they do not wrap, so they ran out of the card. Let them break anywhere inside the card.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public static function authorize_screen_style( $hook_suffix = '' ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only decides whether to add a stylesheet; core's own screen handles the request.
		$app_id = isset( $_REQUEST['app_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['app_id'] ) ) : '';
		if ( 'authorize-application.php' !== $hook_suffix || RemoteAccess::APP_ID !== $app_id ) {
			return;
		}
		wp_register_style( self::AUTH_STYLE, false, array(), ADDSYNAPSE_ASSISTANT_VERSION );
		wp_enqueue_style( self::AUTH_STYLE );
		wp_add_inline_style( self::AUTH_STYLE, '.auth-app-card .description code{white-space:normal;overflow-wrap:anywhere;word-break:break-all}' );
	}

	/**
	 * Admin page with `as_relay=callback`: hand over and leave (before any output).
	 */
	public static function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WordPress core's Authorize Application screen redirects here (it cannot carry our nonce); the request is bound by the one-time state AddSynapse issued, the logged-in administrator and WordPress' own hash of the password. The confirmation step below checks its own nonce.
		if ( ! isset( $_GET['page'], $_GET['as_relay'] ) || self::PAGE !== $_GET['page'] || ! in_array( $_GET['as_relay'], array( 'callback', 'confirm', 'start' ), true ) ) {
			return;
		}
		self::no_cache_headers();
		if ( 'start' === $_GET['as_relay'] ) {
			self::start_step();
			return;
		}
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
			return self::already_connected( (int) $user->ID ) ? self::connected_url() : $failed;
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
			return self::already_connected( (int) $user->ID ) ? self::connected_url() : $failed;
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
		self::set_key_cookie( $state, rtrim( strtr( base64_encode( $key ), '+/', '-_' ), '=' ), time() + self::MAX_AGE );
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
	 * `as_relay=start` (protocol 4): AddSynapse sent the administrator here with its one-time state. Asks AddSynapse which
	 * account started this connect and shows «Connect this site to the AddSynapse account …?». Creates nothing.
	 */
	private static function start_step(): void {
		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AddSynapse's link; it only shows the confirmation, which posts with its own nonce.
		$result = self::start( $state );
		if ( is_wp_error( $result ) ) {
			self::leave( $result );
			return;
		}
		if ( is_string( $result ) ) {
			self::leave( $result );
			return;
		}
		self::render_confirm( $state, $result );
	}

	/**
	 * Checks the start of a protocol-4 connect and keeps what the confirmation shows (no secret: the key is created on Connect).
	 *
	 * @param string $state One-time state from AddSynapse.
	 * @return array<string,mixed>|string|\WP_Error The stash to confirm, a page to go to, or why not.
	 */
	public static function start( string $state ) {
		$failed = new \WP_Error( 'addsynapse_assistant_claim', __( 'The connection to AddSynapse was not completed. Nothing was sent. Start again from your AI chat.', 'addsynapse-store-assistant' ) );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'addsynapse_assistant_claim_forbidden', __( 'Only an administrator of this site can connect it to AddSynapse.', 'addsynapse-store-assistant' ) );
		}
		if ( ! RelayKeys::transport_ok() ) {
			return new \WP_Error( 'addsynapse_assistant_claim_https', __( 'AddSynapse connects only to sites that use HTTPS. Switch the site address to https:// (Settings → General), then start again from your AI chat.', 'addsynapse-store-assistant' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/D', $state ) ) {
			return $failed;
		}
		$user    = wp_get_current_user();
		$preview = Api::post(
			self::PREVIEW_PATH,
			array(
				'state'    => $state,
				'site_url' => site_url(),
			),
			10
		);
		if ( ! is_wp_error( $preview ) && 410 === $preview['status'] ) {
			return self::already_connected( (int) $user->ID ) ? self::connected_url() : $failed;
		}
		$who = self::preview_who( $preview );
		// Security review 22/10/2026 (P2-e): never connect without knowing which AddSynapse account asks — the confirm screen
		// must name it. No answer / an empty one → a clear refusal, no key is created.
		if ( '' === $who['account'] && '' === $who['email'] ) {
			return new \WP_Error( 'addsynapse_assistant_claim_account', __( 'AddSynapse did not confirm which account started this connection, so nothing was connected. Check that this site can reach addsynapse.com, then start again from your AI chat.', 'addsynapse-store-assistant' ) );
		}
		$stash = array(
			'mode'     => 'key',
			'user'     => (int) $user->ID,
			'login'    => (string) $user->user_login,
			'site_url' => site_url(),
		) + $who;
		set_transient( self::stash_key( $state ), $stash, self::MAX_AGE );
		return $stash;
	}

	/**
	 * The masked account / email (and the account the site would move from) of a preview answer.
	 *
	 * @param array<string,mixed>|\WP_Error $preview Api::post result.
	 * @return array{account:string,email:string,from:string}
	 */
	private static function preview_who( $preview ): array {
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
		return array(
			'account' => mb_substr( $account, 0, 60 ),
			'email'   => mb_substr( $email, 0, 120 ),
			'from'    => mb_substr( $from, 0, 200 ),
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
			// Back after Connect (the confirm URL was used already) or a second tab after the first connected: not an error.
			if ( self::already_connected( $user ) ) {
				self::leave( self::connected_url() );
				return;
			}
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
		$name   = self::cookie_name( $state );
		$cookie = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		self::set_key_cookie( $state, '', time() - 3600 );
		$user = get_current_user_id();
		if ( ! is_array( $stash ) || (int) ( $stash['user'] ?? 0 ) !== $user ) {
			return self::already_connected( $user ) ? self::connected_url() : $failed;
		}
		if ( $cancel ) {
			if ( 'key' !== ( $stash['mode'] ?? '' ) ) {
				self::drop_unconfirmed( $user, (string) $stash['uuid'] );
			}
			return HomePage::url( array( 'view' => 'home' ) );
		}
		if ( 'key' === ( $stash['mode'] ?? '' ) ) {
			return self::handle_key( $state, $stash );
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
	 * A confirmation waiting to be shown inside the plugin's Home (set on admin_init, printed by HomePage::render).
	 *
	 * @var array{state:string,stash:array<string,mixed>}|null
	 */
	private static ?array $confirm = null;

	/**
	 * Whether this request shows the «confirm the account» card (as_relay=confirm, GET, a valid stash of this user).
	 */
	public static function has_confirm(): bool {
		return null !== self::$confirm;
	}

	/**
	 * GET of as_relay=confirm: remember what to ask; the normal admin page then renders it inside the wizard (1.3.3,
	 * owner 22/10/2026: the bare wp_die page looked like an error).
	 *
	 * @param string              $state One-time state.
	 * @param array<string,mixed> $stash What the approval left.
	 */
	private static function render_confirm( string $state, array $stash ): void {
		self::$confirm = array(
			'state' => $state,
			'stash' => $stash,
		);
	}

	/**
	 * «Last step: confirm the account» — step 3 of the wizard, with the AddSynapse account (name + masked email) and
	 * Connect / Cancel (POST, nonce). Same security as before: nothing is sent to AddSynapse until Connect.
	 */
	public static function render_confirm_card(): void {
		if ( null === self::$confirm ) {
			return;
		}
		$state   = (string) self::$confirm['state'];
		$stash   = (array) self::$confirm['stash'];
		$account = trim( (string) ( $stash['account'] ?? '' ) );
		$email   = trim( (string) ( $stash['email'] ?? '' ) );
		$who     = trim( $account . ( '' !== $email ? ' (' . $email . ')' : '' ) );
		echo '<li class="adsa-step is-current adsa-relayconfirm" id="adsa-step-connect" data-step="connect" data-adsa-relay-confirm>';
		echo '<div class="adsa-step__head"><span class="adsa-step__num" aria-hidden="true"><span class="adsa-step__n">3</span></span>';
		echo '<h2 class="adsa-step__title">' . esc_html__( 'Last step: confirm the account', 'addsynapse-store-assistant' ) . '</h2></div>';
		echo '<div class="adsa-step__body">';
		echo '<p>' . ( 'key' === ( $stash['mode'] ?? '' )
			? esc_html__( 'AddSynapse asks to connect this site. Connect it to this AddSynapse account?', 'addsynapse-store-assistant' )
			: esc_html__( 'WordPress approved the connection. Connect this site to this AddSynapse account?', 'addsynapse-store-assistant' ) ) . '</p>';
		echo '<div class="adsa-relayconfirm__account">';
		if ( '' !== $account || '' !== $email ) {
			if ( '' !== $account ) {
				echo '<strong class="adsa-relayconfirm__name">' . esc_html( $account ) . '</strong>';
			}
			if ( '' !== $email ) {
				echo '<span class="adsa-relayconfirm__email">' . esc_html( $email ) . '</span>';
			}
		} else {
			echo '<strong class="adsa-relayconfirm__name">' . esc_html__( 'The AddSynapse account that started this connection', 'addsynapse-store-assistant' ) . '</strong>';
		}
		echo '</div>';
		if ( '' !== (string) ( $stash['from'] ?? '' ) ) {
			echo '<p class="adsa-relayconfirm__move" data-adsa-relay-move>' . sprintf(
				/* translators: 1: the AddSynapse account the site is connected to now (masked), 2: the new one. */
				esc_html__( 'This site is connected to another AddSynapse account: %1$s. Move it to %2$s? The other account loses access to this site.', 'addsynapse-store-assistant' ),
				'<strong>' . esc_html( (string) $stash['from'] ) . '</strong>',
				'<strong>' . esc_html( '' !== $who ? $who : __( 'the new account', 'addsynapse-store-assistant' ) ) . '</strong>'
			) . '</p>';
		}
		echo '<p class="adsa-relayconfirm__why">' . esc_html__( 'We ask so nobody can link your site to their own account. Continue only if this is your account and you started this from your AI chat a moment ago.', 'addsynapse-store-assistant' ) . '</p>';
		if ( 'key' === ( $stash['mode'] ?? '' ) ) {
			echo '<p class="adsa-relayconfirm__scope" data-adsa-relay-key>' . esc_html__( 'Connect creates a key for AddSynapse that works only for this plugin\'s tools, acting as you. Changes still wait for your Approve. You can disconnect at any time from this page.', 'addsynapse-store-assistant' ) . '</p>';
		}
		echo '<form method="post" class="adsa-finish adsa-relayconfirm__form" action="' . esc_url(
			add_query_arg(
				array(
					'page'     => self::PAGE,
					'as_relay' => 'confirm',
					'state'    => $state,
				),
				admin_url( 'admin.php' )
			)
		) . '">';
		wp_nonce_field( 'addsynapse_assistant_relay_confirm_' . $state );
		echo '<button type="submit" name="as_connect" value="1" class="button button-primary button-hero adsa-finish__btn">' . esc_html__( 'Connect', 'addsynapse-store-assistant' ) . '</button>';
		echo '<button type="submit" name="as_cancel" value="1" class="button button-secondary">' . esc_html__( 'Cancel', 'addsynapse-store-assistant' ) . '</button>';
		echo '</form>';
		echo '</div></li>';
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
	 * The stash key cookie of one state (two tabs = two cookies).
	 *
	 * @param string $state One-time state.
	 */
	public static function cookie_name( string $state ): string {
		return self::STASH_COOKIE . '_' . substr( hash( 'sha256', 'cookie|' . $state ), 0, 12 );
	}

	/**
	 * Home with «Already connected ✓».
	 */
	private static function connected_url(): string {
		return HomePage::url(
			array(
				'view'    => 'home',
				'welcome' => 'connected',
			)
		);
	}

	/**
	 * The stash key cookie (HttpOnly, secure when the admin is, wp-admin only).
	 *
	 * @param string $state   One-time state (names the cookie).
	 * @param string $value   Cookie value ('' to clear).
	 * @param int    $expires Expiry.
	 */
	private static function set_key_cookie( string $state, string $value, int $expires ): void {
		$name = self::cookie_name( $state );
		if ( ! headers_sent() ) {
			self::send_cookie( $name, $value, $expires );
		}
		if ( '' === $value ) {
			unset( $_COOKIE[ $name ] );
		} else {
			$_COOKIE[ $name ] = $value;
		}
	}

	/**
	 * Sends the stash key cookie.
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry.
	 */
	private static function send_cookie( string $name, string $value, int $expires ): void {
		setcookie(
			$name,
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
		if ( '' === $uuid || self::is_confirmed( $user_id, $uuid ) ) {
			return;
		}
		$key_id = RelayKeys::id_of( $uuid );
		if ( '' !== $key_id ) {
			$key = RelayKeys::get( $key_id );
			if ( null !== $key && $key['user'] === $user_id ) {
				RelayKeys::revoke( $key_id );
			}
			return;
		}
		if ( class_exists( '\WP_Application_Passwords' ) ) {
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
			// A parallel connect (second tab) already finished and retired this password.
			return self::already_connected( (int) $user->ID ) ? self::connected_url() : $failed;
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
				return self::connected_url();
			}
		}
		if ( 200 === $response['status'] && ! empty( $response['body']['ok'] ) ) {
			if ( ! empty( $response['body']['pending'] ) ) {
				// AddSynapse keeps it pending until the member who started confirms on AddSynapse: the working password stays.
				self::add_pending( (int) $user->ID, (string) $item['uuid'] );
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
		if ( ! is_array( $claim ) || (int) ( $claim['user'] ?? 0 ) !== $user_id || empty( $claim['uuid'] ) ) {
			return false;
		}
		$key_id = RelayKeys::id_of( (string) $claim['uuid'] );
		if ( '' !== $key_id ) {
			$key = RelayKeys::get( $key_id );
			return null !== $key && $key['user'] === $user_id;
		}
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return false;
		}
		$item = \WP_Application_Passwords::get_user_application_password( $user_id, (string) $claim['uuid'] );
		return is_array( $item ) && ( $item['app_id'] ?? '' ) === RemoteAccess::APP_ID;
	}

	/**
	 * AddSynapse stored and verified the new credential: it is the only AddSynapse credential of this user from now on (older
	 * passwords and keys — e.g. a second «Authorize», a rotation — are revoked), and the setup wizard is marked finished on
	 * the «Claude or ChatGPT» way.
	 *
	 * @param int    $user_id User.
	 * @param string $uuid    The new credential (password uuid or «key:<id>», kept).
	 */
	private static function claimed( int $user_id, string $uuid ): void {
		RelayKeys::revoke_user( $user_id, RelayKeys::id_of( $uuid ) );
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
	 * Protocol 4: the administrator pressed Connect — create the connection key and hand it to AddSynapse (JSON body, server
	 * to server, once). Anything but a pending/confirmed answer deletes the new key again.
	 *
	 * @param string              $state One-time state from AddSynapse.
	 * @param array<string,mixed> $stash What start() kept.
	 * @return string|\WP_Error
	 */
	public static function handle_key( string $state, array $stash ) {
		$failed = new \WP_Error( 'addsynapse_assistant_claim', __( 'The connection to AddSynapse was not completed. Nothing was sent. Start again from your AI chat.', 'addsynapse-store-assistant' ) );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'addsynapse_assistant_claim_forbidden', __( 'Only an administrator of this site can connect it to AddSynapse.', 'addsynapse-store-assistant' ) );
		}
		$user = wp_get_current_user();
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/D', $state ) || (int) ( $stash['user'] ?? 0 ) !== (int) $user->ID || ! RelayKeys::transport_ok() ) {
			return $failed;
		}
		$who = trim( (string) ( $stash['account'] ?? '' ) . ( '' !== (string) ( $stash['email'] ?? '' ) ? ' (' . (string) $stash['email'] . ')' : '' ) );
		if ( '' === $who ) {
			return new \WP_Error( 'addsynapse_assistant_claim_account', __( 'AddSynapse did not confirm which account started this connection, so nothing was connected. Check that this site can reach addsynapse.com, then start again from your AI chat.', 'addsynapse-store-assistant' ) );
		}
		$key = RelayKeys::mint( (int) $user->ID, $who );

		$response = Api::post(
			self::PATH,
			array(
				'state'      => $state,
				'site_url'   => (string) ( $stash['site_url'] ?? site_url() ),
				'user_login' => (string) $user->user_login,
				'key_id'     => $key['id'],
				'key'        => $key['secret'],
				'protocol'   => self::PROTOCOL,
			),
			15
		);
		unset( $key['secret'] );
		if ( is_wp_error( $response ) ) {
			RelayKeys::revoke( $key['id'] );
			return $response;
		}
		$next = isset( $response['body']['next'] ) ? (string) $response['body']['next'] : '';
		if ( 200 === $response['status'] && ! empty( $response['body']['ok'] ) ) {
			if ( ! empty( $response['body']['pending'] ) ) {
				self::add_pending( (int) $user->ID, $key['ref'] );
			} else {
				self::claimed( (int) $user->ID, $key['ref'] );
			}
		} else {
			// Refused, expired or not verified: the new key is useless (the working connection, if any, stays).
			RelayKeys::revoke( $key['id'] );
			if ( ( 410 === $response['status'] || 422 === $response['status'] ) && self::already_connected( (int) $user->ID ) ) {
				return self::connected_url();
			}
		}
		if ( '' !== $next && Api::link_allowed( $next ) ) {
			return $next;
		}
		return $failed;
	}

	/**
	 * A credential AddSynapse holds but has not used yet (a new key, a rotation): pending until its first real use.
	 *
	 * @param int    $user_id User.
	 * @param string $ref     «key:<id>» or an application password uuid.
	 */
	public static function add_pending( int $user_id, string $ref ): void {
		$pending         = self::pending_list();
		$pending[ $ref ] = array(
			'uuid' => $ref,
			'user' => $user_id,
			'time' => time(),
		);
		update_option( self::PENDING_OPTION, array_slice( $pending, -5, null, true ), false );
		if ( ! wp_next_scheduled( self::PENDING_CRON ) ) {
			wp_schedule_single_event( time() + self::PENDING_MAX_AGE + 60, self::PENDING_CRON );
		}
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
