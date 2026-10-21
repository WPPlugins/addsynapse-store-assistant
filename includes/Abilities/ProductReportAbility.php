<?php
/**
 * Ability addsynapse-assistant/product-report — read-only catalogue and sales reports.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only, bounded SELECTs on core/WC product tables.

/**
 * Reports:
 *  - catalogue lists (low_stock, out_of_stock, missing_image, missing_description,
 *    missing_price): bounded read-only SELECTs on posts/postmeta/wc_product_meta_lookup
 *    (products are posts even with HPOS), published products only;
 *  - sales (sales_summary, best_sellers): wc_get_orders() (HPOS-safe CRUD, source of
 *    truth) over paid statuses in the date range, scanning at most MAX_ORDERS_SCANNED
 *    orders (newest first). Beyond that the result is flagged `partial`. The Analytics
 *    lookup tables are NOT used: they depend on a background import and may lag.
 */
final class ProductReportAbility {

	public const NAME               = 'addsynapse-assistant/product-report';
	public const MAX_ORDERS_SCANNED = 2000;
	public const ORDERS_PAGE_SIZE   = 100;

	/** Sales series: per day up to this many days, per 7-day block up to SERIES_WEEK_MAX_DAYS, else per month. */
	public const SERIES_DAY_MAX_DAYS  = 62;
	public const SERIES_WEEK_MAX_DAYS = 190;

	public const PRODUCT_REPORTS = array( 'low_stock', 'out_of_stock', 'missing_image', 'missing_description', 'missing_price' );
	public const SALES_REPORTS   = array( 'sales_summary', 'best_sellers' );

