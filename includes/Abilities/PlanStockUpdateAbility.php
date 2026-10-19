<?php
/**
 * Ability addsynapse-assistant/plan-stock-update — computes a stock change and stores it as a DRAFT changeset.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Changesets\ProductSelector;
use Addsynapse\StoreAssistant\Changesets\StockPlanner;

defined( 'ABSPATH' ) || exit;

/**
 * Writes NOTHING to products. The manager approves/rejects the draft with UI buttons.
 */
final class PlanStockUpdateAbility {

	public const NAME = 'addsynapse-assistant/plan-stock-update';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Prepare a stock change', 'addsynapse-store-assistant' ),
				'description'         => 'Plans a stock quantity change (set or adjust) for the selected products and stores it as a DRAFT change set for the manager to approve. Changes nothing in the store.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => self::input_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::plan_meta(),
			)
		);
	}

	/**
	 * Input schema (also the tool definition the AI provider receives).
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		$qty = array(
			'type'    => 'integer',
			'minimum' => -StockPlanner::MAX_QTY,
			'maximum' => StockPlanner::MAX_QTY,
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'mode' ),
			'properties'           => array(
				'items' => array(
					'type'     => 'array',
					'maxItems' => 1,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'qty' ),
						'properties'           => array(
							'id'  => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'sku' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 100,
							),
							'qty' => $qty,
						),
					),
				),
			) + ProductSelector::schema_properties() + array(
				'mode'                => array(
					'type' => 'string',
					'enum' => StockPlanner::MODES,
				),
				'value'               => $qty,
				'enable_manage_stock' => array(
					'type'    => 'boolean',
					'default' => false,
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
		return ( new StockPlanner() )->plan( is_array( $input ) ? $input : array(), get_current_user_id(), ToolRegistry::current_conversation_id() );
	}
}
