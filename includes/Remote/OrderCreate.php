<?php
/**
 * 1.3.18 unit of the remote route (read-state / apply-changes): order_new — ONE new WooCommerce order (a phone or manual
 * order the owner asked their AI app for). id 0 → created on Approve through WooCommerce CRUD (HPOS-safe, created_via
 * addsynapse), pending first, the totals compared with the draft's, then moved to the requested status (WooCommerce's own
 * hooks reduce stock, count coupons and sales). Emails only when the draft asked for them. Undo: only an order this plugin
 * created and still as Approve left it (status and totals) — cancelled (stock and coupon usage given back) and moved to the
 * trash, never deleted for good. A customer account is never created or changed.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * New orders.
 */
final class OrderCreate {

	public const KIND = 'order_new';

	/** Keys of a draft (the input of the Connector's new-order tool, plus the totals the draft showed). */
	public const FIELDS = array( 'lines', 'customer_id', 'customer_email', 'billing', 'shipping_address', 'shipping', 'coupons', 'customer_note', 'status', 'payment_method_title', 'send_emails', 'totals' );

	/** Order meta: the typed customer email and whether emails were asked for (read back as the draft's values). */
	public const DRAFT_META = '_addsynapse_assistant_order_draft';

	/**
	 * Whether the current user may create orders.
	 */
	public static function can_create(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * An order this plugin created, not in the trash.
	 *
	 * @param int $id Id.
	 */
	public static function created( int $id ): ?\WC_Order {
		if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || in_array( $order->get_status(), array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		if ( OrderFields::CREATED_VIA !== $order->get_created_via() || '' === (string) $order->get_meta( OrderFields::META ) || ! self::can_create() ) {
			return null;
		}
		return $order;
	}

	/**
	 * Values of a created order, in the shape of the draft.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function values( \WC_Order $order ): array {
		$dp    = wc_get_price_decimals();
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$qty     = max( 1, (int) $item->get_quantity() );
			$lines[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => $qty,
				'unit_price'   => (string) wc_format_decimal( (float) $item->get_subtotal() / $qty, $dp ),
			);
		}
		$shipping = null;
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$shipping = array(
				'method_title' => (string) $item->get_method_title(),
				'total'        => (string) wc_format_decimal( $item->get_total(), $dp ),
			);
			break;
		}
		$billing = array();
		$ship_to = array();
		foreach ( array_keys( OrderFields::ADDRESS ) as $key ) {
			$billing[ $key ] = (string) $order->{'get_billing_' . $key}( 'edit' );
			if ( 'email' !== $key ) {
				$ship_to[ $key ] = (string) $order->{'get_shipping_' . $key}( 'edit' );
			}
		}
		$draft = (array) $order->get_meta( self::DRAFT_META );
		return array(
			'lines'                => $lines,
			'customer_id'          => (int) $order->get_customer_id(),
			'customer_email'       => (string) ( $draft['customer_email'] ?? '' ),
			'billing'              => $billing,
			'shipping_address'     => $ship_to,
			'shipping'             => $shipping,
			'coupons'              => array_values( array_map( 'strval', $order->get_coupon_codes() ) ),
			'customer_note'        => (string) $order->get_customer_note( 'edit' ),
			'status'               => (string) $order->get_status(),
			'payment_method_title' => (string) $order->get_payment_method_title( 'edit' ),
			'send_emails'          => ! empty( $draft['send_emails'] ),
			'totals'               => OrderFields::totals( $order ),
		);
	}

	/**
	 * Current state of one created order.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>
	 */
	public static function read( int $id ): array {
		$order = self::created( $id );
		if ( null === $order ) {
			return array(
				'kind'   => self::KIND,
				'id'     => $id,
				'exists' => false,
				'reason' => 'No order AddSynapse created with this id (or it is in the trash).',
				'values' => array(),
			);
		}
		return array(
			'kind'   => self::KIND,
			'id'     => $id,
			'exists' => true,
			/* translators: %s: order number */
			'name'   => sprintf( __( 'Order #%s', 'addsynapse-store-assistant' ), $order->get_order_number() ),
			'number' => (string) $order->get_order_number(),
			'link'   => (string) $order->get_edit_order_url(),
			'values' => self::values( $order ),
		);
	}

	/**
	 * The draft of a new order, checked against the shop now and computed by WooCommerce in memory (nothing is saved):
	 * what the preview-order ability returns and Approve compares.
	 *
	 * @param array<string,mixed> $input Draft (FIELDS without totals).
	 * @return array<string,mixed> {ok:true, lines, totals, currency, warnings, customer} or {ok:false, error}.
	 */
	public static function preview( array $input ): array {
		$spec = OrderFields::spec( array_intersect_key( $input, array_flip( array_diff( self::FIELDS, array( 'totals' ) ) ) ) );
		if ( is_wp_error( $spec ) ) {
			return array(
				'ok'    => false,
				'error' => $spec->get_error_message(),
			);
		}
		$checked = OrderFields::check( $spec );
		if ( is_wp_error( $checked ) ) {
			return array(
				'ok'    => false,
				'error' => $checked->get_error_message(),
			);
		}
		$computed = OrderFields::preview( $spec, $checked['lines'] );
		if ( is_wp_error( $computed ) ) {
			return array(
				'ok'    => false,
				'error' => $computed->get_error_message(),
			);
		}
		$lines = array();
		foreach ( $checked['lines'] as $n => $line ) {
			$lines[] = array(
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) $line['variation_id'],
				'name'         => (string) $line['name'],
				'sku'          => (string) $line['sku'],
				'quantity'     => (int) $line['quantity'],
				'unit_price'   => (string) $line['unit_price'],
				'store_price'  => (string) $line['store_price'],
				'override'     => (bool) $line['override'],
			) + ( $computed['lines'][ $n ] ?? array() );
		}
		return array(
			'ok'       => true,
			'lines'    => $lines,
			'totals'   => $computed['totals'],
			'currency' => get_woocommerce_currency(),
			'warnings' => $checked['warnings'],
			'customer' => array(
				'type' => (string) $spec['customer']['type'],
				'id'   => (int) $spec['customer']['id'],
			),
			'status'   => (string) $spec['status'],
		);
	}

	/**
	 * Validates the values of a new order ('' = fine).
	 *
	 * @param array<string,mixed> $set Values.
	 */
	public static function invalid( array $set ): string {
		foreach ( array_keys( $set ) as $field ) {
			if ( ! in_array( $field, self::FIELDS, true ) ) {
				return 'Field not allowed: ' . $field;
			}
		}
		$spec = OrderFields::spec( array_diff_key( $set, array( 'totals' => 1 ) ) );
		return is_wp_error( $spec ) ? $spec->get_error_message() : '';
	}

	/**
	 * Why the unit may not be written now ('' = it may).
	 *
	 * @param int                      $id     Id.
	 * @param array<string,mixed>|null $expect Expected values.
	 * @param array<string,mixed>|null $set    New values.
	 */
	public static function problem( int $id, ?array $expect, ?array $set ): string {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 'WooCommerce is not active.';
		}
		if ( ! self::can_create() ) {
			return 'This user may not add orders.';
		}
		if ( 0 === $id ) {
			if ( null !== $expect || null === $set ) {
				return 'A new order needs its values and no expected values.';
			}
			$invalid = self::invalid( $set );
			if ( '' !== $invalid ) {
				return $invalid;
			}
			$now = self::preview( $set );
			if ( empty( $now['ok'] ) ) {
				return 'Not created: ' . $now['error'] . ' Ask for a new draft.';
			}
			if ( isset( $set['totals'] ) && ! self::same_totals( (array) $set['totals'], $now['totals'] ) ) {
				return sprintf( 'Not created: the total changed since the draft (%1$s → %2$s; prices, taxes, stock or coupons changed). Ask for a new draft.', (string) ( $set['totals']['total'] ?? '' ), $now['totals']['total'] );
			}
			return '';
		}
		if ( null !== $set ) {
			return 'A created order is only undone (cancelled and moved to the trash) here.';
		}
		if ( null === $expect ) {
			return 'Expected values are required.';
		}
		$now = self::read( $id );
		if ( empty( $now['exists'] ) ) {
			return (string) $now['reason'];
		}
		if ( ! self::holds( (array) $now['values'], $expect ) ) {
			return 'The order changed after it was created (status or totals); it was kept. Cancel or refund it in WooCommerce if needed.';
		}
		return '';
	}

