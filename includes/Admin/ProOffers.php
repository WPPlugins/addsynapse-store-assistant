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
 * what it can do — the «Upgrade to Pro» button and card on our Home, the Pro card in
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

	/** Slug of the former «Upgrade to Pro» menu item (its address still opens the product page). */
	public const MENU_SLUG = 'addsynapse-store-assistant-pro';

	/** The host the menu item may redirect to. */
	private const HOST = 'addsynapse.com';

	/**
	 * Registers hooks (wp-admin only).
	 */
	public function register_hooks(): void {
		// 1.3.0: no «Get Pro» in our row of the Plugins list (it read as if the plugin needed Pro); Pro is only the small
		// card on Home. Also 1.3.0: no «Upgrade to Pro» menu item any more (a small button on Home); its old address still redirects
		// (HomePage::legacy_redirects() → redirect()).
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
	 * The former menu item's address: before any output, send the manager to the product page.
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
