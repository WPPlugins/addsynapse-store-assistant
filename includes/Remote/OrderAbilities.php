<?php
/**
 * 1.3.18 relay order tools (WooCommerce only), all read-only:
 *  - find-orders: orders newest first with filters (status, period / date range, customer email, search, totals, paging);
 *  - get-order: one order — line items, totals, billing and shipping address, the customer's note and the order notes;
 *  - preview-order: the draft of a new order checked against the shop and computed by WooCommerce in memory (nothing is
 *    saved; the order itself is created only by apply-changes after the owner's Approve — OrderCreate).
 * The shop owner asked their own AI app for these orders: the customer's name, email, phone and addresses are returned
 * to that app (owner decision 25/11/2026), never kept by AddSynapse in logs; the approval log keeps no personal data.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Abilities\ListOrdersAbility;
use Addsynapse\StoreAssistant\Abilities\Shared;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Order abilities of the remote route.
 */
final class OrderAbilities {

	public const FIND    = 'addsynapse-assistant/find-orders';
	public const GET     = 'addsynapse-assistant/get-order';
	public const PREVIEW = 'addsynapse-assistant/preview-order';

	public const MAX_ITEMS  = 200;
	public const MAX_NOTES  = 20;
	public const MAX_SEARCH = 500;

	/**
	 * Registers the abilities.
	 */
	public function register(): void {
		$meta = array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
		wp_register_ability(
			self::FIND,
			array(
				'label'               => __( 'Find orders', 'addsynapse-store-assistant' ),
				'description'         => 'Lists WooCommerce orders (read-only), newest first: id, number, date, status, total, currency, item count, payment method, shipping methods, paid (date_paid), customer name and edit link. Filters: status, period or date_from/date_to, customer_email, search (order number, customer name, email, address, product), total_min/total_max, limit, page.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array_merge(
						array(
							'status'         => array(
								'type'    => 'string',
								'pattern' => '^[a-z0-9_-]{1,30}$',
							),
							'customer_email' => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
							'search'         => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
							'total_min'      => array(
								'type'    => 'number',
								'minimum' => 0,
							),
							'total_max'      => array(
								'type'    => 'number',
								'minimum' => 0,
							),
							'limit'          => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => Shared::MAX_LIMIT,
							),
							'page'           => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 1000,
							),
						),
						Shared::period_input_properties()
					),
				),
				'execute_callback'    => array( $this, 'find' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $meta,
			)
		);
		wp_register_ability(
			self::GET,
			array(
				'label'               => __( 'Order details', 'addsynapse-store-assistant' ),
				'description'         => 'One WooCommerce order (read-only) by id or order number: status, dates, totals, payment and shipping methods, coupons, line items, billing and shipping address, the customer\'s note and the order notes.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'number' => array(
							'type'    => 'string',
							'pattern' => '^#?[A-Za-z0-9][A-Za-z0-9._/-]{0,39}$',
						),
					),
				),
				'execute_callback'    => array( $this, 'get' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $meta,
			)
		);
		wp_register_ability(
			self::PREVIEW,
			array(
				'label'               => __( 'Preview a new order', 'addsynapse-store-assistant' ),
				'description'         => 'Checks the draft of a new order against the shop now (products, variations, stock, coupons, customer, addresses) and lets WooCommerce compute the totals and taxes in memory. Saves nothing: the order is created only when the shop owner approves the change set.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array_merge(
					OrderFields::input_schema(),
					array( 'additionalProperties' => false )
				),
				'execute_callback'    => array( $this, 'preview' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $meta,
			)
		);
	}

	/**
	 * Preview callback.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>
	 */
	public function preview( $input = null ): array {
		return OrderCreate::preview( is_array( $input ) ? $input : array() );
	}

	/**
	 * Find callback.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function find( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$limit  = Shared::limit( $input['limit'] ?? 20 );
		$page   = max( 1, (int) ( $input['page'] ?? 1 ) );
		$status = (string) ( $input['status'] ?? 'any' );
		$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		$args   = array(
			'type'     => 'shop_order',
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'status'   => array_keys( wc_get_order_statuses() ),
		);
		if ( 'any' !== $status && '' !== $status ) {
			if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
				return new \WP_Error( 'addsynapse_assistant_invalid_status', 'Unknown order status. Valid: ' . implode( ', ', ListOrdersAbility::status_slugs() ) . ', any.' );
			}
			$args['status'] = array( 'wc-' . $status );
		}
		$range = null;
		if ( ! empty( $input['period'] ) || ! empty( $input['date_from'] ) || ! empty( $input['date_to'] ) ) {
			$range = Shared::period_range( (string) ( $input['period'] ?? '' ), (string) ( $input['date_from'] ?? '' ), (string) ( $input['date_to'] ?? '' ) );
			if ( is_wp_error( $range ) ) {
				return $range;
			}
			$args['date_created'] = $range['from']->getTimestamp() . '...' . $range['to']->getTimestamp();
		}
		$min = isset( $input['total_min'] ) ? (float) $input['total_min'] : null;
		$max = isset( $input['total_max'] ) ? (float) $input['total_max'] : null;
		if ( null !== $min && null !== $max && $min > $max ) {
			return new \WP_Error( 'addsynapse_assistant_invalid_total_range', 'total_min must not be greater than total_max.' );
		}
		$args  = ListOrdersAbility::add_total_filter( $args, $min, $max );
		$email = trim( (string) ( $input['customer_email'] ?? '' ) );
		if ( '' !== $email ) {
			$email = sanitize_email( $email );
			if ( ! is_email( $email ) ) {
				return new \WP_Error( 'addsynapse_assistant_invalid_email', 'customer_email is not a valid email address.' );
			}
			$args['billing_email'] = $email;
		}
		$search = trim( sanitize_text_field( (string) ( $input['search'] ?? '' ) ) );
		if ( '' !== $search ) {
			$ids = array_slice( array_map( 'intval', (array) wc_order_search( ltrim( $search, '#' ) ) ), 0, self::MAX_SEARCH );
			if ( ! $ids ) {
				$ids = array( 0 );
			}
			$hpos                              = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
			$args[ $hpos ? 'id' : 'post__in' ] = $ids;
		}
		$result = wc_get_orders( $args );
		$items  = array();
		foreach ( (array) $result->orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$items[] = ListOrdersAbility::row( $order ) + array(
					'customer'         => self::customer_name( $order ),
					// 1.3.20 (Giannis 25/11): how it ships and whether it is paid, on every row.
					'shipping_methods' => self::shipping_titles( $order ),
					'date_paid'        => self::date( $order->get_date_paid() ),
					'paid'             => null !== $order->get_date_paid(),
				);
			}
		}
		$out = array(
			'total' => (int) $result->total,
			'page'  => $page,
			'pages' => (int) $result->max_num_pages,
			'limit' => $limit,
			'items' => $items,
			'_data' => Shared::DATA_NOTICE,
		);
		if ( $range ) {
			$out['period'] = Shared::period_output( $range );
		}
		return $out;
	}

	/**
	 * Get callback.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$id     = (int) ( $input['id'] ?? 0 );
		$number = ltrim( trim( (string) ( $input['number'] ?? '' ) ), '#' );
		if ( ( $id > 0 ) === ( '' !== $number ) ) {
			return new \WP_Error( 'addsynapse_assistant_order_input', 'Give exactly one of id or number.' );
		}
		$order = $id > 0 ? self::by_id( $id ) : self::by_number( $number );
		if ( null === $order ) {
			return new \WP_Error( 'addsynapse_assistant_order_not_found', $id > 0 ? sprintf( 'No order with id %d.', $id ) : sprintf( 'No order with number %s.', $number ) );
		}
		return self::result( $order ) + array( '_data' => Shared::DATA_NOTICE );
	}

	/**
	 * A real shop order (not a refund or another order type), or null.
	 *
	 * @param int $id Order id.
	 */
	private static function by_id( int $id ): ?\WC_Order {
		$order = wc_get_order( $id );
		return $order instanceof \WC_Order && 'shop_order' === $order->get_type() ? $order : null;
	}

	/**
	 * An order by its displayed number (numbering plugins: the order-tracking filter); every candidate must show exactly it.
	 *
	 * @param string $number Order number without '#'.
	 */
	private static function by_number( string $number ): ?\WC_Order {
		$candidates = array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
		$mapped = apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number );
		if ( is_numeric( $mapped ) ) {
			$candidates[] = (int) $mapped;
		}
		if ( ctype_digit( $number ) ) {
			$candidates[] = (int) $number;
		}
		foreach ( array_unique( $candidates ) as $candidate ) {
			$order = $candidate > 0 ? self::by_id( $candidate ) : null;
			if ( $order && (string) $order->get_order_number() === $number ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * One order for the owner's AI app.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function result( \WC_Order $order ): array {
		$items     = array();
		$truncated = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			if ( count( $items ) >= self::MAX_ITEMS ) {
				$truncated = true;
				break;
			}
			$items[] = self::item( $item );
		}
		$shipping = array();
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$shipping[] = array(
				'method_title' => self::text( (string) $line->get_name() ),
				'total'        => Shared::money( (float) $line->get_total() ),
			);
		}
		$billing = array();
		$ship_to = array();
		foreach ( array_keys( OrderFields::ADDRESS ) as $key ) {
			$billing[ $key ] = self::text( (string) $order->{'get_billing_' . $key}() );
			if ( 'email' !== $key ) {
				$ship_to[ $key ] = self::text( (string) $order->{'get_shipping_' . $key}() );
			}
		}
		$notes = array();
		foreach ( (array) wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => self::MAX_NOTES,
			)
		) as $note ) {
			$notes[] = array(
				'date'        => isset( $note->date_created ) && $note->date_created ? $note->date_created->date( 'c' ) : '',
				'to_customer' => ! empty( $note->customer_note ),
				'by'          => isset( $note->added_by ) && 'system' !== $note->added_by ? 'person' : 'system',
				'text'        => self::text( (string) ( $note->content ?? '' ) ),
			);
		}
		$out = array(
			'id'                   => (int) $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'status'               => (string) $order->get_status(),
			'status_label'         => (string) wc_get_order_status_name( $order->get_status() ),
			'currency'             => (string) $order->get_currency(),
			'date_created'         => self::date( $order->get_date_created() ),
			'date_paid'            => self::date( $order->get_date_paid() ),
			'date_completed'       => self::date( $order->get_date_completed() ),
			'created_via'          => (string) $order->get_created_via(),
			'totals'               => array(
				'subtotal' => Shared::money( (float) $order->get_subtotal() ),
				'discount' => Shared::money( (float) $order->get_discount_total() ),
				'shipping' => Shared::money( (float) $order->get_shipping_total() ),
				'fees'     => Shared::money( (float) array_sum( array_map( static fn( $fee ) => (float) $fee->get_total(), $order->get_fees() ) ) ),
				'tax'      => Shared::money( (float) $order->get_total_tax() ),
				'total'    => Shared::money( (float) $order->get_total() ),
				'refunded' => Shared::money( (float) $order->get_total_refunded() ),
			),
			'payment_method_title' => self::text( (string) $order->get_payment_method_title() ),
			'shipping_lines'       => $shipping,
			'coupon_codes'         => array_values( array_map( 'strval', $order->get_coupon_codes() ) ),
			'customer'             => array(
				'type' => $order->get_customer_id() > 0 ? 'registered' : 'guest',
				'id'   => (int) $order->get_customer_id(),
				'name' => self::customer_name( $order ),
			),
			'billing'              => $billing,
			'shipping_address'     => $ship_to,
			'customer_note'        => self::text( (string) $order->get_customer_note() ),
			'notes'                => $notes,
			'item_count'           => (int) $order->get_item_count(),
			'items'                => $items,
			'edit_link'            => (string) $order->get_edit_order_url(),
		);
		if ( $truncated ) {
			$out['items_truncated'] = true;
		}
		return $out;
	}

	/**
	 * One line item (no item meta).
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return array<string,mixed>
	 */
	private static function item( \WC_Order_Item_Product $item ): array {
		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();
		$quantity     = (float) $item->get_quantity();
		$subtotal     = (float) $item->get_subtotal();
		$product      = $item->get_product();
		$exists       = $product instanceof \WC_Product && 'trash' !== get_post_status( $product_id );
		return array(
			'product_id'        => $product_id,
			'variation_id'      => $variation_id,
			'name'              => self::text( (string) $item->get_name() ),
			'sku'               => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
			'quantity'          => 0.0 === fmod( $quantity, 1.0 ) ? (int) $quantity : $quantity,
			'unit_price'        => $quantity > 0 ? Shared::money( $subtotal / $quantity ) : 0.0,
			'subtotal'          => Shared::money( $subtotal ),
			'total'             => Shared::money( (float) $item->get_total() ),
			'tax'               => Shared::money( (float) $item->get_total_tax() ),
			'product_edit_link' => $exists && $product_id > 0 ? Shared::product_edit_link( $product_id ) : '',
		);
	}

	/**
	 * The billing name (or company) of an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function customer_name( \WC_Order $order ): string {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return self::text( '' !== $name ? $name : (string) $order->get_billing_company() );
	}

	/**
	 * Store text as plain text.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Shipping method titles of an order (its shipping lines).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,string>
	 */
	private static function shipping_titles( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$title = self::text( (string) $line->get_name() );
			if ( '' !== $title ) {
				$out[] = $title;
			}
		}
		return $out;
	}

	/**
	 * ISO 8601 date, '' when absent.
	 *
	 * @param \WC_DateTime|null $date Date.
	 */
	private static function date( $date ): string {
		return $date ? $date->date( 'c' ) : '';
	}
}