	// Catalogue report SQL (product_list): fixed fragments only; %i = table placeholders, filled in order by product_list().
	private const SQL_FROM           = '%i p LEFT JOIN %i parent ON parent.ID = p.post_parent';
	private const SQL_JOIN_NONE      = '';
	private const SQL_JOIN_LOOKUP    = ' INNER JOIN %i l ON l.product_id = p.ID';
	private const SQL_JOIN_THUMBNAIL = " LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id'";
	private const SQL_JOIN_PRICE     = " LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_price'";
	// Published products, or published variations of published parents.
	private const SQL_VISIBLE = "( ( p.post_type = 'product' AND p.post_status = 'publish' ) OR ( p.post_type = 'product_variation' AND p.post_status = 'publish' AND parent.post_status = 'publish' ) )";
	// Variable parents are represented by their variations.
	private const SQL_NO_CHILDREN               = "NOT EXISTS ( SELECT 1 FROM %i c WHERE c.post_parent = p.ID AND c.post_type = 'product_variation' )";
	private const SQL_WHERE_LOW_STOCK           = self::SQL_VISIBLE . " AND l.stock_quantity IS NOT NULL AND l.stock_status <> 'outofstock' AND l.stock_quantity <= %d AND l.stock_quantity > %d";
	private const SQL_WHERE_OUT_OF_STOCK        = self::SQL_VISIBLE . " AND l.stock_status = 'outofstock' AND " . self::SQL_NO_CHILDREN;
	private const SQL_WHERE_MISSING_IMAGE       = "p.post_type = 'product' AND p.post_status = 'publish' AND ( m.meta_value IS NULL OR m.meta_value IN ( '', '0' ) )";
	private const SQL_WHERE_MISSING_DESCRIPTION = "p.post_type = 'product' AND p.post_status = 'publish' AND TRIM( p.post_content ) = ''";
	private const SQL_WHERE_MISSING_PRICE       = self::SQL_VISIBLE . ' AND ' . self::SQL_NO_CHILDREN . " AND ( m.meta_value IS NULL OR m.meta_value = '' )";
	// Per-product low-stock threshold (own, else the parent's for a variation, else the store setting %d) — like wc_get_low_stock_amount().
	private const SQL_JOIN_LOW_STOCK_AMOUNT = " LEFT JOIN %i lsa ON lsa.post_id = p.ID AND lsa.meta_key = '_low_stock_amount' LEFT JOIN %i plsa ON plsa.post_id = p.post_parent AND plsa.meta_key = '_low_stock_amount'";
	private const SQL_WHERE_LOW_STOCK_OWN   = self::SQL_VISIBLE . " AND l.stock_quantity IS NOT NULL AND l.stock_status <> 'outofstock' AND l.stock_quantity <= CAST( COALESCE( NULLIF( lsa.meta_value, '' ), NULLIF( plsa.meta_value, '' ), %d ) AS SIGNED ) AND l.stock_quantity > %d";
	// Stock not tracked: neither the product/variation nor (for a variation) its parent manages stock — no count to report.
	private const SQL_JOIN_MANAGE_STOCK = " LEFT JOIN %i ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' LEFT JOIN %i pms ON pms.post_id = p.post_parent AND pms.meta_key = '_manage_stock'";
	private const SQL_WHERE_NOT_TRACKED = self::SQL_VISIBLE . ' AND ' . self::SQL_NO_CHILDREN . " AND COALESCE( ms.meta_value, 'no' ) <> 'yes' AND ( p.post_type = 'product' OR COALESCE( pms.meta_value, 'no' ) <> 'yes' )";
	private const NOT_TRACKED_MAX       = 50;
	private const SQL_ORDER_STOCK       = 'l.stock_quantity ASC, p.ID ASC';
	private const SQL_ORDER_TITLE       = 'p.post_title ASC, p.ID ASC';
	private const SQL_ORDER_NEWEST      = 'p.ID DESC';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Product report', 'addsynapse-store-assistant' ),
				'description'         => 'Read-only store reports. report = low_stock (managed stock <= threshold: the own low-stock threshold of each product when set, else the WooCommerce setting; a given threshold applies to all; also not_tracked = products/variations without stock tracking), out_of_stock, missing_image, missing_description, missing_price (these list published products/variations with total count), best_sellers (top products by quantity in a period; variations rolled up to their parent product) or sales_summary (revenue, refunds, orders count, average order, items sold, top products and a series per day/week/month in a period; paid statuses only). Optional filters for every report: category, tag, brand, attribute {name, value}, product_ids. period: today, 7d, 30d (default), month, custom (date_from/date_to). limit default 20, max 100. Sales scan at most 2000 orders; larger periods return partial=true.',
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
	 * Input schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'report' ),
			'properties'           => array_merge(
				array(
					'report'    => array(
						'type' => 'string',
						'enum' => array_merge( self::PRODUCT_REPORTS, self::SALES_REPORTS ),
					),
					'threshold' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 100000,
						'description' => 'low_stock only. Default: WooCommerce "low stock threshold" setting.',
					),
					'limit'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Shared::MAX_LIMIT,
						'default' => Shared::DEFAULT_LIMIT,
					),
				),
				Shared::period_input_properties( '30d' ),
				ProductFilter::input_properties()
			),
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		$number = array( 'type' => 'number' );
		$top    = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'product_id', 'name', 'quantity', 'revenue' ),
				'properties'           => array(
					'product_id' => array( 'type' => 'integer' ),
					'name'       => array( 'type' => 'string' ),
					'sku'        => array( 'type' => 'string' ),
					'quantity'   => array( 'type' => 'integer' ),
					'revenue'    => $number,
					'edit_link'  => array( 'type' => 'string' ),
					'variations' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'variation_id' => array( 'type' => 'integer' ),
								'name'         => array( 'type' => 'string' ),
								'sku'          => array( 'type' => 'string' ),
								'quantity'     => array( 'type' => 'integer' ),
								'revenue'      => $number,
							),
						),
					),
				),
			),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'report' ),
			'properties'           => array(
				'report'           => array( 'type' => 'string' ),
				'total'            => array( 'type' => 'integer' ),
				'limit'            => array( 'type' => 'integer' ),
				'threshold'        => array( 'type' => 'integer' ),
				'not_tracked'      => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'total' => array( 'type' => 'integer' ),
						'items' => array(
							'type'  => 'array',
							'items' => Shared::product_row_schema(),
						),
						'note'  => array( 'type' => 'string' ),
					),
				),
				'items'            => array(
					'type'  => 'array',
					'items' => Shared::product_row_schema(),
				),
				'period'           => Shared::period_output_schema(),
				'summary'          => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'revenue'         => $number,
						'refunds'         => $number,
						'net_revenue'     => $number,
						'orders_count'    => array( 'type' => 'integer' ),
						'avg_order_value' => $number,
						'items_sold'      => array( 'type' => 'integer' ),
					),
				),
				'top_products'     => $top,
				'series'           => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'bucket', 'labels', 'revenue', 'orders', 'items' ),
					'properties'           => array(
						'bucket'        => array(
							'type' => 'string',
							'enum' => array( 'day', 'week', 'month' ),
						),
						'labels'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'revenue'       => array(
							'type'  => 'array',
							'items' => $number,
						),
						'orders'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'items'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'complete_from' => array( 'type' => 'string' ),
					),
				),
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
				'filters'          => ProductFilter::applied_schema(),
				'warnings'         => array(
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
		$input  = is_array( $input ) ? $input : array();
		$report = (string) ( $input['report'] ?? '' );
		$limit  = Shared::limit( $input['limit'] ?? null );
		$filter = ProductFilter::has_filters( $input ) ? ProductFilter::resolve( $input ) : null;
		if ( is_wp_error( $filter ) ) {
			return $filter;
		}

		if ( in_array( $report, self::SALES_REPORTS, true ) ) {
			$range = Shared::period_range(
				(string) ( $input['period'] ?? ( empty( $input['date_from'] ) ? '30d' : 'custom' ) ),
				(string) ( $input['date_from'] ?? '' ),
				(string) ( $input['date_to'] ?? '' )
			);
			if ( is_wp_error( $range ) ) {
				return $range;
			}
			return self::with_filter( $this->sales( $report, $range, $limit, $filter ), $filter );
		}
		if ( in_array( $report, self::PRODUCT_REPORTS, true ) ) {
			return self::with_filter( $this->product_list( $report, $limit, $input, $filter ), $filter );
		}
		return new WP_Error( 'addsynapse_assistant_unknown_report', 'Unknown report.' );
	}

	/**
	 * Echoes the applied filters (+ why nothing matched) in the result.
	 *
	 * @param array<string,mixed>      $out    Result.
	 * @param array<string,mixed>|null $filter Resolved filter (null = none).
	 * @return array<string,mixed>
	 */
	private static function with_filter( array $out, ?array $filter ): array {
		if ( null === $filter ) {
			return $out;
		}
		$out['filters']  = (object) $filter['applied'];
		$out['warnings'] = $filter['warnings'];
		return $out;
	}

	/**
	 * Catalogue list reports.
	 *
	 * @param string                   $report Report.
	 * @param int                      $limit  Limit.
	 * @param array<string,mixed>      $input  Input.
	 * @param array<string,mixed>|null $filter Resolved product filter (null = none).
	 * @return array<string,mixed>
	 */
	private function product_list( string $report, int $limit, array $input, ?array $filter = null ): array {
		global $wpdb;
		$notes = array( 'Published products only.' );
		$out   = array(
			'report' => $report,
		);
		// Fixed SQL per report (class constants); tables are %i placeholders, values %d — nothing is interpolated.
		$tables = array( $wpdb->posts, $wpdb->posts );
		$params = array();

		switch ( $report ) {
			case 'low_stock':
				$given            = isset( $input['threshold'] );
				$threshold        = $given ? (int) $input['threshold'] : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
				$no_stock         = (int) get_option( 'woocommerce_notify_no_stock_amount', 0 );
				$out['threshold'] = $threshold;
				$join             = self::SQL_JOIN_LOOKUP . ( $given ? '' : self::SQL_JOIN_LOW_STOCK_AMOUNT );
				$where            = $given ? self::SQL_WHERE_LOW_STOCK : self::SQL_WHERE_LOW_STOCK_OWN;
				$order            = self::SQL_ORDER_STOCK;
				$tables[]         = $wpdb->wc_product_meta_lookup;
				if ( ! $given ) {
					$tables[] = $wpdb->postmeta;
					$tables[] = $wpdb->postmeta;
				}
				$params  = array( $threshold, $no_stock );
				$notes[] = $given
					? 'Only products that manage stock; the given threshold is used for every product.'
					: 'Only products that manage stock; each product\'s own low-stock threshold is used when set, else the store setting (threshold).';
				$notes[] = 'Products/variations that do not track stock have no count: they are listed separately in not_tracked.';
				break;
			case 'out_of_stock':
				$join     = self::SQL_JOIN_LOOKUP;
				$where    = self::SQL_WHERE_OUT_OF_STOCK;
				$order    = self::SQL_ORDER_TITLE;
				$tables[] = $wpdb->wc_product_meta_lookup;
				$tables[] = $wpdb->posts;
				$notes[]  = 'Variable products are listed per variation.';
				break;
			case 'missing_image':
				$join     = self::SQL_JOIN_THUMBNAIL;
				$where    = self::SQL_WHERE_MISSING_IMAGE;
				$order    = self::SQL_ORDER_NEWEST;
				$tables[] = $wpdb->postmeta;
				break;
			case 'missing_description':
				$join    = self::SQL_JOIN_NONE;
				$where   = self::SQL_WHERE_MISSING_DESCRIPTION;
				$order   = self::SQL_ORDER_NEWEST;
				$notes[] = 'Checks the long description only.';
				break;
			default:
				// missing_price.
				$join     = self::SQL_JOIN_PRICE;
				$where    = self::SQL_WHERE_MISSING_PRICE;
				$order    = self::SQL_ORDER_NEWEST;
				$tables[] = $wpdb->postmeta;
				$tables[] = $wpdb->posts;
				$notes[]  = 'Variable products are checked per variation.';
				break;
		}//end switch

		// Parent ids (integers only): a product itself, or a variation of it.
		$filters = null !== $filter && null !== $filter['ids'] ? array( true ) : array();
		$ids_in  = $filters && $filter['ids'] ? array_values( array_map( 'intval', $filter['ids'] ) ) : array( 0 );
		$in      = implode( ',', array_fill( 0, count( $ids_in ), '%d' ) );
		$only    = implode( '', array_fill( 0, count( $filters ), " AND ( p.ID IN ( {$in} ) OR ( p.post_type = 'product_variation' AND p.post_parent IN ( {$in} ) ) )" ) );
		$params  = array_merge( $tables, $params, $filters ? array_merge( $ids_in, $ids_in ) : array() );

		// Parenthesized: Plugin Check's DirectDB sniff otherwise reads past a statement that ends with a string.
		$count_sql = ( 'SELECT COUNT(*) FROM ' . self::SQL_FROM . $join . ' WHERE ' . $where . $only );
		$ids_sql   = ( 'SELECT p.ID FROM ' . self::SQL_FROM . $join . ' WHERE ' . $where . $only . ' ORDER BY ' . $order . ' LIMIT %d' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed SQL constants, tables/values are placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		$ids   = (array) $wpdb->get_col( $wpdb->prepare( $ids_sql, array_merge( $params, array( $limit ) ) ) );
		// phpcs:enable

		if ( 'low_stock' === $report ) {
			$out['not_tracked'] = self::not_tracked( min( $limit, self::NOT_TRACKED_MAX ), $filters ? $ids_in : null );
		}

		return $out + array(
			'total'    => $total,
			'limit'    => $limit,
			'items'    => Shared::product_rows( array_map( 'intval', $ids ) ),
			'currency' => Shared::currency(),
			'notes'    => $notes,
			'_data'    => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Published products/variations that do not track stock (low_stock report): no count, the status is set by hand.
	 *
	 * @param int             $limit  Max rows.
	 * @param array<int>|null $ids_in Parent ids of the product filter (null = no filter), like product_list.
	 * @return array<string,mixed>
	 */
	private static function not_tracked( int $limit, ?array $ids_in ): array {
		global $wpdb;
		$filters   = null === $ids_in ? array() : array( true );
		$ids_in    = $ids_in ? array_values( array_map( 'intval', $ids_in ) ) : array( 0 );
		$in        = implode( ',', array_fill( 0, count( $ids_in ), '%d' ) );
		$only      = implode( '', array_fill( 0, count( $filters ), " AND ( p.ID IN ( {$in} ) OR ( p.post_type = 'product_variation' AND p.post_parent IN ( {$in} ) ) )" ) );
		$params    = array_merge( array( $wpdb->posts, $wpdb->posts, $wpdb->postmeta, $wpdb->postmeta, $wpdb->posts ), $filters ? array_merge( $ids_in, $ids_in ) : array() );
		$count_sql = ( 'SELECT COUNT(*) FROM ' . self::SQL_FROM . self::SQL_JOIN_MANAGE_STOCK . ' WHERE ' . self::SQL_WHERE_NOT_TRACKED . $only );
		$ids_sql   = ( 'SELECT p.ID FROM ' . self::SQL_FROM . self::SQL_JOIN_MANAGE_STOCK . ' WHERE ' . self::SQL_WHERE_NOT_TRACKED . $only . ' ORDER BY ' . self::SQL_ORDER_TITLE . ' LIMIT %d' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed SQL constants, tables/values are placeholders; a live stock report (no cache).
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		$ids   = (array) $wpdb->get_col( $wpdb->prepare( $ids_sql, array_merge( $params, array( $limit ) ) ) );
		// phpcs:enable

		return array(
			'total' => $total,
			'items' => Shared::product_rows( array_map( 'intval', $ids ) ),
			'note'  => 'These do not track stock (no quantity); their stock status is set by hand.',
		);
	}

	/**
	 * Sales reports from orders (bounded scan).
	 *
	 * @param string                                                             $report Report.
	 * @param array{from:\DateTimeImmutable,to:\DateTimeImmutable,period:string} $range  Range.
	 * @param int                                                                $limit  Top products limit.
	 * @param array<string,mixed>|null                                           $filter Resolved product filter (null = none):
	 *                                                                                   only matching lines count.
	 * @return array<string,mixed>
	 */
	private function sales( string $report, array $range, int $limit, ?array $filter = null ): array {
		$only     = null !== $filter && null !== $filter['ids'] ? array_fill_keys( $filter['ids'], true ) : null;
		$statuses = array_values( array_map( 'strval', (array) wc_get_is_paid_statuses() ) );
		$base     = array(
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

		$revenue  = 0.0;
		$refunds  = 0.0;
		$count    = 0;
		$matched  = 0;
		$items    = 0;
		$products = array();
		$frame    = 'sales_summary' === $report ? self::series_frame( $range ) : null;
		$buckets  = null === $frame ? array() : array_fill( 0, count( $frame['labels'] ), array( 0.0, 0, 0 ) );
		$oldest   = null;
		$page     = 1;
		do {
			$orders = wc_get_orders(
				$base + array(
					'limit'   => self::ORDERS_PAGE_SIZE,
					'page'    => $page,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order || $count >= self::MAX_ORDERS_SCANNED ) {
					continue;
				}
				++$count;
				$slot = null;
				if ( null !== $frame && $order->get_date_created() ) {
					$local  = ( new \DateTimeImmutable( '@' . $order->get_date_created()->getTimestamp() ) )->setTimezone( wp_timezone() );
					$slot   = self::bucket_index( $frame, $local );
					$oldest = null === $oldest || $local < $oldest ? $local : $oldest;
				}
				if ( null === $only ) {
					$revenue += (float) $order->get_total();
					$refunds += (float) $order->get_total_refunded();
					if ( null !== $slot ) {
						$buckets[ $slot ][0] += (float) $order->get_total();
					}
				}
				$hit = false;
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Product ) {
						continue;
					}
					// Variations roll up to their parent product (the id plan tools use).
					$pid = (int) $item->get_product_id();
					if ( null !== $only && ! isset( $only[ $pid ] ) ) {
						continue;
					}
					$hit      = true;
					$refunded = absint( $order->get_qty_refunded_for_item( $item->get_id() ) );
					$qty      = (int) $item->get_quantity() - $refunded;
					$items   += (int) $item->get_quantity();
					if ( null !== $only ) {
						// Filtered: product revenue of the matching lines only (after discounts, excl. tax/shipping).
						$revenue += (float) $item->get_total();
						$refunds += (float) $order->get_total_refunded_for_item( $item->get_id() );
						if ( null !== $slot ) {
							$buckets[ $slot ][0] += (float) $item->get_total();
						}
					}
					if ( null !== $slot ) {
						$buckets[ $slot ][2] += (int) $item->get_quantity();
					}
					$key = $pid ? $pid : 'n:' . $item->get_name();
					if ( ! isset( $products[ $key ] ) ) {
						$products[ $key ] = array(
							'product_id' => $pid,
							'name'       => (string) $item->get_name(),
							'quantity'   => 0,
							'revenue'    => 0.0,
							'variations' => array(),
						);
					}
					$products[ $key ]['quantity'] += max( 0, $qty );
					$products[ $key ]['revenue']  += (float) $item->get_total();
					$vid                           = (int) $item->get_variation_id();
					if ( $vid > 0 ) {
						if ( ! isset( $products[ $key ]['variations'][ $vid ] ) ) {
							$products[ $key ]['variations'][ $vid ] = array(
								'quantity' => 0,
								'revenue'  => 0.0,
							);
						}
						$products[ $key ]['variations'][ $vid ]['quantity'] += max( 0, $qty );
						$products[ $key ]['variations'][ $vid ]['revenue']  += (float) $item->get_total();
					}
				}//end foreach
				if ( $hit ) {
					++$matched;
				}
				if ( null !== $slot && ( null === $only || $hit ) ) {
					++$buckets[ $slot ][1];
				}
			}//end foreach
			++$page;
			$full_page = count( $orders ) === self::ORDERS_PAGE_SIZE;
		} while ( $full_page && $count < self::MAX_ORDERS_SCANNED );

		$sort = static function ( $a, $b ) {
			return array( $b['quantity'], $b['revenue'] ) <=> array( $a['quantity'], $a['revenue'] );
		};
		usort( $products, $sort );
		$top = array();
		foreach ( array_slice( $products, 0, 'best_sellers' === $report ? $limit : min( $limit, 5 ) ) as $row ) {
			$product = $row['product_id'] ? wc_get_product( $row['product_id'] ) : null;
			$line    = array(
				'product_id' => $row['product_id'],
				'name'       => html_entity_decode( wp_strip_all_tags( $product ? $product->get_name() : $row['name'] ), ENT_QUOTES, 'UTF-8' ),
				'sku'        => $product ? (string) $product->get_sku() : '',
				'quantity'   => (int) $row['quantity'],
				'revenue'    => Shared::money( $row['revenue'] ),
				'edit_link'  => $row['product_id'] ? Shared::product_edit_link( $row['product_id'] ) : '',
			);
			if ( 'best_sellers' === $report && $row['variations'] ) {
				$variations = array();
				foreach ( $row['variations'] as $vid => $sold ) {
					$variations[] = array( 'variation_id' => (int) $vid ) + $sold;
				}
				usort( $variations, $sort );
				$line['variations'] = array();
				foreach ( array_slice( $variations, 0, 10 ) as $sold ) {
					$variation            = wc_get_product( $sold['variation_id'] );
					$line['variations'][] = array(
						'variation_id' => $sold['variation_id'],
						'name'         => $variation ? html_entity_decode( wp_strip_all_tags( $variation->get_name() ), ENT_QUOTES, 'UTF-8' ) : '',
						'sku'          => $variation ? (string) $variation->get_sku() : '',
						'quantity'     => (int) $sold['quantity'],
						'revenue'      => Shared::money( $sold['revenue'] ),
					);
				}
			}
			$top[] = $line;
		}//end foreach

		$notes = array(
			'Counts orders with paid statuses only (' . implode( ', ', $statuses ) . '), by creation date in the store timezone.',
			'Revenue = order totals incl. tax and shipping; product revenue = line totals after discounts, excl. tax.',
			'Variations are counted under their parent product (product_id = the parent, usable by the plan tools; plan_stock_update/plan_price_update change a variable product per variation).',
		);
		if ( null !== $only ) {
			$notes[1] = 'Filtered: only lines of the matching products count; revenue = their line totals after discounts, excl. tax and shipping; orders_count = orders containing at least one matching product.';
		}
		$out = array(
			'report'           => $report,
			'period'           => Shared::period_output( $range ),
			'top_products'     => $top,
			'statuses'         => $statuses,
			'orders_scanned'   => $count,
			'orders_in_period' => $in_period,
			'partial'          => $in_period > $count,
			'currency'         => Shared::currency(),
			'notes'            => $notes,
			'_data'            => Shared::DATA_NOTICE,
		);
		if ( $out['partial'] ) {
			$out['notes'][] = sprintf( 'Partial: only the newest %d orders of the period were scanned. Use a shorter period for exact figures.', $count );
		}
		if ( 'sales_summary' === $report ) {
			$orders_count   = null === $only ? $count : $matched;
			$out['summary'] = array(
				'revenue'         => Shared::money( $revenue ),
				'refunds'         => Shared::money( $refunds ),
				'net_revenue'     => Shared::money( $revenue - $refunds ),
				'orders_count'    => $orders_count,
				'avg_order_value' => $orders_count ? Shared::money( $revenue / $orders_count ) : 0.0,
				'items_sold'      => $items,
			);
			if ( null !== $frame ) {
				$out['series'] = self::series_output( $frame, $buckets, $out['partial'] ? $oldest : null );
				if ( $out['partial'] ) {
					$out['notes'][] = '' === $out['series']['complete_from']
						? 'Series: every bucket is incomplete (partial scan).'
						: sprintf( 'Series: buckets before %s are incomplete (partial scan).', $out['series']['complete_from'] );
				}
			}
		}
		return $out;
	}

	/**
	 * Buckets of the sales series for a range (store timezone): per day for ranges up to
	 * SERIES_DAY_MAX_DAYS days, per 7-day block (starting at the range start) up to
	 * SERIES_WEEK_MAX_DAYS, else per calendar month. Null for a single day (nothing to chart).
	 *
	 * @param array{from:\DateTimeImmutable,to:\DateTimeImmutable,period:string} $range Range.
	 * @return array{bucket:string,start:string,labels:string[]}|null
	 */
	public static function series_frame( array $range ): ?array {
		$from = $range['from']->format( 'Y-m-d' );
		$to   = $range['to']->format( 'Y-m-d' );
		$days = self::day_diff( $from, $to ) + 1;
		if ( $days < 2 ) {
			return null;
		}
		$labels = array();
		if ( $days <= self::SERIES_DAY_MAX_DAYS ) {
			$bucket = 'day';
			$step   = 1;
		} elseif ( $days <= self::SERIES_WEEK_MAX_DAYS ) {
			$bucket = 'week';
			$step   = 7;
		} else {
			$month = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $from, 0, 7 ) . '-01', new \DateTimeZone( 'UTC' ) );
			$last  = substr( $to, 0, 7 );
			while ( $month->format( 'Y-m' ) <= $last ) {
				$labels[] = $month->format( 'Y-m' );
				$month    = $month->modify( '+1 month' );
			}
			return array(
				'bucket' => 'month',
				'start'  => $from,
				'labels' => $labels,
			);
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < $days; $i += $step ) {
			$labels[] = $day->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
		}
		return array(
			'bucket' => $bucket,
			'start'  => $from,
			'labels' => $labels,
		);
	}

	/**
	 * Bucket of a local (store timezone) date/time, or null when outside the frame.
	 *
	 * @param array{bucket:string,start:string,labels:string[]} $frame Frame.
	 * @param \DateTimeImmutable                                $when  Date in the store timezone.
	 */
	public static function bucket_index( array $frame, \DateTimeImmutable $when ): ?int {
		if ( 'month' === $frame['bucket'] ) {
			$index = array_search( $when->format( 'Y-m' ), $frame['labels'], true );
			return false === $index ? null : (int) $index;
		}
		$diff = self::day_diff( $frame['start'], $when->format( 'Y-m-d' ) );
		if ( $diff < 0 ) {
			return null;
		}
		$index = 'week' === $frame['bucket'] ? intdiv( $diff, 7 ) : $diff;
		return $index < count( $frame['labels'] ) ? $index : null;
	}

	/**
	 * Compact series output (arrays of numbers; empty buckets = 0).
	 *
	 * @param array{bucket:string,start:string,labels:string[]} $frame   Frame.
	 * @param array<int,array{0:float,1:int,2:int}>             $buckets Revenue, orders, items per bucket.
	 * @param \DateTimeImmutable|null                           $oldest  Oldest scanned order when the scan was partial.
	 * @return array<string,mixed>
	 */
	private static function series_output( array $frame, array $buckets, ?\DateTimeImmutable $oldest ): array {
		$out = array(
			'bucket'  => $frame['bucket'],
			'labels'  => $frame['labels'],
			'revenue' => array(),
			'orders'  => array(),
			'items'   => array(),
		);
		foreach ( $buckets as $b ) {
			$out['revenue'][] = Shared::money( $b[0] );
			$out['orders'][]  = $b[1];
			$out['items'][]   = $b[2];
		}
		if ( null !== $oldest ) {
			// The bucket holding the oldest scanned order (and every earlier one) is undercounted.
			$index                = self::bucket_index( $frame, $oldest );
			$first                = null === $index ? 0 : $index + 1;
			$out['complete_from'] = $frame['labels'][ $first ] ?? '';
		}
		return $out;
	}

	/**
	 * Whole days between two Y-m-d dates (calendar days, DST-safe).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	private static function day_diff( string $from, string $to ): int {
		return intdiv( (int) strtotime( $to . ' 00:00:00 UTC' ) - (int) strtotime( $from . ' 00:00:00 UTC' ), 86400 );
	}
}