	/**
	 * Whether the order still is as Approve left it: status and totals (an order that was paid, shipped or edited since is
	 * never cancelled by an Undo).
	 *
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( array $now, array $expect ): bool {
		// A draft without a status created a pending order.
		if ( ( $now['status'] ?? null ) !== ( $expect['status'] ?? 'pending' ) ) {
			return false;
		}
		return ! isset( $expect['totals'] ) || self::same_totals( (array) $expect['totals'], (array) ( $now['totals'] ?? array() ) );
	}

	/**
	 * Same amounts (as numbers; "12.5" = "12.50").
	 *
	 * @param array<string,mixed> $a Totals.
	 * @param array<string,mixed> $b Totals.
	 */
	private static function same_totals( array $a, array $b ): bool {
		foreach ( array( 'subtotal', 'discount', 'shipping', 'tax', 'total' ) as $key ) {
			if ( abs( (float) ( $a[ $key ] ?? 0 ) - (float) ( $b[ $key ] ?? 0 ) ) > 0.0001 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Creates the order (id 0) or cancels it and moves it to the trash (null).
	 *
	 * @param int                      $id        Id.
	 * @param array<string,mixed>|null $set       Values.
	 * @param string                   $changeset Change set id (marker of a created order).
	 * @return int Id written.
	 * @throws \RuntimeException When WooCommerce refuses.
	 */
	public static function write( int $id, ?array $set, string $changeset ): int {
		if ( null === $set ) {
			$order = self::created( $id );
			if ( null === $order ) {
				throw new \RuntimeException( 'No order AddSynapse created with this id.' );
			}
			if ( '' !== $changeset && (string) $order->get_meta( OrderFields::META ) !== $changeset ) {
				throw new \RuntimeException( 'This order was not created by this change set; it was kept.' );
			}
			$unmute = OrderFields::mute_emails();
			try {
				/* translators: %s: change set number */
				$order->update_status( 'cancelled', sprintf( __( 'Undone from AddSynapse (change set #%s): cancelled and moved to the trash.', 'addsynapse-store-assistant' ), $changeset ), true );
				$order->delete( false );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as data, never printed raw.
				throw new \RuntimeException( 'WooCommerce refused the change: ' . $e->getMessage() );
			} finally {
				$unmute();
			}
			return $id;
		}//end if
		$marker = '' !== $changeset ? $changeset : '1';
		$found  = self::find( $marker );
		if ( $found ) {
			// An earlier, interrupted Approve of this change set: never a second order.
			return (int) $found->get_id();
		}
		$spec = OrderFields::spec( array_diff_key( $set, array( 'totals' => 1 ) ) );
		if ( is_wp_error( $spec ) ) {
			throw new \RuntimeException( $spec->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- data.
		}
		$checked = OrderFields::check( $spec );
		if ( is_wp_error( $checked ) ) {
			throw new \RuntimeException( $checked->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- data.
		}
		$unmute = empty( $spec['send_emails'] ) ? OrderFields::mute_emails() : null;
		$order  = null;
		try {
			$order = new \WC_Order();
			OrderFields::fill( $order, $spec, $checked['lines'] );
			$order->update_meta_data( OrderFields::META, $marker );
			$order->update_meta_data(
				self::DRAFT_META,
				array(
					'customer_email' => (string) ( $set['customer_email'] ?? '' ),
					'send_emails'    => ! empty( $spec['send_emails'] ),
				)
			);
			$order->save();
			$ok = OrderFields::calculate( $order, $spec );
			if ( is_wp_error( $ok ) ) {
				throw new \RuntimeException( $ok->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- data.
			}
			if ( isset( $set['totals'] ) && ! self::same_totals( (array) $set['totals'], OrderFields::totals( $order ) ) ) {
				throw new \RuntimeException( sprintf( 'WooCommerce computed a different total (%1$s instead of %2$s). Ask for a new draft.', OrderFields::totals( $order )['total'], (string) ( $set['totals']['total'] ?? '' ) ) );
			}
			if ( 'pending' !== $spec['status'] ) {
				$order->update_status( (string) $spec['status'], '', true );
			}
			/* translators: %s: change set number */
			$order->add_order_note( sprintf( __( 'Created from AddSynapse (change set #%s), approved by the shop owner.', 'addsynapse-store-assistant' ), $marker ), 0, false );
		} catch ( \Throwable $e ) {
			if ( $order instanceof \WC_Order && $order->get_id() > 0 ) {
				self::discard( $order );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as data, never printed raw.
			throw new \RuntimeException( $e->getMessage() );
		} finally {
			if ( $unmute ) {
				$unmute();
			}
		}//end try
		return (int) $order->get_id();
	}

	/**
	 * The order an earlier run of this change set created.
	 *
	 * @param string $marker Change set id.
	 */
	private static function find( string $marker ): ?\WC_Order {
		$meta = array(
			array(
				'key'   => OrderFields::META,
				'value' => $marker,
			),
		);
		// HPOS: the order query takes meta_query. Posts storage: it does not (it would return the newest order) → get_posts.
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$ids  = $hpos
			? wc_get_orders(
				array(
					'limit'      => 5,
					'return'     => 'ids',
					'status'     => array_keys( wc_get_order_statuses() ),
					'type'       => 'shop_order',
					'meta_query' => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded lookup on Approve.
				)
			)
			: get_posts(
				array(
					'post_type'      => 'shop_order',
					'post_status'    => 'any',
					'posts_per_page' => 5,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded lookup on Approve.
				)
			);
		foreach ( (array) $ids as $id ) {
			$order = self::created( (int) $id );
			// Only an order of exactly this change set (never another AddSynapse order).
			if ( null !== $order && (string) $order->get_meta( OrderFields::META ) === $marker ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Throws away an order this Approve just created and could not finish (cancelled first, then deleted for good — it never
	 * existed for the shop).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function discard( \WC_Order $order ): void {
		$unmute = OrderFields::mute_emails();
		try {
			$order->update_status( 'cancelled', '', false );
			$order->delete( true );
		} catch ( \Throwable $e ) {
			unset( $e );
		} finally {
			$unmute();
		}
	}
}
