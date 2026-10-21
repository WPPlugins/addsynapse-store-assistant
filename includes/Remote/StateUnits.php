<?php
/**
 * The «units» AddSynapse may read and (after the owner approved a change set) write through the remote route:
 *  - product: price/stock fields of a product or variation (Changesets\ProductFields),
 *  - seo:     SEO title / meta description of a product, page or post through the active SEO plugin
 *             (Rank Math or Yoast — Changesets\SeoFields; nothing when neither is active),
 *  - coupon:  a WooCommerce coupon (fixed list of fields; create, update, delete only for Undo of a create).
 *
 * Reads return normalized values; writes take the same shape, so a change set is just expect(BEFORE)/set(AFTER).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use Addsynapse\StoreAssistant\Changesets\ProductFields;
use Addsynapse\StoreAssistant\Changesets\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Read / compare / write of remote units.
 */
final class StateUnits {

	public const KINDS = array( 'product', 'seo', 'coupon' );

	/** Product fields the remote route may change (no sale dates). */
	public const PRODUCT_FIELDS = array( 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status' );

	public const SEO_PARTS = array( 'title', 'description' );

	public const COUPON_FIELDS = array(
		'code',
		'description',
		'discount_type',
		'amount',
		'date_expires',
		'usage_limit',
		'usage_limit_per_user',
		'individual_use',
		'free_shipping',
		'minimum_amount',
		'maximum_amount',
		'product_ids',
		'excluded_product_ids',
		'product_categories',
		'excluded_product_categories',
	);

	public const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );
	public const COUPON_TYPES   = array( 'percent', 'fixed_cart', 'fixed_product' );

