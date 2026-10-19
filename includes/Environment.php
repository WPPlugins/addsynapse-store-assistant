<?php
/**
 * Runtime environment: is WooCommerce active (shop) or is this a plain WordPress site?
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin works on WooCommerce shops AND on plain WordPress sites (decision 2026-09-25:
 * "the manager may only want to fix the look of their site"). Every WooCommerce-only feature
 * (products, orders, stock, prices, product taxonomies/attributes/variations/meta, CSV import,
 * product SEO) is registered ONLY when has_woocommerce() is true; the rest (pages, banners,
 * menus, page/post SEO, media, image uploads, mappings) works on both.
 *
 * Detection runs at call time (all plugins are loaded by `plugins_loaded`), so deactivating
 * WooCommerce switches the plugin to site mode on the next request — nothing is stored.
 */
final class Environment {

	public const SITE_SHOP = 'shop';
	public const SITE      = 'site';

	/**
	 * Whether WooCommerce is loaded. The filter `addsynapse_assistant_has_woocommerce` can only turn it OFF
	 * (tests / troubleshooting): claiming WooCommerce when its functions are missing would fatal.
	 */
	public static function has_woocommerce(): bool {
		$loaded = class_exists( 'WooCommerce', false ) && function_exists( 'wc_get_products' ) && function_exists( 'wc_get_product' );
		return $loaded && (bool) apply_filters( 'addsynapse_assistant_has_woocommerce', true );
	}

	/**
	 * Site type for the prompt/UI: `shop` (WooCommerce) | `site` (plain WordPress).
	 */
	public static function site_type(): string {
		return self::has_woocommerce() ? self::SITE_SHOP : self::SITE;
	}
}
