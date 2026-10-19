<?php
/**
 * Ability addsynapse-assistant/plan-price-update — computes a price change and stores it as a DRAFT changeset.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Changesets\PricePlanner;
use Addsynapse\StoreAssistant\Changesets\ProductSelector;

defined( 'ABSPATH' ) || exit;

/**
 * Writes NOTHING to products. The manager approves/rejects the draft with UI buttons.
 */
final class PlanPriceUpdateAbility {

	public const NAME = 'addsynapse-assistant/plan-price-update';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Prepare a price change', 'addsynapse-store-assistant' ),
				'description'         => 'Plans a price change (percent, fixed amount or exact price, on the regular or sale price, optional sale dates) for the selected products and stores it as a DRAFT change set for the manager to approve. Changes nothing in the store.',
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
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'mode', 'value' ),
			'properties'           => ProductSelector::schema_properties() + array(
				'mode'      => array(
					'type' => 'string',
					'enum' => PricePlanner::MODES,
				),
				'value'     => array( 'type' => 'number' ),
				'target'    => array(
					'type'    => 'string',
					'enum'    => PricePlanner::TARGETS,
					'default' => 'regular',
				),
				'sale_from' => $date,
				'sale_to'   => $date,
				'rounding'  => array(
					'type'    => 'string',
					'enum'    => PricePlanner::ROUNDING,
					'default' => '2dp',
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
		return ( new PricePlanner() )->plan( is_array( $input ) ? $input : array(), get_current_user_id(), ToolRegistry::current_conversation_id() );
	}
}