	/**
	 * Current state of one unit.
	 *
	 * @param array<string,mixed> $item {kind, id, code?}.
	 * @return array<string,mixed> {kind, id, exists, name, values, [adapter]} or {error}.
	 */
	public static function read( array $item ): array {
		$kind = (string) ( $item['kind'] ?? '' );
		$id   = (int) ( $item['id'] ?? 0 );
		if ( 'product' === $kind ) {
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product || 'trash' === $product->get_status( 'edit' ) || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
				return self::missing( $kind, $id, $product ? 'Not a simple product or a variation (change the variations of a variable product).' : 'Product not found.' );
			}
			$values = array();
			foreach ( self::PRODUCT_FIELDS as $field ) {
				$values[ $field ] = ProductFields::read( $product, $field );
			}
			return array(
				'kind'      => $kind,
				'id'        => $id,
				'exists'    => true,
				'name'      => self::text( $product->get_name() ),
				'type'      => (string) $product->get_type(),
				'parent_id' => (int) $product->get_parent_id(),
				'values'    => $values,
			);
		}
		if ( 'seo' === $kind ) {
			$adapter = SeoAdapters::current();
			$subject = self::seo_subject( $id );
			if ( null === $subject ) {
				return self::missing( $kind, $id, 'Product, page or post not found (variations take the SEO of their parent product).' );
			}
			$values = array();
			if ( $adapter->supports_seo() ) {
				foreach ( self::SEO_PARTS as $part ) {
					$value           = SeoFields::read( $subject, SeoFields::field( $adapter->key(), $part ) );
					$values[ $part ] = is_array( $value ) ? null : $value;
				}
			}
			return array(
				'kind'    => $kind,
				'id'      => $id,
				'exists'  => true,
				'name'    => self::text( $subject instanceof \WC_Product ? $subject->get_name() : $subject->post_title ),
				'type'    => $subject instanceof \WC_Product ? 'product' : $subject->post_type,
				'adapter' => $adapter->key(),
				'values'  => $values,
			);
		}//end if
		if ( 'coupon' === $kind ) {
			if ( ! $id && ! empty( $item['code'] ) ) {
				$id = (int) wc_get_coupon_id_by_code( (string) $item['code'] );
			}
			$coupon = $id ? new \WC_Coupon( $id ) : null;
			if ( ! $coupon || ! $coupon->get_id() || 'shop_coupon' !== get_post_type( $coupon->get_id() ) || 'trash' === get_post_status( $coupon->get_id() ) ) {
				return self::missing( $kind, $id, 'Coupon not found.' );
			}
			return array(
				'kind'   => $kind,
				'id'     => (int) $coupon->get_id(),
				'exists' => true,
				'name'   => (string) $coupon->get_code(),
				'values' => self::coupon_values( $coupon ),
			);
		}
		return self::missing( $kind, $id, 'Unknown kind.' );
	}

	/**
	 * Whether the current values hold every expected value (only the keys of $expect are compared).
	 *
	 * @param string              $kind   Kind.
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( string $kind, array $now, array $expect ): bool {
		foreach ( $expect as $field => $value ) {
			if ( ! array_key_exists( $field, $now ) ) {
				return false;
			}
			if ( 'product' === $kind ) {
				if ( ! ProductFields::same( $field, $now[ $field ], $value ) ) {
					return false;
				}
				continue;
			}
			if ( 'coupon' === $kind ) {
				$a = self::coupon_norm( $field, $now[ $field ] );
				$b = self::coupon_norm( $field, $value );
				if ( $a !== $b ) {
					return false;
				}
				continue;
			}
			if ( $now[ $field ] !== $value && ! ( null !== $now[ $field ] && null !== $value && str_replace( "\r\n", "\n", (string) $now[ $field ] ) === str_replace( "\r\n", "\n", (string) $value ) ) ) {
				return false;
			}
		}//end foreach
		return true;
	}

	/**
	 * Validates the values a change set wants to write ('' = fine).
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $set  Values.
	 */
	public static function invalid( string $kind, array $set ): string {
		$allowed = 'product' === $kind ? self::PRODUCT_FIELDS : ( 'seo' === $kind ? self::SEO_PARTS : self::COUPON_FIELDS );
		foreach ( $set as $field => $value ) {
			if ( ! in_array( $field, $allowed, true ) ) {
				return 'Field not allowed: ' . $field;
			}
		}
		if ( 'product' === $kind ) {
			foreach ( array( 'regular_price', 'sale_price' ) as $f ) {
				if ( isset( $set[ $f ] ) && '' !== (string) $set[ $f ] && ! preg_match( '/^[0-9]+(\.[0-9]{1,6})?$/', (string) $set[ $f ] ) ) {
					return 'Invalid ' . $f;
				}
			}
			if ( isset( $set['stock_status'] ) && ! in_array( $set['stock_status'], self::STOCK_STATUSES, true ) ) {
				return 'Invalid stock_status';
			}
			if ( array_key_exists( 'stock_quantity', $set ) && null !== $set['stock_quantity'] && ! is_int( $set['stock_quantity'] ) ) {
				return 'Invalid stock_quantity';
			}
		}
		if ( 'seo' === $kind ) {
			foreach ( $set as $value ) {
				if ( null !== $value && ( ! is_string( $value ) || mb_strlen( $value ) > 320 ) ) {
					return 'SEO texts must be strings of at most 320 characters.';
				}
			}
		}
		if ( 'coupon' === $kind ) {
			if ( isset( $set['discount_type'] ) && ! in_array( $set['discount_type'], self::COUPON_TYPES, true ) ) {
				return 'Invalid discount_type';
			}
			if ( isset( $set['date_expires'] ) && null !== $set['date_expires'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $set['date_expires'] ) ) {
				return 'date_expires must be YYYY-MM-DD';
			}
			if ( isset( $set['amount'] ) && ! preg_match( '/^[0-9]+(\.[0-9]{1,4})?$/', (string) $set['amount'] ) ) {
				return 'Invalid amount';
			}
		}
		return '';
	}

	/**
	 * Writes one unit. Coupons: $id 0 + $set → create; $set null → delete (Undo of a create).
	 *
	 * @param string                   $kind Kind.
	 * @param int                      $id   Id (0 = new coupon).
	 * @param array<string,mixed>|null $set  Values (null = delete coupon).
	 * @return int The id written.
	 * @throws \RuntimeException On a write that cannot be done.
	 */
	public static function write( string $kind, int $id, ?array $set ): int {
		if ( 'product' === $kind ) {
			$product = wc_get_product( $id );
			$fields  = array_keys( (array) $set );
			usort(
				$fields,
				static function ( $a, $b ) {
					return ProductFields::order( $a ) <=> ProductFields::order( $b );
				}
			);
			foreach ( $fields as $field ) {
				ProductFields::write( $product, $field, $set[ $field ] );
			}
			$product->save();
			return $id;
		}
		if ( 'seo' === $kind ) {
			$adapter = SeoAdapters::current();
			$subject = self::seo_subject( $id );
			foreach ( (array) $set as $part => $value ) {
				$field = SeoFields::field( $adapter->key(), $part );
				if ( $subject instanceof \WC_Product ) {
					SeoFields::write( $subject, $field, $value );
					continue;
				}
				$key = SeoFields::meta_key( $field );
				if ( null === $value ) {
					delete_post_meta( $id, $key );
				} else {
					update_post_meta( $id, $key, wp_slash( (string) $value ) );
				}
			}
			if ( $subject instanceof \WC_Product ) {
				$subject->save();
			} else {
				clean_post_cache( $id );
			}
			return $id;
		}//end if
		// Coupon.
		if ( null === $set ) {
			$coupon = new \WC_Coupon( $id );
			$coupon->delete( true );
			return $id;
		}
		$coupon = $id ? new \WC_Coupon( $id ) : new \WC_Coupon();
		foreach ( $set as $field => $value ) {
			self::coupon_set( $coupon, $field, $value );
		}
		return (int) $coupon->save();
	}

	/**
	 * Normalized coupon values.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return array<string,mixed>
	 */
	public static function coupon_values( \WC_Coupon $coupon ): array {
		$expires = $coupon->get_date_expires( 'edit' );
		$out     = array(
			'code'                        => (string) $coupon->get_code( 'edit' ),
			'description'                 => (string) $coupon->get_description( 'edit' ),
			'discount_type'               => (string) $coupon->get_discount_type( 'edit' ),
			'amount'                      => (string) $coupon->get_amount( 'edit' ),
			'date_expires'                => $expires ? $expires->date( 'Y-m-d' ) : null,
			'usage_limit'                 => $coupon->get_usage_limit( 'edit' ) ? (int) $coupon->get_usage_limit( 'edit' ) : null,
			'usage_limit_per_user'        => $coupon->get_usage_limit_per_user( 'edit' ) ? (int) $coupon->get_usage_limit_per_user( 'edit' ) : null,
			'individual_use'              => (bool) $coupon->get_individual_use( 'edit' ),
			'free_shipping'               => (bool) $coupon->get_free_shipping( 'edit' ),
			'minimum_amount'              => (string) $coupon->get_minimum_amount( 'edit' ),
			'maximum_amount'              => (string) $coupon->get_maximum_amount( 'edit' ),
			'product_ids'                 => array_map( 'intval', $coupon->get_product_ids( 'edit' ) ),
			'excluded_product_ids'        => array_map( 'intval', $coupon->get_excluded_product_ids( 'edit' ) ),
			'product_categories'          => array_map( 'intval', $coupon->get_product_categories( 'edit' ) ),
			'excluded_product_categories' => array_map( 'intval', $coupon->get_excluded_product_categories( 'edit' ) ),
		);
		foreach ( $out as $field => $value ) {
			$out[ $field ] = self::coupon_norm( $field, $value );
		}
		$out['usage_count'] = (int) $coupon->get_usage_count( 'edit' );
		return $out;
	}

	/**
	 * Same representation for the same coupon value.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private static function coupon_norm( string $field, $value ) {
		switch ( $field ) {
			case 'amount':
			case 'minimum_amount':
			case 'maximum_amount':
				$value = trim( (string) $value );
				return '' === $value ? '' : wc_format_decimal( $value, 2 );
			case 'usage_limit':
			case 'usage_limit_per_user':
				return empty( $value ) ? null : (int) $value;
			case 'individual_use':
			case 'free_shipping':
				return (bool) $value;
			case 'product_ids':
			case 'excluded_product_ids':
			case 'product_categories':
			case 'excluded_product_categories':
				$list = array_values( array_unique( array_map( 'intval', (array) $value ) ) );
				sort( $list );
				return $list;
			case 'code':
				return wc_format_coupon_code( (string) $value );
			case 'date_expires':
				return empty( $value ) ? null : (string) $value;
		}//end switch
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Sets one coupon field.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @param string     $field  Field.
	 * @param mixed      $value  Value.
	 */
	private static function coupon_set( \WC_Coupon $coupon, string $field, $value ): void {
		$value = self::coupon_norm( $field, $value );
		switch ( $field ) {
			case 'date_expires':
				$coupon->set_date_expires( null === $value ? null : strtotime( $value . ' 23:59:59 ' . wp_timezone_string() ) );
				return;
			case 'usage_limit':
			case 'usage_limit_per_user':
				$coupon->{'set_' . $field}( null === $value ? 0 : $value );
				return;
		}
		$coupon->{'set_' . $field}( $value );
	}

	/**
	 * Product (not a variation), page or post for SEO.
	 *
	 * @param int $id Id.
	 * @return \WC_Product|\WP_Post|null
	 */
	private static function seo_subject( int $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'trash' === $post->post_status ) {
			return null;
		}
		if ( in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return current_user_can( 'edit_post', $post->ID ) ? $post : null;
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );
			return $product ? $product : null;
		}
		return null;
	}

	/**
	 * A unit that cannot be read.
	 *
	 * @param string $kind   Kind.
	 * @param int    $id     Id.
	 * @param string $reason Why.
	 * @return array<string,mixed>
	 */
	private static function missing( string $kind, int $id, string $reason ): array {
		return array(
			'kind'   => $kind,
			'id'     => $id,
			'exists' => false,
			'reason' => $reason,
			'values' => null,
		);
	}

	/**
	 * Plain text.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}
}
