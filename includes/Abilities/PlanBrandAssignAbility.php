<?php
/**
 * Ability addsynapse-assistant/plan-brand-assign — sets/adds/removes the brand of products through the
 * store's brand adapter, as a DRAFT changeset.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Adapters\Brand\BrandResolver;
use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Changesets\ProductSelector;
use Addsynapse\StoreAssistant\Changesets\TermsPlanner;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Convenience over plan-taxonomy-terms: BrandResolver picks the adapter (WooCommerce Brands,
 * Perfect Brands, YITH Brands or a global brand attribute) from the store map; anything
 * else is "unsupported brand storage". Writes NOTHING.
 */
final class PlanBrandAssignAbility {

	public const NAME      = 'addsynapse-assistant/plan-brand-assign';
	public const OPERATION = 'brand_assign';
	public const MODES     = array( 'set', 'add', 'remove' );

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Prepare a brand change', 'addsynapse-store-assistant' ),
				'description'         => 'Plans setting, adding or removing the brand of the selected products, wherever this store keeps brands, and stores it as a DRAFT change set for the manager to approve. Changes nothing in the store.',
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
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => ProductSelector::schema_properties() + array(
				'mode'     => array(
					'type'    => 'string',
					'enum'    => self::MODES,
					'default' => 'set',
				),
				'brand'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'brand_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
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
		$input = is_array( $input ) ? $input : array();
		$brand = trim( (string) ( $input['brand'] ?? '' ) );
		$id    = (int) ( $input['brand_id'] ?? 0 );
		if ( '' === $brand && $id < 1 ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Give the brand: brand (name) or brand_id.' );
		}
		$resolved = BrandResolver::resolve();
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$adapter = $resolved['adapter'];
		$mode    = (string) ( $input['mode'] ?? 'set' );
		$terms   = array_diff_key( $input, array_flip( array( 'brand', 'brand_id', 'mode' ) ) );
		if ( $id > 0 ) {
			$terms['term_ids'] = array( $id );
		} else {
			$terms['term_names'] = array( $brand );
		}
		$terms['mode'] = 'set' === $mode ? 'replace' : $mode;
		return ( new TermsPlanner() )->plan(
			$terms,
			get_current_user_id(),
			ToolRegistry::current_conversation_id(),
			array(
				'operation' => self::OPERATION,
				'taxonomy'  => $adapter->taxonomy(),
				'warnings'  => $resolved['warnings'],
				'params'    => array(
					'brand_mode'    => $mode,
					'brand_adapter' => $adapter->key(),
					'brand_storage' => $adapter->storage(),
					'brand_plugin'  => $adapter->label(),
				),
			)
		);
	}
}
