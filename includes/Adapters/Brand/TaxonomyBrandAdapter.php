<?php
/**
 * Brand kept as terms of a product taxonomy registered by a brand plugin.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Brand;

use Addsynapse\StoreAssistant\Changesets\TermFields;

defined( 'ABSPATH' ) || exit;

/**
 * Terms are assigned to the PARENT product (variations inherit them) with
 * wp_set_object_terms inside the engine's single product save.
 */
abstract class TaxonomyBrandAdapter implements BrandAdapter {

	/**
	 * Storage kind.
	 */
	public function storage(): string {
		return 'taxonomy';
	}

	/**
	 * Available when the plugin registered its taxonomy for products.
	 */
	public function available(): bool {
		return TermFields::writable_taxonomy( $this->taxonomy() );
	}

	/**
	 * Changeset field.
	 */
	public function field(): string {
		return TermFields::TERMS . $this->taxonomy();
	}
}
