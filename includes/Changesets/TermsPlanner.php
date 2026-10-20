<?php
/**
 * Terms plan: add / remove / replace terms of one product taxonomy (categories, tags, brand
 * taxonomies; an attribute-based brand through plan-brand-assign) on ONE product — optionally with explicit new-term lines.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Abilities\FindTermsAbility;
use Addsynapse\StoreAssistant\Adapters\Brand\BrandResolver;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Discovery\StoreMap;
use Addsynapse\StoreAssistant\Discovery\TermMatcher;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rules:
 *  - only writable product taxonomies (TermFields::writable_taxonomy); pa_* → attribute field,
 *  - terms are given as ids or names; a name must match an existing term exactly
 *    (case/accent-insensitive). A missing name is an error: new terms are never created here,
 *  - taxonomies belong to the PARENT product: variations are mapped to their parent,
 *  - an attribute used for variations is never replaced/removed (the row is skipped),
 *  - removing the last category leaves WooCommerce's default category (shown in the preview).
 * Writes nothing: only the draft changeset.
 */
final class TermsPlanner extends Planner {

	public const OPERATION = 'taxonomy_terms';
	public const MODES     = array( 'add', 'remove', 'replace' );
	public const MAX_TERMS = 20;

	/**
	 * Builds the plan and stores a draft changeset.
	 *
	 * @param array<string,mixed> $input           Validated ability input (selectors, taxonomy, mode, term_ids, term_names).
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @param array<string,mixed> $context         operation, taxonomy (already resolved), params (extra), warnings.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0, array $context = array() ) {
		$mode = (string) ( $input['mode'] ?? '' );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'mode must be add, remove or replace.' );
		}
		$taxonomy = isset( $context['taxonomy'] ) ? (string) $context['taxonomy'] : self::resolve_taxonomy( (string) ( $input['taxonomy'] ?? '' ) );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		if ( ! TermFields::writable_taxonomy( $taxonomy ) ) {
			return new WP_Error( 'addsynapse_assistant_taxonomy_not_writable', sprintf( 'Terms of "%s" cannot be changed from here: only product categories, tags, brand taxonomies and global attributes can.', $taxonomy ) );
		}
		$field   = TermFields::field_for( $taxonomy );
		$is_attr = 'attribute' === TermFields::kind( $field );
		if ( $is_attr && ! isset( $context['taxonomy'] ) ) {
			// Global attribute values (pa_*) are attribute work (Pro). An attribute-based BRAND (plan-brand-assign,
			// which passes the resolved taxonomy) stays a plain one-product brand assignment.
			return self::one_item_only( 'attributes' );
		}
		$warnings = (array) ( $context['warnings'] ?? array() );
		if ( ! $is_attr && ! in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) && ! isset( BrandResolver::taxonomy_adapters()[ $taxonomy ] ) ) {
			$warnings[] = sprintf( '%s has no dedicated adapter; its terms are set with the standard WordPress term functions.', $taxonomy );
		}

		$terms = self::resolve_terms( $input, $taxonomy, $mode, $warnings );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		list( $wanted, $names ) = $terms;

		$max      = Config::changeset_max_items();
		$selected = ProductSelector::resolve( $input );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$skipped  = $selected['skipped'];
		$warnings = array_merge( $warnings, $selected['warnings'] );
		$products = array();
		$mapped   = 0;
		foreach ( $selected['products'] as $product ) {
			if ( $product instanceof \WC_Product_Variation ) {
				$parent = wc_get_product( $product->get_parent_id() );
				++$mapped;
				if ( ! $parent ) {
					$skipped[] = ProductSelector::skip( $product, 'Product not found.' );
					continue;
				}
				$product = $parent;
			}
			$products[ $product->get_id() ] = $product;
		}
		if ( $mapped ) {
			$warnings[] = sprintf( '%d variations were mapped to their parent products (terms belong to the parent).', $mapped );
		}
		if ( count( $products ) > $max ) {
			return new WP_Error( 'addsynapse_assistant_changeset_too_large', sprintf( 'The selection has %1$d products; the limit is %2$d per plan. Narrow it and make several plans.', count( $products ), $max ) );
		}

		$default_cat = 'product_cat' === $taxonomy ? (int) get_option( 'default_product_cat', 0 ) : 0;
		$rows        = array();
		$unchanged   = 0;
		$defaulted   = 0;
		$real_ids    = array_values( array_filter( $wanted, 'is_int' ) );
		foreach ( $products as $product ) {
			$before = TermFields::read( $product, $field );
			if ( $is_attr ) {
				$after = self::attribute_after( $product, $before, $wanted, $real_ids, $mode );
				if ( is_string( $after ) ) {
					$skipped[] = ProductSelector::skip( $product, $after );
					continue;
				}
			} else {
				if ( 'add' === $mode ) {
					$after = array_merge( $before, $wanted );
				} elseif ( 'remove' === $mode ) {
					$after = array_values( array_diff( $before, $real_ids ) );
				} else {
					$after = $wanted;
				}
				$after = self::canonical( $after );
				if ( ! $after && $default_cat > 0 && get_term( $default_cat, 'product_cat' ) instanceof \WP_Term ) {
					$after = array( $default_cat );
					++$defaulted;
				}
			}
			if ( TermFields::same( $field, $before, $after ) ) {
				++$unchanged;
				continue;
			}
			$rows[] = self::row(
				$product,
				array(
					array(
						'field'  => $field,
						'before' => $before,
						'after'  => $after,
					),
				)
			);
		}//end foreach
		if ( $defaulted ) {
			$warnings[] = sprintf( '%d products would be left without a category, so WooCommerce keeps its default category on them.', $defaulted );
		}
		if ( $skipped ) {
			$warnings[] = sprintf( '%d products/variations were skipped (see skipped).', count( $skipped ) );
		}
		foreach ( $rows as $row ) {
			foreach ( TermFields::term_list( $field, $row['changes'][0]['before'] ) as $id ) {
				if ( is_int( $id ) && ! isset( $names[ $id ] ) ) {
					$names[ $id ] = self::term_name( $id );
				}
			}
		}

		$params = array(
			'taxonomy'       => $taxonomy,
			'taxonomy_label' => TermFields::label( $taxonomy ),
			'kind'           => $is_attr ? 'attribute' : 'taxonomy',
			'mode'           => $mode,
			'terms'          => array_values( array_map( static fn( $id ) => (string) ( $names[ $id ] ?? $id ), $wanted ) ),
			'term_names'     => self::names_param( $names ),
		) + (array) ( $context['params'] ?? array() ) + self::selector_params( $input );

		$operation = (string) ( $context['operation'] ?? self::OPERATION );
		return $this->finish(
			array(
				'operation'       => $operation,
				'params'          => $params,
				'summary'         => self::summary( $params, count( $rows ) ),
				'rows'            => $rows,
				'names'           => $names,
				'skipped'         => $skipped,
				'warnings'        => $warnings,
				'unchanged'       => $unchanged,
				'totals'          => array( 'products' => count( $rows ) ),
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
			)
		);
	}

	/**
	 * "category" | "tag" | taxonomy | attribute slug/label → taxonomy ("brand" goes through
	 * the brand ability / BrandResolver instead).
	 *
	 * @param string $name Name.
	 * @return string|WP_Error
	 */
	public static function resolve_taxonomy( string $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Say which taxonomy (product_cat, product_tag, a brand taxonomy or a pa_* attribute).' );
		}
		return FindTermsAbility::resolve_taxonomy( $name );
	}

	/**
	 * Resolves term ids/names of EXISTING terms → wanted list and names map.
	 *
	 * @param array<string,mixed> $input    Input.
	 * @param string              $taxonomy Taxonomy.
	 * @param string              $mode     Mode.
	 * @param array<int,string>   $warnings Warnings (by ref).
	 * @return array{0:array<int,int>,1:array<int,string>}|WP_Error
	 */
	private static function resolve_terms( array $input, string $taxonomy, string $mode, array &$warnings ) {
		$ids        = array_values( array_unique( array_map( 'intval', (array) ( $input['term_ids'] ?? array() ) ) ) );
		$term_names = array_values( array_filter( array_map( static fn( $n ) => trim( (string) $n ), (array) ( $input['term_names'] ?? array() ) ), 'strlen' ) );
		if ( ! $ids && ! $term_names ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Give the terms: term_ids and/or term_names.' );
		}
		if ( count( $ids ) + count( $term_names ) > self::MAX_TERMS ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', sprintf( 'At most %d terms per plan.', self::MAX_TERMS ) );
		}
		$wanted = array();
		$names  = array();
		foreach ( $ids as $id ) {
			$term = $id > 0 ? get_term( $id, $taxonomy ) : null;
			if ( ! $term instanceof \WP_Term ) {
				return new WP_Error( 'addsynapse_assistant_term_not_found', sprintf( 'Term id %1$d is not a term of %2$s. Use find_terms to get the right id.', $id, $taxonomy ) );
			}
			$wanted[]     = (int) $term->term_id;
			$names[ $id ] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
		}
		$missing = array();
		foreach ( $term_names as $name ) {
			$term = self::find_exact( $name, $taxonomy );
			if ( $term ) {
				$wanted[]                      = (int) $term->term_id;
				$names[ (int) $term->term_id ] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
			} else {
				$missing[ TermMatcher::normalize( $name ) ] = mb_substr( $name, 0, 100 );
			}
		}
		if ( $missing ) {
			if ( 'remove' === $mode ) {
				foreach ( $missing as $name ) {
					$warnings[] = sprintf( '"%1$s" is not a term of %2$s, so there is nothing to remove.', $name, $taxonomy );
				}
			} else {
				$parts = array();
				foreach ( $missing as $name ) {
					$close   = TermMatcher::rank( $name, StoreMap::terms( $taxonomy, 2000 ), 3 );
					$parts[] = sprintf( '"%1$s" (closest: %2$s)', $name, $close ? implode( ', ', wp_list_pluck( $close, 'name' ) ) : 'none' );
				}
				return new WP_Error(
					'addsynapse_assistant_term_not_found',
					sprintf( 'No such term in %1$s: %2$s. Nothing was planned. Only EXISTING terms can be assigned here: ask the manager whether they mean one of the existing terms (creating new categories, tags or brands is part of Store Assistant Pro).', $taxonomy, implode( '; ', $parts ) )
				);
			}//end if
		}//end if
		if ( ! $wanted && 'remove' !== $mode ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'No valid terms were given.' );
		}
		return array( self::canonical( $wanted ), $names );
	}

	/**
	 * Existing term whose name or slug equals $name (case/accent-insensitive), or null.
	 *
	 * @param string $name     Name.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function find_exact( string $name, string $taxonomy ): ?\WP_Term {
		$wanted = TermMatcher::normalize( $name );
		if ( '' === $wanted ) {
			return null;
		}
		foreach ( array(
			'name' => $name,
			'slug' => sanitize_title( $name ),
		) as $by => $value ) {
			$term = get_term_by( $by, $value, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 5000,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( TermMatcher::normalize( (string) $term->name ) === $wanted || TermMatcher::normalize( rawurldecode( (string) $term->slug ) ) === $wanted ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * New attribute payload for a product, or a skip reason (string).
	 *
	 * @param \WC_Product              $product  Product.
	 * @param array<string,mixed>|null $before   Current payload.
	 * @param array<int,int|string>    $wanted   Wanted ids/refs.
	 * @param array<int,int>           $real_ids Wanted existing ids.
	 * @param string                   $mode     Mode.
	 * @return array<string,mixed>|string|null
	 */
	private static function attribute_after( \WC_Product $product, ?array $before, array $wanted, array $real_ids, string $mode ) {
		if ( null === $before ) {
			if ( 'remove' === $mode ) {
				return null;
			}
			return array(
				'terms'     => $wanted,
				'visible'   => true,
				'variation' => false,
				'position'  => count( $product->get_attributes( 'edit' ) ),
			);
		}
		if ( 'add' !== $mode && ! empty( $before['variation'] ) ) {
			return 'This attribute is used for variations; change it in the product editor.';
		}
		$after = $before;
		if ( 'add' === $mode ) {
			$after['terms'] = self::canonical( array_merge( $before['terms'], $wanted ) );
		} elseif ( 'remove' === $mode ) {
			$after['terms'] = self::canonical( array_diff( $before['terms'], $real_ids ) );
			if ( ! $after['terms'] ) {
				return null;
			}
		} else {
			$after['terms'] = $wanted;
		}
		return $after;
	}

	/**
	 * Sorted unique term ids.
	 *
	 * @param array<int,mixed> $values Ids.
	 * @return array<int,int>
	 */
	public static function canonical( array $values ): array {
		return TermFields::ids( $values );
	}

	/**
	 * Names map for the stored params (UI fallback once a term is gone), max 200 entries.
	 *
	 * @param array<int|string,string> $names Names.
	 * @return array<string,string>
	 */
	private static function names_param( array $names ): array {
		$out = array();
		foreach ( array_slice( $names, 0, 200, true ) as $id => $name ) {
			$out[ (string) $id ] = $name;
		}
		return $out;
	}

	/**
	 * English summary (audit/model).
	 *
	 * @param array<string,mixed> $params Params.
	 * @param int                 $count  Products.
	 */
	private static function summary( array $params, int $count ): string {
		$verb = array(
			'add'     => 'add',
			'remove'  => 'remove',
			'replace' => 'set (replace with)',
		)[ $params['mode'] ];
		return sprintf( '%1$s: %2$s %3$s — %4$d products.', $params['taxonomy'], $verb, implode( ', ', $params['terms'] ), $count );
	}
}
