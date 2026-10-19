<?php
/**
 * Ability addsynapse-assistant/customer-report — top customers by total spent / number of orders, WITHOUT personal data.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Same scan as the sales reports: wc_get_orders() (HPOS-safe) over paid statuses in the period, newest first,
 * at most MAX_ORDERS_SCANNED orders (beyond that `partial`). Registered customers are grouped by customer id,
 * guests by a keyed hash of the billing email (HMAC with the site's salts — not reversible outside this site).
 *
 * 🔴 PII: the result (what the AI provider sees) carries ONLY an opaque reference (`c:123` / `g:<hash>`),
 * counts, amounts, dates, the id of the customer's latest order and a wp-admin link — never names, emails,
 * phones or addresses (ROW_KEYS; tests check keys and values). The names are added ONLY for the browser of
 * the logged-in manager, when the stored result is formatted (MessageFormatter → display()).
 */
final class CustomerReportAbility {

	public const NAME   = 'addsynapse-assistant/customer-report';
	public const REPORT = 'top_customers';
	public const SORTS  = array( 'spent', 'orders' );

	/**
	 * Row keys — the PII test asserts nothing else appears.
	 */
	public const ROW_KEYS = array( 'customer_ref', 'registered', 'orders_count', 'total_spent', 'first_order', 'last_order', 'last_order_id', 'link' );

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Top customers', 'addsynapse-store-assistant' ),
				'description'         => 'Top customers (read-only) in a period by total spent or by number of orders, paid statuses only. Registered customers are grouped by account, guests by billing email. Rows: customer_ref (c:<id> registered, g:<hash> guest), registered, orders_count, total_spent (net of refunds), first/last order date, last_order_id, link. NO personal data (no names, emails, phones, addresses): the manager sees the names in the table.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Input schema (same periods as the sales reports).
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'sort'  => array(
						'type'        => 'string',
						'enum'        => self::SORTS,
						'description' => 'spent (default: total spent) or orders (number of orders).',
					),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Shared::MAX_LIMIT,
						'default' => Shared::DEFAULT_LIMIT,
					),
				),
				Shared::period_input_properties( '30d' )
			),
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'report', 'customers' ),
			'properties'           => array(
				'report'           => array( 'type' => 'string' ),
				'sort'             => array( 'type' => 'string' ),
				'period'           => Shared::period_output_schema(),
				'customers'        => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => self::ROW_KEYS,
						'properties'           => array(
							'customer_ref'  => array( 'type' => 'string' ),
							'registered'    => array( 'type' => 'boolean' ),
							'orders_count'  => array( 'type' => 'integer' ),
							'total_spent'   => array( 'type' => 'number' ),
							'first_order'   => array( 'type' => 'string' ),
							'last_order'    => array( 'type' => 'string' ),
							'last_order_id' => array( 'type' => 'integer' ),
							'link'          => array( 'type' => 'string' ),
						),
					),
				),
				'customers_total'  => array( 'type' => 'integer' ),
				'statuses'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'orders_scanned'   => array( 'type' => 'integer' ),
				'orders_in_period' => array( 'type' => 'integer' ),
				'partial'          => array( 'type' => 'boolean' ),
				'currency'         => array( 'type' => 'string' ),
				'notes'            => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_data'            => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$limit = Shared::limit( $input['limit'] ?? null );
		$sort  = in_array( $input['sort'] ?? '', self::SORTS, true ) ? (string) $input['sort'] : 'spent';
		$range = Shared::period_range(
			(string) ( $input['period'] ?? ( empty( $input['date_from'] ) ? '30d' : 'custom' ) ),
			(string) ( $input['date_from'] ?? '' ),
			(string) ( $input['date_to'] ?? '' )
		);
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		$statuses  = array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) );
		$base      = array(
			'type'         => 'shop_order',
			'status'       => array_map(
				static function ( $s ) {
					return 'wc-' . $s;
				},
				$statuses
			),
			'date_created' => $range['from']->getTimestamp() . '...' . $range['to']->getTimestamp(),
		);
		$in_period = wc_get_orders(
			$base + array(
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);
		$in_period = (int) $in_period->total;

		$customers = array();
		$count     = 0;
		$page      = 1;
		do {
			$orders = wc_get_orders(
				$base + array(
					'limit'   => ProductReportAbility::ORDERS_PAGE_SIZE,
					'page'    => $page,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order || $count >= ProductReportAbility::MAX_ORDERS_SCANNED ) {
					continue;
				}
				++$count;
				$ref     = self::ref_of( $order );
				$created = $order->get_date_created();
				$time    = $created ? (int) $created->getTimestamp() : 0;
				if ( ! isset( $customers[ $ref ] ) ) {
					$customers[ $ref ] = array(
						'customer_ref'  => $ref,
						'registered'    => 0 === strpos( $ref, 'c:' ),
						'orders_count'  => 0,
						'total_spent'   => 0.0,
						'first'         => $time,
						'last'          => $time,
						'last_order_id' => (int) $order->get_id(),
					);
				}
				$row                 = &$customers[ $ref ];
				$row['orders_count'] = $row['orders_count'] + 1;
				$row['total_spent']  = $row['total_spent'] + (float) $order->get_total() - (float) $order->get_total_refunded();
				if ( $time < $row['first'] ) {
					$row['first'] = $time;
				}
				if ( $time >= $row['last'] ) {
					$row['last']          = $time;
					$row['last_order_id'] = (int) $order->get_id();
				}
				unset( $row );
			}//end foreach
			++$page;
			$full_page = count( $orders ) === ProductReportAbility::ORDERS_PAGE_SIZE;
		} while ( $full_page && $count < ProductReportAbility::MAX_ORDERS_SCANNED );

		$key = 'orders' === $sort ? array( 'orders_count', 'total_spent' ) : array( 'total_spent', 'orders_count' );
		usort(
			$customers,
			static function ( $a, $b ) use ( $key ) {
				return array( $b[ $key[0] ], $b[ $key[1] ], $b['last'] ) <=> array( $a[ $key[0] ], $a[ $key[1] ], $a['last'] );
			}
		);
		$rows = array();
		foreach ( array_slice( $customers, 0, $limit ) as $customer ) {
			$rows[] = self::row( $customer );
		}
		$out = array(
			'report'           => self::REPORT,
			'sort'             => $sort,
			'period'           => Shared::period_output( $range ),
			'customers'        => $rows,
			'customers_total'  => count( $customers ),
			'statuses'         => $statuses,
			'orders_scanned'   => $count,
			'orders_in_period' => $in_period,
			'partial'          => $in_period > $count,
			'currency'         => Shared::currency(),
			'notes'            => array(
				'Paid statuses only (' . implode( ', ', $statuses ) . '), by order creation date in the store timezone; total_spent = order totals (incl. tax and shipping) minus refunds.',
				'customer_ref only: c:<id> = registered customer account, g:<hash> = guest (grouped by billing email). No names, emails, phones or addresses are given to you; the manager sees the names in the table. Never guess or ask for them.',
			),
			'_data'            => Shared::DATA_NOTICE,
		);
		if ( $out['partial'] ) {
			$out['notes'][] = sprintf( 'Partial: only the newest %d orders of the period were scanned. Use a shorter period for exact figures.', $count );
		}
		return $out;
	}

	/**
	 * One row from the allow-list (ROW_KEYS) — no personal data.
	 *
	 * @param array<string,mixed> $customer Aggregate.
	 * @return array<string,mixed>
	 */
	private static function row( array $customer ): array {
		$tz = wp_timezone();
		return array(
			'customer_ref'  => (string) $customer['customer_ref'],
			'registered'    => (bool) $customer['registered'],
			'orders_count'  => (int) $customer['orders_count'],
			'total_spent'   => Shared::money( (float) $customer['total_spent'] ),
			'first_order'   => $customer['first'] ? wp_date( 'Y-m-d', (int) $customer['first'], $tz ) : '',
			'last_order'    => $customer['last'] ? wp_date( 'Y-m-d', (int) $customer['last'], $tz ) : '',
			'last_order_id' => (int) $customer['last_order_id'],
			'link'          => self::link( (string) $customer['customer_ref'], (int) $customer['last_order_id'] ),
		);
	}

	/**
	 * The customer reference of an order: c:<customer id> or g:<keyed hash of the billing email>.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function ref_of( \WC_Order $order ): string {
		$id = (int) $order->get_customer_id();
		if ( $id > 0 ) {
			return 'c:' . $id;
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		// A guest without an email is its own customer (one order).
		return 'g:' . self::guest_hash( '' !== $email ? $email : 'order:' . $order->get_id() );
	}

	/**
	 * Keyed, truncated hash (HMAC with the site's salts): stable per site, not reversible outside this site.
	 *
	 * @param string $value Normalised email (or order marker).
	 */
	public static function guest_hash( string $value ): string {
		return substr( hash_hmac( 'sha256', $value, wp_salt( 'auth' ) . '|addsynapse-assistant-customer-ref' ), 0, 12 );
	}

	/**
	 * Link to wp-admin (no personal data in it): the customer's orders (registered) or their latest order (guest).
	 *
	 * @param string $ref      Reference.
	 * @param int    $order_id Latest order.
	 */
	private static function link( string $ref, int $order_id ): string {
		if ( 0 === strpos( $ref, 'c:' ) ) {
			$hpos = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
			$base = $hpos ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order';
			return admin_url( $base . '&_customer_user=' . (int) substr( $ref, 2 ) );
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		return $order instanceof \WC_Order ? (string) $order->get_edit_order_url() : '';
	}

	/**
	 * Names for the BROWSER of the logged-in manager only (never stored, never sent to the AI provider): ref → name.
	 * A guest's name comes from their latest order in the result, and only if that order still hashes to the ref.
	 *
	 * @param array<string,mixed> $data Stored result of this ability.
	 * @return array<string,array{name:string}>
	 */
	public static function display( array $data ): array {
		if ( self::REPORT !== ( $data['report'] ?? '' ) || ! is_array( $data['customers'] ?? null ) || ! AbilitiesRegistrar::can_manage() || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $data['customers'], 0, Shared::MAX_LIMIT ) as $row ) {
			$ref  = is_array( $row ) ? (string) ( $row['customer_ref'] ?? '' ) : '';
			$name = '';
			if ( preg_match( '/^c:(\d{1,18})$/', $ref, $m ) ) {
				$user = get_userdata( (int) $m[1] );
				if ( $user ) {
					$name = trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'billing_last_name', true ) );
					$name = '' !== $name ? $name : (string) $user->display_name;
				}
			} elseif ( preg_match( '/^g:[0-9a-f]{12}$/', $ref ) ) {
				$order = wc_get_order( (int) ( $row['last_order_id'] ?? 0 ) );
				if ( $order instanceof \WC_Order && self::ref_of( $order ) === $ref ) {
					$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
					$name = '' !== $name ? $name : (string) $order->get_billing_company();
				}
			}
			if ( '' !== $ref && '' !== $name ) {
				$out[ $ref ] = array( 'name' => html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES, 'UTF-8' ) );
			}
		}
		return $out;
	}
}
