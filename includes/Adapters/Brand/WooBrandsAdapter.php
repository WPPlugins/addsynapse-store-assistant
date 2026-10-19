<?php
/**
 * Brand adapter: WooCommerce Brands.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce core Brands (WooCommerce 9.6+, formerly the WooCommerce Brands extension): taxonomy product_brand.
 */
final class WooBrandsAdapter extends TaxonomyBrandAdapter {

	public const TAXONOMY = 'product_brand';

	/**
	 * Key.
	 */
	public function key(): string {
		return 'woocommerce_brands';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'WooCommerce Brands';
	}

	/**
	 * Taxonomy.
	 */
	public function taxonomy(): string {
		return self::TAXONOMY;
	}
}
