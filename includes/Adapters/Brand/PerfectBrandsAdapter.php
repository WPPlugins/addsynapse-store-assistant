<?php
/**
 * Brand adapter: Perfect Brands for WooCommerce.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Perfect Brands for WooCommerce (QuadLayers): taxonomy pwb-brand.
 */
final class PerfectBrandsAdapter extends TaxonomyBrandAdapter {

	public const TAXONOMY = 'pwb-brand';

	/**
	 * Key.
	 */
	public function key(): string {
		return 'perfect_brands';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'Perfect Brands for WooCommerce';
	}

	/**
	 * Taxonomy.
	 */
	public function taxonomy(): string {
		return self::TAXONOMY;
	}
}
