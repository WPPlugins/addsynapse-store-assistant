<?php
/**
 * Field dispatcher of the changeset engine: fixed product fields + taxonomy-term fields.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

defined( 'ABSPATH' ) || exit;

/**
 * The engine only knows these field families. Anything else is rejected:
 *  - ProductFields::FIELDS (prices, sale dates, stock) on products/variations,
 *  - TermFields (terms:*, create_term:*, and attribute:* for an attribute-based brand) on parent products / new terms,
 *  - SeoFields (`seo:{adapter}:{part}` SEO plugin keys, `short_description` / `description`),
 *  - CoreFields (name, status, visibility, SKU, shipping, tax, stock settings, images… via setters).
 */
final class Fields {

	/**
	 * Whether a field is allowed at all.
	 *
	 * @param string $field Field.
	 */
	public static function allowed( string $field ): bool {
		return ProductFields::allowed( $field ) || TermFields::allowed( $field ) || SeoFields::allowed( $field ) || CoreFields::is_field( $field );
	}

	/**
	 * Whether a field is a term field.
	 *
	 * @param string $field Field.
	 */
	public static function is_term( string $field ): bool {
		return '' !== TermFields::kind( $field );
	}

	/**
	 * Write order inside one product (product fields first, then terms).
	 *
	 * @param string $field Field.
	 */
	public static function order( string $field ): int {
		switch ( TermFields::kind( $field ) ) {
			case 'terms':
				return 50;
			case 'attribute':
				return 60;
		}
		if ( SeoFields::is_field( $field ) ) {
			return SeoFields::order( $field );
		}
		if ( CoreFields::is_field( $field ) ) {
			return CoreFields::order( $field );
		}
		return ProductFields::order( $field );
	}

	/**
	 * Current value.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $field   Field.
	 * @return mixed
	 */
	public static function read( \WC_Product $product, string $field ) {
		if ( SeoFields::is_field( $field ) ) {
			return SeoFields::read( $product, $field );
		}
		if ( CoreFields::is_field( $field ) ) {
			return CoreFields::read( $product, $field );
		}
		return self::is_term( $field ) ? TermFields::read( $product, $field ) : ProductFields::read( $product, $field );
	}

	/**
	 * Writes a value (the caller saves).
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $field   Field.
	 * @param mixed       $value   Value.
	 */
	public static function write( \WC_Product $product, string $field, $value ): void {
		if ( self::is_term( $field ) ) {
			TermFields::write( $product, $field, $value );
			return;
		}
		if ( SeoFields::is_field( $field ) ) {
			SeoFields::write( $product, $field, $value );
			return;
		}
		if ( CoreFields::is_field( $field ) ) {
			CoreFields::write( $product, $field, $value );
			return;
		}
		ProductFields::write( $product, $field, $value );
	}

	/**
	 * Why an item may not be APPLIED now ('' = it may): SEO fields need the same SEO plugin, images must still be images, a new SKU still unique.
	 *
	 * @param string $field     Field.
	 * @param mixed  $after     Value to write.
	 * @param int    $object_id Product/variation id.
	 */
	public static function apply_denial( string $field, $after = null, int $object_id = 0 ): string {
		if ( CoreFields::is_field( $field ) ) {
			return CoreFields::apply_denial( $field, $after, $object_id );
		}
		return SeoFields::is_field( $field ) ? SeoFields::apply_denial( $field ) : '';
	}

	/**
	 * Same value?
	 *
	 * @param string $field Field.
	 * @param mixed  $a     Value.
	 * @param mixed  $b     Value.
	 */
	public static function same( string $field, $a, $b ): bool {
		if ( SeoFields::is_field( $field ) ) {
			return SeoFields::same( $field, $a, $b );
		}
		if ( CoreFields::is_field( $field ) ) {
			return CoreFields::same( $field, $a, $b );
		}
		return self::is_term( $field ) ? TermFields::same( $field, $a, $b ) : ProductFields::same( $field, $a, $b );
	}

	/**
	 * Short text of a value for reasons.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public static function show( string $field, $value ): string {
		if ( self::is_term( $field ) ) {
			return TermFields::show( $field, $value );
		}
		if ( SeoFields::is_field( $field ) ) {
			return SeoFields::show( $field, $value );
		}
		if ( CoreFields::is_field( $field ) ) {
			return CoreFields::show( $field, $value );
		}
		if ( null === $value || '' === $value ) {
			return 'empty';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( in_array( $field, array( 'date_on_sale_from', 'date_on_sale_to' ), true ) ) {
			return wp_date( 'Y-m-d H:i', (int) $value );
		}
		return mb_substr( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ), 0, 40 );
	}
}
