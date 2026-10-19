<?php
/**
 * Ability addsynapse-assistant/plan-taxonomy-terms — add/remove/replace product terms as a DRAFT changeset.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\I18n\Locales;
use Addsynapse\StoreAssistant\Adapters\Brand\BrandResolver;
use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Changesets\ProductSelector;
use Addsynapse\StoreAssistant\Changesets\TermsPlanner;
use Addsynapse\StoreAssistant\Discovery\TermMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Writes NOTHING to products or terms. Only EXISTING terms are assigned (creating terms is not part of this plugin);
 * the manager approves/rejects the draft with UI buttons.
 */
final class PlanTaxonomyTermsAbility {

	public const NAME = 'addsynapse-assistant/plan-taxonomy-terms';


	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Prepare a category/tag/brand change', 'addsynapse-store-assistant' ),
				'description'         => 'Plans adding, removing or replacing terms of one product taxonomy (categories, tags, brand taxonomy, global attribute) on the selected products and stores it as a DRAFT change set for the manager to approve. Changes nothing in the store.',
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
			'required'             => array( 'taxonomy', 'mode' ),
			'properties'           => ProductSelector::schema_properties() + self::term_properties() + array(
				'taxonomy' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'mode'     => array(
					'type' => 'string',
					'enum' => TermsPlanner::MODES,
				),
			),
		);
	}

	/**
	 * Term properties term_ids / term_names.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function term_properties(): array {
		return array(
			'term_ids'   => array(
				'type'     => 'array',
				'maxItems' => TermsPlanner::MAX_TERMS,
				'items'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'term_names' => array(
				'type'     => 'array',
				'maxItems' => TermsPlanner::MAX_TERMS,
				'items'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
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
		$input   = is_array( $input ) ? $input : array();
		$context = array();
		if ( Locales::is( 'brand', (string) ( $input['taxonomy'] ?? '' ) ) ) {
			// "brand" = wherever this store keeps brands, through its adapter (no guessing).
			$resolved = BrandResolver::resolve();
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$context = array(
				'taxonomy' => $resolved['adapter']->taxonomy(),
				'warnings' => $resolved['warnings'],
			);
		}
		return ( new TermsPlanner() )->plan( $input, get_current_user_id(), ToolRegistry::current_conversation_id(), $context );
	}
}
