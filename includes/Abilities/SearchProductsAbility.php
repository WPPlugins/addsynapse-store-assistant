<?php
/**
 * Ability addsynapse-assistant/search-products — read-only product search with filters and pagination.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only SELECTs on core/WC tables (no API offers name/SKU/price-range search).

/**
 * Searches products by name/SKU, taxonomy, price range, stock, type and status.
 *
 * Name/SKU matching: LIKE on post_title (every word must match) or on the SKU of the
 * product or of one of its variations. Case/accent-insensitivity comes from the DB
 * collation (utf8mb4_*_ci ignores Greek tonos and case); the accent-stripped form of
 * the query is also tried for binary collations. Candidates are capped (MAX_CANDIDATES)
 * so a very broad term cannot scan the whole catalogue.
 */
final class SearchProductsAbility {

	public const NAME           = 'addsynapse-assistant/search-products';
	public const MAX_CANDIDATES = 2000;
	public const STATUSES       = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Sort fields. price / sales / stock sort on wc_product_meta_lookup (min/max price,
	 * total_sales, stock_quantity; products without a value come last).
	 */
	public const ORDERBY = array( 'date', 'modified', 'title', 'price', 'sales', 'stock' );

	/**
	 * Filters resolved through ProductFilter (category/tag keep the WooCommerce query args).
	 */
	public const FILTERS = array( 'brand', 'attribute' );

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Search products', 'addsynapse-store-assistant' ),
				'description'         => 'Searches WooCommerce products (read-only). Filters: query (matches product name words or SKU, also variation SKUs; case- and accent-insensitive), category and tag (slug or name), brand (in the store brand storage), attribute {name, value} (a global attribute value), price_min/price_max, stock_status, type, status (default: any non-trashed). Sort with orderby (date, modified, title, price, sales, stock) and order (asc/desc); default newest first. Returns compact rows (id, name, sku, type, prices, stock, categories, edit_link) plus total/pages for pagination. limit default 20, max 100.',
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
			'properties'           => array(
				'query'        => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => 'Words of the product name, or a SKU.',
				),
				'category'     => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => 'Product category slug or name.',
				),
				'tag'          => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => 'Product tag slug or name.',
				),
				'brand'        => ProductFilter::input_properties( array( 'brand' ) )['brand'],
				'attribute'    => ProductFilter::input_properties( array( 'attribute' ) )['attribute'],
				'price_min'    => array(
					'type'    => 'number',
					'minimum' => 0,
				),
				'price_max'    => array(
					'type'    => 'number',
					'minimum' => 0,
				),
				'stock_status' => array(
					'type' => 'string',
					'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
				),
				'type'         => array(
					'type' => 'string',
					'enum' => array( 'simple', 'variable', 'grouped', 'external' ),
				),
				'status'       => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private' ),
					'description' => 'Default any (all except trash).',
				),
				'orderby'      => array(
					'type'        => 'string',
					'enum'        => self::ORDERBY,
					'description' => 'Sort field. Default date (newest first).',
				),
				'order'        => array(
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'description' => 'Default desc for date, modified, sales and stock; asc for title and price.',
				),
				'limit'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => Shared::MAX_LIMIT,
					'default' => Shared::DEFAULT_LIMIT,
				),
				'page'         => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 1000,
					'default' => 1,
				),
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
			'required'             => array( 'total', 'page', 'pages', 'limit', 'items' ),
			'properties'           => array(
				'total'    => array( 'type' => 'integer' ),
				'page'     => array( 'type' => 'integer' ),
				'pages'    => array( 'type' => 'integer' ),
				'limit'    => array( 'type' => 'integer' ),
				'orderby'  => array( 'type' => 'string' ),
				'order'    => array( 'type' => 'string' ),
				'currency' => array( 'type' => 'string' ),
				'items'    => array(
					'type'  => 'array',
					'items' => Shared::product_row_schema(),
				),
				'warnings' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'filters'  => ProductFilter::applied_schema(),
				'_data'    => array( 'type' => 'string' ),
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
		$input    = is_array( $input ) ? $input : array();
		$limit    = Shared::limit( $input['limit'] ?? null );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$warnings = array();
		$empty    = array(
			'total'    => 0,
			'page'     => $page,
			'pages'    => 0,
			'limit'    => $limit,
			'currency' => Shared::currency(),
			'items'    => array(),
			'warnings' => array(),
			'_data'    => Shared::DATA_NOTICE,
		);

		$args = self::query_args( $input, $warnings );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		if ( null === $args ) {
			$empty['warnings'] = $warnings;
			return $empty;
		}
		list( $orderby, $order ) = self::sort( $input );
		$args                   += array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'objects',
		);
		if ( in_array( $orderby, array( 'price', 'sales', 'stock' ), true ) ) {
			// Sorted by a lookup-table column (see sort_clauses); ties by newest id.
			$args['addsynapse_assistant_sort'] = array( $orderby, $order );
			add_filter( 'posts_clauses', array( self::class, 'sort_clauses' ), 10, 2 );
		} else {
			$args['orderby'] = array(
				$orderby => $order,
				'ID'     => 'DESC',
			);
		}

		try {
			$result = wc_get_products( $args );
		} finally {
			remove_filter( 'posts_clauses', array( self::class, 'sort_clauses' ), 10 );
		}
		$items = array();
		foreach ( (array) $result->products as $product ) {
			$items[] = Shared::product_row( $product );
		}
		$filters = self::applied_filters( $input );

		return $filters + array(
			'total'    => (int) $result->total,
			'page'     => $page,
			'pages'    => (int) $result->max_num_pages,
			'limit'    => $limit,
			'orderby'  => $orderby,
			'order'    => strtolower( $order ),
			'currency' => Shared::currency(),
			'items'    => $items,
			'warnings' => $warnings,
			'_data'    => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Echo of the brand / attribute filters that matched ({filters: …} or nothing).
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	private static function applied_filters( array $input ): array {
		if ( ! ProductFilter::has_filters( $input, self::FILTERS ) ) {
			return array();
		}
		$resolved = ProductFilter::resolve( $input, self::FILTERS );
		return is_wp_error( $resolved ) || ! $resolved['applied'] ? array() : array( 'filters' => $resolved['applied'] );
	}

	/**
	 * Sort field + direction (validated; defaults: newest first, A→Z, cheapest first).
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array{0:string,1:string} [orderby, ASC|DESC].
	 */
	public static function sort( array $input ): array {
		$orderby = in_array( $input['orderby'] ?? '', self::ORDERBY, true ) ? (string) $input['orderby'] : 'date';
		$order   = strtolower( (string) ( $input['order'] ?? '' ) );
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = in_array( $orderby, array( 'title', 'price' ), true ) ? 'asc' : 'desc';
		}
		return array( $orderby, strtoupper( $order ) );
	}

	/**
	 * `posts_clauses` for price / sales / stock sorting: LEFT JOIN wc_product_meta_lookup so products
	 * without a lookup value are kept (sorted last). Only touches the query flagged `addsynapse_assistant_sort`.
	 *
	 * @param array<string,string> $clauses Clauses.
	 * @param \WP_Query            $query   Query.
	 * @return array<string,string>
	 */
	public static function sort_clauses( $clauses, $query ) {
		$sort = $query instanceof \WP_Query ? $query->get( 'addsynapse_assistant_sort' ) : null;
		if ( ! is_array( $sort ) || 2 !== count( $sort ) ) {
			return $clauses;
		}
		global $wpdb;
		list( $orderby, $order ) = $sort;
		$order                   = 'ASC' === $order ? 'ASC' : 'DESC';
		$columns                 = array(
			'price' => 'ASC' === $order ? 'min_price' : 'max_price',
			'sales' => 'total_sales',
			'stock' => 'stock_quantity',
		);
		if ( ! isset( $columns[ $orderby ] ) ) {
			return $clauses;
		}
		$column             = $columns[ $orderby ];
		$clauses['join']   .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} addsynapse_assistant_sort ON addsynapse_assistant_sort.product_id = {$wpdb->posts}.ID";
		$clauses['orderby'] = "addsynapse_assistant_sort.{$column} IS NULL ASC, addsynapse_assistant_sort.{$column} {$order}, {$wpdb->posts}.ID DESC";
		return $clauses;
	}

	/**
	 * Filter args for wc_get_products() from the input. Null = nothing can match (reason in $warnings).
	 *
	 * @param array<string,mixed> $input    Input.
	 * @param array<int,string>   $warnings Warnings (by ref).
	 * @return array<string,mixed>|WP_Error|null
	 */
	private static function query_args( array $input, array &$warnings ) {
		$price_min = isset( $input['price_min'] ) ? (float) $input['price_min'] : null;
		$price_max = isset( $input['price_max'] ) ? (float) $input['price_max'] : null;
		if ( null !== $price_min && null !== $price_max && $price_min > $price_max ) {
			return new WP_Error( 'addsynapse_assistant_invalid_price_range', 'price_min must not be greater than price_max.' );
		}

		$status = (string) ( $input['status'] ?? 'any' );
		$args   = array(
			'status' => 'any' === $status ? self::STATUSES : array( $status ),
		);

		foreach ( array(
			'category' => 'product_cat',
			'tag'      => 'product_tag',
		) as $key => $taxonomy ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$term = self::find_term( $value, $taxonomy );
			if ( ! $term ) {
				$warnings[] = sprintf( 'No %s matches "%s".', 'category' === $key ? 'category' : 'tag', $value );
				return null;
			}
			$args[ $key ] = array( $term->slug );
		}
		if ( ! empty( $input['stock_status'] ) ) {
			$args['stock_status'] = (string) $input['stock_status'];
		}
		if ( ! empty( $input['type'] ) ) {
			$args['type'] = (string) $input['type'];
		}

		$query = trim( (string) ( $input['query'] ?? '' ) );
		if ( '' !== $query || null !== $price_min || null !== $price_max ) {
			$ids = self::candidate_ids( $query, $price_min, $price_max );
			if ( ! $ids ) {
				return null;
			}
			if ( count( $ids ) >= self::MAX_CANDIDATES ) {
				$warnings[] = sprintf( 'Very broad search: only the first %d matches were considered. Narrow the query.', self::MAX_CANDIDATES );
			}
			$args['include'] = $ids;
		}
		if ( ProductFilter::has_filters( $input, self::FILTERS ) ) {
			// Brand / global attribute value → parent product ids (exact term match only).
			$filter = ProductFilter::resolve( $input, self::FILTERS );
			if ( is_wp_error( $filter ) ) {
				return $filter;
			}
			$warnings = array_merge( $warnings, $filter['warnings'] );
			$ids      = isset( $args['include'] ) ? array_values( array_intersect( $args['include'], (array) $filter['ids'] ) ) : (array) $filter['ids'];
			if ( ! $ids ) {
				return null;
			}
			$args['include'] = $ids;
		}
		return $args;
	}

	/**
	 * Finds a term by id (a numeric string such as "80", as models often pass the id from
	 * find_terms), then by slug, then by name (case-insensitive via the DB collation).
	 *
	 * @param string $value    Id, slug or name.
	 * @param string $taxonomy Taxonomy.
	 * @return \WP_Term|null
	 */
	private static function find_term( string $value, string $taxonomy ): ?\WP_Term {
		$term = preg_match( '/^\d{1,18}$/', $value ) ? get_term( (int) $value, $taxonomy ) : null;
		if ( $term instanceof \WP_Term ) {
			return $term;
		}
		$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'name', $value, $taxonomy );
		}
		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Product ids (parents only) matching the name/SKU query and the price range.
	 *
	 * @param string     $query     Search text ('' = no text filter).
	 * @param float|null $price_min Min price.
	 * @param float|null $price_max Max price.
	 * @return array<int,int>
	 */
	private static function candidate_ids( string $query, ?float $price_min, ?float $price_max ): array {
		global $wpdb;
		// Each spelling (as typed, without accents): every word in the title, OR the SKU, OR a variation SKU. The SQL is
		// built from fixed fragments repeated by count (array_fill) — values only ever go through placeholders.
		$queries  = '' !== $query ? array( $query ) : array();
		$variants = array_values( array_unique( array( $query, Shared::strip_accents( $query ) ) ) );
		$words    = array();
		foreach ( $variants as $i => $variant ) {
			$list        = array_slice( preg_split( '/\s+/u', $variant, -1, PREG_SPLIT_NO_EMPTY ), 0, 8 );
			$words[ $i ] = $list ? $list : array( $variant );
		}
		$extra  = '' !== $query ? array_slice( $variants, 1 ) : array();
		$words0 = '' !== $query ? $words[0] : array();
		$words1 = $extra ? $words[1] : array();
		$mins   = null !== $price_min ? array( $price_min ) : array();
		$maxes  = null !== $price_max ? array( $price_max ) : array();

		$params = array();
		if ( $queries ) {
			foreach ( $variants as $i => $variant ) {
				foreach ( $words[ $i ] as $word ) {
					$params[] = '%' . $wpdb->esc_like( $word ) . '%';
				}
				$like = '%' . $wpdb->esc_like( $variant ) . '%';
				array_push( $params, $like, $wpdb->posts, $wpdb->wc_product_meta_lookup, $like );
			}
		}
		$params   = array_merge( array( $wpdb->posts, $wpdb->wc_product_meta_lookup ), $params, $mins, $maxes );
		$params[] = self::MAX_CANDIDATES;

		// Parenthesized: Plugin Check's DirectDB sniff otherwise reads past a statement that ends with a string.
		$sql = ( "SELECT p.ID FROM %i p LEFT JOIN %i l ON l.product_id = p.ID WHERE p.post_type = 'product' AND p.post_status <> 'trash'"
			. implode( '', array_fill( 0, count( $queries ), ' AND ( ( ' ) )
			. implode( ' AND ', array_fill( 0, count( $words0 ), 'p.post_title LIKE %s' ) )
			. implode( '', array_fill( 0, count( $queries ), " ) OR l.sku LIKE %s OR p.ID IN ( SELECT v.post_parent FROM %i v INNER JOIN %i vl ON vl.product_id = v.ID WHERE v.post_type = 'product_variation' AND vl.sku LIKE %s )" ) )
			. implode( '', array_fill( 0, count( $extra ), ' OR ( ' ) )
			. implode( ' AND ', array_fill( 0, count( $words1 ), 'p.post_title LIKE %s' ) )
			. implode( '', array_fill( 0, count( $extra ), " ) OR l.sku LIKE %s OR p.ID IN ( SELECT v.post_parent FROM %i v INNER JOIN %i vl ON vl.product_id = v.ID WHERE v.post_type = 'product_variation' AND vl.sku LIKE %s )" ) )
			. implode( '', array_fill( 0, count( $queries ), ' )' ) )
			. implode( '', array_fill( 0, count( $mins ), ' AND l.max_price >= %f' ) )
			. implode( '', array_fill( 0, count( $maxes ), ' AND l.min_price <= %f' ) )
			. ' ORDER BY p.ID DESC LIMIT %d' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql only contains fixed fragments; values are placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		return array_map( 'intval', (array) $ids );
	}
}
