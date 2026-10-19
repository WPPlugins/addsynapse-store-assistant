<?php
/**
 * Brand adapter: YITH WooCommerce Brands.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * YITH WooCommerce Brands Add-On: taxonomy yith_product_brand.
 */
final class YithBrandsAdapter extends TaxonomyBrandAdapter {

	public const TAXONOMY = 'yith_product_brand';

	/**
	 * Key.
	 */
	public function key(): string {
		return 'yith_brands';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'YITH WooCommerce Brands';
	}

	/**
	 * Taxonomy.
	 */
	public function taxonomy(): string {
		return self::TAXONOMY;
	}
}
