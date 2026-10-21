<?php
/**
 * Store Assistant admin page (AddSynapse → Store Assistant, with or without WooCommerce).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Account\Announcements;
use Addsynapse\StoreAssistant\Account\OptIn;
use Addsynapse\StoreAssistant\Account\Usage;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\Rest\RestRoutes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the submenu and enqueues the React app.
 */
final class AdminPage {

	public const SLUG   = 'addsynapse-store-assistant';
	public const HANDLE = 'addsynapse-assistant-admin';

	/** Stylesheet of the server-rendered screens (assets/admin.css). */
	public const STYLE_HANDLE = 'addsynapse-assistant-screens';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), AddsynapseMenu::PRIORITY_SUBMENU );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * AddSynapse → Store Assistant (admin.php?page=addsynapse-store-assistant), in both modes (shop / plain site),
	 * under the shared top-level menu (docs/ADMIN-MENU.md).
	 */
	public function add_menu(): void {
		$this->hook = AddsynapseMenu::add_page(
			'Store Assistant',
			__( 'Chat', 'addsynapse-store-assistant' ),
			Config::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
		if ( $this->hook ) {
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Adds a body class on our screen only.
	 *
	 * @param string $classes Classes.
	 */
	public function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && $screen->id === $this->hook ) {
			$classes .= ' aiops-screen';
		}
		return $classes;
	}

	/**
	 * The small stylesheet of the server-rendered screens (Settings, approval page, Overview). `?ver=` = version +
	 * file time, so a cached copy never outlives a change (guide §2.5).
	 */
	public static function enqueue_admin_css(): void {
		$file = ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'assets/admin.css';
		wp_enqueue_style( self::STYLE_HANDLE, ADDSYNAPSE_ASSISTANT_PLUGIN_URL . 'assets/admin.css', array(), ADDSYNAPSE_ASSISTANT_VERSION . '.' . ( is_readable( $file ) ? (int) filemtime( $file ) : 0 ) );
	}

	/**
	 * Enqueues the app on our screen.
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook ) {
			return;
		}
		// The opt-in card / announcements above the chat are server-rendered.
		self::enqueue_admin_css();
		// Chat and "Change history" share the bundle.
		$asset_file = ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			ADDSYNAPSE_ASSISTANT_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style(
			self::HANDLE,
			ADDSYNAPSE_ASSISTANT_PLUGIN_URL . 'build/index.css',
			array( 'wp-components' ),
			$asset['version']
		);
		wp_set_script_translations( self::HANDLE, 'addsynapse-store-assistant' );

		$user = wp_get_current_user();
		wp_add_inline_script(
			self::HANDLE,
			'window.addsynapseAssistant = ' . wp_json_encode(
				array(
					'restRoot'      => esc_url_raw( rest_url( RestRoutes::NAMESPACE . '/' ) ),
					'adminUrl'      => esc_url_raw( admin_url() ),
					'homeUrl'       => esc_url_raw( home_url( '/' ) ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'locale'        => str_replace( '_', '-', get_user_locale() ),
					'userId'        => (int) $user->ID,
					'userName'      => $user->display_name,
					'debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'version'       => ADDSYNAPSE_ASSISTANT_VERSION,
					'streaming'     => Config::streaming_enabled(),
					'settingsUrl'   => esc_url_raw( SettingsPage::url() ),
					// Home: the setup wizard when the AI is not set up yet.
					'setupUrl'      => esc_url_raw( HomePage::setup_url( 'key' ) ),
					// «Try the chat» of the wizard: a fixed first question in the composer (never text from the URL).
					'initialPrompt' => '1' === (string) filter_input( INPUT_GET, 'try', FILTER_SANITIZE_NUMBER_INT ) ? self::sample_prompt() : '',
					'chatUrl'       => esc_url_raw( self::url() ),
					'timezone'      => wp_timezone_string(),
					// `shop` (WooCommerce) | `site` (plain WordPress): suggestions, /help, card links.
					'siteType'      => Environment::site_type(),
					'canSettings'   => current_user_can( Config::settings_capability() ),
					'voice'         => SettingsPage::voice_enabled(),
					// Links to the separate Store Assistant Pro add-on (none once another plugin provides it).
					'pro'           => ProOffers::client_state(),
					// Images from the chat: only when WordPress lets this user upload media.
					'images'        => array(
						'enabled'  => current_user_can( 'upload_files' ),
						'maxBytes' => Config::image_max_bytes(),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The first question the wizard puts in the composer (reads only).
	 */
	public static function sample_prompt(): string {
		return __( 'Which of my products have no image or no description?', 'addsynapse-store-assistant' );
	}

	/**
	 * URL of the chat.
	 *
	 * @param string               $tab  Kept for compatibility (only «chat»).
	 * @param array<string,string> $args Extra query args.
	 */
	public static function url( string $tab = 'chat', array $args = array() ): string {
		unset( $tab );
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Where a form posted from one of our screens returns.
	 *
	 * @param string $tab home | chat | settings.
	 */
	public static function back_url( string $tab ): string {
		if ( 'settings' === $tab ) {
			return SettingsPage::url();
		}
		return 'home' === $tab ? HomePage::url() : self::url();
	}

	/**
	 * Page markup (the React app mounts into #addsynapse-assistant-root).
	 */
	public function render(): void {
		if ( ! current_user_can( Config::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ) );
		}
		if ( ! is_readable( ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'build/index.js' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Store Assistant assets are missing. Run "npm run build" in the plugin folder.', 'addsynapse-store-assistant' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap aiops-wrap"><h1 class="screen-reader-text">' . esc_html( 'Store Assistant' ) . '</h1>';
		// Inside a container: the chat screen hides stray admin notices that are direct children of the wrap.
		echo '<div class="aiops-account-area">';
		Announcements::render( 'chat' );
		ReviewRequest::render( 'chat' );
		OptIn::render( 'chat' );
		Usage::render( 'chat' );
		echo '</div><div id="addsynapse-assistant-root" class="aiops-root"></div></div>';
	}
}
