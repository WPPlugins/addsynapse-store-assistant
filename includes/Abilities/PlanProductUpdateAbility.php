<?php
/**
 * Ability addsynapse-assistant/plan-product-update — the name and texts of ONE product as a DRAFT changeset.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Changesets\CoreFields;
use Addsynapse\StoreAssistant\Changesets\ProductSelector;
use Addsynapse\StoreAssistant\Changesets\ProductUpdatePlanner;
use Addsynapse\StoreAssistant\Changesets\SeoText;

defined( 'ABSPATH' ) || exit;

/**
 * Writes NOTHING to products. Values go through the WooCommerce setters only after the manager approves the draft
 * with the UI buttons. Never: prices/stock quantity (own tools), slug, product type, trash/delete.
 */
final class PlanProductUpdateAbility {

	public const NAME = 'addsynapse-assistant/plan-product-update';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Prepare a product details change', 'addsynapse-store-assistant' ),
				'description'         => 'Plans a change of the name, short description, description, main image or gallery (add) of ONE product (or the description / main image of one variation) and stores it as a DRAFT change set for the manager to approve. Changes nothing in the store.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => self::input_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::plan_meta(),
			)
		);
	}

	/**
	 * Input schema. `set` stays open (additionalProperties) so another field gets a clear answer from the planner
	 * instead of a schema error.
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => ProductSelector::schema_properties() + array(
				'images' => array(
					'type'       => 'object',
					'properties' => array(
						'featured'    => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'gallery_add' => array(
							'type'     => 'array',
							'maxItems' => CoreFields::MAX_GALLERY,
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
					),
				),
				'set'    => array(
					'type'       => 'object',
					'properties' => array(
						'name'              => array(
							'type'      => 'string',
							'maxLength' => CoreFields::MAX_NAME,
						),
						'short_description' => array(
							'type'      => 'string',
							'maxLength' => SeoText::LONG_STORED_MAX,
						),
						'description'       => array(
							'type'      => 'string',
							'maxLength' => SeoText::LONG_STORED_MAX,
						),
					),
				),
			),
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( $input = null ) {
		return ( new ProductUpdatePlanner() )->plan( is_array( $input ) ? $input : array(), get_current_user_id(), ToolRegistry::current_conversation_id() );
	}
}
