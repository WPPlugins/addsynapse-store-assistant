<?php
/**
 * The optional «Create a free AddSynapse account» card (after the first successful «Save and test»).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Account;

use Addsynapse\StoreAssistant\Admin\AdminPage;
use Addsynapse\StoreAssistant\Admin\OwnKeySection;
use Addsynapse\StoreAssistant\Admin\SettingsPage;
use Addsynapse\StoreAssistant\Byok\KeyStore;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in only (Guidelines 5/7/11): nothing in the plugin is locked and the chat works the same either way. The card shows
 * only on the plugin's own screens, only to administrators, only once the AI key passed «Save and test». «Skip» hides it;
 * it comes back at most ONCE, 30 days later, and never after a second «Skip». Data leaves the site only when an
 * administrator clicks «Create account»: the email (prefilled with the admin email, editable), the shop's address, the
 * separate news-and-offers choice (unticked by default) and the version of the consent text. The account lets the manager
 * sign in to addsynapse.com (their sites in one place, where Store Assistant Pro is bought if they ever want it); the
 * plugin works the same without it.
 */
final class OptIn {

	public const ACTION_CREATE  = 'addsynapse_assistant_optin_create';
	public const ACTION_DISMISS = 'addsynapse_assistant_optin_dismiss';
	public const ACTION_FORGET  = 'addsynapse_assistant_account_forget';

	/** State: dismissed_at, dismiss_count, success_hidden. */
	public const OPTION = 'addsynapse_assistant_optin';

	/** Version of the consent text below (sent with «Create account», recorded by AddSynapse). */
	public const CONSENT_VERSION = '2026-10-20';

	/** Days before a dismissed card is offered again (once). */
	public const REOFFER_DAYS = 30;

	public const TERMS_URL   = 'https://addsynapse.com/terms';
	public const PRIVACY_URL = 'https://addsynapse.com/privacy';

