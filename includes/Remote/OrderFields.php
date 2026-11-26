<?php
/**
 * 1.3.18 relay (new order from the AI app, kind order_new): one NEW WooCommerce order — input schema, validation, in-memory
 * totals and the snapshot Undo compares against. Ported from the Connector's order tool (same rules, same argument names).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rules (docs/06 → «Νέα παραγγελία», CLAUDE.md → Security rules):
 *  - customers: a GUEST order with the billing/shipping details the manager typed, or an EXISTING customer
 *    (by id or exact email). A WordPress user / customer account is NEVER created or edited;
 *  - products must exist and be purchasable (a variation must belong to the given parent), stock follows the
 *    WooCommerce backorder settings (not enough stock → refused, backorders allowed → warning);
 *  - totals are computed by WooCommerce itself (calculate_totals, store tax settings) on an order that is never
 *    saved (OrderPreview) — the draft writes nothing to the order tables;
 *  - never a payment: only a payment method TITLE is stored; the currency is always the store currency.
 */
final class OrderFields {

	public const CREATED_VIA = 'addsynapse';
	public const META        = '_addsynapse_assistant_changeset';

	public const STATUSES    = array( 'pending', 'on-hold', 'processing', 'completed' );
	public const MAX_LINES   = 50;
	public const MAX_QTY     = 999;
	public const MAX_COUPONS = 5;
	public const MAX_PRICE   = 1000000;
	public const MAX_SHIP    = 100000;

	/**
	 * Address fields (WooCommerce order props) → max length. `email` only for billing.
	 */
	public const ADDRESS = array(
		'first_name' => 100,
		'last_name'  => 100,
		'company'    => 100,
		'address_1'  => 200,
		'address_2'  => 200,
		'city'       => 100,
		'state'      => 100,
		'postcode'   => 20,
		'country'    => 2,
		'email'      => 100,
		'phone'      => 40,
	);

