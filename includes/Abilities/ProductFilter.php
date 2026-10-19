<?php
/**
 * Product filters shared by the read abilities (product-report, search-products): category, tag,
 * brand (through the store's brand storage), global attribute value and product ids.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Adapters\Brand\BrandResolver;
use Addsynapse\StoreAssistant\Discovery\StoreMap;
use Addsynapse\StoreAssistant\Discovery\TermMatcher;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the filters to a set of PARENT product ids (variations are matched through their parent).
 * Terms must match exactly (id, slug or name — case/accent-insensitive); an unknown term never matches
 * "something close": the result is empty with a warning that points to find_terms. Read-only.
 */
final class ProductFilter {

	public const KEYS            = array( 'category', 'tag', 'brand', 'attribute', 'product_ids' );
	public const MAX_PRODUCT_IDS = 500;

	/**
	 * Input schema properties.
	 *
	 * @param array<int,string> $keys Which filters (default all).
	 * @return array<string,array<string,mixed>>
	 */
	public static function input_properties( array $keys = self::KEYS ): array {
		$all = array(
			'category'    => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => 'Product category id, slug or name (subcategories included).',
			),
			'tag'         => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => 'Product tag id, slug or name.',
			),
			'brand'       => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => 'Brand id, slug or exact name, in the store\'s brand storage (find_terms taxonomy "brand" for fuzzy names).',
			),
			'attribute'   => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'name', 'value' ),
				'description'          => 'A global attribute value, e.g. {name: "pa_color", value: "red"}.',
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 100,
						'description' => 'Attribute taxonomy (pa_color), slug or label.',
					),
					'value' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => 'Term id, slug or exact name.',
					),
				),
			),
			'product_ids' => array(
				'type'        => 'array',
				'maxItems'    => self::MAX_PRODUCT_IDS,
				'items'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'description' => 'Only these products (a variation id matches its parent product).',
			),
		);
		return array_intersect_key( $all, array_flip( $keys ) );
	}

	/**
	 * Whether the input has any of the filters.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param array<int,string>   $keys  Filters to look at.
	 */
	public static function has_filters( array $input, array $keys = self::KEYS ): bool {
		foreach ( $keys as $key ) {
			$value = $input[ $key ] ?? null;
			if ( ( is_string( $value ) && '' !== trim( $value ) ) || ( is_array( $value ) && $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves the filters of the input.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param array<int,string>   $keys  Filters to apply.
	 * @return array{ids:array<int,int>|null,applied:array<string,mixed>,warnings:array<int,string>}|WP_Error
	 *         ids null = no filter; [] = nothing matches (reason in warnings).
	 */
	public static function resolve( array $input, array $keys = self::KEYS ) {
		$ids      = null;
		$applied  = array();
		$warnings = array();
		foreach ( $keys as $key ) {
			$value = $input[ $key ] ?? null;
			if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) || ( is_array( $value ) && ! $value ) ) {
				continue;
			}
			switch ( $key ) {
				case 'category':
				case 'tag':
					$taxonomy = 'category' === $key ? 'product_cat' : 'product_tag';
					$term     = self::term( (string) $value, $taxonomy );
					if ( ! $term ) {
						$warnings[] = sprintf( 'No %1$s matches "%2$s" exactly (use find_terms with taxonomy %3$s).', $key, mb_substr( (string) $value, 0, 100 ), $taxonomy );
						$found      = array();
						break;
					}
					$applied[ $key ] = self::term_info( $term );
					$found           = self::objects( $term, 'category' === $key );
					break;
				case 'brand':
					$match = self::brand( (string) $value );
					if ( is_wp_error( $match ) ) {
						return $match;
					}
					if ( ! $match ) {
						$warnings[] = sprintf( 'No brand matches "%s" exactly (use find_terms with taxonomy "brand" to find the closest name).', mb_substr( (string) $value, 0, 100 ) );
						$found      = array();
						break;
					}
					$applied['brand'] = self::term_info( $match );
					$found            = self::objects( $match, true );
					break;
				case 'attribute':
					$attr     = is_array( $value ) ? $value : array();
					$taxonomy = FindTermsAbility::resolve_taxonomy( trim( (string) ( $attr['name'] ?? '' ) ) );
					if ( is_wp_error( $taxonomy ) || 0 !== strpos( $taxonomy, 'pa_' ) ) {
						return new WP_Error( 'addsynapse_assistant_unknown_attribute', sprintf( 'Unknown global attribute "%s". Use describe_store to see the attributes (local per-product attributes cannot be filtered).', mb_substr( (string) ( $attr['name'] ?? '' ), 0, 60 ) ) );
					}
					$term = self::term( (string) ( $attr['value'] ?? '' ), $taxonomy );
					if ( ! $term ) {
						$warnings[] = sprintf( 'No value of %1$s matches "%2$s" exactly (use find_terms with taxonomy %1$s).', $taxonomy, mb_substr( (string) ( $attr['value'] ?? '' ), 0, 100 ) );
						$found      = array();
						break;
					}
					$applied['attribute'] = self::term_info( $term );
					$found                = self::objects( $term, false );
					break;
				default:
					// product_ids: variations match their parent.
					$wanted = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $value ) ) ) ), 0, self::MAX_PRODUCT_IDS );
					$found  = self::parents_of( $wanted );
					if ( ! $found ) {
						$warnings[] = 'None of the product_ids is a product of this store.';
					}
					$applied['product_ids'] = $wanted;
					break;
			}//end switch
			$ids = null === $ids ? $found : array_values( array_intersect( $ids, $found ) );
		}//end foreach
		return array(
			'ids'      => null === $ids ? null : array_values( array_unique( array_map( 'intval', $ids ) ) ),
			'applied'  => $applied,
			'warnings' => $warnings,
		);
	}

	/**
	 * A term of a taxonomy by id, slug or exact name (case/accent-insensitive).
	 *
	 * @param string $value    Id, slug or name.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function term( string $value, string $taxonomy ): ?\WP_Term {
		$value = trim( $value );
		if ( '' === $value || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$term = preg_match( '/^\d{1,18}$/', $value ) ? get_term( (int) $value, $taxonomy ) : null;
		if ( ! $term instanceof \WP_Term ) {
			$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		}
		if ( ! $term instanceof \WP_Term ) {
			$term = get_term_by( 'name', $value, $taxonomy );
		}
		if ( ! $term instanceof \WP_Term ) {
			// Same exact-key rule as find_terms (accents, case, Greek ↔ Latin); never a fuzzy match.
			$wanted = TermMatcher::key( $value );
			$terms  = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => FindTermsAbility::MAX_SCAN,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $candidate ) {
				if ( '' !== $wanted && TermMatcher::key( html_entity_decode( (string) $candidate->name, ENT_QUOTES, 'UTF-8' ) ) === $wanted ) {
					$term = $candidate;
					break;
				}
			}
		}
		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * The brand term (primary brand storage first, then the others). Null = no exact match.
	 *
	 * @param string $value Id, slug or name.
	 * @return \WP_Term|WP_Error|null
	 */
	private static function brand( string $value ) {
		$map     = StoreMap::get();
		$sources = array_values( (array) ( $map['brand']['sources'] ?? array() ) );
		if ( ! $sources ) {
			return new WP_Error( 'addsynapse_assistant_brand_unsupported', 'This store has no brand taxonomy or global brand attribute, so products cannot be filtered by brand. Try search_products with the brand name as query.' );
		}
		$primary    = (string) ( $map['brand']['primary'] ?? '' );
		$taxonomies = array();
		foreach ( $sources as $source ) {
			if ( BrandResolver::for_source( (array) $source ) ) {
				$taxonomies[] = (string) $source['taxonomy'];
			}
		}
		usort(
			$taxonomies,
			static function ( $a, $b ) use ( $primary ) {
				return ( $b === $primary ) <=> ( $a === $primary );
			}
		);
		foreach ( $taxonomies as $taxonomy ) {
			$term = self::term( $value, $taxonomy );
			if ( $term ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Parent product ids that have the term (and optionally its children).
	 *
	 * @param \WP_Term $term     Term.
	 * @param bool     $children Include child terms.
	 * @return array<int,int>
	 */
	private static function objects( \WP_Term $term, bool $children ): array {
		$terms = array( (int) $term->term_id );
		if ( $children && is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$more  = get_term_children( (int) $term->term_id, $term->taxonomy );
			$terms = array_merge( $terms, is_array( $more ) ? array_map( 'intval', $more ) : array() );
		}
		$ids = get_objects_in_term( $terms, $term->taxonomy );
		return is_array( $ids ) ? self::parents_of( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Product ids → parent product ids (variations → their parent; anything else dropped).
	 *
	 * @param array<int,int> $ids Ids.
	 * @return array<int,int>
	 */
	private static function parents_of( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id; read-only.
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT IF( post_type = 'product_variation', post_parent, ID ) FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_type IN ( 'product', 'product_variation' )", $chunk ) );
			$out  = array_merge( $out, array_map( 'intval', (array) $rows ) );
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Echo of a matched term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string,mixed>
	 */
	private static function term_info( \WP_Term $term ): array {
		return array(
			'id'       => (int) $term->term_id,
			'name'     => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			'taxonomy' => (string) $term->taxonomy,
		);
	}

	/**
	 * JSON schema of the `filters` echo in results.
	 *
	 * @return array<string,mixed>
	 */
	public static function applied_schema(): array {
		$term = array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'name'     => array( 'type' => 'string' ),
				'taxonomy' => array( 'type' => 'string' ),
			),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'category'    => $term,
				'tag'         => $term,
				'brand'       => $term,
				'attribute'   => $term,
				'product_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
		);
	}
}
