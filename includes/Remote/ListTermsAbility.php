<?php
/**
 * Remote ability «list-terms» (1.3.9): the product taxonomies a change set may set on a product (categories, tags, the
 * brand, global attributes) and the terms of one of them. Read-only. Its presence also tells AddSynapse that this site
 * can write the «product family» (read-state / apply-changes kinds product_terms and term).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Changesets\TermFields;

defined( 'ABSPATH' ) || exit;

/**
 * Lists product taxonomies and their terms.
 */
final class ListTermsAbility {

	public const NAME = 'addsynapse-assistant/list-terms';

	/** Most terms returned at once. */
	public const MAX = 500;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'List product categories, tags, brands and attributes', 'addsynapse-store-assistant' ),
				'description'         => 'Read-only. Lists the product taxonomies AddSynapse may set on a product (categories, tags, the brand, global attributes) and, when `taxonomy` is given (product_cat, product_tag, brand, a brand taxonomy or pa_*), its terms: id, name, slug, parent and number of products. Optional search by name.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'taxonomy' => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
						'search'   => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Taxonomies (+ the terms of one).
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$fields = ProductTerms::fields();
		$brand  = ProductTerms::brand_field();
		$list   = array();
		foreach ( $fields as $field => $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$list[] = array(
				'taxonomy'     => $taxonomy,
				'label'        => TermFields::label( $taxonomy ),
				'kind'         => self::kind( $field, $taxonomy, $brand ),
				'field'        => $field,
				'hierarchical' => (bool) is_taxonomy_hierarchical( $taxonomy ),
				'count'        => (int) wp_count_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				),
				'label_plural' => $object ? html_entity_decode( (string) $object->labels->name, ENT_QUOTES, 'UTF-8' ) : $taxonomy,
			);
		}
		$out    = array(
			'taxonomies' => $list,
			'brand'      => null === $brand ? null : array(
				'taxonomy' => TermFields::taxonomy( $brand ),
				'field'    => $brand,
			),
			'taxonomy'   => null,
			'terms'      => array(),
		);
		$wanted = (string) ( $input['taxonomy'] ?? '' );
		if ( '' === $wanted ) {
			return $out;
		}
		if ( 'brand' === $wanted ) {
			if ( null === $brand ) {
				return new \WP_Error( 'addsynapse_assistant_brand_unsupported', 'This store keeps no brand AddSynapse can change (no brand taxonomy or global brand attribute).' );
			}
			$wanted = TermFields::taxonomy( $brand );
		}
		$aliases = array(
			'category'   => 'product_cat',
			'categories' => 'product_cat',
			'tag'        => 'product_tag',
			'tags'       => 'product_tag',
		);
		$wanted  = $aliases[ strtolower( $wanted ) ] ?? $wanted;
		if ( ! in_array( $wanted, $fields, true ) ) {
			return new \WP_Error( 'addsynapse_assistant_taxonomy_not_writable', sprintf( 'Unknown product taxonomy "%s". Available: %s.', mb_substr( $wanted, 0, 64 ), implode( ', ', array_values( $fields ) ) ) );
		}
		$args = array(
			'taxonomy'   => $wanted,
			'hide_empty' => false,
			'number'     => self::MAX,
			'orderby'    => 'name',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		$terms = get_terms( $args );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out['terms'][] = array(
				'id'     => (int) $term->term_id,
				'name'   => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
				'slug'   => (string) $term->slug,
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			);
		}
		$out['taxonomy'] = $wanted;
		return $out;
	}

	/**
	 * What a taxonomy is for the owner.
	 *
	 * @param string      $field    Field.
	 * @param string      $taxonomy Taxonomy.
	 * @param string|null $brand    Brand field.
	 */
	private static function kind( string $field, string $taxonomy, ?string $brand ): string {
		if ( $field === $brand ) {
			return 'brand';
		}
		if ( 'product_cat' === $taxonomy ) {
			return 'category';
		}
		if ( 'product_tag' === $taxonomy ) {
			return 'tag';
		}
		return 'attribute' === TermFields::kind( $field ) ? 'attribute' : 'brand';
	}
}
