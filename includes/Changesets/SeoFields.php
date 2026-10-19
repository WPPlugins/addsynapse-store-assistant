<?php
/**
 * SEO / product text changes: `seo:{adapter}:{part}` (SEO plugin keys through the adapter — on
 * products, and on pages/posts as object_type `post`) and `short_description` / `description`
 * (product setters; products only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Adapters\Seo\AbstractSeoAdapter;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;

defined( 'ABSPATH' ) || exit;

/**
 * Values:
 *  - `seo:{rank_math|yoast}:{title|description|focus_keyword}`: the raw meta row of the
 *    adapter's FIXED key — null = absent (undo deletes the row), '' = empty. Written with
 *    update_meta_data()/delete_meta_data() + the product's one save(). This is the ONLY way
 *    SEO keys are ever written, and
 *    only while the same SEO plugin is active (apply_denial).
 *  - `short_description` / `description`: the product's own text (post_excerpt /
 *    post_content), through set_short_description()/set_description().
 * Slugs are never changed (broken links).
 */
final class SeoFields {

	/**
	 * Key of a read value that cannot be used (several values, structured data).
	 */
	public const UNREADABLE = '__unreadable';

	/**
	 * Why a read value cannot be used ('' = usable).
	 *
	 * @param mixed $value Read value.
	 */
	public static function unreadable( $value ): string {
		return is_array( $value ) && isset( $value[ self::UNREADABLE ] ) ? (string) $value[ self::UNREADABLE ] : '';
	}

	public const SEO     = 'seo:';
	public const PARTS   = array( 'title', 'description', 'focus_keyword' );
	public const CONTENT = array( 'short_description', 'description' );

	/**
	 * Adapters whose keys may be written.
	 */
	public const ADAPTERS = array( 'rank_math', 'yoast' );

	/**
	 * Whether a field belongs to this family.
	 *
	 * @param string $field Field.
	 */
	public static function is_field( string $field ): bool {
		return '' !== self::kind( $field );
	}

	/**
	 * Family: seo | content | ''.
	 *
	 * @param string $field Field.
	 */
	public static function kind( string $field ): string {
		if ( 0 === strpos( $field, self::SEO ) ) {
			return 'seo';
		}
		return in_array( $field, self::CONTENT, true ) ? 'content' : '';
	}

	/**
	 * Field name of an SEO part.
	 *
	 * @param string $adapter Adapter key.
	 * @param string $part    Part.
	 */
	public static function field( string $adapter, string $part ): string {
		return self::SEO . $adapter . ':' . $part;
	}

	/**
	 * Adapter key of an SEO field ('' for content fields).
	 *
	 * @param string $field Field.
	 */
	public static function adapter_key( string $field ): string {
		$parts = explode( ':', $field );
		return 'seo' === self::kind( $field ) ? (string) ( $parts[1] ?? '' ) : '';
	}

	/**
	 * Part: title|description|focus_keyword (SEO) or short_description|description (content).
	 *
	 * @param string $field Field.
	 */
	public static function part( string $field ): string {
		if ( 'content' === self::kind( $field ) ) {
			return $field;
		}
		$parts = explode( ':', $field );
		return (string) ( $parts[2] ?? '' );
	}

	/**
	 * Whether a field name is acceptable at all.
	 *
	 * @param string $field Field.
	 */
	public static function allowed( string $field ): bool {
		if ( 'content' === self::kind( $field ) ) {
			return true;
		}
		return 'seo' === self::kind( $field ) && 3 === count( explode( ':', $field ) )
			&& in_array( self::adapter_key( $field ), self::ADAPTERS, true ) && in_array( self::part( $field ), self::PARTS, true );
	}

	/**
	 * The fixed meta key of an SEO field ('' = none).
	 *
	 * @param string $field Field.
	 */
	public static function meta_key( string $field ): string {
		if ( ! self::allowed( $field ) || 'seo' !== self::kind( $field ) ) {
			return '';
		}
		$adapter = SeoAdapters::for_key( self::adapter_key( $field ) );
		return $adapter ? $adapter->meta_key( self::part( $field ) ) : '';
	}

	/**
	 * Why an item may not be APPLIED now ('' = it may): an SEO field needs the same SEO plugin.
	 *
	 * @param string $field Field.
	 */
	public static function apply_denial( string $field ): string {
		if ( 'seo' !== self::kind( $field ) ) {
			return '';
		}
		$current = SeoAdapters::current();
		return $current->key() === self::adapter_key( $field ) ? '' : sprintf( 'The SEO plugin changed since the preview (now: %s).', $current->label() );
	}

