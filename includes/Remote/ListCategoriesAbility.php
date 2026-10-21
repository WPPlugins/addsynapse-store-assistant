<?php
/**
 * Ability: the product categories of the shop (id, name, slug, parent, product count). Read-only.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/list-categories.
 */
final class ListCategoriesAbility {

	public const NAME = 'addsynapse-assistant/list-categories';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'List product categories', 'addsynapse-store-assistant' ),
				'description'         => 'Lists WooCommerce product categories (read-only): id, name, slug, parent and number of products. Optional search by name.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'search' => array(
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
	 * Execute callback.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>
	 */
	public function execute( $input = null ): array {
		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => 500,
		);
		if ( is_array( $input ) && ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		$terms = get_terms( $args );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'id'     => (int) $term->term_id,
				'name'   => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
				'slug'   => (string) $term->slug,
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			);
		}
		return array( 'categories' => $out );
	}
}