	/** Message of the last failed attempt (per user, 5 minutes). */
	private const ERROR_TRANSIENT = 'addsynapse_assistant_optin_error_';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_CREATE, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
		add_action( 'admin_post_' . self::ACTION_FORGET, array( $this, 'handle_forget' ) );
	}

	/**
	 * State.
	 *
	 * @return array{dismissed_at:int,dismiss_count:int,success_hidden:bool}
	 */
	public static function state(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'dismissed_at'   => (int) ( $raw['dismissed_at'] ?? 0 ),
			'dismiss_count'  => (int) ( $raw['dismiss_count'] ?? 0 ),
			'success_hidden' => ! empty( $raw['success_hidden'] ),
		);
	}

	/**
	 * Whether the card is offered now (administrators, tested key, no account yet, not dismissed — or dismissed once
	 * more than REOFFER_DAYS ago).
	 *
	 * @param int|null $now Unix time.
	 */
	public static function eligible( ?int $now = null ): bool {
		if ( ! OwnKeySection::can_manage() || ! KeyStore::active() || Connection::connected() ) {
			return false;
		}
		$state = self::state();
		if ( 0 === $state['dismiss_count'] ) {
			return true;
		}
		return 1 === $state['dismiss_count'] && ( $now ?? time() ) - $state['dismissed_at'] >= self::REOFFER_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Sends the opt-in to AddSynapse — the ONLY place that does (from the card's form, after an administrator clicked
	 * «Create account»).
	 *
	 * @param string $email     Email.
	 * @param bool   $marketing News-and-offers box ticked.
	 * @return array{status:string,message:string,link:string} status created | sign_in | error.
	 */
	public static function create( string $email, bool $marketing ): array {
		$fail = static function ( string $message, string $status = 'error', string $link = '' ): array {
			return array(
				'status'  => $status,
				'message' => $message,
				'link'    => $link,
			);
		};
		if ( '' === $email || ! is_email( $email ) ) {
			return $fail( __( 'Enter a valid email address.', 'addsynapse-store-assistant' ) );
		}
		$answer = Api::post(
			'/api/v1/assistant/optin',
			array(
				'email'           => $email,
				'marketing'       => $marketing,
				'consent_version' => self::CONSENT_VERSION,
				'home_url'        => home_url( '/' ),
			)
		);
		if ( is_wp_error( $answer ) ) {
			return $fail( $answer->get_error_message() );
		}
		$status = (string) ( $answer['body']['status'] ?? '' );
		if ( 'created' === $status && Connection::save( $answer['body'] ) ) {
			update_option( self::OPTION, array( 'success_hidden' => false ) + self::state(), false );
			return $fail( '', 'created' );
		}
		if ( 'sign_in' === $status ) {
			$portal = (string) ( $answer['body']['portal_url'] ?? '' );
			return $fail(
				__( 'This email or this site already has an AddSynapse account. Sign in to it on addsynapse.com to add this site; nothing was created.', 'addsynapse-store-assistant' ),
				'sign_in',
				Api::link_allowed( $portal ) ? esc_url_raw( $portal ) : ''
			);
		}
		$message = (string) ( $answer['body']['message'] ?? '' );
		return $fail( '' !== $message ? sanitize_text_field( mb_substr( $message, 0, 300 ) ) : __( 'The account could not be created. Try again later.', 'addsynapse-store-assistant' ) );
	}

	/**
	 * «Create account» from the card (admin-post).
	 */
	public function handle_create(): void {
		if ( ! OwnKeySection::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_CREATE );
		$email     = isset( $_POST['addsynapse_assistant_optin_email'] ) ? sanitize_email( wp_unslash( $_POST['addsynapse_assistant_optin_email'] ) ) : '';
		$marketing = ! empty( $_POST['addsynapse_assistant_optin_marketing'] );
		$back      = self::back_url( isset( $_POST['addsynapse_assistant_tab'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_tab'] ) ) : '' );
		$result    = self::create( $email, $marketing );
		if ( 'created' === $result['status'] ) {
			self::redirect( $back, 'account_created' );
		}
		set_transient(
			self::ERROR_TRANSIENT . get_current_user_id(),
			array(
				'message' => $result['message'],
				'link'    => $result['link'],
			),
			5 * MINUTE_IN_SECONDS
		);
		self::redirect( $back, 'account_failed' );
	}

	/**
	 * «Skip»: hides the card (it comes back once, REOFFER_DAYS later).
	 */
	public function handle_dismiss(): void {
		if ( ! OwnKeySection::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_DISMISS );
		$back  = self::back_url( isset( $_POST['addsynapse_assistant_tab'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_tab'] ) ) : '' );
		$state = self::state();
		if ( Connection::connected() ) {
			$state['success_hidden'] = true;
		} else {
			$state['dismissed_at']  = time();
			$state['dismiss_count'] = min( 2, $state['dismiss_count'] + 1 );
		}
		update_option( self::OPTION, $state, false );
		self::redirect( $back, '' );
	}

	/**
	 * «Disconnect»: forgets the account on this site (announcements stop; the account on addsynapse.com stays).
	 */
	public function handle_forget(): void {
		if ( ! OwnKeySection::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_FORGET );
		Connection::forget();
		update_option( self::OPTION, array( 'success_hidden' => true ) + self::state(), false );
		wp_safe_redirect( SettingsPage::url() . '#addsynapse-assistant-account' );
		exit;
	}

	/**
	 * Where a form of the card returns: the tab it was posted from (our screens only).
	 *
	 * @param string $tab Posted tab.
	 */
	private static function back_url( string $tab ): string {
		return AdminPage::back_url( $tab );
	}

	/**
	 * Redirect back with a notice code.
	 *
	 * @param string $url    Back URL.
	 * @param string $notice Notice.
	 */
	private static function redirect( string $url, string $notice ): void {
		wp_safe_redirect( ( '' !== $notice ? add_query_arg( 'addsynapse_assistant_optin', $notice, $url ) : $url ) );
		exit;
	}

	/**
	 * Renders the card (or the «account ready» panel) on one of the plugin's screens.
	 *
	 * @param string $tab chat | settings.
	 */
	public static function render( string $tab ): void {
		$notice = sanitize_key( (string) filter_input( INPUT_GET, 'addsynapse_assistant_optin', FILTER_UNSAFE_RAW ) );
		if ( 'account_failed' === $notice && OwnKeySection::can_manage() ) {
			$error = get_transient( self::ERROR_TRANSIENT . get_current_user_id() );
			if ( is_array( $error ) ) {
				echo '<div class="notice notice-warning inline aiops-optin__notice"><p>' . esc_html( (string) $error['message'] );
				if ( '' !== (string) $error['link'] ) {
					echo ' <a href="' . esc_url( (string) $error['link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sign in', 'addsynapse-store-assistant' ) . '</a>';
				}
				echo '</p></div>';
			}
		}
		$connection = Connection::get();
		if ( null !== $connection ) {
			if ( OwnKeySection::can_manage() && ( 'account_created' === $notice || ! self::state()['success_hidden'] ) ) {
				self::render_ready( $connection, $tab );
			}
			return;
		}
		if ( ! self::eligible() ) {
			return;
		}
		$email = (string) get_option( 'admin_email', '' );
		echo '<div class="aiops-connector__card aiops-optin aiops-optin--compact" id="addsynapse-assistant-optin">';
		echo '<p class="aiops-optin__lead"><strong>' . esc_html__( 'Optional: create a free AddSynapse account to manage your sites in one place on addsynapse.com. The assistant works the same without it.', 'addsynapse-store-assistant' ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aiops-optin__form">';
		wp_nonce_field( self::ACTION_CREATE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREATE ) . '" />';
		echo '<input type="hidden" name="addsynapse_assistant_tab" value="' . esc_attr( $tab ) . '" />';
		echo '<p class="aiops-optin__fields"><label class="screen-reader-text" for="addsynapse-assistant-optin-email">' . esc_html__( 'Email', 'addsynapse-store-assistant' ) . '</label>';
		echo '<input type="email" class="regular-text" id="addsynapse-assistant-optin-email" name="addsynapse_assistant_optin_email" value="' . esc_attr( $email ) . '" required="required" autocomplete="email" /> ';
		echo '<label class="aiops-optin__consent"><input type="checkbox" name="addsynapse_assistant_optin_marketing" value="1" /> ' . esc_html__( 'Send me news and offers by email (optional; you can unsubscribe at any time)', 'addsynapse-store-assistant' ) . '</label></p>';
		echo '<details class="aiops-optin__privacy"><summary>' . esc_html__( 'What do we receive?', 'addsynapse-store-assistant' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Your email, the address of your shop and your choice above, only when you click «Create account». Never your AI key.', 'addsynapse-store-assistant' ) . ' ';
		echo '<a href="' . esc_url( self::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'addsynapse-store-assistant' ) . '</a> · <a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'addsynapse-store-assistant' ) . '</a></p></details>';
		echo '<p class="aiops-optin__actions">';
		submit_button( __( 'Create account', 'addsynapse-store-assistant' ), 'primary', 'submit', false );
		// «Skip» posts its own form (HTML form attribute), shown next to «Create account».
		submit_button( __( 'Skip', 'addsynapse-store-assistant' ), 'secondary', 'submit', false, array( 'form' => 'addsynapse-assistant-optin-dismiss' ) );
		echo '</p></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="addsynapse-assistant-optin-dismiss" class="aiops-optin__dismiss">';
		wp_nonce_field( self::ACTION_DISMISS, '_wpnonce', true, true );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISMISS ) . '" />';
		echo '<input type="hidden" name="addsynapse_assistant_tab" value="' . esc_attr( $tab ) . '" />';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * «Your account is ready»: what happens next, set a password, hide.
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @param string              $tab        Tab.
	 */
	private static function render_ready( array $connection, string $tab ): void {
		echo '<div class="aiops-connector__card aiops-optin aiops-optin--ready" id="addsynapse-assistant-optin">';
		echo '<h2>' . esc_html__( 'Your AddSynapse account is ready', 'addsynapse-store-assistant' ) . '</h2>';
		/* translators: %s: email address. */
		echo '<p>' . esc_html( sprintf( __( 'Account: %s. Set a password to sign in to addsynapse.com.', 'addsynapse-store-assistant' ), (string) $connection['email'] ) ) . '</p>';
		echo '<p>' . esc_html__( 'Sign in to addsynapse.com to see your sites. Store Assistant Pro is there too, if you ever need it.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<p class="aiops-optin__actions">';
		if ( '' !== $connection['portal_url'] ) {
			echo '<a class="button" href="' . esc_url( (string) $connection['portal_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Set a password', 'addsynapse-store-assistant' ) . '</a> ';
		}
		submit_button( __( 'Hide', 'addsynapse-store-assistant' ), 'secondary', 'submit', false, array( 'form' => 'addsynapse-assistant-optin-dismiss' ) );
		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="addsynapse-assistant-optin-dismiss" class="aiops-optin__dismiss">';
		wp_nonce_field( self::ACTION_DISMISS );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISMISS ) . '" />';
		echo '<input type="hidden" name="addsynapse_assistant_tab" value="' . esc_attr( $tab ) . '" />';
		echo '</form></div>';
	}

	/**
	 * Settings → «AddSynapse account»: the state, and «Disconnect» (administrators).
	 */
	public static function render_settings(): void {
		$connection = Connection::get();
		if ( null === $connection || ! OwnKeySection::can_manage() ) {
			return;
		}
		echo '<h2 id="addsynapse-assistant-account">' . esc_html__( 'AddSynapse account', 'addsynapse-store-assistant' ) . '</h2>';
		/* translators: %s: email address. */
		echo '<p>' . esc_html( sprintf( __( 'This site created an AddSynapse account (%s). Once a day, while you open these screens, the plugin asks AddSynapse for short announcements (it sends this site\'s key, the plugin version and the site language).', 'addsynapse-store-assistant' ), (string) $connection['email'] ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_FORGET );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_FORGET ) . '" />';
		submit_button( __( 'Disconnect this site', 'addsynapse-store-assistant' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html__( 'Stops the announcements and forgets the account on this site. Your account on addsynapse.com stays until you delete it there.', 'addsynapse-store-assistant' ) . '</p>';
		echo '</form>';
	}
}
