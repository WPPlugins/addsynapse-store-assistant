<?php
/**
 * Optional anonymous usage info (off by default): how many sites use Store Assistant, and with which versions.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Account;

use Addsynapse\StoreAssistant\Admin\AdminPage;
use Addsynapse\StoreAssistant\Admin\OwnKeySection;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in only (Guideline 7), separate from the account card and from the AI key:
 *  - Nothing is sent and nothing is scheduled until an administrator clicks «Allow» in the notice on the plugin's own
 *    screens (or «Turn on» in Settings). The notice is shown once: «No thanks» hides it for good.
 *  - After «Allow»: one call now, then at most one a week (WP-Cron), to `POST /api/v1/assistant/usage` on
 *    the AddSynapse API (same host allowlist as the account card, Api). It sends a random id made on this site when the
 *    administrator allowed it (not derived from the site, the domain or any user), the plugin, WordPress and WooCommerce
 *    versions and the site language. Never the site address, an email, content, store or customer data, or the AI key.
 *  - «Turn off» in Settings, deactivating or deleting the plugin: the weekly call stops and the id is deleted (nothing is
 *    sent to say so). Deactivation forgets an «Allow» (asked again once after reactivation); a «No thanks» is kept.
 */
final class Usage {

	public const ACTION = 'addsynapse_assistant_usage';

	/** State: choice (''|allowed|declined), id, allowed_at, last_sent. Not autoloaded. */
	public const OPTION = 'addsynapse_assistant_usage';

	/** WP-Cron hook of the weekly call (scheduled only after «Allow»). */
	public const HOOK = 'addsynapse_assistant_usage_ping';

	/** Version of the consent text (sent with every call, so AddSynapse knows which text the administrator agreed to). */
	public const CONSENT_VERSION = '2026-10-20';

	/** Minimum time between two weekly calls (a late cron run must not make two calls in a row). */
	private const MIN_GAP = 6 * DAY_IN_SECONDS;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'heartbeat' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * State.
	 *
	 * @return array{choice:string,id:string,allowed_at:int,last_sent:int}
	 */
	public static function state(): array {
		$raw    = get_option( self::OPTION, array() );
		$raw    = is_array( $raw ) ? $raw : array();
		$choice = (string) ( $raw['choice'] ?? '' );
		$id     = (string) ( $raw['id'] ?? '' );
		return array(
			'choice'     => in_array( $choice, array( 'allowed', 'declined' ), true ) ? $choice : '',
			'id'         => (bool) preg_match( '/^[a-f0-9]{32}$/D', $id ) ? $id : '',
			'allowed_at' => (int) ( $raw['allowed_at'] ?? 0 ),
			'last_sent'  => (int) ( $raw['last_sent'] ?? 0 ),
		);
	}

	/**
	 * Whether usage info is being shared.
	 */
	public static function allowed(): bool {
		$state = self::state();
		return 'allowed' === $state['choice'] && '' !== $state['id'];
	}

	/**
	 * Whether the notice is shown now: administrators, no answer yet, and not while the account card asks something.
	 */
	public static function eligible(): bool {
		return OwnKeySection::can_manage() && '' === self::state()['choice'] && ! OptIn::eligible();
	}

