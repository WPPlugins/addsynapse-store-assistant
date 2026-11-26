<?php
/**
 * Ability addsynapse-assistant/list-orders — read-only order list WITHOUT customer personal data.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lists orders through wc_get_orders() (HPOS-safe). Rows are built from an explicit
 * allow-list of fields: never names, emails, phones, addresses, IPs, user agents,
 * customer ids or notes.
 */
final class ListOrdersAbility {

	public const NAME = 'addsynapse-assistant/list-orders';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'List orders', 'addsynapse-store-assistant' ),
				'description'         => 'Lists WooCommerce orders (read-only), newest first. Filters: status (e.g. processing, completed, on-hold, pending, cancelled, refunded, failed or any), period (today, 7d, 30d, month, custom with date_from/date_to), total_min/total_max, limit (default 20, max 100), page. Returns id, number, date, status, total, currency, item count, payment method title and edit link. Contains NO customer personal data (no names, emails, phones, addresses, IPs or notes).',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array_merge(
						array(
							'status'    => array(
								'type'        => 'string',
								'pattern'     => '^[a-z0-9_-]{1,30}$',
								'description' => 'Order status slug without the wc- prefix, or "any".',
							),
							'total_min' => array(
								'type'    => 'number',
								'minimum' => 0,
							),
							'total_max' => array(
								'type'    => 'number',
								'minimum' => 0,
							),
							'limit'     => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => Shared::MAX_LIMIT,
								'default' => Shared::DEFAULT_LIMIT,
							),
							'page'      => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 1000,
								'default' => 1,
							),
						),
						Shared::period_input_properties()
					),
				),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Allowed row keys — the PII test asserts nothing else appears.
	 */
	public const ROW_KEYS = array( 'id', 'number', 'date_created', 'status', 'status_label', 'total', 'currency', 'item_count', 'payment_method_title', 'edit_link' );

	/**
	 * Output schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'total', 'page', 'pages', 'limit', 'items' ),
			'properties'           => array(
				'total'  => array( 'type' => 'integer' ),
				'page'   => array( 'type' => 'integer' ),
				'pages'  => array( 'type' => 'integer' ),
				'limit'  => array( 'type' => 'integer' ),
				'period' => Shared::period_output_schema(),
				'items'  => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => self::ROW_KEYS,
						'properties'           => array(
							'id'                   => array( 'type' => 'integer' ),
							'number'               => array( 'type' => 'string' ),
							'date_created'         => array( 'type' => 'string' ),
							'status'               => array( 'type' => 'string' ),
							'status_label'         => array( 'type' => 'string' ),
							'total'                => array( 'type' => 'number' ),
							'currency'             => array( 'type' => 'string' ),
							'item_count'           => array( 'type' => 'integer' ),
							'payment_method_title' => array( 'type' => 'string' ),
							'edit_link'            => array( 'type' => 'string' ),
						),
					),
				),
				'_data'  => array( 'type' => 'string' ),
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
		$input  = is_array( $input ) ? $input : array();
		$limit  = Shared::limit( $input['limit'] ?? null );
		$page   = max( 1, (int) ( $input['page'] ?? 1 ) );
		$status = (string) ( $input['status'] ?? 'any' );
		$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		$args = array(
			'type'     => 'shop_order',
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( 'any' !== $status && '' !== $status ) {
			if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
				return new WP_Error(
					'addsynapse_assistant_invalid_status',
					'Unknown order status. Valid: ' . implode( ', ', self::status_slugs() ) . ', any.'
				);
			}
			$args['status'] = array( 'wc-' . $status );
		} else {
			$args['status'] = array_keys( wc_get_order_statuses() );
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
			return new WP_Error( 'addsynapse_assistant_invalid_total_range', 'total_min must not be greater than total_max.' );
		}
		$args = self::add_total_filter( $args, $min, $max );

		$result = wc_get_orders( $args );
		$items  = array();
		foreach ( (array) $result->orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$items[] = self::row( $order );
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
	 * Adds the order-total filter for the active order storage.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @param float|null          $min  Min total.
	 * @param float|null          $max  Max total.
	 * @return array<string,mixed>
	 */
	public static function add_total_filter( array $args, ?float $min, ?float $max ): array {
		if ( null === $min && null === $max ) {
			return $args;
		}
		$hpos    = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$clauses = array();
		foreach ( array(
			'>=' => $min,
			'<=' => $max,
		) as $compare => $value ) {
			if ( null === $value ) {
				continue;
			}
			$clauses[] = array(
				$hpos ? 'field' : 'key' => $hpos ? 'total' : '_order_total',
				'value'                 => $value,
				'compare'               => $compare,
				'type'                  => 'DECIMAL(19,4)',
			);
		}
		if ( $hpos ) {
			$args['field_query'] = $clauses;
		} else {
			$args['meta_query'] = $clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- legacy (non-HPOS) storage only; bounded by limit.
		}
		return $args;
	}

	/**
	 * One PII-free row, built from an allow-list.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function row( \WC_Order $order ): array {
		$created = $order->get_date_created();
		return array(
			'id'                   => (int) $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'date_created'         => $created ? $created->date( 'c' ) : '',
			'status'               => (string) $order->get_status(),
			'status_label'         => (string) wc_get_order_status_name( $order->get_status() ),
			'total'                => (float) $order->get_total(),
			'currency'             => (string) $order->get_currency(),
			'item_count'           => (int) $order->get_item_count(),
			'payment_method_title' => html_entity_decode( wp_strip_all_tags( (string) $order->get_payment_method_title() ), ENT_QUOTES, 'UTF-8' ),
			'edit_link'            => (string) $order->get_edit_order_url(),
		);
	}

	/**
	 * Status slugs without the wc- prefix.
	 *
	 * @return array<int,string>
	 */
	public static function status_slugs(): array {
		return array_map(
			static function ( $s ) {
				return substr( $s, 3 );
			},
			array_keys( wc_get_order_statuses() )
		);
	}
}