	/**
	 * Current value.
	 *
	 * @param \WC_Product|\WP_Post $product Product, or a page/post (SEO keys only).
	 * @param string               $field   Field.
	 * @return mixed
	 * @throws \InvalidArgumentException When it is neither a product nor a post.
	 */
	public static function read( $product, string $field ) {
		if ( ! ( $product instanceof \WC_Product || $product instanceof \WP_Post ) ) {
			throw new \InvalidArgumentException( 'Expected a WC_Product or a WP_Post.' );
		}
		if ( $product instanceof \WP_Post ) {
			return self::read_post( $product, $field );
		}
		if ( 'short_description' === $field ) {
			return (string) $product->get_short_description( 'edit' );
		}
		if ( 'description' === $field ) {
			return (string) $product->get_description( 'edit' );
		}
		$key  = self::meta_key( $field );
		$rows = array();
		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( '' !== $key && (string) $data['key'] === $key ) {
				$rows[] = $data['value'];
			}
		}
		if ( ! $rows ) {
			return null;
		}
		if ( count( $rows ) > 1 ) {
			return array( self::UNREADABLE => 'It has several values for this SEO field.' );
		}
		$value = $rows[0];
		if ( ! is_scalar( $value ) || ( is_string( $value ) && is_serialized( $value ) ) ) {
			return array( self::UNREADABLE => 'Its SEO field holds structured data.' );
		}
		return (string) $value;
	}

	/**
	 * Current SEO value of a page/post: the raw post meta row of the adapter's FIXED key (null =
	 * absent). Page/post texts (content/excerpt) are never SEO fields here.
	 *
	 * @param \WP_Post $post  Page or post.
	 * @param string   $field Field.
	 * @return mixed
	 */
	public static function read_post( \WP_Post $post, string $field ) {
		$key = 'seo' === self::kind( $field ) ? self::meta_key( $field ) : '';
		if ( '' === $key ) {
			return array( self::UNREADABLE => 'Only SEO fields can be changed on pages and posts.' );
		}
		if ( ! metadata_exists( 'post', $post->ID, $key ) ) {
			return null;
		}
		$rows = get_post_meta( $post->ID, $key, false );
		if ( count( $rows ) > 1 ) {
			return array( self::UNREADABLE => 'It has several values for this SEO field.' );
		}
		$value = $rows[0] ?? null;
		if ( ! is_scalar( $value ) || ( is_string( $value ) && is_serialized( $value ) ) ) {
			return array( self::UNREADABLE => 'Its SEO field holds structured data.' );
		}
		return (string) $value;
	}

	/**
	 * Writes a value into the product object (the caller saves). Pages and posts are never written here.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $field   Field.
	 * @param mixed       $value   Value.
	 * @throws \RuntimeException When the field cannot be written.
	 */
	public static function write( \WC_Product $product, string $field, $value ): void {
		if ( 'short_description' === $field ) {
			$product->set_short_description( (string) $value );
			return;
		}
		if ( 'description' === $field ) {
			$product->set_description( (string) $value );
			return;
		}
		$key = self::meta_key( $field );
		if ( '' === $key ) {
			throw new \RuntimeException( 'Unknown SEO field.' );
		}
		if ( null === $value ) {
			$product->delete_meta_data( $key );
			return;
		}
		$product->update_meta_data( $key, (string) $value );
	}

	/**
	 * Same value? (SEO: null = absent ≠ '' = empty; texts: line endings normalized.)
	 *
	 * @param string $field Field.
	 * @param mixed  $a     Value.
	 * @param mixed  $b     Value.
	 */
	public static function same( string $field, $a, $b ): bool {
		if ( '' !== self::unreadable( $a ) || '' !== self::unreadable( $b ) ) {
			return false;
		}
		if ( 'seo' === self::kind( $field ) && ( null === $a || null === $b ) ) {
			return $a === $b;
		}
		if ( ( null !== $a && ! is_scalar( $a ) ) || ( null !== $b && ! is_scalar( $b ) ) ) {
			return false;
		}
		return str_replace( "\r\n", "\n", (string) $a ) === str_replace( "\r\n", "\n", (string) $b );
	}

	/**
	 * Whether a stored before/after value has the right shape.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public static function valid_value( string $field, $value ): bool {
		if ( 'seo' === self::kind( $field ) ) {
			return null === $value || ( is_string( $value ) && mb_strlen( $value ) <= SeoText::STORED_MAX );
		}
		return is_string( $value ) && mb_strlen( $value ) <= SeoText::LONG_STORED_MAX;
	}

	/**
	 * Compact value for the model: long texts as a plain excerpt + length (the card shows all).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	public static function display( string $field, $value ) {
		if ( '' !== self::unreadable( $value ) ) {
			return self::unreadable( $value );
		}
		if ( 'content' !== self::kind( $field ) ) {
			return $value;
		}
		$text = AbstractSeoAdapter::clean( (string) $value );
		$len  = mb_strlen( $text );
		return $len > 140 ? mb_substr( $text, 0, 140 ) . '… [' . $len . ' chars]' : $text;
	}

	/**
	 * Short text for reasons.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public static function show( string $field, $value ): string {
		if ( '' !== self::unreadable( $value ) ) {
			return self::unreadable( $value );
		}
		if ( null === $value ) {
			return 'absent';
		}
		$text = AbstractSeoAdapter::clean( (string) $value );
		if ( '' === $text ) {
			return 'empty';
		}
		return mb_strlen( $text ) > 40 ? mb_substr( $text, 0, 40 ) . '…' : $text;
	}

	/**
	 * Write order inside one product (texts before SEO keys).
	 *
	 * @param string $field Field.
	 */
	public static function order( string $field ): int {
		return 'content' === self::kind( $field ) ? 85 : 90;
	}
}
