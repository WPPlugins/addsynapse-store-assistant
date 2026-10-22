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
use Addsynapse\StoreAssistant\Account\Usage;
use Addsynapse\StoreAssistant\Brain\Models;
use Addsynapse\StoreAssistant\Byok\KeyStore;
use Addsynapse\StoreAssistant\Byok\Usage as KeyUsage;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\Remote\PlanInfo;
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

	/** Script/style handle. */
	public const HANDLE = 'addsynapse-assistant-home';

	/** The wizard views (query arg `setup`). */
	public const VIEWS = array( 'choose', 'mcp', 'key' );

	/**
	 * Registers hooks (wp-admin only).
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_FINISH, array( $this, 'handle_finish' ) );
		// Before WordPress checks access to the requested page: old addresses keep working.
		add_action( 'admin_menu', array( self::class, 'legacy_redirects' ), 1 );
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
		return 'choose';
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
		$view = self::view();
		echo '<div class="wrap adsa-home adsa-view-' . esc_attr( $view ) . '">';
		// WordPress moves admin notices after the first heading; keep them above our hero.
		echo '<h1 class="screen-reader-text">' . esc_html( 'AddSynapse Store Assistant' ) . '</h1><hr class="wp-header-end">';
		if ( 'choose' === $view ) {
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
		$target = self::state()['done'] || self::is_set_up() ? self::url() : self::setup_url( 'choose' );
		echo '<a class="adsa-back" href="' . esc_url( $target ) . '">';
		self::print_icon( 'back' );
		echo esc_html( self::state()['done'] || self::is_set_up() ? __( 'Back to Home', 'addsynapse-store-assistant' ) : __( 'Choose another way', 'addsynapse-store-assistant' ) ) . '</a>';
	}

	/**
	 * The «Finish» form of a path.
	 *
	 * @param string $path mcp | key.
	 * @param string $note Text next to the button.
	 */
	private static function finish( string $path, string $note ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="adsa-finish">';
		wp_nonce_field( self::ACTION_FINISH );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_FINISH ) . '">';
		echo '<input type="hidden" name="addsynapse_assistant_path" value="' . esc_attr( $path ) . '">';
		echo '<button type="submit" class="button button-primary button-hero adsa-finish__btn">' . esc_html__( 'Finish', 'addsynapse-store-assistant' ) . '</button>';
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
			self::is_site() ? __( 'Talk to your site from claude.ai or ChatGPT (beta).', 'addsynapse-store-assistant' ) : __( 'Talk to your store from claude.ai or ChatGPT (beta).', 'addsynapse-store-assistant' ),
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
		$status  = SetupStatus::connection();
		$confirm = self::is_site()
			? __( 'Using AddSynapse, tell me my site name and how many pages it has. Change nothing.', 'addsynapse-store-assistant' )
			: __( 'Using AddSynapse, tell me my store name and how many products it has. Change nothing.', 'addsynapse-store-assistant' );
		// Connected for sure (AddSynapse confirmed the password, or used it): every step is done.
		$ready = ! empty( $status['ready'] );
		$steps = array(
			'check'   => array( __( 'Check', 'addsynapse-store-assistant' ), $ready || (bool) $status['checked'] ),
			// Done only when the site is connected for sure, or the manager says so («I've added it») — never on a click of «Add to Claude» or «Copy».
			'add'     => array( __( 'Add to your AI', 'addsynapse-store-assistant' ), $ready ),
			'connect' => array( __( 'Connect', 'addsynapse-store-assistant' ), (bool) $status['connected'] ),
			'confirm' => array( __( 'Confirm', 'addsynapse-store-assistant' ), $ready || (bool) $status['used'] ),
		);
		self::back_link();
		echo '<div class="adsa-pathhead">';
		echo '<h2 class="adsa-pathhead__title">' . esc_html( self::is_site() ? __( 'Use your site from Claude or ChatGPT', 'addsynapse-store-assistant' ) : __( 'Use your store from Claude or ChatGPT', 'addsynapse-store-assistant' ) ) . '</h2>';
		echo '<p class="adsa-pathhead__lead">' . esc_html__( 'Four short steps, about five minutes. Each step turns green on its own.', 'addsynapse-store-assistant' ) . '</p>';
		echo '</div>';
		self::progress( $steps );
		echo '<ol class="adsa-steps" data-path="mcp">';

		self::step_open( 'check', 1, __( 'Check the connection', 'addsynapse-store-assistant' ), $steps['check'][1] );
		echo '<p>' . esc_html__( 'Checks HTTPS, Application Passwords, the REST API and the sign-in headers of this site. Runs on your server only.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<button type="button" class="button button-primary adsa-check">' . esc_html__( 'Check site connectivity', 'addsynapse-store-assistant' ) . '</button>';
		echo '<div class="adsa-check-result" aria-live="polite"></div>';
		self::step_close();

		self::step_open( 'add', 2, __( 'Add AddSynapse to your AI', 'addsynapse-store-assistant' ), $steps['add'][1] );
		self::render_add_ways();
		self::step_close();

		self::step_open( 'connect', 3, __( 'Connect this site from the chat', 'addsynapse-store-assistant' ), $steps['connect'][1] );
		if ( $ready ) {
			// Already connected (owner 21/10: a second «Connect my site» made a second password) — no connect prompt here.
			echo '<p class="adsa-state is-ok" data-connected="1"><span class="adsa-dot" aria-hidden="true"></span><span class="adsa-state__text">' . esc_html__( 'Connected ✓ — try asking your AI:', 'addsynapse-store-assistant' ) . '</span></p>';
			self::copy_field( $confirm, __( 'Prompt to send', 'addsynapse-store-assistant' ), __( 'Copy prompt', 'addsynapse-store-assistant' ) );
		} else {
			echo '<p>' . esc_html__( 'Send this to your AI. It answers with a link to this site\'s «Authorize Application» screen — approve it there.', 'addsynapse-store-assistant' ) . '</p>';
			self::copy_field( self::connect_prompt(), __( 'Prompt to send', 'addsynapse-store-assistant' ), __( 'Copy prompt', 'addsynapse-store-assistant' ) );
			self::authorize_note();
			$waiting = $status['connected']
				/* translators: %s: user login */
				? sprintf( __( 'Approved by %s.', 'addsynapse-store-assistant' ), (string) $status['detail']['user'] )
				: __( 'Waiting for your approval…', 'addsynapse-store-assistant' );
			echo '<p class="adsa-state' . ( $status['connected'] ? ' is-ok' : ' is-waiting' ) . '" data-state="connect" aria-live="polite"><span class="adsa-dot" aria-hidden="true"></span><span class="adsa-state__text">' . esc_html( $waiting ) . '</span></p>';
		}
		self::step_close();

		self::step_open( 'confirm', 4, self::is_site() ? __( 'Confirm your AI can read the site', 'addsynapse-store-assistant' ) : __( 'Confirm your AI can read the store', 'addsynapse-store-assistant' ), $steps['confirm'][1] );
		echo '<p>' . esc_html__( 'Ask one question that changes nothing. This step turns green when your AI reads the store.', 'addsynapse-store-assistant' ) . '</p>';
		self::copy_field( $confirm, __( 'Prompt to send', 'addsynapse-store-assistant' ), __( 'Copy prompt', 'addsynapse-store-assistant' ) );
		$confirmed = $status['used']
			? __( 'Your AI read the store. You are all set.', 'addsynapse-store-assistant' )
			: ( $ready ? __( 'AddSynapse checked the connection. You are all set.', 'addsynapse-store-assistant' ) : __( 'Waiting for the first answer…', 'addsynapse-store-assistant' ) );
		echo '<p class="adsa-state' . ( $status['used'] || $ready ? ' is-ok' : '' ) . '" data-state="confirm" aria-live="polite"><span class="adsa-dot" aria-hidden="true"></span><span class="adsa-state__text">' . esc_html( $confirmed ) . '</span></p>';
		echo '<p><button type="button" class="button-link adsa-mark" data-mark="confirm">' . esc_html__( 'It worked — mark as done', 'addsynapse-store-assistant' ) . '</button></p>';
		self::step_close();
		echo '</ol>';

		self::finish( 'mcp', __( 'You can finish now and come back to these steps later from Home.', 'addsynapse-store-assistant' ) );

		echo '<details class="adsa-more adsa-more--box"><summary>' . esc_html__( 'More details: what AddSynapse can do on this site', 'addsynapse-store-assistant' ) . '</summary><ul>';
		echo '<li>' . esc_html__( 'AddSynapse (addsynapse.com) is an external service. It connects only after you approve it on the «Authorize Application» screen, with a password WordPress creates for it.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'That password opens only this plugin\'s store tools: read the store, products, categories and sales totals (no customer details), and write prices you approved. It cannot reach users, plugins, settings or XML-RPC.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Changes are prepared as drafts on AddSynapse. They are written here only after you press Approve, are listed in the Approval log, and can be undone.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Disconnect at any time: Users → Profile → Application Passwords → revoke «AddSynapse».', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Deactivating or deleting this plugin also revokes every password WordPress issued to AddSynapse, for all users of this site.', 'addsynapse-store-assistant' ) . '</li>';
		echo '</ul></details>';
	}

	/**
	 * The line shown before the manager meets WordPress' own «Authorize Application» screen (owner 21/10: «why is this
	 * needed?»).
	 */
	public static function authorize_note(): void {
		echo '<p class="adsa-authnote">' . esc_html__( 'Next you\'ll see WordPress\' own approval screen — click «Yes, I approve». It gives AddSynapse a password that works only for these tools; you can revoke it any time.', 'addsynapse-store-assistant' ) . '</p>';
	}

	/**
	 * Step 2: two clear ways to add AddSynapse — Claude's connector directory (one click), or a custom connector by address
	 * (ChatGPT beta, Claude, other AI apps).
	 */
	private static function render_add_ways(): void {
		echo '<p>' . esc_html__( 'Once per AI account — the same address serves all your sites. Choose one way:', 'addsynapse-store-assistant' ) . '</p>';
		echo '<div class="adsa-addways">';

		echo '<section class="adsa-addway is-recommended" aria-labelledby="adsa-addway-claude">';
		echo '<h3 class="adsa-addway__title" id="adsa-addway-claude">' . esc_html__( 'Claude — one click from the connector directory', 'addsynapse-store-assistant' ) . '</h3>';
		echo '<p>' . esc_html__( 'Opens AddSynapse in Claude\'s connector directory. Press Connect there and sign in to AddSynapse (or create a free account).', 'addsynapse-store-assistant' ) . '</p>';
		printf( '<p class="adsa-buttons"><a class="button button-primary adsa-ai" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></p>', esc_url( \Addsynapse\StoreAssistant\Account\Api::claude_connect_url( home_url( '/' ) ) ), esc_html__( 'Add to Claude', 'addsynapse-store-assistant' ), esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) );
		echo '</section>';

		echo '<section class="adsa-addway" aria-labelledby="adsa-addway-manual">';
		echo '<h3 class="adsa-addway__title" id="adsa-addway-manual">' . esc_html__( 'Manually — ChatGPT (Beta) and other AI apps', 'addsynapse-store-assistant' ) . '</h3>';
		echo '<p>' . esc_html__( 'Add AddSynapse as a custom connector with this MCP server address:', 'addsynapse-store-assistant' ) . '</p>';
		self::copy_field( ConnectPage::mcp_url(), __( 'MCP server address:', 'addsynapse-store-assistant' ), __( 'Copy', 'addsynapse-store-assistant' ) );

		echo '<details class="adsa-more"><summary>' . esc_html__( 'Claude (custom connector)', 'addsynapse-store-assistant' ) . '</summary><ol class="adsa-gpt__steps">';
		echo '<li>' . esc_html__( 'In Claude open Settings → Connectors → Add custom connector.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Name it AddSynapse, paste the address above and press Add.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Press Connect and sign in to AddSynapse (or create a free account).', 'addsynapse-store-assistant' ) . '</li>';
		echo '</ol></details>';

		echo '<details class="adsa-more"><summary>' . esc_html__( 'ChatGPT', 'addsynapse-store-assistant' ) . ' <span class="adsa-badge">' . esc_html__( 'Beta', 'addsynapse-store-assistant' ) . '</span></summary>';
		echo '<p class="description">' . esc_html__( 'This needs a ChatGPT plan that offers developer mode, and menu names may differ slightly. If the chat shows no Approve button, open the link it gives you to approve the change on your AddSynapse Changes page.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<ol class="adsa-gpt__steps">';
		echo '<li>' . esc_html__( 'In ChatGPT open Settings → Apps & Connectors → Advanced settings and turn on Developer mode.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Back in Apps & Connectors press Create.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Name: AddSynapse. MCP server URL: paste the address above.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Authentication: OAuth. Tick «I trust this application» and press Create.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'Sign in to AddSynapse (or create a free account) and approve the access.', 'addsynapse-store-assistant' ) . '</li>';
		echo '<li>' . esc_html__( 'In a new chat press + → Developer mode, turn on AddSynapse, then continue with step 3 below.', 'addsynapse-store-assistant' ) . '</li>';
		echo '</ol>';
		printf( '<p><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></p>', esc_url( ConnectPage::CHATGPT_URL ), esc_html__( 'Open ChatGPT', 'addsynapse-store-assistant' ), esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) );
		echo '</details>';

		echo '<details class="adsa-more"><summary>' . esc_html__( 'Cursor and other AI apps', 'addsynapse-store-assistant' ) . '</summary>';
		echo '<p>' . esc_html__( 'Add a remote MCP server (Streamable HTTP) with the address above. When the app asks you to sign in, sign in to AddSynapse.', 'addsynapse-store-assistant' ) . '</p>';
		echo '</details>';
		echo '</section>';
		echo '</div>';

		echo '<p class="adsa-added"><button type="button" class="button adsa-mark" data-mark="add">' . esc_html__( 'I\'ve added it', 'addsynapse-store-assistant' ) . '</button> <span class="description">' . esc_html__( 'This step also turns green on its own once the site is connected.', 'addsynapse-store-assistant' ) . '</span></p>';
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

		if ( 'connected' === $welcome ) {
			// RelayClaim: the approval came back twice (refresh/back) and AddSynapse had already taken the first one.
			echo '<div class="adsa-welcome" role="status">';
			self::print_icon( 'check' );
			echo '<div><strong>' . esc_html__( 'Already connected ✓', 'addsynapse-store-assistant' ) . '</strong> ';
			echo esc_html__( 'This site is connected to AddSynapse. Go back to Claude or ChatGPT and continue there.', 'addsynapse-store-assistant' ) . '</div></div>';
		} elseif ( in_array( $welcome, array( 'mcp', 'key' ), true ) ) {
			echo '<div class="adsa-welcome" role="status">';
			self::print_icon( 'check' );
			echo '<div><strong>' . esc_html__( 'You are all set.', 'addsynapse-store-assistant' ) . '</strong> ';
			echo esc_html( 'mcp' === $welcome ? __( 'Ask Claude or ChatGPT about your store. Changes come back as drafts you approve.', 'addsynapse-store-assistant' ) : __( 'Ask the assistant in the Chat. Changes come back as drafts you approve.', 'addsynapse-store-assistant' ) ) . '</div></div>';
		}

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
		} else {
			echo '<p>' . esc_html( self::is_site() ? __( 'Talk to your site from claude.ai or ChatGPT (beta). No API key needed.', 'addsynapse-store-assistant' ) : __( 'Talk to your store from claude.ai or ChatGPT (beta). No API key needed.', 'addsynapse-store-assistant' ) ) . '</p>';
			echo '<p class="adsa-plan">' . esc_html( PlanInfo::line() ) . '</p>';
			echo '<p class="adsa-buttons"><a class="button button-primary" href="' . esc_url( self::setup_url( 'mcp' ) ) . '">' . esc_html( $active ? __( 'Use this way too', 'addsynapse-store-assistant' ) : __( 'Set up', 'addsynapse-store-assistant' ) ) . '</a></p>';
		}
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
