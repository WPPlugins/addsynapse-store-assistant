<?php
/**
 * Ability addsynapse-assistant/find-terms — exact/fuzzy term lookup in a product taxonomy or attribute.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\I18n\Locales;
use Addsynapse\StoreAssistant\Discovery\StoreMap;
use Addsynapse\StoreAssistant\Discovery\TermMatcher;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * "Huadidas" → Adidas (score 0.86). Only PRODUCT taxonomies are searchable (categories,
 * tags, brands, pa_* attributes…) — never users or other objects. Read-only: a missing
 * term is reported, never created.
 */
final class FindTermsAbility {

	public const NAME = 'addsynapse-assistant/find-terms';

	/**
	 * Terms scanned per taxonomy (most used first).
	 */
	public const MAX_SCAN = 5000;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Find terms', 'addsynapse-store-assistant' ),
				'description'         => 'Finds terms of one product taxonomy or global attribute (read-only) that match a name exactly or approximately (case/accent-insensitive, any script with a locale pack (e.g. Greek) and Latin, typos). taxonomy may be a taxonomy name (product_cat, product_tag, product_brand, pa_color), an attribute slug/label (e.g. color, or a label in the store\'s language) or "brand" for the store\'s brand storage. Returns matches with a similarity score.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'taxonomy', 'query' ),
					'properties'           => array(
						'taxonomy' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
						'query'    => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
						'limit'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'object',
					'required' => array( 'taxonomy', 'query', 'exact', 'matches' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
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
		$query    = trim( (string) ( $input['query'] ?? '' ) );
		$limit    = max( 1, min( 20, (int) ( $input['limit'] ?? 5 ) ) );
		$taxonomy = self::resolve_taxonomy( trim( (string) ( $input['taxonomy'] ?? '' ) ) );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		if ( '' === TermMatcher::normalize( $query ) ) {
			return new WP_Error( 'addsynapse_assistant_empty_query', 'Give a term name to look for.' );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => self::MAX_SCAN,
			)
		);
		$rows  = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$rows[] = array(
				'id'    => (int) $term->term_id,
				'name'  => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
				'slug'  => rawurldecode( (string) $term->slug ),
				'count' => (int) $term->count,
			);
		}
		$matches = TermMatcher::rank( $query, $rows, $limit );
		$exact   = $matches && 'exact' === $matches[0]['match'];
		$object  = get_taxonomy( $taxonomy );
		$total   = (int) wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( $exact ) {
			$note = 'Exact match found.';
		} elseif ( $matches ) {
			$note = 'No exact match. Ask the user to confirm the closest term before using it. A new term is only created as a separate, explicit, user-approved change.';
		} else {
			$note = 'No similar term exists. Tell the user; do not assume a new term — creating one needs an explicit, separate confirmation.';
		}
		return array(
			'taxonomy'       => $taxonomy,
			'taxonomy_label' => $object ? html_entity_decode( (string) $object->label, ENT_QUOTES, 'UTF-8' ) : $taxonomy,
			'kind'           => 0 === strpos( $taxonomy, 'pa_' ) ? 'attribute' : 'taxonomy',
			'query'          => $query,
			'exact'          => $exact,
			'matches'        => $matches,
			'total_terms'    => $total,
			'scanned'        => count( $rows ),
			'note'           => $note,
			'_data'          => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Resolves "product_cat" | "pa_color" | "color" | "Color" (label, any language) | "brand" to a product taxonomy.
	 *
	 * @param string $name User/AI supplied name.
	 * @return string|WP_Error
	 */
	public static function resolve_taxonomy( string $name ) {
		$product_taxonomies = array_values(
			array_filter(
				get_object_taxonomies( 'product' ),
				static function ( $t ) {
					return ! in_array( $t, StoreMap::INTERNAL_TAXONOMIES, true );
				}
			)
		);
		if ( in_array( $name, $product_taxonomies, true ) ) {
			return $name;
		}
		$wanted = TermMatcher::normalize( $name );
		if ( Locales::is( 'brand', $name ) ) {
			$map = StoreMap::get();
			if ( ! empty( $map['brand']['primary'] ) ) {
				return (string) $map['brand']['primary'];
			}
		}
		$alias = Locales::lookup( 'taxonomies', $name );
		if ( null !== $alias && in_array( $alias, $product_taxonomies, true ) ) {
			return $alias;
		}
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				if ( TermMatcher::normalize( $attribute->attribute_name ) === $wanted || TermMatcher::normalize( (string) $attribute->attribute_label ) === $wanted || TermMatcher::normalize( $taxonomy ) === $wanted ) {
					return $taxonomy;
				}
			}
		}
		foreach ( $product_taxonomies as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( $object && ( TermMatcher::normalize( (string) $object->label ) === $wanted || TermMatcher::normalize( (string) $object->labels->singular_name ) === $wanted ) ) {
				return $taxonomy;
			}
		}
		return new WP_Error(
			'addsynapse_assistant_unknown_taxonomy',
			sprintf( 'Unknown product taxonomy or attribute "%s". Available: %s. Local (per-product) attributes have no terms; use get-product-raw.', mb_substr( $name, 0, 60 ), implode( ', ', array_slice( $product_taxonomies, 0, 40 ) ) )
		);
	}
}
