<?php
/**
 * AddSynapse → Home: the welcome wizard (choose how to connect → step-by-step setup) and, once set up, the status of
 * both ways (Claude / ChatGPT through AddSynapse, and the chat here with the manager's own AI key) with quick actions.
 *
 * Nothing on these screens contacts AddSynapse or an AI provider: the connection check is a loopback request to this
 * same site, the «Add to …» buttons are plain links the manager opens, the steps turn green by reading this site's own
 * state (Application Passwords, the saved key), and the key is tested only when an administrator presses «Save and test».
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Account\Announcements;
use Addsynapse\StoreAssistant\Account\Api;
use Addsynapse\StoreAssistant\Account\OptIn;
use Addsynapse\StoreAssistant\Account\PendingOnboarding;
use Addsynapse\StoreAssistant\Account\Usage;
use Addsynapse\StoreAssistant\Brain\Models;
use Addsynapse\StoreAssistant\Byok\KeyStore;
use Addsynapse\StoreAssistant\Byok\Usage as KeyUsage;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\Remote\RelayKeys;
use Addsynapse\StoreAssistant\Remote\PlanInfo;
use Addsynapse\StoreAssistant\Remote\RelayClaim;
use Addsynapse\StoreAssistant\Remote\RemoteAccess;
use Addsynapse\StoreAssistant\Remote\SetupStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Home + onboarding wizard (rendered by our Overview renderer on admin.php?page=addsynapse).
 */
final class HomePage {

	/** Option: array{done:bool,path:string} — the manager finished (or skipped) the wizard. */
	public const OPTION = 'addsynapse_assistant_onboarding';

	/** The admin-post action of the «Finish» button. */
	public const ACTION_FINISH = 'addsynapse_assistant_onboarding_finish';

	/** The admin-post action of «Hide» on the «You're all set!» panel (1.3.5). */
	public const ACTION_ALLSET_HIDE = 'addsynapse_assistant_allset_hide';

	/** Option: array{since:int,hidden:bool} — when Home first showed «You're all set!», and whether it was hidden. */
	public const ALLSET_OPTION = 'addsynapse_assistant_allset';

	/** Days the «You're all set!» panel stays open on Home. */
	public const ALLSET_DAYS = 7;

	/** The admin-post action of «Disconnect AddSynapse» (1.3.4). */
	public const ACTION_DISCONNECT = 'addsynapse_assistant_relay_disconnect';

	/** Script/style handle. */
	public const HANDLE = 'addsynapse-assistant-home';

	/** Transient: the administrator (user id) who just activated the plugin (one minute). */
	public const JUST_ACTIVATED = 'addsynapse_assistant_just_activated';

	/** Transient: the Claude connection was continued automatically once (no loop; 10 minutes). */
	public const AUTO_STARTED = 'addsynapse_assistant_onboarding_auto';

	/** The wizard views (query arg `setup`). */
	public const VIEWS = array( 'choose', 'mcp', 'key' );

