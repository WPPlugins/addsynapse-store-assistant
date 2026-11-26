<?php
/**
 * An order that WooCommerce computes but NEVER stores (new-order preview).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Order::calculate_totals(), update_taxes() and apply_coupon() call save() themselves. Here save()
 * does nothing, so the totals, taxes and coupon discounts come from WooCommerce's own code while the
 * draft writes nothing to the order tables. Items are added with add_item() (never add_product(), which
 * saves the item), so they only live in memory. Loaded only when WooCommerce is active.
 *
 * WooCommerce's coupon code finds items by id (WC_Discounts keys them by get_id(), set_coupon_discount_amounts()
 * looks them up with get_item( $id, false )), so every in-memory item gets a temporary id that no stored item
 * has; and update_taxes() would save() the tax lines of an earlier pass, so they are dropped before each pass.
 */
final class OrderPreview extends \WC_Order {

	/**
	 * Temporary item ids (far above any real order item id; never stored).
	 */
	private const FIRST_TEMP_ID = 2000000000;

	/**
	 * Last temporary id.
	 *
	 * @var int
	 */
	private int $temp_id = self::FIRST_TEMP_ID;

	/**
	 * Adds an item in memory with a temporary id.
	 *
	 * @param \WC_Order_Item $item Item.
	 * @return false|void
	 */
	public function add_item( $item ) {
		if ( $item instanceof \WC_Order_Item && ! $item->get_id() ) {
			$item->set_id( ++$this->temp_id );
		}
		return parent::add_item( $item );
	}

	/**
	 * Recomputes the tax lines from scratch (the previous pass's lines exist only in memory).
	 */
	public function update_taxes() {
		$this->items['tax_lines'] = array();
		parent::update_taxes();
	}

	/**
	 * Never stores anything.
	 *
	 * @return int Always 0.
	 */
	public function save() {
		return 0;
	}

	/**
	 * Nothing to delete (never stored).
	 *
	 * @param bool $force_delete Unused.
	 * @return bool
	 */
	public function delete( $force_delete = false ) {
		unset( $force_delete );
		return false;
	}
}
