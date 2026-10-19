<?php
/**
 * Taxonomy-term fields of a changeset: EXISTING terms of a product (categories, tags, brand).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

defined( 'ABSPATH' ) || exit;

/**
 * Two field kinds (the taxonomy is part of the field name and is re-validated on every use):
 *  - `terms:{taxonomy}`     value = sorted list of existing term ids of the PARENT product (never a variation).
 *  - `attribute:{pa_x}`     a brand kept as a global attribute (plan-brand-assign only): value = null (not on the
 *                           product) or {terms:[ids], visible, variation, position}; written through
 *                           WC_Product_Attribute. New terms are never created here.
 * Only product taxonomies with an admin UI are writable; WooCommerce internals
 * (product_type, product_visibility, product_shipping_class) never are.
 */
final class TermFields {

	public const TERMS     = 'terms:';
	public const ATTRIBUTE = 'attribute:';

	/**
	 * Product taxonomies that are WooCommerce internals, never changed through terms.
	 */
	public const DENY = array( 'product_type', 'product_visibility', 'product_shipping_class' );

	/**
	 * Field kind: terms|attribute|''.
	 *
	 * @param string $field Field.
	 */
	public static function kind( string $field ): string {
		if ( 0 === strpos( $field, self::TERMS ) ) {
			return 'terms';
		}
		if ( 0 === strpos( $field, self::ATTRIBUTE ) ) {
			return 'attribute';
		}
		return '';
	}

	/**
	 * Taxonomy of a term field.
	 *
	 * @param string $field Field.
	 */
	public static function taxonomy( string $field ): string {
		$pos = strpos( $field, ':' );
		return false === $pos ? '' : (string) substr( $field, $pos + 1 );
	}

	/**
	 * Whether a field is a valid term field for a currently writable taxonomy.
	 *
	 * @param string $field Field.
	 */
	public static function allowed( string $field ): bool {
		$taxonomy = self::taxonomy( $field );
		switch ( self::kind( $field ) ) {
			case 'terms':
				return self::writable_taxonomy( $taxonomy ) && ! self::is_attribute_taxonomy( $taxonomy );
			case 'attribute':
				return self::is_attribute_taxonomy( $taxonomy );
		}
		return false;
	}

