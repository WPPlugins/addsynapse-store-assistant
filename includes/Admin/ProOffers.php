<?php
/**
 * Links to Store Assistant Pro (the separate, paid add-on): one place for every «See Pro» / «Get Pro» link.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Information only (wordpress.org guidelines 5 and 11): nothing in this plugin is locked, checks a licence or waits for
 * the add-on. The links are plain links to the product page (opened by the manager), placed where the plugin already lists
 * what it can do — the «Upgrade to Pro» item of our own menu, «Get Pro» in our row of the Plugins list, the Pro card in
 * Settings, the capability list of the empty chat and a «See Pro» button under the chat's answer to a request only the
 * add-on makes. No notice, no banner on other screens, no timer.
 *
 * Every link carries `utm_source=<spot>&utm_medium=wp-admin&utm_campaign=gopro`, one spot per placement, and goes to the
 * page in the manager's language when there is one (Greek → /el/).
 *
 * Another plugin that provides the Pro features (the add-on, once it is licensed) turns every offer off with the neutral
 * filter `addsynapse_assistant_show_pro_offers` (bool, default true) — this plugin never looks for it itself.
 */
final class ProOffers {

	/** Product page (English) and the languages that have their own page. */
	public const PAGE_URL = 'https://addsynapse.com/store-assistant';

	/** Locale prefix → page. */
	public const LOCALE_PAGES = array(
		'el' => 'https://addsynapse.com/el/store-assistant',
	);

	/** Slug of the «Upgrade to Pro» menu item (it opens the product page). */
	public const MENU_SLUG = 'addsynapse-store-assistant-pro';

	/** The host the menu item may redirect to. */
	private const HOST = 'addsynapse.com';

	/**
	 * Registers hooks (wp-admin only).
	 */
	public function register_hooks(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( ADDSYNAPSE_ASSISTANT_PLUGIN_FILE ), array( $this, 'action_links' ) );
		// After the Store Assistant item (same priority, registered later).
		add_action( 'admin_menu', array( $this, 'add_menu' ), AddsynapseMenu::PRIORITY_SUBMENU + 1 );
	}

	/**
	 * Whether the offers are shown (false once another plugin provides the Pro features).
	 */
	public static function shown(): bool {
		/**
		 * Whether the plugin shows its links to Store Assistant Pro. A plugin that provides the Pro features returns false.
		 *
		 * @param bool $show Default true.
		 */
		return (bool) apply_filters( 'addsynapse_assistant_show_pro_offers', true );
	}

	/**
	 * Product page in the manager's language.
	 */
	public static function page(): string {
		$locale = strtolower( (string) get_user_locale() );
		foreach ( self::LOCALE_PAGES as $prefix => $url ) {
			if ( 0 === strpos( $locale, $prefix ) ) {
				return $url;
			}
		}
		return self::PAGE_URL;
	}

	/**
	 * Link of one placement.
	 *
	 * @param string $spot Placement (lower-case letters, digits, «-»).
	 */
	public static function url( string $spot ): string {
		$spot = preg_replace( '/[^a-z0-9-]/', '', strtolower( $spot ) );
		return add_query_arg(
			array(
				'utm_source'   => '' !== $spot ? $spot : 'wp-admin',
				'utm_medium'   => 'wp-admin',
				'utm_campaign' => 'gopro',
			),
			self::page()
		);
	}

	/**
	 * «Get Pro» in our row of the Plugins list.
	 *
	 * @param mixed $links Action links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		if ( ! self::shown() || ! current_user_can( Config::capability() ) ) {
			return $links;
		}
		$links['addsynapse_assistant_get_pro'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s"><strong>%3$s</strong></a>',
			esc_url( self::url( 'plugins-row' ) ),
			esc_attr__( 'Get Store Assistant Pro (opens in a new tab)', 'addsynapse-store-assistant' ),
			esc_html__( 'Get Pro', 'addsynapse-store-assistant' )
		);
		return $links;
	}

	/**
	 * AddSynapse → «Upgrade to Pro» (opens the product page).
	 */
	public function add_menu(): void {
		if ( ! self::shown() ) {
			return;
		}
		$hook = AddsynapseMenu::add_page(
			__( 'Upgrade to Pro', 'addsynapse-store-assistant' ),
			__( 'Upgrade to Pro', 'addsynapse-store-assistant' ),
			Config::capability(),
			self::MENU_SLUG,
			array( $this, 'render' )
		);
		if ( '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'redirect' ) );
		}
	}

	/**
	 * The menu item is a link: before any output, send the manager to the product page.
	 */
	public function redirect(): void {
		if ( ! current_user_can( Config::capability() ) ) {
			return;
		}
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_host' ) );
		wp_safe_redirect( self::url( 'admin-menu' ) );
		exit;
	}

	/**
	 * Allows the product page's host for this one redirect.
	 *
	 * @param mixed $hosts Hosts.
	 * @return array<int,string>
	 */
	public static function allow_host( $hosts ): array {
		$hosts   = is_array( $hosts ) ? $hosts : array();
		$hosts[] = self::HOST;
		return $hosts;
	}

	/**
	 * Fallback when a redirect is not possible (output already started): a link.
	 */
	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Store Assistant Pro', 'addsynapse-store-assistant' ) . '</h1><p><a class="button button-primary" href="' . esc_url( self::url( 'admin-menu' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See Pro', 'addsynapse-store-assistant' ) . '</a></p></div>';
	}

	/**
	 * What the chat needs: whether to show the links, and the product page (the chat adds the spot).
	 *
	 * @return array{show:bool,url:string}
	 */
	public static function client_state(): array {
		return array(
			'show' => self::shown(),
			'url'  => self::page(),
		);
	}
}