	/**
	 * Registers hooks (wp-admin only).
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_FINISH, array( $this, 'handle_finish' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( self::class, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::ACTION_ALLSET_HIDE, array( self::class, 'handle_allset_hide' ) );
		// Before WordPress checks access to the requested page: old addresses keep working.
		add_action( 'admin_menu', array( self::class, 'legacy_redirects' ), 1 );
		// After RelayClaim::maybe_handle (admin_init:1), before any output.
		add_action( 'admin_init', array( self::class, 'maybe_start_from_claude' ), 5 );
	}

	/**
	 * Activation (a second activation callback, next to Schema::install): remember for one minute that an administrator
	 * just activated the plugin, so the next admin page can continue the Claude connection (maybe_start_from_claude).
	 * Not on bulk activation, WP-CLI or network activation.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activated( $network_wide = false ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only tells a bulk activation apart; core checked its own nonce.
		if ( $network_wide || ( defined( 'WP_CLI' ) && WP_CLI ) || isset( $_REQUEST['checked'] ) ) {
			return;
		}
		// A new activation asks AddSynapse afresh and may continue the connection again (satest 24/10: a re-activation
		// within 10 minutes found the old «already continued» guard and did nothing).
		PendingOnboarding::reset();
		set_transient( self::JUST_ACTIVATED, get_current_user_id(), MINUTE_IN_SECONDS );
	}

	/**
	 * 1.3.5 (owner 24/10, «6 clicks from Claude»): the manager started in Claude and AddSynapse has a connection pending for
	 * this site → right after activation (or on the first visit of the wizard) continue that connection: the browser goes
	 * to AddSynapse's connect page for this site, exactly as the «Connect to Claude» button does, and AddSynapse sends
	 * it straight back to this plugin's «Connect <host> to your AddSynapse account? [Yes, connect] [Cancel]» screen.
	 * Nothing is connected without «Yes, connect». Once per 10 minutes at most (no loop); otherwise nothing happens and the
	 * usual screens show.
	 */
	public static function maybe_start_from_claude(): void {
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}
		global $pagenow;
		$just = (int) get_transient( self::JUST_ACTIVATED );
		if ( $just > 0 && 'plugins.php' === $pagenow ) {
			delete_transient( self::JUST_ACTIVATED );
		} else {
			$just = 0;
		}
		$wizard = 'admin.php' === $pagenow && AddsynapseMenu::PARENT_SLUG === self::flag( 'page' )
			&& '' === self::flag( 'as_relay' ) && '' === self::flag( 'setup' ) && '' === self::flag( 'view' ) && '' === self::flag( 'welcome' );
		if ( ( get_current_user_id() !== $just && ! $wizard ) || get_transient( self::AUTO_STARTED ) || self::is_set_up() || ! RelayKeys::transport_ok() ) {
			// Not «needs_setup()»: a wizard finished on an earlier install (the option outlives deactivation) must not stop
			// a new connection started in Claude (satest 24/10). Only a working connection does.
			return;
		}
		if ( ! PendingOnboarding::from_claude() ) {
			return;
		}
		$url = Api::claude_connect_url( home_url( '/' ), 'claude' );
		if ( ! Api::link_allowed( $url ) ) {
			return;
		}
		set_transient( self::AUTO_STARTED, 1, PendingOnboarding::TTL );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- AddSynapse's own connect page (Api::link_allowed: https on an AddSynapse host only).
		wp_redirect( $url, 302 );
		exit;
	}

	/**
	 * Home address.
	 *
	 * @param array<string,string> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => AddsynapseMenu::PARENT_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Address of one wizard path.
	 *
	 * @param string $view choose | mcp | key.
	 */
	public static function setup_url( string $view ): string {
		return self::url( array( 'setup' => $view ) );
	}

	/**
	 * A display-only query value (which view to show), matched against fixed lists by the callers.
	 *
	 * @param string $name Query arg.
	 */
	private static function flag( string $name ): string {
		return sanitize_key( (string) filter_input( INPUT_GET, $name, FILTER_UNSAFE_RAW ) );
	}

	/**
	 * Wizard state.
	 *
	 * @return array{done:bool,path:string}
	 */
	public static function state(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'done' => ! empty( $raw['done'] ),
			'path' => in_array( $raw['path'] ?? '', array( 'mcp', 'key' ), true ) ? (string) $raw['path'] : '',
		);
	}

	/**
	 * Whether at least one way works: the chat has a tested key, or AddSynapse holds an approved application password.
	 *
	 * @param array<string,mixed>|null $connection SetupStatus::connection() (computed when null).
	 */
	public static function is_set_up( ?array $connection = null ): bool {
		if ( KeyStore::active() ) {
			return true;
		}
		$connection = $connection ?? SetupStatus::connection();
		return ! empty( $connection['connected'] );
	}

	/**
	 * Whether the manager has not started yet: the wizard was never finished or skipped, and no way works. While true,
	 * every entry point of the plugin (Plugins row, Home) opens the wizard.
	 */
	public static function needs_setup(): bool {
		return ! self::state()['done'] && ! self::is_set_up();
	}

	/**
	 * Which screen to show: a requested wizard path, the wizard (first visits, nothing set up), or Home.
	 */
	public static function view(): string {
		$setup = self::flag( 'setup' );
		if ( in_array( $setup, self::VIEWS, true ) ) {
			return $setup;
		}
		if ( 'home' === self::flag( 'view' ) || ! self::needs_setup() ) {
			return 'home';
		}
		return self::from_claude() ? 'mcp' : 'choose';
	}

	/**
	 * 1.3.5 (owner 24/10): the manager started in Claude (AddSynapse says a connection for this site address is pending),
	 * so the first screen skips «Choose how you want to connect» and opens the Claude path with one button. Only on the
	 * wizard's default screen (no `setup`/`view` asked for) of a site with nothing set up; «Other options» (setup=choose)
	 * always shows both ways. Any failure of the question = the usual first screen.
	 */
	public static function from_claude(): bool {
		if ( '' !== self::flag( 'setup' ) || 'home' === self::flag( 'view' ) || ! current_user_can( Config::capability() ) || self::is_set_up() ) {
			return false;
		}
		return PendingOnboarding::from_claude();
	}

	/**
	 * «Finish» (and «Skip for now»): remembers that the manager went through the wizard, then shows Home.
	 */
	public function handle_finish(): void {
		if ( ! current_user_can( Config::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_FINISH );
		$path = isset( $_POST['addsynapse_assistant_path'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_path'] ) ) : '';
		update_option(
			self::OPTION,
			array(
				'done' => true,
				'path' => in_array( $path, array( 'mcp', 'key' ), true ) ? $path : self::state()['path'],
			),
			false
		);
		wp_safe_redirect( self::url( in_array( $path, array( 'mcp', 'key' ), true ) ? array( 'welcome' => $path ) : array() ) );
		exit;
	}

	/**
	 * «Disconnect AddSynapse» (Home): revokes every AddSynapse connection key and application password of this site at once
	 * (RemoteAccess::revoke_all, as on deactivation). AddSynapse can no longer reach the site; the approval log stays.
	 */
	public static function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_DISCONNECT );
		RemoteAccess::revoke_all();
		wp_safe_redirect( self::url( array( 'welcome' => 'disconnected' ) ) );
		exit;
	}

	/**
	 * Old addresses (menu items of 1.3.0-dev and earlier) → their new place. Runs on admin_menu:1, before any output.
	 */
	public static function legacy_redirects(): void {
		$page = self::flag( 'page' );
		if ( '' === $page || ! current_user_can( Config::capability() ) ) {
			return;
		}
		$target = '';
		if ( AdminPage::SLUG === $page && 'settings' === self::flag( 'tab' ) ) {
			$target = SettingsPage::url();
		} elseif ( ConnectPage::SLUG === $page ) {
			$target = 'log' === self::flag( 'tab' ) ? SettingsPage::url( array( 'view' => 'log' ) ) : self::setup_url( 'mcp' );
		} elseif ( ProOffers::MENU_SLUG === $page ) {
			( new ProOffers() )->redirect();
			return;
		}
		if ( '' !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * Styles + script of Home / the wizard.
	 */
	public static function enqueue(): void {
		AdminPage::enqueue_admin_css();
		$css = ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'assets/home.css';
		$js  = ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'assets/home.js';
		wp_enqueue_style( self::HANDLE, ADDSYNAPSE_ASSISTANT_PLUGIN_URL . 'assets/home.css', array(), ADDSYNAPSE_ASSISTANT_VERSION . '.' . ( is_readable( $css ) ? (int) filemtime( $css ) : 0 ) );
		wp_enqueue_script( self::HANDLE, ADDSYNAPSE_ASSISTANT_PLUGIN_URL . 'assets/home.js', array( 'wp-api-fetch' ), ADDSYNAPSE_ASSISTANT_VERSION . '.' . ( is_readable( $js ) ? (int) filemtime( $js ) : 0 ), true );
		wp_localize_script(
			self::HANDLE,
			'addsynapseHome',
			array(
				'check'  => '/' . RemoteAccess::REST_NAMESPACE . '/connect/check',
				'status' => '/' . RemoteAccess::REST_NAMESPACE . '/connect/status',
				'poll'   => 'mcp' === self::view(),
				'i18n'   => array(
					'checking' => __( 'Checking…', 'addsynapse-store-assistant' ),
					'passed'   => __( 'All checks passed.', 'addsynapse-store-assistant' ),
					'failed'   => __( 'Some checks need attention.', 'addsynapse-store-assistant' ),
					'copied'   => __( 'Copied', 'addsynapse-store-assistant' ),
					/* translators: %s: WordPress user name */
					'approved' => __( 'Approved by %s.', 'addsynapse-store-assistant' ),
					'read'     => __( 'Your AI read the store. You are all set.', 'addsynapse-store-assistant' ),
					'checked'  => __( 'AddSynapse checked the connection. You are all set.', 'addsynapse-store-assistant' ),
					'done'     => __( 'Done', 'addsynapse-store-assistant' ),
					'error'    => __( 'Something went wrong. Try again.', 'addsynapse-store-assistant' ),
				),
			)
		);
	}

	/**
	 * Screen body (called by our Overview renderer).
	 */
	public static function render(): void {
		$view = RelayClaim::has_confirm() ? 'mcp' : self::view();
		echo '<div class="wrap adsa-home adsa-view-' . esc_attr( $view ) . '">';
		// WordPress moves admin notices after the first heading; keep them above our hero.
		echo '<h1 class="screen-reader-text">' . esc_html( 'AddSynapse Store Assistant' ) . '</h1><hr class="wp-header-end">';
		if ( RelayClaim::has_confirm() ) {
			self::render_relay_confirm();
		} elseif ( 'choose' === $view ) {
			self::render_choose();
		} elseif ( 'mcp' === $view ) {
			self::render_mcp();
		} elseif ( 'key' === $view ) {
			self::render_key();
		} else {
			self::render_home();
		}
		echo '</div>';
	}

	/**
	 * Small inline SVG icons (no external assets). Only fixed markup of this class goes through here.
	 *
	 * @param string $name Icon.
	 */
	private static function icon( string $name ): string {
		$paths = array(
			'chat'  => '<path d="M4 5h16v11H8l-4 4z"/>',
			'cloud' => '<path d="M7 18h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6 9.5 4.25 4.25 0 0 0 7 18z"/>',
			'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'key'   => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M16 7l2 2M14 9l2 2"/>',
			'back'  => '<path d="M14 6l-6 6 6 6"/>',
			'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>',
		);
		return '<svg class="adsa-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * Allowed SVG markup for wp_kses().
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function svg_tags(): array {
		$common = array(
			'd'  => true,
			'cx' => true,
			'cy' => true,
			'r'  => true,
		);
		return array(
			'svg'    => array(
				'class'           => true,
				'viewbox'         => true,
				'width'           => true,
				'height'          => true,
				'aria-hidden'     => true,
				'focusable'       => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'path'   => $common,
			'circle' => $common,
		);
	}

	/**
	 * Prints an icon.
	 *
	 * @param string $name Icon.
	 */
	private static function print_icon( string $name ): void {
		echo wp_kses( self::icon( $name ), self::svg_tags() );
	}

	/**
	 * A read-only text with a «Copy» button.
	 *
	 * @param string $value  Text.
	 * @param string $label  Accessible label of the field.
	 * @param string $button Button text.
	 * @param string $mark   Step the copy marks as done ('' = none).
	 */
	private static function copy_field( string $value, string $label, string $button, string $mark = '' ): void {
		$id = 'adsa-copy-' . substr( md5( $value ), 0, 8 );
		echo '<div class="adsa-copy">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="text" readonly id="' . esc_attr( $id ) . '" class="adsa-copy__src" value="' . esc_attr( $value ) . '">';
		echo '<button type="button" class="button adsa-copy__btn" data-copy="' . esc_attr( $id ) . '"' . ( '' !== $mark ? ' data-mark="' . esc_attr( $mark ) . '"' : '' ) . '>' . esc_html( $button ) . '</button>';
		echo '</div>';
	}

	/**
	 * The progress bar of a path.
	 *
	 * @param array<string,array{0:string,1:bool}> $steps Step id → [label, done].
	 */
	private static function progress( array $steps ): void {
		$current = '';
		foreach ( $steps as $id => $step ) {
			if ( ! $step[1] ) {
				$current = $id;
				break;
			}
		}
		echo '<ol class="adsa-progress" aria-label="' . esc_attr__( 'Setup progress', 'addsynapse-store-assistant' ) . '">';
		$n = 0;
		foreach ( $steps as $id => $step ) {
			++$n;
			$class = 'adsa-progress__item' . ( $step[1] ? ' is-done' : '' ) . ( $id === $current ? ' is-current' : '' );
			echo '<li class="' . esc_attr( $class ) . '" data-step="' . esc_attr( $id ) . '"' . ( $id === $current ? ' aria-current="step"' : '' ) . '>';
			echo '<a href="#adsa-step-' . esc_attr( $id ) . '"><span class="adsa-progress__num"><span class="adsa-progress__n">' . esc_html( (string) $n ) . '</span>';
			self::print_icon( 'check' );
			echo '</span><span class="adsa-progress__label">' . esc_html( $step[0] ) . '</span>';
			echo '<span class="screen-reader-text adsa-progress__sr">' . esc_html( $step[1] ? __( '(done)', 'addsynapse-store-assistant' ) : '' ) . '</span></a></li>';
		}
		echo '</ol>';
	}

	/**
	 * Opening markup of a step card.
	 *
	 * @param string $id    Step id.
	 * @param int    $n     Number.
	 * @param string $title Title.
	 * @param bool   $done  Done.
	 */
	private static function step_open( string $id, int $n, string $title, bool $done ): void {
		echo '<li class="adsa-step' . ( $done ? ' is-done' : '' ) . '" id="adsa-step-' . esc_attr( $id ) . '" data-step="' . esc_attr( $id ) . '">';
		echo '<div class="adsa-step__head"><span class="adsa-step__num" aria-hidden="true"><span class="adsa-step__n">' . esc_html( (string) $n ) . '</span>';
		self::print_icon( 'check' );
		echo '</span><h2 class="adsa-step__title">' . esc_html( $title ) . ' <span class="adsa-step__badge">' . esc_html__( 'Done', 'addsynapse-store-assistant' ) . '</span></h2></div>';
		echo '<div class="adsa-step__body">';
	}

	/**
	 * Closing markup of a step card.
	 */
	private static function step_close(): void {
		echo '</div></li>';
	}

	/**
	 * «← Choose another way».
	 */
	private static function back_link(): void {
		if ( self::from_claude() ) {
			// Not a lock (wp.org guideline 5): both ways stay one click away.
			echo '<a class="adsa-back" href="' . esc_url( self::setup_url( 'choose' ) ) . '">';
			self::print_icon( 'back' );
			echo esc_html__( 'Other options', 'addsynapse-store-assistant' ) . '</a>';
			return;
		}
		$target = self::state()['done'] || self::is_set_up() ? self::url() : self::setup_url( 'choose' );
		echo '<a class="adsa-back" href="' . esc_url( $target ) . '">';
		self::print_icon( 'back' );
		echo esc_html( self::state()['done'] || self::is_set_up() ? __( 'Back to Home', 'addsynapse-store-assistant' ) : __( 'Choose another way', 'addsynapse-store-assistant' ) ) . '</a>';
	}

	/**
	 * The «Finish» form of a path.
	 *
	 * @param string $path    mcp | key.
	 * @param string $note    Text next to the button.
	 * @param bool   $primary The screen's main button (false: a plain one, when another button is the main one).
	 */
	private static function finish( string $path, string $note, bool $primary = true ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="adsa-finish">';
		wp_nonce_field( self::ACTION_FINISH );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_FINISH ) . '">';
		echo '<input type="hidden" name="addsynapse_assistant_path" value="' . esc_attr( $path ) . '">';
		echo '<button type="submit" class="button ' . ( $primary ? 'button-primary button-hero ' : '' ) . 'adsa-finish__btn">' . esc_html__( 'Finish', 'addsynapse-store-assistant' ) . '</button>';
		echo '<p class="adsa-finish__note">' . esc_html( $note ) . '</p>';
		echo '</form>';
	}

	/**
	 * Wizard, first screen: the hero and the two ways.
	 */
	private static function render_choose(): void {
		echo '<section class="adsa-hero">';
		echo '<p class="adsa-hero__eyebrow">';
		self::print_icon( 'spark' );
		echo esc_html__( 'Welcome to Store Assistant', 'addsynapse-store-assistant' ) . '</p>';
		echo '<h2 class="adsa-hero__title">' . esc_html( self::is_site() ? __( 'Manage your site by talking to AI', 'addsynapse-store-assistant' ) : __( 'Manage your store by talking to AI', 'addsynapse-store-assistant' ) ) . '</h2>';
		echo '<p class="adsa-hero__lead">' . esc_html( self::is_site() ? __( 'Ask in plain words — «which pages have no SEO description?», «what is in my main menu?». Every change is a draft you approve, and you can undo it.', 'addsynapse-store-assistant' ) : __( 'Ask in plain words — «lower the Coffee prices by 5%», «which products have no image?». Every change is a draft you approve, and you can undo it.', 'addsynapse-store-assistant' ) ) . '</p>';
		echo '<ol class="adsa-flow" aria-label="' . esc_attr__( 'How changes work', 'addsynapse-store-assistant' ) . '">';
		foreach ( array( __( 'Draft', 'addsynapse-store-assistant' ), __( 'Approve', 'addsynapse-store-assistant' ), __( 'Undo', 'addsynapse-store-assistant' ) ) as $i => $label ) {
			echo '<li><span>' . esc_html( (string) ( $i + 1 ) ) . '</span>' . esc_html( $label ) . '</li>';
		}
		echo '</ol></section>';

		echo '<h2 class="adsa-section-title">' . esc_html__( 'Choose how you want to connect', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<div class="adsa-choices">';
		self::choice(
			'mcp',
			'cloud',
			__( 'Use it from Claude or ChatGPT', 'addsynapse-store-assistant' ),
			self::is_site() ? __( 'Talk to your site from Claude or ChatGPT.', 'addsynapse-store-assistant' ) : __( 'Talk to your store from Claude or ChatGPT.', 'addsynapse-store-assistant' ),
			array(
				__( 'No API key needed', 'addsynapse-store-assistant' ),
				__( 'Uses your Claude or ChatGPT subscription', 'addsynapse-store-assistant' ),
				/* translators: %s: number of actions per day, e.g. 100. */
				sprintf( __( 'Free: %s actions a day', 'addsynapse-store-assistant' ), number_format_i18n( PlanInfo::FREE_PER_DAY ) ),
				__( 'Changes arrive as drafts: Approve, then Undo if needed', 'addsynapse-store-assistant' ),
			),
			true
		);
		self::choice(
			'key',
			'chat',
			__( 'Chat here in WordPress', 'addsynapse-store-assistant' ),
			__( 'A chat inside wp-admin, with your own AI key.', 'addsynapse-store-assistant' ),
			array(
				__( 'Works with an Anthropic or OpenAI API key', 'addsynapse-store-assistant' ),
				__( 'You pay your AI provider directly for what you use', 'addsynapse-store-assistant' ),
				__( 'The same drafts, Approve and Undo', 'addsynapse-store-assistant' ),
			),
			false
		);
		echo '</div>';
		echo '<p class="adsa-choose-foot">' . esc_html__( 'You can use both ways, and switch at any time from Home.', 'addsynapse-store-assistant' ) . ' ';
		echo '<a href="' . esc_url( self::url( array( 'view' => 'home' ) ) ) . '">' . esc_html__( 'Skip for now', 'addsynapse-store-assistant' ) . '</a></p>';
	}

	/**
	 * One big choice card.
	 *
	 * @param string   $view        Path.
	 * @param string   $icon        Icon.
	 * @param string   $title       Title.
	 * @param string   $lead        One line.
	 * @param string[] $points      Bullet points.
	 * @param bool     $recommended Recommended badge.
	 */
	private static function choice( string $view, string $icon, string $title, string $lead, array $points, bool $recommended ): void {
		$id = 'adsa-choice-' . $view;
		echo '<div class="adsa-choice' . ( $recommended ? ' is-recommended' : '' ) . '">';
		if ( $recommended ) {
			echo '<span class="adsa-choice__badge">' . esc_html__( 'Recommended', 'addsynapse-store-assistant' ) . '</span>';
		}
		echo '<span class="adsa-choice__icon">';
		self::print_icon( $icon );
		echo '</span>';
		echo '<h3 class="adsa-choice__title" id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h3>';
		echo '<p class="adsa-choice__lead">' . esc_html( $lead ) . '</p><ul class="adsa-choice__points">';
		foreach ( $points as $point ) {
			echo '<li>';
			self::print_icon( 'check' );
			echo esc_html( $point ) . '</li>';
		}
		echo '</ul>';
		echo '<a class="button ' . ( $recommended ? 'button-primary' : 'button-secondary' ) . ' adsa-choice__go" href="' . esc_url( self::setup_url( $view ) ) . '" aria-describedby="' . esc_attr( $id ) . '">' . esc_html__( 'Continue', 'addsynapse-store-assistant' ) . '</a>';
		echo '</div>';
	}

	/**
	 * A plain WordPress site (no WooCommerce): «site» wording instead of «store».
	 */
	private static function is_site(): bool {
		return 'site' === Environment::site_type();
	}

	/**
	 * The «Connect my site to AddSynapse at …» prompt (names AddSynapse so the AI picks our connect tool).
	 */
	public static function connect_prompt(): string {
		/* translators: %s: site address */
		return sprintf( __( 'Connect my site to AddSynapse at %s', 'addsynapse-store-assistant' ), untrailingslashit( home_url() ) );
	}

	/**
	 * Path A: Claude / ChatGPT through AddSynapse.
	 */
	private static function render_mcp(): void {
		$status = SetupStatus::connection();
		// Came from Claude and just pressed «Yes, connect» (AddSynapse sent the browser back): a clean «Done» screen, not
		// the three steps (owner 24/10). The steps stay one click away.
		if ( ( ! empty( $status['ready'] ) || ! empty( $status['connected'] ) ) && 'connected' === self::flag( 'as_relay' ) && PendingOnboarding::cached_from_claude() ) {
			self::render_arrival_done();
			return;
		}
		// Connected for sure (AddSynapse confirmed the password, or used it): every step is done.
		$ready = ! empty( $status['ready'] );
		// 1.3.4: AddSynapse connects with this plugin's own key, so Application Passwords being off (Wordfence's default) no
		// longer blocks anything; only HTTPS is required (RelayKeys::transport_ok).
		// 1.3.5 (owner 24/10): step 2 is ONE button that connects this site to the AddSynapse account (the account usually
		// already uses Claude); adding AddSynapse to an AI app moves to step 3, shown once the site is connected.
		$connected = $ready || (bool) $status['connected'];
		// Came from Claude and not connected yet: «Connect to Claude» / «Connect to ChatGPT» are the only primary buttons.
		$arrival = self::from_claude() && ! $connected;
		$steps   = array(
			// A connected site has passed what the check checks (AddSynapse reached it): step 1 is done unless a real
			// check ran and failed (owner 24/10: never «not done» next to two green steps).
			'check'   => array( __( 'Check', 'addsynapse-store-assistant' ), $ready || (bool) $status['checked'] || ( $connected && empty( $status['check_failed'] ) ) ),
			'connect' => array( __( 'Connect', 'addsynapse-store-assistant' ), $connected ),
		);
		// 1.3.5 (owner 24/10): two steps — the check (runs by itself) and Connect — then «Finish». How to use it from
		// Claude or ChatGPT is on Home («You're all set!»).
		self::back_link();
		echo '<div class="adsa-pathhead">';
		echo '<h2 class="adsa-pathhead__title">' . esc_html( self::is_site() ? __( 'Use your site from Claude or ChatGPT', 'addsynapse-store-assistant' ) : __( 'Use your store from Claude or ChatGPT', 'addsynapse-store-assistant' ) ) . '</h2>';
		if ( $arrival ) {
			echo '<p class="adsa-pathhead__lead adsa-arrival" data-adsa-from="claude"><strong>' . esc_html__( 'You came from Claude — one click to finish.', 'addsynapse-store-assistant' ) . '</strong> ' . esc_html__( 'Press «Connect to Claude» below.', 'addsynapse-store-assistant' ) . '</p>';
		} else {
			echo '<p class="adsa-pathhead__lead">' . esc_html__( 'Two short steps. Each step turns green on its own.', 'addsynapse-store-assistant' ) . '</p>';
		}
		echo '</div>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: a success line when AddSynapse sent the browser back.
		if ( $connected && isset( $_GET['as_relay'] ) && 'connected' === sanitize_key( wp_unslash( $_GET['as_relay'] ) ) ) {
			// Back from AddSynapse right after «Connect» (1.3.3, hub protocol 3).
			if ( PendingOnboarding::cached_from_claude() ) {
				// Came from Claude: the AddSynapse page in the other tab moves on by itself.
				echo '<div class="notice notice-success adsa-connected-notice adsa-arrival-done" data-adsa-relay-connected data-adsa-from="claude"><p><strong>' . esc_html__( 'Done — go back to Claude.', 'addsynapse-store-assistant' ) . '</strong> ' . esc_html__( 'This site is now connected to AddSynapse. Ask Claude something about your site.', 'addsynapse-store-assistant' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success adsa-connected-notice" data-adsa-relay-connected><p><strong>' . esc_html__( 'Connected ✓', 'addsynapse-store-assistant' ) . '</strong> ' . esc_html__( 'This site is now connected to AddSynapse. Go back to your AI chat and ask it something about your site.', 'addsynapse-store-assistant' ) . '</p></div>';
			}
		}
		if ( ! $ready && ! RelayKeys::transport_ok() ) {
			echo '<div class="notice notice-error inline adsa-apppw" data-adsa-https><p><strong>' . esc_html__( 'Before you connect: this site does not use HTTPS', 'addsynapse-store-assistant' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'AddSynapse connects only to sites that use HTTPS. Switch the site address to https:// (Settings → General), then check again.', 'addsynapse-store-assistant' ) . '</p>';
			echo '<p class="adsa-buttons"><a class="button button-primary" href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Open General Settings', 'addsynapse-store-assistant' ) . '</a> <a class="button" href="' . esc_url( self::setup_url( 'mcp' ) ) . '">' . esc_html__( 'Check again', 'addsynapse-store-assistant' ) . '</a></p></div>';
		}
		self::progress( $steps );
		echo '<ol class="adsa-steps" data-path="mcp">';

		self::step_open( 'check', 1, __( 'Check the connection', 'addsynapse-store-assistant' ), $steps['check'][1] );
		echo '<p>' . esc_html__( 'Checks HTTPS, the REST API and the sign-in header of this site. Runs on your server only.', 'addsynapse-store-assistant' ) . '</p>';
		// Runs by itself when the page opens (home.js); «Re-check» runs it again.
		echo '<div class="adsa-check-result" aria-live="polite" data-adsa-autocheck></div>';
		echo '<p class="adsa-recheck"><button type="button" class="button-link adsa-check">' . esc_html__( 'Re-check', 'addsynapse-store-assistant' ) . '</button></p>';
		self::step_close();

		self::step_open( 'connect', 2, __( 'Connect this site to AddSynapse', 'addsynapse-store-assistant' ), $steps['connect'][1] );
		if ( $connected ) {
			// Already connected (owner 21/10: a second «Connect my site» made a second password) — no connect button here.
			// 1.3.5: also while AddSynapse has not used the new key yet (pending): a second Connect would make a second key.
			echo '<p class="adsa-state is-ok" data-connected="1"><span class="adsa-dot" aria-hidden="true"></span><span class="adsa-state__text">' . esc_html__( 'Connected ✓', 'addsynapse-store-assistant' ) . '</span></p>';
		} else {
			self::render_connect_button();
			$waiting = $status['connected']
				/* translators: %s: user login */
				? sprintf( __( 'Approved by %s.', 'addsynapse-store-assistant' ), (string) $status['detail']['user'] )
				: __( 'Waiting for your approval…', 'addsynapse-store-assistant' );
			echo '<p class="adsa-state' . ( $status['connected'] ? ' is-ok' : ' is-waiting' ) . '" data-state="connect" aria-live="polite"><span class="adsa-dot" aria-hidden="true"></span><span class="adsa-state__text">' . esc_html( $waiting ) . '</span></p>';
			// The older way, from the chat (the AI answers with the same confirm link) — kept for whoever starts there.
			echo '<details class="adsa-more" data-adsa-chat-connect><summary>' . esc_html__( 'Prefer to connect from your AI chat?', 'addsynapse-store-assistant' ) . '</summary>';
			echo '<p>' . esc_html__( 'Send this to your AI. It answers with a link to this plugin\'s page on your site — confirm the connection there.', 'addsynapse-store-assistant' ) . '</p>';
			self::copy_field( self::connect_prompt(), __( 'Prompt to send', 'addsynapse-store-assistant' ), __( 'Copy prompt', 'addsynapse-store-assistant' ) );
			echo '</details>';
		}
		self::step_close();

		echo '</ol>';

		// «Finish» is the main action once connected; before that «Connect to Claude» / «Connect to ChatGPT» are.
		self::finish( 'mcp', $connected ? __( 'Next: Home shows how to use it from Claude or ChatGPT.', 'addsynapse-store-assistant' ) : __( 'You can finish now and come back to these steps later from Home.', 'addsynapse-store-assistant' ), $connected );

		echo '<details class="adsa-more adsa-more--box"><summary>' . esc_html__( 'More details: what AddSynapse can do on this site', 'addsynapse-store-assistant' ) . '</summary><ul>';
		echo '<li>' . esc_html__( 'AddSynapse (addsynapse.com) is an external service. It connects only after you confirm it on this plugin\'s page, with a key this plugin creates for it.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'That key opens only this plugin\'s store tools: read the store, products, categories and sales totals (no customer details), and write changes you approved. It cannot reach users, plugins, settings, other REST routes or XML-RPC.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Changes are prepared as drafts on AddSynapse. They are written here only after you press Approve, are listed in the Approval log, and can be undone.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Disconnect at any time: AddSynapse → Home → «Disconnect AddSynapse».', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Deactivating or deleting this plugin also revokes the AddSynapse key (and any password WordPress issued to AddSynapse before), for all users of this site.', 'addsynapse-store-assistant' ) . '</li>';
		echo '</ul></details>';
	}

	/**
	 * «Done — go back to Claude» (from=claude, right after «Yes, connect»). Nothing to press here: the AddSynapse page in
	 * the other tab moves on by itself. A prompt to try, and links to Home and to the setup steps.
	 */
	private static function render_arrival_done(): void {
		self::render_allset( __( 'Done — go back to Claude.', 'addsynapse-store-assistant' ), __( 'This site is now connected to AddSynapse. Ask Claude something about your site.', 'addsynapse-store-assistant' ), false );
		echo '<p class="adsa-done__links"><a href="' . esc_url( self::url( array( 'view' => 'home' ) ) ) . '">' . esc_html__( 'Go to Home', 'addsynapse-store-assistant' ) . '</a> · <a href="' . esc_url( self::setup_url( 'mcp' ) ) . '">' . esc_html__( 'See the setup steps', 'addsynapse-store-assistant' ) . '</a></p>';
	}

	/**
	 * The first prompt to paste into Claude or ChatGPT: it names the app (1.3.9 — ChatGPT uses an app only when the
	 * prompt names it) and asks for something visible from this site. In the
	 * admin's language; Greek is built in (I18n\FirstPrompt) until translate.wordpress.org has it (the text is sent to
	 * the AI, so a Greek admin should get it in Greek).
	 */
	public static function first_prompt(): string {
		$site   = self::is_site();
		$prompt = $site
			? __( 'Use Site Manager for WordPress to show me 3 pages from my site.', 'addsynapse-store-assistant' )
			: __( 'Use Store Manager for WooCommerce to show me 3 products from my store.', 'addsynapse-store-assistant' );
		$source = $site
			? 'Use Site Manager for WordPress to show me 3 pages from my site.'
			: 'Use Store Manager for WooCommerce to show me 3 products from my store.';
		if ( $prompt === $source ) {
			$built_in = \Addsynapse\StoreAssistant\I18n\FirstPrompt::built_in( (string) get_user_locale(), $site );
			if ( '' !== $built_in ) {
				return $built_in;
			}
		}
		return $prompt;
	}

	/**
	 * Two more example requests for Claude or ChatGPT (store or plain site), under the first prompt.
	 *
	 * @return string[]
	 */
	private static function try_prompts(): array {
		return self::is_site()
			? array(
				__( 'Which pages have no SEO description?', 'addsynapse-store-assistant' ),
				__( 'What is in my main menu?', 'addsynapse-store-assistant' ),
			)
			: array(
				__( 'Which products have no image?', 'addsynapse-store-assistant' ),
				__( 'What were my best sellers last month?', 'addsynapse-store-assistant' ),
			);
	}

	/**
	 * «You're all set!» — the first prompt to copy (primary), open Claude or ChatGPT, two more requests to copy, and (collapsed) how to add AddSynapse
	 * to an AI app. Ordinary links the browser opens; nothing is sent by the server.
	 *
	 * @param string $title   Heading.
	 * @param string $lead    One line under it ('' = none).
	 * @param bool   $dismiss Show «Hide» (Home only).
	 */
	private static function render_allset( string $title, string $lead, bool $dismiss ): void {
		echo '<section class="adsa-allset" data-adsa-allset role="status" aria-labelledby="adsa-allset-title">';
		echo '<div class="adsa-allset__head"><span class="adsa-done__icon" aria-hidden="true">';
		self::print_icon( 'check' );
		echo '</span><h2 class="adsa-allset__title" id="adsa-allset-title">' . esc_html( $title ) . '</h2></div>';
		if ( '' !== $lead ) {
			echo '<p class="adsa-allset__lead">' . esc_html( $lead ) . '</p>';
		}
		// 1.3.6: ONE prompt first, large, with its own Copy button; then Open Claude; then two smaller examples.
		$first = self::first_prompt();
		$id    = 'adsa-copy-' . substr( md5( $first ), 0, 8 );
		echo '<div class="adsa-allset__first" data-adsa-first-prompt>';
		echo '<p class="adsa-allset__try"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html__( 'Copy this and paste it into Claude or ChatGPT:', 'addsynapse-store-assistant' ) . '</strong></label></p>';
		echo '<textarea readonly rows="2" id="' . esc_attr( $id ) . '" class="adsa-copy__src adsa-allset__prompt">' . esc_textarea( $first ) . '</textarea>';
		echo '<p class="adsa-buttons adsa-allset__open"><button type="button" class="button button-primary button-hero adsa-copy__btn" data-copy="' . esc_attr( $id ) . '">' . esc_html__( 'Copy prompt', 'addsynapse-store-assistant' ) . '</button> ';
		echo '<a class="button button-hero adsa-allset__claude" href="https://claude.ai/new" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Claude ↗', 'addsynapse-store-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) . '</span></a> ';
		echo '<a class="button button-hero adsa-allset__gpt" href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open ChatGPT ↗', 'addsynapse-store-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) . '</span></a></p>';
		echo '</div>';
		echo '<p class="adsa-allset__more">' . esc_html__( 'More to try:', 'addsynapse-store-assistant' ) . '</p>';
		echo '<ul class="adsa-allset__prompts">';
		foreach ( self::try_prompts() as $prompt ) {
			echo '<li>';
			self::copy_field( $prompt, __( 'Request to send', 'addsynapse-store-assistant' ), __( 'Copy', 'addsynapse-store-assistant' ) );
			echo '</li>';
		}
		echo '</ul>';
		echo '<details class="adsa-more adsa-addai" data-adsa-add-ways><summary>' . esc_html__( 'AddSynapse not in your Claude or ChatGPT yet?', 'addsynapse-store-assistant' ) . '</summary>';
		self::render_add_ways();
		echo '</details>';
		if ( $dismiss ) {
			echo '<form method="post" class="adsa-allset__hide" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_ALLSET_HIDE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_ALLSET_HIDE ) . '">';
			echo '<button type="submit" class="button-link">' . esc_html__( 'Hide', 'addsynapse-store-assistant' ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * Home: the «You're all set!» panel once connected — prominent for the first days (ALLSET_DAYS) or until hidden,
	 * then collapsed.
	 *
	 * @param array<string,mixed> $status SetupStatus::connection().
	 */
	private static function render_home_allset( array $status ): void {
		if ( empty( $status['connected'] ) ) {
			return;
		}
		$state = get_option( self::ALLSET_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if ( empty( $state['since'] ) ) {
			$state = array(
				'since'  => time(),
				'hidden' => false,
			);
			update_option( self::ALLSET_OPTION, $state, false );
		}
		$fresh = empty( $state['hidden'] ) && ( time() - (int) $state['since'] ) < self::ALLSET_DAYS * DAY_IN_SECONDS;
		if ( $fresh ) {
			self::render_allset( __( 'You\'re all set!', 'addsynapse-store-assistant' ), '', true );
			return;
		}
		echo '<details class="adsa-more adsa-allset-collapsed" data-adsa-allset-collapsed><summary>' . esc_html__( 'Try it in Claude or ChatGPT', 'addsynapse-store-assistant' ) . '</summary>';
		self::render_allset( __( 'You\'re all set!', 'addsynapse-store-assistant' ), '', false );
		echo '</details>';
	}

	/**
	 * «Hide» of the «You're all set!» panel (it stays, collapsed).
	 */
	public static function handle_allset_hide(): void {
		if ( ! current_user_can( Config::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_ALLSET_HIDE );
		$state           = get_option( self::ALLSET_OPTION, array() );
		$state           = is_array( $state ) ? $state : array();
		$state['since']  = (int) ( $state['since'] ?? time() );
		$state['hidden'] = true;
		update_option( self::ALLSET_OPTION, $state, false );
		wp_safe_redirect( self::url( array( 'view' => 'home' ) ) );
		exit;
	}


	/**
	 * The «confirm the account» screen (back from WordPress' «Authorize Application» screen, or AddSynapse's start link): the wizard's head and progress (step 1 done, step 2
	 * current) with the «confirm the account» card of RelayClaim.
	 */
	private static function render_relay_confirm(): void {
		if ( PendingOnboarding::cached_from_claude() ) {
			// Keep the answer for the «Done» screen after «Yes, connect» (the confirm may take a while).
			PendingOnboarding::keep();
		}
		if ( PendingOnboarding::cached_from_claude() && ! self::is_set_up() ) {
			// Not a lock (wp.org guideline 5): Cancel and both ways stay one click away.
			echo '<a class="adsa-back" href="' . esc_url( self::setup_url( 'choose' ) ) . '">';
			self::print_icon( 'back' );
			echo esc_html__( 'Other options', 'addsynapse-store-assistant' ) . '</a>';
		}
		echo '<div class="adsa-pathhead">';
		echo '<h2 class="adsa-pathhead__title">' . esc_html( self::is_site() ? __( 'Use your site from Claude or ChatGPT', 'addsynapse-store-assistant' ) : __( 'Use your store from Claude or ChatGPT', 'addsynapse-store-assistant' ) ) . '</h2>';
		if ( PendingOnboarding::cached_from_claude() ) {
			echo '<p class="adsa-pathhead__lead adsa-arrival" data-adsa-from="claude"><strong>' . esc_html__( 'You came from Claude — one click to finish.', 'addsynapse-store-assistant' ) . '</strong></p>';
		}
		echo '</div>';
		self::progress(
			array(
				'check'   => array( __( 'Check', 'addsynapse-store-assistant' ), true ),
				'connect' => array( __( 'Connect', 'addsynapse-store-assistant' ), false ),
			)
		);
		echo '<ol class="adsa-steps" data-path="mcp">';
		RelayClaim::render_confirm_card();
		echo '</ol>';
	}

	/**
	 * The line shown before the manager meets WordPress' own «Authorize Application» screen (owner 21/10: «why is this
	 * needed?»).
	 */
	public static function authorize_note(): void {
		echo '<p class="adsa-authnote">' . esc_html__( 'Next you\'ll see this plugin\'s page with the AddSynapse account — click «Yes, connect». It gives AddSynapse a key that works only for these tools; you can disconnect any time.', 'addsynapse-store-assistant' ) . '</p>';
	}

	/**
	 * Step 2: the one button — AddSynapse's connect page for this site (sign in or create a free account, approve the
	 * access, then confirm it on this plugin's page). An ordinary link the administrator's browser opens; nothing is sent
	 * by the server.
	 */
	private static function render_connect_button(): void {
		echo '<p>' . esc_html( self::is_site() ? __( 'Links this site to your AddSynapse account, so Claude or ChatGPT can work with it.', 'addsynapse-store-assistant' ) : __( 'Links this store to your AddSynapse account, so Claude or ChatGPT can work with it.', 'addsynapse-store-assistant' ) ) . '</p>';
		// 1.3.9 (owner 12/11): one button per AI app, so AddSynapse sends the manager back to the right one.
		printf(
			'<p class="adsa-buttons"><a class="button button-primary button-hero adsa-connect" data-adsa-ai="claude" href="%s">%s</a> <a class="button button-primary button-hero adsa-connect" data-adsa-ai="chatgpt" href="%s">%s</a></p>',
			esc_url( \Addsynapse\StoreAssistant\Account\Api::claude_connect_url( home_url( '/' ), 'claude' ) ),
			esc_html__( 'Connect to Claude', 'addsynapse-store-assistant' ),
			esc_url( \Addsynapse\StoreAssistant\Account\Api::claude_connect_url( home_url( '/' ), 'chatgpt' ) ),
			esc_html__( 'Connect to ChatGPT', 'addsynapse-store-assistant' )
		);
		echo '<p class="description adsa-connect__sub">' . esc_html__( 'Free AddSynapse account — you sign in or create one.', 'addsynapse-store-assistant' ) . '</p>';
		self::authorize_note();
	}

	/**
	 * Step 3 (collapsed): how to add AddSynapse to an AI app — «Store Manager for WooCommerce» in Claude's connector
	 * directory or in ChatGPT's apps (one click each, 1.3.9), or by address for other AI apps.
	 */
	private static function render_add_ways(): void {
		echo '<p>' . esc_html__( 'Once per AI account — the same app serves all your sites. Choose your AI app:', 'addsynapse-store-assistant' ) . '</p>';
		echo '<div class="adsa-addways">';

		echo '<section class="adsa-addway is-recommended" aria-labelledby="adsa-addway-claude">';
		echo '<h3 class="adsa-addway__title" id="adsa-addway-claude">' . esc_html__( 'Claude', 'addsynapse-store-assistant' ) . '</h3>';
		echo '<p>' . esc_html__( 'Opens «Store Manager for WooCommerce» in Claude\'s connector directory. Press Connect there and sign in to AddSynapse (or create a free account).', 'addsynapse-store-assistant' ) . '</p>';
		printf( '<p class="adsa-buttons"><a class="button adsa-ai" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></p>', esc_url( ConnectPage::CLAUDE_URL ), esc_html__( 'Add to Claude', 'addsynapse-store-assistant' ), esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) );
		echo '</section>';

		echo '<section class="adsa-addway is-recommended" aria-labelledby="adsa-addway-chatgpt">';
		echo '<h3 class="adsa-addway__title" id="adsa-addway-chatgpt">' . esc_html__( 'ChatGPT', 'addsynapse-store-assistant' ) . '</h3>';
		echo '<p>' . esc_html__( 'Opens «Store Manager for WooCommerce» in ChatGPT\'s apps. Press Connect there and sign in to AddSynapse (or create a free account).', 'addsynapse-store-assistant' ) . '</p>';
		printf( '<p class="adsa-buttons"><a class="button adsa-ai" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></p>', esc_url( ConnectPage::CHATGPT_URL ), esc_html__( 'Add to ChatGPT', 'addsynapse-store-assistant' ), esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) );
		echo '</section>';
		echo '</div>';

		echo '<details class="adsa-more"><summary>' . esc_html__( 'Cursor and other AI apps', 'addsynapse-store-assistant' ) . '</summary>';
		echo '<p>' . esc_html__( 'Add a remote MCP server (Streamable HTTP) with this address. When the app asks you to sign in, sign in to AddSynapse.', 'addsynapse-store-assistant' ) . '</p>';
		self::copy_field( ConnectPage::mcp_url(), __( 'MCP server address:', 'addsynapse-store-assistant' ), __( 'Copy', 'addsynapse-store-assistant' ) );
		echo '</details>';
	}

	/**
	 * Whether the chat was used with the own key (any AI call in the last 30 days).
	 */
	private static function chat_used(): bool {
		$summary = KeyUsage::summary();
		return (int) ( $summary['days30']['calls'] ?? 0 ) > 0;
	}

	/**
	 * Path B: the chat here with the manager's own AI key.
	 */
	private static function render_key(): void {
		$settings = KeyStore::settings();
		$active   = KeyStore::active();
		$notice   = self::flag( 'addsynapse_assistant_notice' );
		$steps    = array(
			'provider' => array( __( 'Provider', 'addsynapse-store-assistant' ), '' !== $settings['provider'] ),
			'key'      => array( __( 'API key', 'addsynapse-store-assistant' ), $active ),
			'try'      => array( __( 'Try the chat', 'addsynapse-store-assistant' ), $active && self::chat_used() ),
		);
		self::back_link();
		echo '<div class="adsa-pathhead">';
		echo '<h2 class="adsa-pathhead__title">' . esc_html__( 'Chat here in WordPress', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<p class="adsa-pathhead__lead">' . esc_html__( 'Three steps. Your key stays on this server; you pay your AI provider for what you use.', 'addsynapse-store-assistant' ) . '</p>';
		echo '</div>';
		self::progress( $steps );

		if ( ! OwnKeySection::can_manage() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Only an administrator can add the AI key. Ask an administrator of this site to open this page.', 'addsynapse-store-assistant' ) . '</p></div>';
		}
		$current  = '' !== $settings['provider'] ? $settings['provider'] : Models::PROVIDERS[0];
		$catalog  = OwnKeySection::model_catalog();
		$selected = '' !== $settings['model'] && Models::matches( $current, $settings['model'] ) ? $settings['model'] : Models::default_model( $current );
		$links    = array(
			'anthropic' => 'https://console.anthropic.com/settings/keys',
			'openai'    => 'https://platform.openai.com/api-keys',
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off" class="adsa-keyform">';
		wp_nonce_field( OwnKeySection::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( OwnKeySection::ACTION_SAVE ) . '">';
		echo '<input type="hidden" name="addsynapse_assistant_return" value="wizard">';
		echo '<ol class="adsa-steps" data-path="key">';

		self::step_open( 'provider', 1, __( 'Choose your AI provider', 'addsynapse-store-assistant' ), $steps['provider'][1] );
		echo '<fieldset class="adsa-providers"><legend class="screen-reader-text">' . esc_html__( 'AI provider', 'addsynapse-store-assistant' ) . '</legend>';
		$blurbs = array(
			'anthropic' => __( 'Claude models. Recommended for this assistant.', 'addsynapse-store-assistant' ),
			'openai'    => __( 'GPT models.', 'addsynapse-store-assistant' ),
		);
		foreach ( Models::PROVIDERS as $provider ) {
			echo '<label class="adsa-provider"><input type="radio" name="addsynapse_assistant_ai_provider" value="' . esc_attr( $provider ) . '"' . checked( $settings['provider'], $provider, false ) . ( OwnKeySection::can_manage() ? '' : ' disabled' ) . '>';
			echo '<span class="adsa-provider__body"><strong>' . esc_html( OwnKeySection::label( $provider ) ) . '</strong><span>' . esc_html( $blurbs[ $provider ] ?? '' ) . '</span></span></label>';
		}
		echo '</fieldset>';
		self::step_close();

		self::step_open( 'key', 2, __( 'Add your API key', 'addsynapse-store-assistant' ), $steps['key'][1] );
		OwnKeySection::render_notice( $notice );
		if ( $active ) {
			/* translators: 1: provider name, 2: model id. */
			echo '<p class="adsa-state is-ok"><span class="adsa-dot" aria-hidden="true"></span><span class="adsa-state__text">' . esc_html( sprintf( __( 'Active: %1$s, model %2$s.', 'addsynapse-store-assistant' ), OwnKeySection::label( $settings['provider'] ), $settings['model'] ) ) . '</span></p>';
		}
		if ( OwnKeySection::can_manage() ) {
			echo '<p class="adsa-field"><label for="addsynapse-assistant-ai-key">' . esc_html__( 'API key', 'addsynapse-store-assistant' ) . '</label>';
			if ( '' !== $settings['provider'] && KeyStore::from_constant( $settings['provider'] ) ) {
				/* translators: %s: PHP constant name. */
				echo '<span>' . esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'addsynapse-store-assistant' ), KeyStore::CONSTANTS[ $settings['provider'] ] ) ) . '</span>';
			} else {
				$hint = KeyStore::hint();
				/* translators: %s: last characters of the saved key. */
				$placeholder = '' !== $hint ? sprintf( __( 'Saved (%s) — leave empty to keep it', 'addsynapse-store-assistant' ), $hint ) : '';
				echo '<input type="password" class="regular-text" id="addsynapse-assistant-ai-key" name="addsynapse_assistant_ai_key" value="" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $placeholder ) . '" aria-describedby="adsa-key-help">';
			}
			echo '</p>';
			echo '<p class="description" id="adsa-key-help">' . esc_html__( 'Get a key:', 'addsynapse-store-assistant' ) . ' ';
			foreach ( $links as $provider => $link ) {
				echo '<a class="adsa-keylink" data-provider="' . esc_attr( $provider ) . '" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer"' . ( $provider === $current ? '' : ' hidden' ) . '>' . esc_html( OwnKeySection::label( $provider ) ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) . '</span></a>';
			}
			echo '</p>';
			echo '<p class="adsa-field"><label for="addsynapse-assistant-ai-model">' . esc_html__( 'Model', 'addsynapse-store-assistant' ) . '</label><select id="addsynapse-assistant-ai-model" name="addsynapse_assistant_ai_model" data-addsynapse-assistant-models="' . esc_attr( (string) wp_json_encode( $catalog ) ) . '">';
			foreach ( $catalog[ $current ] as $option ) {
				echo '<option value="' . esc_attr( $option['id'] ) . '"' . selected( $selected, $option['id'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
			}
			echo '</select></p>';
			echo '<details class="adsa-more"><summary>' . esc_html__( 'More details', 'addsynapse-store-assistant' ) . '</summary>';
			echo '<p class="description">' . esc_html__( 'When you chat, your message, the conversation, a summary of the site structure and the results of the tools the assistant uses for your question (for example product names, prices, stock and sales figures — never customer names, emails or phone numbers) are sent from this server to the AI provider you choose, under that provider\'s terms and privacy policy. An image you attach in the chat is sent with that message (a reduced copy; you can turn this off below: «The AI sees the images you upload»). Nothing is sent before you press «Save and test», and nothing goes to AddSynapse.', 'addsynapse-store-assistant' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Stored encrypted in the database (not autoloaded) and used only on this server; it is never shown again or sent to the browser. For extra safety define it in wp-config.php instead: ADDSYNAPSE_ASSISTANT_ANTHROPIC_KEY or ADDSYNAPSE_ASSISTANT_OPENAI_KEY.', 'addsynapse-store-assistant' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Anthropic:', 'addsynapse-store-assistant' ) . ' <a href="' . esc_url( 'https://www.anthropic.com/legal/commercial-terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'addsynapse-store-assistant' ) . '</a> · <a href="' . esc_url( 'https://www.anthropic.com/legal/privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'addsynapse-store-assistant' ) . '</a> — ';
			echo esc_html__( 'OpenAI:', 'addsynapse-store-assistant' ) . ' <a href="' . esc_url( 'https://openai.com/policies/business-terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'addsynapse-store-assistant' ) . '</a> · <a href="' . esc_url( 'https://openai.com/policies/privacy-policy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'addsynapse-store-assistant' ) . '</a></p>';
			echo '<p><label><input type="checkbox" name="addsynapse_assistant_ai_step_mode" value="1"' . checked( KeyStore::step_mode(), true, false ) . '> ' . esc_html__( 'One AI step per request (for hosts that stop long requests or buffer the live answer).', 'addsynapse-store-assistant' ) . '</label></p>';
			echo '</details>';
			echo '<p class="adsa-buttons"><button type="submit" class="button button-primary">' . esc_html__( 'Save and test', 'addsynapse-store-assistant' ) . '</button></p>';
		}//end if
		self::step_close();

		self::step_open( 'try', 3, __( 'Try the chat', 'addsynapse-store-assistant' ), $steps['try'][1] );
		echo '<p>' . esc_html__( 'Open the chat with a first question ready to send. It only reads the store.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<p class="adsa-sample">' . esc_html( AdminPage::sample_prompt() ) . '</p>';
		if ( $active ) {
			echo '<p class="adsa-buttons"><a class="button button-primary" href="' . esc_url( AdminPage::url( 'chat', array( 'try' => '1' ) ) ) . '">' . esc_html__( 'Open the chat', 'addsynapse-store-assistant' ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Available after the key test passes.', 'addsynapse-store-assistant' ) . '</p>';
		}
		self::step_close();
		echo '</ol></form>';

		self::finish( 'key', __( 'You can change the key, the model and the other options later in Settings.', 'addsynapse-store-assistant' ) );
	}

	/**
	 * Home: the status of both ways, quick actions, optional cards.
	 */
	private static function render_home(): void {
		$status   = SetupStatus::connection();
		$settings = KeyStore::settings();
		$active   = KeyStore::active();
		$welcome  = self::flag( 'welcome' );

		echo '<header class="adsa-homehead"><div><p class="adsa-hero__eyebrow">AddSynapse</p><h2 class="adsa-homehead__title">Store Assistant</h2></div>';
		if ( ProOffers::shown() ) {
			echo '<a class="button adsa-pro-btn" href="' . esc_url( ProOffers::url( 'home' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade to Pro', 'addsynapse-store-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) . '</span></a>';
		}
		echo '</header>';

		if ( 'disconnected' === $welcome ) {
			echo '<div class="adsa-welcome" role="status" data-adsa-disconnected>';
			self::print_icon( 'check' );
			echo '<div><strong>' . esc_html__( 'Disconnected.', 'addsynapse-store-assistant' ) . '</strong> ';
			echo esc_html__( 'AddSynapse can no longer reach this site. Connect again any time from your AI chat.', 'addsynapse-store-assistant' ) . '</div></div>';
		} elseif ( 'connected' === $welcome ) {
			// RelayClaim: the approval came back twice (refresh/back) and AddSynapse had already taken the first one.
			echo '<div class="adsa-welcome" role="status">';
			self::print_icon( 'check' );
			echo '<div><strong>' . esc_html__( 'Already connected ✓', 'addsynapse-store-assistant' ) . '</strong> ';
			echo esc_html__( 'This site is connected to AddSynapse. Go back to Claude or ChatGPT and continue there.', 'addsynapse-store-assistant' ) . '</div></div>';
		} elseif ( 'key' === $welcome || ( 'mcp' === $welcome && empty( $status['connected'] ) ) ) {
			echo '<div class="adsa-welcome" role="status">';
			self::print_icon( 'check' );
			echo '<div><strong>' . esc_html__( 'You are all set.', 'addsynapse-store-assistant' ) . '</strong> ';
			echo esc_html( 'mcp' === $welcome ? __( 'Ask Claude or ChatGPT about your store. Changes come back as drafts you approve.', 'addsynapse-store-assistant' ) : __( 'Ask the assistant in the Chat. Changes come back as drafts you approve.', 'addsynapse-store-assistant' ) ) . '</div></div>';
		}

		self::render_home_allset( $status );
		Announcements::render( 'home' );
		ReviewRequest::render( 'home' );

		echo '<div class="adsa-ways">';
		// Way A.
		echo '<section class="adsa-way" aria-labelledby="adsa-way-mcp"><div class="adsa-way__top"><span class="adsa-choice__icon">';
		self::print_icon( 'cloud' );
		echo '</span><h3 id="adsa-way-mcp">' . esc_html__( 'Claude or ChatGPT', 'addsynapse-store-assistant' ) . '</h3>';
		self::pill( (bool) $status['connected'], $status['connected'] ? __( 'Connected', 'addsynapse-store-assistant' ) : __( 'Not set up', 'addsynapse-store-assistant' ) );
		echo '</div>';
		if ( $status['connected'] ) {
			/* translators: %s: user login */
			echo '<p>' . esc_html( sprintf( __( 'Approved by %s.', 'addsynapse-store-assistant' ), (string) $status['detail']['user'] ) ) . ' ';
			echo esc_html(
				null !== $status['detail']['last_used']
					/* translators: %s: time difference, e.g. «5 mins» */
					? sprintf( __( 'Last used %s ago.', 'addsynapse-store-assistant' ), human_time_diff( (int) $status['detail']['last_used'] ) )
					: __( 'Not used yet.', 'addsynapse-store-assistant' )
			) . '</p>';
			// 1.3.2: the plan and today's count (as AddSynapse last reported it) — and no second approve pushed here.
			echo '<p class="adsa-plan" data-adsa-plan>' . esc_html( PlanInfo::line() ) . ' <a href="' . esc_url( Api::BILLING_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Change plan', 'addsynapse-store-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) . '</span></a></p>';
			echo '<p>' . esc_html( self::is_site() ? __( 'Nothing else to do here: ask Claude or ChatGPT about your site.', 'addsynapse-store-assistant' ) : __( 'Nothing else to do here: ask Claude or ChatGPT about your store.', 'addsynapse-store-assistant' ) ) . '</p>';
			echo '<p class="adsa-buttons"><a class="button-link" href="' . esc_url( SettingsPage::url( array( 'view' => 'log' ) ) ) . '">' . esc_html__( 'Approval log', 'addsynapse-store-assistant' ) . '</a></p>';
			echo '<details class="adsa-more" data-adsa-another><summary>' . esc_html__( 'Connect another site or AI account', 'addsynapse-store-assistant' ) . '</summary>';
			echo '<p class="adsa-label">' . esc_html__( 'Only for a different AI account or app. In that chat, send:', 'addsynapse-store-assistant' ) . '</p>';
			self::copy_field( self::connect_prompt(), __( 'Prompt to send', 'addsynapse-store-assistant' ), __( 'Copy prompt', 'addsynapse-store-assistant' ) );
			echo '<p><a href="' . esc_url( self::setup_url( 'mcp' ) ) . '">' . esc_html__( 'Setup steps', 'addsynapse-store-assistant' ) . '</a></p>';
			echo '</details>';
			if ( current_user_can( 'manage_options' ) ) {
				echo '<form method="post" class="adsa-disconnect" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-adsa-disconnect>';
				wp_nonce_field( self::ACTION_DISCONNECT );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISCONNECT ) . '">';
				echo '<button type="submit" class="button-link button-link-delete" data-confirm="' . esc_attr__( 'Disconnect AddSynapse? Claude or ChatGPT will no longer reach this site until you connect again.', 'addsynapse-store-assistant' ) . '">' . esc_html__( 'Disconnect AddSynapse', 'addsynapse-store-assistant' ) . '</button>';
				echo '</form>';
			}
		} else {
			echo '<p>' . esc_html( self::is_site() ? __( 'Talk to your site from Claude or ChatGPT. No API key needed.', 'addsynapse-store-assistant' ) : __( 'Talk to your store from Claude or ChatGPT. No API key needed.', 'addsynapse-store-assistant' ) ) . '</p>';
			echo '<p class="adsa-plan">' . esc_html( PlanInfo::line() ) . '</p>';
			echo '<p class="adsa-buttons"><a class="button button-primary" href="' . esc_url( self::setup_url( 'mcp' ) ) . '">' . esc_html( $active ? __( 'Use this way too', 'addsynapse-store-assistant' ) : __( 'Set up', 'addsynapse-store-assistant' ) ) . '</a></p>';
		}//end if
		echo '</section>';

		// Way B.
		echo '<section class="adsa-way" aria-labelledby="adsa-way-key"><div class="adsa-way__top"><span class="adsa-choice__icon">';
		self::print_icon( 'chat' );
		echo '</span><h3 id="adsa-way-key">' . esc_html__( 'Chat here in WordPress', 'addsynapse-store-assistant' ) . '</h3>';
		self::pill( $active, $active ? __( 'Ready', 'addsynapse-store-assistant' ) : __( 'Not set up', 'addsynapse-store-assistant' ) );
		echo '</div>';
		if ( $active ) {
			/* translators: 1: provider name, 2: model id. */
			echo '<p>' . esc_html( sprintf( __( 'Active: %1$s, model %2$s.', 'addsynapse-store-assistant' ), OwnKeySection::label( $settings['provider'] ), $settings['model'] ) ) . '</p>';
			echo '<p class="adsa-buttons"><a class="button button-primary" href="' . esc_url( AdminPage::url() ) . '">' . esc_html__( 'Open the chat', 'addsynapse-store-assistant' ) . '</a> <a class="button" href="' . esc_url( SettingsPage::url() . '#addsynapse-assistant-own-key' ) . '">' . esc_html__( 'Key and model', 'addsynapse-store-assistant' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'A chat inside wp-admin, with your own Anthropic or OpenAI key.', 'addsynapse-store-assistant' ) . '</p>';
			echo '<p class="adsa-buttons"><a class="button' . ( $status['connected'] ? '' : ' button-primary' ) . '" href="' . esc_url( self::setup_url( 'key' ) ) . '">' . esc_html( $status['connected'] ? __( 'Use this way too', 'addsynapse-store-assistant' ) : __( 'Set up', 'addsynapse-store-assistant' ) ) . '</a></p>';
		}
		echo '</section></div>';

		// Opt-in cards: only here (after setup), never on the first wizard screen.
		if ( self::is_set_up( $status ) || self::state()['done'] ) {
			echo '<div class="adsa-optins">';
			Usage::render( 'home' );
			OptIn::render( 'home' );
			echo '</div>';
		}

		echo '<nav class="adsa-quick" aria-label="' . esc_attr__( 'Quick actions', 'addsynapse-store-assistant' ) . '">';
		$quick = array(
			array( AdminPage::url(), __( 'Chat', 'addsynapse-store-assistant' ) ),
			array( SettingsPage::url(), __( 'Settings', 'addsynapse-store-assistant' ) ),
			array( SettingsPage::url( array( 'view' => 'log' ) ), __( 'Approval log', 'addsynapse-store-assistant' ) ),
			array( self::setup_url( 'choose' ), __( 'Setup wizard', 'addsynapse-store-assistant' ) ),
		);
		foreach ( $quick as $q ) {
			echo '<a href="' . esc_url( $q[0] ) . '">' . esc_html( $q[1] ) . '</a>';
		}
		echo '</nav>';

		if ( ProOffers::shown() ) {
			echo '<aside class="adsa-procard"><div><strong>' . esc_html__( 'Store Assistant Pro', 'addsynapse-store-assistant' ) . '</strong> ';
			echo esc_html__( 'A separate add-on for whole categories at once, CSV imports, coupons, appearance and SEO at scale.', 'addsynapse-store-assistant' ) . '</div>';
			echo '<a class="button" href="' . esc_url( ProOffers::url( 'home-card' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See Pro', 'addsynapse-store-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) . '</span></a></aside>';
		}

		$others = AddsynapseMenu::products();
		unset( $others['ai-shop-assistant'] );
		if ( $others ) {
			echo '<h2 class="adsa-section-title">' . esc_html__( 'Other AddSynapse plugins', 'addsynapse-store-assistant' ) . '</h2>';
			AddsynapseMenu::render_cards( $others );
		}
	}

	/**
	 * Status pill.
	 *
	 * @param bool   $ok    Green.
	 * @param string $label Text.
	 */
	private static function pill( bool $ok, string $label ): void {
		echo '<span class="adsa-pill' . ( $ok ? ' is-ok' : '' ) . '">' . esc_html( $label ) . '</span>';
	}
}