	/**
	 * A product taxonomy that may be written: registered for products, has an admin UI and
	 * is not a WooCommerce internal. pa_* attribute taxonomies qualify (written as attributes).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function writable_taxonomy( string $taxonomy ): bool {
		if ( '' === $taxonomy || in_array( $taxonomy, self::DENY, true ) || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		if ( self::is_attribute_taxonomy( $taxonomy ) ) {
			return true;
		}
		$object = get_taxonomy( $taxonomy );
		return $object && in_array( 'product', (array) $object->object_type, true ) && ! empty( $object->show_ui );
	}

	/**
	 * A registered global attribute taxonomy (pa_*).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function is_attribute_taxonomy( string $taxonomy ): bool {
		return 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) && function_exists( 'wc_attribute_taxonomy_id_by_name' ) && wc_attribute_taxonomy_id_by_name( $taxonomy ) > 0;
	}

	/**
	 * Translated plural label of a product taxonomy (WooCommerce's own labels are often
	 * untranslated on the site; brand plugins all become "Brands").
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function label( string $taxonomy ): string {
		if ( 'product_cat' === $taxonomy ) {
			return __( 'Categories', 'addsynapse-store-assistant' );
		}
		if ( 'product_tag' === $taxonomy ) {
			return __( 'Tags', 'addsynapse-store-assistant' );
		}
		if ( in_array( $taxonomy, array( 'product_brand', 'pwb-brand', 'yith_product_brand' ), true ) ) {
			return __( 'Brands', 'addsynapse-store-assistant' );
		}
		if ( 0 === strpos( $taxonomy, 'pa_' ) && function_exists( 'wc_attribute_label' ) ) {
			return html_entity_decode( (string) wc_attribute_label( $taxonomy ), ENT_QUOTES, 'UTF-8' );
		}
		$object = $taxonomy ? get_taxonomy( $taxonomy ) : null;
		return $object ? html_entity_decode( (string) $object->labels->name, ENT_QUOTES, 'UTF-8' ) : $taxonomy;
	}

	/**
	 * Field name for writing terms of $taxonomy on a product.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function field_for( string $taxonomy ): string {
		return ( self::is_attribute_taxonomy( $taxonomy ) ? self::ATTRIBUTE : self::TERMS ) . $taxonomy;
	}

	/**
	 * Current value.
	 *
	 * @param \WC_Product $product Parent product.
	 * @param string      $field   terms:* | attribute:*.
	 * @return array<int,int>|array<string,mixed>|null
	 * @throws \InvalidArgumentException For an invalid field.
	 */
	public static function read( \WC_Product $product, string $field ) {
		$taxonomy = self::taxonomy( $field );
		if ( 'terms' === self::kind( $field ) ) {
			if ( 'product_cat' === $taxonomy ) {
				return self::ids( $product->get_category_ids( 'edit' ) );
			}
			if ( 'product_tag' === $taxonomy ) {
				return self::ids( $product->get_tag_ids( 'edit' ) );
			}
			$ids = wp_get_object_terms( $product->get_id(), $taxonomy, array( 'fields' => 'ids' ) );
			return is_wp_error( $ids ) ? array() : self::ids( $ids );
		}
		if ( 'attribute' === self::kind( $field ) ) {
			$attributes = $product->get_attributes( 'edit' );
			$attribute  = $attributes[ sanitize_title( $taxonomy ) ] ?? ( $attributes[ $taxonomy ] ?? null );
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				return null;
			}
			return array(
				'terms'     => self::ids( $attribute->get_options() ),
				'visible'   => (bool) $attribute->get_visible(),
				'variation' => (bool) $attribute->get_variation(),
				'position'  => (int) $attribute->get_position(),
			);
		}
		throw new \InvalidArgumentException( 'Field not allowed.' );
	}

	/**
	 * Sets the value on the product (caller saves once). Terms of taxonomies that the
	 * product CRUD does not manage are written with wp_set_object_terms right away.
	 *
	 * @param \WC_Product $product Parent product.
	 * @param string      $field   terms:* | attribute:*.
	 * @param mixed       $value   Normalized value (refs already resolved).
	 * @throws \InvalidArgumentException On an invalid field.
	 * @throws \RuntimeException On a missing term / WP error.
	 */
	public static function write( \WC_Product $product, string $field, $value ): void {
		if ( ! self::allowed( $field ) || $product instanceof \WC_Product_Variation ) {
			throw new \InvalidArgumentException( 'Field not allowed.' );
		}
		$taxonomy = self::taxonomy( $field );
		if ( 'terms' === self::kind( $field ) ) {
			$ids = self::ids( (array) $value );
			self::assert_terms_exist( $ids, $taxonomy );
			if ( 'product_cat' === $taxonomy ) {
				$product->set_category_ids( $ids );
				return;
			}
			if ( 'product_tag' === $taxonomy ) {
				$product->set_tag_ids( $ids );
				return;
			}
			$result = wp_set_object_terms( $product->get_id(), $ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( esc_html( $result->get_error_message() ) );
			}
			return;
		}
		if ( 'attribute' === self::kind( $field ) ) {
			$attributes = $product->get_attributes( 'edit' );
			$key        = sanitize_title( $taxonomy );
			unset( $attributes[ $taxonomy ] );
			if ( null === $value ) {
				unset( $attributes[ $key ] );
			} else {
				$value = (array) $value;
				$ids   = self::ids( (array) ( $value['terms'] ?? array() ) );
				self::assert_terms_exist( $ids, $taxonomy );
				$attribute = new \WC_Product_Attribute();
				$attribute->set_id( (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( $ids );
				$attribute->set_visible( ! empty( $value['visible'] ) );
				$attribute->set_variation( ! empty( $value['variation'] ) );
				$attribute->set_position( (int) ( $value['position'] ?? 0 ) );
				$attributes[ $key ] = $attribute;
			}
			$product->set_attributes( $attributes );
			return;
		}//end if
		throw new \InvalidArgumentException( 'Field not allowed.' );
	}

	/**
	 * Whether two values are the same.
	 *
	 * @param string $field Field.
	 * @param mixed  $a     Value.
	 * @param mixed  $b     Value.
	 */
	public static function same( string $field, $a, $b ): bool {
		switch ( self::kind( $field ) ) {
			case 'terms':
				return self::ids( (array) $a ) === self::ids( (array) $b );
			case 'attribute':
				return self::normalize_attribute( $a ) === self::normalize_attribute( $b );
		}
		return false;
	}

	/**
	 * Whether an item value is well-formed for its field (refs must be declared by the plan).
	 *
	 * @param string            $field Field.
	 * @param mixed             $value Value.
	 * @param array<int,string> $refs  "new:N" refs of the changeset's create_term lines.
	 */
	public static function valid_value( string $field, $value, array $refs ): bool {
		switch ( self::kind( $field ) ) {
			case 'terms':
				if ( ! is_array( $value ) ) {
					return false;
				}
				foreach ( $value as $id ) {
					if ( ! ( is_int( $id ) && $id > 0 ) ) {
						return false;
					}
				}
				return true;
			case 'attribute':
				if ( null === $value ) {
					return true;
				}
				if ( ! is_array( $value ) || ! isset( $value['terms'] ) || ! is_array( $value['terms'] ) || ! $value['terms'] ) {
					return false;
				}
				return self::valid_value( self::TERMS . self::taxonomy( $field ), $value['terms'], $refs );
		}
		return false;
	}

	/**
	 * Term ids of a value (refs dropped).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return array<int,int|string>
	 */
	public static function term_list( string $field, $value ): array {
		if ( 'attribute' === self::kind( $field ) ) {
			return null === $value ? array() : array_values( (array) ( ( (array) $value )['terms'] ?? array() ) );
		}
		return array_values( (array) $value );
	}

	/**
	 * Short text of a value (reasons): term names.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public static function show( string $field, $value ): string {
		$names = array();
		foreach ( self::term_list( $field, $value ) as $id ) {
			$term    = is_int( $id ) ? get_term( $id ) : null;
			$names[] = $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : (string) $id;
		}
		return $names ? mb_substr( implode( ', ', $names ), 0, 60 ) : 'empty';
	}

	/**
	 * Sorted unique positive int ids.
	 *
	 * @param mixed $ids Ids.
	 * @return array<int,int>
	 */
	public static function ids( $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Canonical attribute payload (null = absent).
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>|null
	 */
	private static function normalize_attribute( $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		$value = (array) $value;
		return array(
			'terms'     => self::ids( (array) ( $value['terms'] ?? array() ) ),
			'visible'   => ! empty( $value['visible'] ),
			'variation' => ! empty( $value['variation'] ),
			'position'  => (int) ( $value['position'] ?? 0 ),
		);
	}

	/**
	 * Throws unless every id is a term of $taxonomy.
	 *
	 * @param array<int,int> $ids      Ids.
	 * @param string         $taxonomy Taxonomy.
	 * @throws \RuntimeException When a term is missing.
	 */
	private static function assert_terms_exist( array $ids, string $taxonomy ): void {
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				throw new \RuntimeException( esc_html( sprintf( 'Term %d does not exist in %s.', $id, $taxonomy ) ) );
			}
		}
	}
}