	/**
	 * «Allow»: a new random id, one call now, the weekly call scheduled.
	 *
	 * @param int|null $now Unix time.
	 */
	public static function allow( ?int $now = null ): void {
		$now = $now ?? time();
		update_option(
			self::OPTION,
			array(
				'choice'     => 'allowed',
				'id'         => bin2hex( random_bytes( 16 ) ),
				'allowed_at' => $now,
				'last_sent'  => 0,
			),
			false
		);
		self::send( 'optin', $now );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( $now + WEEK_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	/**
	 * «No thanks» (notice) or «Turn off» (Settings): stops the weekly call and deletes the id.
	 */
	public static function decline(): void {
		wp_clear_scheduled_hook( self::HOOK );
		update_option( self::OPTION, array( 'choice' => 'declined' ), false );
	}

	/**
	 * Deactivation: stops the weekly call and deletes the id. A «No thanks» is kept; an «Allow» is forgotten.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( 'declined' === self::state()['choice'] ) {
			return;
		}
		delete_option( self::OPTION );
	}

	/**
	 * The weekly WP-Cron call (does nothing unless an administrator allowed it).
	 *
	 * @param int|null $now Unix time.
	 */
	public static function heartbeat( ?int $now = null ): void {
		if ( ! self::allowed() ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		$now = $now ?? time();
		if ( $now - self::state()['last_sent'] < self::MIN_GAP ) {
			return;
		}
		self::send( 'heartbeat', $now );
	}

	/**
	 * What is sent — the whole body (tests check its keys).
	 *
	 * @param string $event optin | heartbeat.
	 * @return array<string,string>
	 */
	public static function payload( string $event ): array {
		return array(
			'install_id'      => self::state()['id'],
			'event'           => $event,
			'plugin_version'  => (string) ADDSYNAPSE_ASSISTANT_VERSION,
			'wp_version'      => (string) get_bloginfo( 'version' ),
			'wc_version'      => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'locale'          => (string) get_locale(),
			'consent_version' => self::CONSENT_VERSION,
		);
	}

	/**
	 * One call (failures are ignored: the next one is a week later).
	 *
	 * @param string $event Event.
	 * @param int    $now   Unix time.
	 */
	private static function send( string $event, int $now ): void {
		if ( ! self::allowed() ) {
			return;
		}
		Api::post( '/api/v1/assistant/usage', self::payload( $event ), 5 );
		$state              = get_option( self::OPTION, array() );
		$state              = is_array( $state ) ? $state : array();
		$state['last_sent'] = $now;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * «Allow» / «No thanks» (notice) and «Turn on» / «Turn off» (Settings) — admin-post.
	 */
	public function handle(): void {
		if ( ! OwnKeySection::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$choice = isset( $_POST['addsynapse_assistant_usage'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_usage'] ) ) : '';
		$tab    = isset( $_POST['addsynapse_assistant_tab'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_tab'] ) ) : '';
		if ( 'allow' === $choice ) {
			if ( ! self::allowed() ) {
				self::allow();
			}
		} else {
			self::decline();
		}
		wp_safe_redirect( AdminPage::back_url( $tab ) . ( 'settings' === $tab ? '#addsynapse-assistant-usage' : '' ) );
		exit;
	}

	/**
	 * The one-time notice on one of the plugin's own screens.
	 *
	 * @param string $tab chat | settings.
	 */
	public static function render( string $tab ): void {
		if ( ! self::eligible() ) {
			return;
		}
		echo '<div class="aiops-connector__card aiops-usage" id="addsynapse-assistant-usage-notice">';
		echo '<p><strong>' . esc_html__( 'Help improve Store Assistant', 'addsynapse-store-assistant' ) . '</strong> ';
		echo esc_html__( 'Share anonymous usage info with AddSynapse, the makers of this plugin: the plugin, WordPress and WooCommerce versions, the site language and a random site ID — once now, then once a week. No content, no customer data, no email or site address. You can turn it off in Settings at any time.', 'addsynapse-store-assistant' ) . ' ';
		echo '<a href="' . esc_url( OptIn::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'addsynapse-store-assistant' ) . '</a></p>';
		self::render_form( $tab, 'allow', _x( 'Allow', 'anonymous usage info', 'addsynapse-store-assistant' ), 'decline', __( 'No thanks', 'addsynapse-store-assistant' ) );
		echo '</div>';
	}

	/**
	 * Settings → «Anonymous usage info»: the state and the switch.
	 */
	public static function render_settings(): void {
		if ( ! OwnKeySection::can_manage() ) {
			return;
		}
		echo '<h2 id="addsynapse-assistant-usage">' . esc_html__( 'Anonymous usage info', 'addsynapse-store-assistant' ) . '</h2>';
		if ( self::allowed() ) {
			echo '<p>' . esc_html__( 'On. Once a week this site sends AddSynapse the plugin, WordPress and WooCommerce versions, the site language and a random site ID. No content, no customer data, no email or site address.', 'addsynapse-store-assistant' ) . '</p>';
			self::render_form( 'settings', 'decline', __( 'Turn off', 'addsynapse-store-assistant' ) );
			echo '<p class="description">' . esc_html__( 'Turning it off stops the weekly call and deletes the random ID on this site.', 'addsynapse-store-assistant' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Off. Nothing is sent. If you turn it on, this site sends AddSynapse once now and then once a week the plugin, WordPress and WooCommerce versions, the site language and a random site ID — no content, no customer data, no email or site address. It helps us know how many shops use Store Assistant.', 'addsynapse-store-assistant' ) . '</p>';
		self::render_form( 'settings', 'allow', __( 'Turn on', 'addsynapse-store-assistant' ) );
	}

	/**
	 * Form with one or two buttons.
	 *
	 * @param string $tab          Tab.
	 * @param string $value        Primary button value.
	 * @param string $label        Primary button label.
	 * @param string $second_value Second button value.
	 * @param string $second_label Second button label.
	 */
	private static function render_form( string $tab, string $value, string $label, string $second_value = '', string $second_label = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aiops-usage__actions">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="addsynapse_assistant_tab" value="' . esc_attr( $tab ) . '" />';
		echo '<button type="submit" class="button ' . ( 'allow' === $value ? 'button-primary' : 'button-secondary' ) . '" name="addsynapse_assistant_usage" value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</button>';
		if ( '' !== $second_value ) {
			echo ' <button type="submit" class="button button-link" name="addsynapse_assistant_usage" value="' . esc_attr( $second_value ) . '">' . esc_html( $second_label ) . '</button>';
		}
		echo '</form>';
	}
}