	/**
	 * Input schema (the hub sends the same definition to the model; the commerce contract new-order tool).
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'lines' ),
			'properties'           => array(
				'lines'                => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::MAX_LINES,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'product_id', 'quantity' ),
						'properties'           => array(
							'product_id'   => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'variation_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'quantity'     => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => self::MAX_QTY,
							),
							'unit_price'   => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => self::MAX_PRICE,
							),
						),
					),
				),
				'customer_id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'customer_email'       => array(
					'type'      => 'string',
					'minLength' => 3,
					'maxLength' => 100,
				),
				'billing'              => self::address_schema( true ),
				'shipping_address'     => self::address_schema( false ),
				'shipping'             => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'method_title' ),
					'properties'           => array(
						'method_title' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
						'total'        => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => self::MAX_SHIP,
						),
					),
				),
				'coupons'              => array(
					'type'     => 'array',
					'maxItems' => self::MAX_COUPONS,
					'items'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 50,
					),
				),
				'customer_note'        => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
				'status'               => array(
					'type' => 'string',
					'enum' => self::STATUSES,
				),
				'payment_method_title' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'send_emails'          => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Schema of an address (billing with email, shipping without).
	 *
	 * @param bool $email Billing (has email).
	 * @return array<string,mixed>
	 */
	private static function address_schema( bool $email ): array {
		$props = array();
		foreach ( self::ADDRESS as $key => $max ) {
			if ( 'email' === $key && ! $email ) {
				continue;
			}
			$props[ $key ] = 'country' === $key ? array(
				'type'    => 'string',
				'pattern' => '^[A-Za-z]{2}$',
			) : array(
				'type'      => 'string',
				'maxLength' => $max,
			);
		}
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $props,
		);
	}

	/**
	 * Validates and normalises the input into the stored spec (everything the manager/model typed, sanitised).
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function spec( array $input ) {
		$lines = array();
		foreach ( array_values( (array) ( $input['lines'] ?? array() ) ) as $n => $line ) {
			$line = (array) $line;
			$qty  = (int) ( $line['quantity'] ?? 0 );
			if ( (int) ( $line['product_id'] ?? 0 ) < 1 || $qty < 1 || $qty > self::MAX_QTY ) {
				return self::error( sprintf( 'Line %d: a product id and a quantity of 1-%d are required.', $n + 1, self::MAX_QTY ) );
			}
			$price = $line['unit_price'] ?? null;
			if ( null !== $price && ( ! is_numeric( $price ) || (float) $price < 0 || (float) $price > self::MAX_PRICE ) ) {
				return self::error( sprintf( 'Line %d: invalid unit price.', $n + 1 ) );
			}
			$lines[] = array(
				'product_id'   => (int) $line['product_id'],
				'variation_id' => max( 0, (int) ( $line['variation_id'] ?? 0 ) ),
				'quantity'     => $qty,
				'unit_price'   => null === $price ? null : (string) wc_format_decimal( $price ),
			);
		}
		if ( ! $lines || count( $lines ) > self::MAX_LINES ) {
			return self::error( sprintf( 'An order needs 1-%d lines.', self::MAX_LINES ) );
		}

		$customer = self::customer( $input );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$billing = null;
		if ( isset( $input['billing'] ) ) {
			$billing = self::address( (array) $input['billing'], 'billing' );
			if ( is_wp_error( $billing ) ) {
				return $billing;
			}
		}
		$shipping_address = null;
		if ( isset( $input['shipping_address'] ) ) {
			$shipping_address = self::address( (array) $input['shipping_address'], 'shipping' );
			if ( is_wp_error( $shipping_address ) ) {
				return $shipping_address;
			}
		}
		if ( 'guest' === $customer['type'] ) {
			// A guest order needs a name and a way to reach the customer, typed by the manager (never invented).
			if ( ! $billing || '' === $billing['first_name'] || '' === $billing['last_name'] || ( '' === $billing['email'] && '' === $billing['phone'] ) ) {
				return self::error( 'A guest order needs billing first_name, last_name and an email or phone, as the manager gave them. Ask the manager for the missing details (never invent them), or use an existing customer (customer_id or customer_email). A customer account is never created.' );
			}
		}

		$shipping = null;
		if ( isset( $input['shipping'] ) ) {
			$title = self::text( (string) ( $input['shipping']['method_title'] ?? '' ), 100 );
			$total = $input['shipping']['total'] ?? 0;
			if ( '' === $title || ! is_numeric( $total ) || (float) $total < 0 || (float) $total > self::MAX_SHIP ) {
				return self::error( 'Invalid shipping line: method_title and a total of 0 or more are required.' );
			}
			$shipping = array(
				'method_title' => $title,
				'total'        => (string) wc_format_decimal( $total ),
			);
		}

		$coupons = array();
		foreach ( (array) ( $input['coupons'] ?? array() ) as $code ) {
			$code = wc_format_coupon_code( self::text( (string) $code, 50 ) );
			if ( '' === $code ) {
				continue;
			}
			if ( ! wc_get_coupon_id_by_code( $code ) ) {
				return self::error( sprintf( 'The coupon "%s" does not exist. Only existing coupons can be used; coupons are never created.', $code ) );
			}
			$coupons[] = $code;
		}
		$coupons = array_values( array_unique( $coupons ) );
		if ( count( $coupons ) > self::MAX_COUPONS ) {
			return self::error( sprintf( 'At most %d coupons per order.', self::MAX_COUPONS ) );
		}

		$status = (string) ( $input['status'] ?? 'pending' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return self::error( 'Invalid status: pending, on-hold, processing or completed.' );
		}
		return array(
			'lines'                => $lines,
			'customer'             => $customer,
			'billing'              => $billing,
			'shipping_address'     => $shipping_address,
			'shipping'             => $shipping,
			'coupons'              => $coupons,
			'customer_note'        => mb_substr( sanitize_textarea_field( (string) ( $input['customer_note'] ?? '' ) ), 0, 1000 ),
			'status'               => $status,
			'payment_method_title' => self::text( (string) ( $input['payment_method_title'] ?? '' ), 100 ),
			'send_emails'          => ! empty( $input['send_emails'] ),
		);
	}

	/**
	 * The customer: an existing one (id or exact email — only an EXISTING account; nothing is ever created) or a guest.
	 * Returns nothing about the account beyond what was typed (the id and the typed email).
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array{type:string,id:int,email:string}|WP_Error
	 */
	private static function customer( array $input ) {
		$id    = (int) ( $input['customer_id'] ?? 0 );
		$email = trim( (string) ( $input['customer_email'] ?? '' ) );
		if ( $id > 0 && '' !== $email ) {
			return self::error( 'Give either customer_id or customer_email, not both.' );
		}
		if ( $id > 0 ) {
			if ( ! get_userdata( $id ) ) {
				return self::error( sprintf( 'There is no customer account #%d. Use an existing customer, or a guest order with the billing details the manager gives (a customer account is never created).', $id ) );
			}
			return array(
				'type'  => 'existing',
				'id'    => $id,
				'email' => '',
			);
		}
		if ( '' !== $email ) {
			$clean = sanitize_email( $email );
			$user  = is_email( $clean ) ? get_user_by( 'email', $clean ) : false;
			if ( ! $user ) {
				return self::error( 'No customer account has exactly this email. Ask the manager whether to create a guest order with billing details instead (a customer account is never created).' );
			}
			return array(
				'type'  => 'existing',
				'id'    => (int) $user->ID,
				'email' => $clean,
			);
		}
		return array(
			'type'  => 'guest',
			'id'    => 0,
			'email' => '',
		);
	}

	/**
	 * Sanitised address (all keys present, '' = not given). Country/state must be valid WooCommerce codes.
	 *
	 * @param array<string,mixed> $raw  Typed address.
	 * @param string              $type billing|shipping.
	 * @return array<string,string>|WP_Error
	 */
	private static function address( array $raw, string $type ) {
		$out = array();
		foreach ( self::ADDRESS as $key => $max ) {
			if ( 'email' === $key && 'shipping' === $type ) {
				continue;
			}
			$out[ $key ] = self::text( (string) ( $raw[ $key ] ?? '' ), $max );
		}
		if ( 'billing' === $type && '' !== $out['email'] ) {
			$email = sanitize_email( $out['email'] );
			if ( ! is_email( $email ) ) {
				return self::error( sprintf( 'The %s email is not a valid email address.', $type ) );
			}
			$out['email'] = $email;
		}
		if ( '' !== $out['phone'] ) {
			$phone  = wc_sanitize_phone_number( $out['phone'] );
			$digits = preg_replace( '/\D/', '', $phone );
			if ( strlen( (string) $digits ) < 5 || strlen( (string) $digits ) > 20 ) {
				return self::error( sprintf( 'The %s phone is not a valid phone number.', $type ) );
			}
			$out['phone'] = $phone;
		}
		$countries = WC()->countries;
		if ( '' !== $out['country'] ) {
			$out['country'] = strtoupper( $out['country'] );
			if ( ! array_key_exists( $out['country'], (array) $countries->get_countries() ) ) {
				return self::error( sprintf( 'The %1$s country "%2$s" is not a valid WooCommerce country code (ISO 3166-1 alpha-2, e.g. GR).', $type, $out['country'] ) );
			}
		}
		if ( '' !== $out['state'] ) {
			$states = '' !== $out['country'] ? $countries->get_states( $out['country'] ) : false;
			if ( is_array( $states ) && $states ) {
				$code = self::state_code( $out['state'], $states );
				if ( '' === $code ) {
					return self::error( sprintf( 'The %1$s state "%2$s" is not a state of %3$s in WooCommerce.', $type, $out['state'], $out['country'] ) );
				}
				$out['state'] = $code;
			}
		}
		return $out;
	}

	/**
	 * State code from a code or a name ('' = none matches).
	 *
	 * @param string               $state  Typed state.
	 * @param array<string,string> $states Code → name.
	 */
	private static function state_code( string $state, array $states ): string {
		if ( isset( $states[ strtoupper( $state ) ] ) ) {
			return strtoupper( $state );
		}
		$wanted = mb_strtolower( remove_accents( $state ) );
		foreach ( $states as $code => $name ) {
			if ( mb_strtolower( remove_accents( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ) ) === $wanted ) {
				return (string) $code;
			}
		}
		return '';
	}

	/**
	 * Resolves the lines against the CURRENT catalogue: products/variations, prices, purchasability, stock.
	 * Used at plan time and again right before the order is created.
	 *
	 * @param array<string,mixed> $spec Spec.
	 * @return array{lines:array<int,array<string,mixed>>,warnings:array<int,string>}|WP_Error
	 */
	public static function check( array $spec ) {
		$lines    = array();
		$warnings = array();
		$wanted   = array();
		foreach ( $spec['lines'] as $n => $line ) {
			$where   = sprintf( 'Line %d', $n + 1 );
			$product = wc_get_product( (int) $line['product_id'] );
			if ( ! $product || 'trash' === $product->get_status() ) {
				return self::error( sprintf( '%1$s: product #%2$d not found.', $where, $line['product_id'] ) );
			}
			if ( $line['variation_id'] > 0 ) {
				$variation = wc_get_product( (int) $line['variation_id'] );
				if ( ! $variation instanceof \WC_Product_Variation || (int) $variation->get_parent_id() !== (int) $product->get_id() || 'trash' === $variation->get_status() ) {
					return self::error( sprintf( '%1$s: variation #%2$d does not belong to product #%3$d.', $where, $line['variation_id'], $line['product_id'] ) );
				}
				$product = $variation;
			}
			if ( $product->is_type( 'variable' ) ) {
				return self::error( sprintf( '%1$s: "%2$s" is a variable product — give the variation_id of the variation to order (get_product lists them).', $where, self::label( $product ) ) );
			}
			if ( ! $product->is_type( array( 'simple', 'variation' ) ) ) {
				return self::error( sprintf( '%1$s: "%2$s" is a %3$s product and cannot be ordered from this chat.', $where, self::label( $product ), $product->get_type() ) );
			}
			if ( $product instanceof \WC_Product_Variation && 'publish' !== $product->get_status() ) {
				return self::error( sprintf( '%1$s: the variation "%2$s" is disabled.', $where, self::label( $product ) ) );
			}
			$store_price = (string) $product->get_price();
			$unit        = null !== $line['unit_price'] ? (string) $line['unit_price'] : $store_price;
			if ( ! $product->is_purchasable() && ( null === $line['unit_price'] || ! in_array( $product->get_status(), array( 'publish', 'private' ), true ) ) ) {
				return self::error( sprintf( '%1$s: "%2$s" cannot be purchased (not published or no price). A unit_price can be given for a published product without a price.', $where, self::label( $product ) ) );
			}
			if ( $product->is_sold_individually() && $line['quantity'] > 1 ) {
				return self::error( sprintf( '%1$s: "%2$s" is sold individually (quantity 1).', $where, self::label( $product ) ) );
			}
			$managed                   = $product->managing_stock() ? (int) $product->get_stock_managed_by_id() : 0;
			$key                       = $managed > 0 ? 'm' . $managed : 'p' . $product->get_id();
			$wanted[ $key ]['qty']     = ( $wanted[ $key ]['qty'] ?? 0 ) + (int) $line['quantity'];
			$wanted[ $key ]['product'] = $product;
			$wanted[ $key ]['managed'] = $managed;
			$lines[]                   = array(
				'product'      => $product,
				'product_id'   => $product instanceof \WC_Product_Variation ? (int) $product->get_parent_id() : (int) $product->get_id(),
				'variation_id' => $product instanceof \WC_Product_Variation ? (int) $product->get_id() : 0,
				'name'         => self::label( $product ),
				'sku'          => (string) $product->get_sku( 'edit' ),
				'quantity'     => (int) $line['quantity'],
				'unit_price'   => (string) wc_format_decimal( $unit, wc_get_price_decimals() ),
				'store_price'  => '' === $store_price ? '' : (string) wc_format_decimal( $store_price, wc_get_price_decimals() ),
				'override'     => null !== $line['unit_price'] && ( '' === $store_price || abs( (float) $line['unit_price'] - (float) $store_price ) > 0.00001 ),
			);
		}//end foreach
		foreach ( $wanted as $entry ) {
			$product = $entry['product'];
			$name    = self::label( $product );
			if ( $entry['managed'] > 0 ) {
				$holder = $entry['managed'] === (int) $product->get_id() ? $product : wc_get_product( $entry['managed'] );
				$stock  = $holder ? (int) $holder->get_stock_quantity() : 0;
				if ( $entry['qty'] > $stock ) {
					if ( ! $product->backorders_allowed() ) {
						return self::error( sprintf( 'Not enough stock for "%1$s": %2$d in stock, %3$d ordered, and the product does not allow backorders.', $name, $stock, $entry['qty'] ) );
					}
					$warnings[] = sprintf( 'Backorder: "%1$s" (%2$d in stock, %3$d ordered).', $name, $stock, $entry['qty'] );
				}
			} elseif ( 'outofstock' === $product->get_stock_status() ) {
				return self::error( sprintf( '"%s" is out of stock.', $name ) );
			} elseif ( 'onbackorder' === $product->get_stock_status() ) {
				$warnings[] = sprintf( 'Backorder: "%1$s" (%2$d in stock, %3$d ordered).', $name, 0, $entry['qty'] );
			}
		}
		return array(
			'lines'    => $lines,
			'warnings' => $warnings,
		);
	}

	/**
	 * Fills an order (in memory): addresses, customer, items, shipping line, note, payment title. Nothing is saved here.
	 *
	 * @param \WC_Order                      $order   Order.
	 * @param array<string,mixed>            $spec    Spec.
	 * @param array<int,array<string,mixed>> $lines   Checked lines.
	 */
	public static function fill( \WC_Order $order, array $spec, array $lines ): void {
		$order->set_created_via( self::CREATED_VIA );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
		$order->set_status( 'pending' );
		if ( 'existing' === $spec['customer']['type'] ) {
			$order->set_customer_id( (int) $spec['customer']['id'] );
			// Read-only: the customer's SAVED addresses (never written back to the account).
			$customer = new \WC_Customer( (int) $spec['customer']['id'] );
			foreach ( array_keys( self::ADDRESS ) as $key ) {
				$getter = 'get_billing_' . $key;
				$setter = 'set_billing_' . $key;
				$order->$setter( (string) $customer->$getter( 'edit' ) );
				if ( 'email' !== $key ) {
					$getter = 'get_shipping_' . $key;
					$setter = 'set_shipping_' . $key;
					$order->$setter( (string) $customer->$getter( 'edit' ) );
				}
			}
		}
		foreach ( array( 'billing', 'shipping_address' ) as $which ) {
			if ( ! is_array( $spec[ $which ] ) ) {
				continue;
			}
			$prefix = 'billing' === $which ? 'set_billing_' : 'set_shipping_';
			foreach ( $spec[ $which ] as $key => $value ) {
				if ( '' !== $value || 'guest' === $spec['customer']['type'] ) {
					$setter = $prefix . $key;
					$order->$setter( (string) $value );
				}
			}
		}
		$order->set_customer_note( (string) $spec['customer_note'] );
		$order->set_payment_method( '' );
		$order->set_payment_method_title( (string) $spec['payment_method_title'] );
		foreach ( $lines as $line ) {
			$product  = $line['product'];
			$subtotal = wc_get_price_excluding_tax(
				$product,
				array(
					'qty'   => $line['quantity'],
					'price' => $line['unit_price'],
				)
			);
			$item     = new \WC_Order_Item_Product();
			$item->set_props(
				array(
					'name'         => $product->get_name(),
					'tax_class'    => $product->get_tax_class(),
					'product_id'   => $line['product_id'],
					'variation_id' => $line['variation_id'],
					'variation'    => $product instanceof \WC_Product_Variation ? $product->get_variation_attributes() : array(),
					'quantity'     => $line['quantity'],
					'subtotal'     => $subtotal,
					'total'        => $subtotal,
				)
			);
			$order->add_item( $item );
		}//end foreach
		if ( is_array( $spec['shipping'] ) ) {
			$item = new \WC_Order_Item_Shipping();
			$item->set_method_title( (string) $spec['shipping']['method_title'] );
			$item->set_method_id( 'other' );
			$item->set_total( (string) $spec['shipping']['total'] );
			$order->add_item( $item );
		}
	}

	/**
	 * Applies the coupons and lets WooCommerce compute the totals (taxes per store settings).
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $spec  Spec.
	 * @return true|WP_Error
	 */
	public static function calculate( \WC_Order $order, array $spec ) {
		$order->calculate_totals( true );
		foreach ( $spec['coupons'] as $code ) {
			$applied = $order->apply_coupon( $code );
			if ( is_wp_error( $applied ) ) {
				return self::error( sprintf( 'The coupon "%1$s" cannot be used for this order: %2$s', $code, wp_strip_all_tags( $applied->get_error_message() ) ) );
			}
		}
		$order->calculate_totals( true );
		return true;
	}

	/**
	 * Totals as fixed decimal strings (what the preview shows and apply compares).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,string>
	 */
	public static function totals( \WC_Order $order ): array {
		$dp = wc_get_price_decimals();
		return array(
			'subtotal' => (string) wc_format_decimal( $order->get_subtotal(), $dp ),
			'discount' => (string) wc_format_decimal( $order->get_discount_total(), $dp ),
			'shipping' => (string) wc_format_decimal( $order->get_shipping_total(), $dp ),
			'tax'      => (string) wc_format_decimal( $order->get_total_tax(), $dp ),
			'total'    => (string) wc_format_decimal( $order->get_total(), $dp ),
		);
	}

	/**
	 * Per-line amounts of a computed order (same order as the lines).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,string>>
	 */
	public static function line_amounts( \WC_Order $order ): array {
		$dp  = wc_get_price_decimals();
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$out[] = array(
				'subtotal' => (string) wc_format_decimal( $item->get_subtotal(), $dp ),
				'total'    => (string) wc_format_decimal( $item->get_total(), $dp ),
				'tax'      => (string) wc_format_decimal( $item->get_total_tax(), $dp ),
			);
		}
		return $out;
	}

	/**
	 * Computes the order in memory (never saved) → totals + per-line amounts.
	 *
	 * @param array<string,mixed>            $spec  Spec.
	 * @param array<int,array<string,mixed>> $lines Checked lines.
	 * @return array{totals:array<string,string>,lines:array<int,array<string,string>>}|WP_Error
	 */
	public static function preview( array $spec, array $lines ) {
		$order = new OrderPreview();
		self::fill( $order, $spec, $lines );
		$ok = self::calculate( $order, $spec );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return array(
			'totals' => self::totals( $order ),
			'lines'  => self::line_amounts( $order ),
		);
	}

	/**
	 * What undo compares: status, items, totals, customer and the modified date as the apply left them.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function snapshot( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$items[] = array( (int) $item->get_product_id(), (int) $item->get_variation_id(), (int) $item->get_quantity(), (string) wc_format_decimal( $item->get_total(), wc_get_price_decimals() ) );
		}
		$modified = $order->get_date_modified();
		return array(
			'status'      => (string) $order->get_status(),
			'customer_id' => (int) $order->get_customer_id(),
			'items'       => $items,
			'totals'      => self::totals( $order ),
			'modified'    => $modified ? (int) $modified->getTimestamp() : 0,
		);
	}

	/**
	 * Turns WooCommerce emails off (unless the manager asked for them) while an order is created or undone.
	 * Returns the function that turns them back on.
	 *
	 * @return callable
	 */
	public static function mute_emails(): callable {
		$ids = array();
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			foreach ( (array) WC()->mailer()->get_emails() as $email ) {
				if ( is_object( $email ) && isset( $email->id ) ) {
					$ids[] = (string) $email->id;
				}
			}
		}
		foreach ( $ids as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', PHP_INT_MAX );
		}
		// Deferred emails would be sent at shutdown, after the filters are gone.
		add_filter( 'woocommerce_defer_transactional_emails', '__return_false', PHP_INT_MAX );
		return static function () use ( $ids ): void {
			foreach ( $ids as $id ) {
				remove_filter( 'woocommerce_email_enabled_' . $id, '__return_false', PHP_INT_MAX );
			}
			remove_filter( 'woocommerce_defer_transactional_emails', '__return_false', PHP_INT_MAX );
		};
	}

	/**
	 * The name people read for a product or variation (with its SKU when it has one).
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function label( \WC_Product $product ): string {
		$name = wp_strip_all_tags( html_entity_decode( (string) $product->get_name(), ENT_QUOTES, 'UTF-8' ) );
		$sku  = (string) $product->get_sku( 'edit' );
		return '' !== $sku ? $name . ' (' . $sku . ')' : $name;
	}

	/**
	 * Plain text, trimmed and cut.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max chars.
	 */
	private static function text( string $value, int $max ): string {
		return mb_substr( trim( sanitize_text_field( $value ) ), 0, $max );
	}

	/**
	 * Tool error.
	 *
	 * @param string $message English message for the model.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( 'addsynapse_assistant_order_invalid', $message );
	}
}
