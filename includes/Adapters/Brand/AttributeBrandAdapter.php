<?php
/**
 * Brand kept as a global product attribute (pa_brand or similar).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Brand;

use Addsynapse\StoreAssistant\Changesets\TermFields;

defined( 'ABSPATH' ) || exit;

/**
 * The brand is a term of a pa_* taxonomy AND an entry in the product's attributes: it is
 * written as a WC_Product_Attribute (term ids as options, visible, not used for variations,
 * position after the existing attributes) and WooCommerce stores the term relationships
 * on save. Never plain wp_set_object_terms (the attribute would not show on the product).
 */
final class AttributeBrandAdapter implements BrandAdapter {

	/**
	 * Taxonomy (pa_*).
	 *
	 * @var string
	 */
	private string $taxonomy;

	/**
	 * Constructor.
	 *
	 * @param string $taxonomy Attribute taxonomy (pa_*).
	 */
	public function __construct( string $taxonomy ) {
		$this->taxonomy = $taxonomy;
	}

	/**
	 * Key.
	 */
	public function key(): string {
		return 'attribute';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		$id        = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? (int) wc_attribute_taxonomy_id_by_name( $this->taxonomy ) : 0;
		$attribute = $id && function_exists( 'wc_get_attribute' ) ? wc_get_attribute( $id ) : null;
		return 'Global attribute' . ( $attribute ? ' "' . $attribute->name . '"' : '' );
	}

	/**
	 * Taxonomy.
	 */
	public function taxonomy(): string {
		return $this->taxonomy;
	}

	/**
	 * Storage kind.
	 */
	public function storage(): string {
		return 'attribute';
	}

	/**
	 * Available when the pa_* attribute is registered in WooCommerce.
	 */
	public function available(): bool {
		return TermFields::is_attribute_taxonomy( $this->taxonomy );
	}

	/**
	 * Changeset field.
	 */
	public function field(): string {
		return TermFields::ATTRIBUTE . $this->taxonomy;
	}
}
