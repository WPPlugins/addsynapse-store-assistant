<?php
/**
 * The ONLY product fields a changeset may touch, with typed read/write through WC CRUD.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed allow-list: a field name coming from anywhere else is rejected. Values are
 * normalized so that "current value" and the recorded before/after compare exactly:
 *  - prices: decimal string as stored ('' = empty),
 *  - sale dates: unix timestamp (int) or null,
 *  - manage_stock: bool, stock_quantity: int|null, stock_status: string.
 */
final class ProductFields {

	public const FIELDS = array(
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'manage_stock',
		'stock_quantity',
		'stock_status',
	);

	/**
	 * Order in which fields are written inside one product (stock tracking before quantity,
	 * quantity before status; prices before sale dates).
	 */
	private const ORDER = array(
		'manage_stock'      => 0,
		'stock_quantity'    => 1,
		'stock_status'      => 2,
		'regular_price'     => 3,
		'sale_price'        => 4,
		'date_on_sale_from' => 5,
		'date_on_sale_to'   => 6,
	);

	/**
	 * Whether a field is allowed.
	 *
	 * @param string $field Field.
	 */
	public static function allowed( string $field ): bool {
		return in_array( $field, self::FIELDS, true );
	}

	/**
	 * Sort key for a field.
	 *
	 * @param string $field Field.
	 */
	public static function order( string $field ): int {
		return self::ORDER[ $field ] ?? 99;
	}

	/**
	 * Current (edit-context) value of a field, normalized.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $field   Allowed field.
	 * @return mixed
	 * @throws \InvalidArgumentException For a field outside the allow-list.
	 */
	public static function read( \WC_Product $product, string $field ) {
		switch ( $field ) {
			case 'regular_price':
				return self::price( $product->get_regular_price( 'edit' ) );
			case 'sale_price':
				return self::price( $product->get_sale_price( 'edit' ) );
			case 'date_on_sale_from':
				$date = $product->get_date_on_sale_from( 'edit' );
				return $date ? (int) $date->getTimestamp() : null;
			case 'date_on_sale_to':
				$date = $product->get_date_on_sale_to( 'edit' );
				return $date ? (int) $date->getTimestamp() : null;
			case 'manage_stock':
				return (bool) $product->get_manage_stock( 'edit' );
			case 'stock_quantity':
				$qty = $product->get_stock_quantity( 'edit' );
				return null === $qty || '' === $qty ? null : (int) $qty;
			case 'stock_status':
				return (string) $product->get_stock_status( 'edit' );
		}
		throw new \InvalidArgumentException( 'Field not allowed.' );
	}

	/**
	 * Sets a field through the WC setter (caller saves).
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $field   Allowed field.
	 * @param mixed       $value   Normalized value.
	 * @throws \InvalidArgumentException For a field outside the allow-list.
	 */
	public static function write( \WC_Product $product, string $field, $value ): void {
		switch ( $field ) {
			case 'regular_price':
				$product->set_regular_price( (string) $value );
				return;
			case 'sale_price':
				$product->set_sale_price( (string) $value );
				return;
			case 'date_on_sale_from':
				$product->set_date_on_sale_from( null === $value ? '' : (int) $value );
				return;
			case 'date_on_sale_to':
				$product->set_date_on_sale_to( null === $value ? '' : (int) $value );
				return;
			case 'manage_stock':
				$product->set_manage_stock( (bool) $value );
				return;
			case 'stock_quantity':
				$product->set_stock_quantity( null === $value ? '' : (int) $value );
				return;
			case 'stock_status':
				$product->set_stock_status( (string) $value );
				return;
		}//end switch
		throw new \InvalidArgumentException( 'Field not allowed.' );
	}

	/**
	 * Whether two normalized values are the same (prices compare numerically).
	 *
	 * @param string $field Field.
	 * @param mixed  $a     Value.
	 * @param mixed  $b     Value.
	 */
	public static function same( string $field, $a, $b ): bool {
		if ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) {
			$a = (string) $a;
			$b = (string) $b;
			if ( '' === $a || '' === $b ) {
				return $a === $b;
			}
			return abs( (float) $a - (float) $b ) < 0.00001;
		}
		if ( 'manage_stock' === $field ) {
			return (bool) $a === (bool) $b;
		}
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		if ( in_array( $field, array( 'date_on_sale_from', 'date_on_sale_to', 'stock_quantity' ), true ) ) {
			return (int) $a === (int) $b;
		}
		return (string) $a === (string) $b;
	}

	/**
	 * Price as stored ('' when empty).
	 *
	 * @param mixed $value Raw.
	 */
	private static function price( $value ): string {
		return null === $value ? '' : (string) $value;
	}
}
