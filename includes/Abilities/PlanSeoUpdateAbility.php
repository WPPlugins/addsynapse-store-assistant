<?php
/**
 * Ability addsynapse-assistant/plan-seo-update — AI-written SEO texts / descriptions as a DRAFT changeset.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Changesets\SeoPlanner;
use Addsynapse\StoreAssistant\Changesets\SeoText;

defined( 'ABSPATH' ) || exit;

/**
 * Writes NOTHING to products. The model passes the proposed texts per product; the plugin
 * validates/sanitises them (SeoText) and records before/after per field for the manager's
 * Έγκριση. SEO plugin keys are written only through the SEO adapter, on approval.
 */
final class PlanSeoUpdateAbility {

	public const NAME = 'addsynapse-assistant/plan-seo-update';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Prepare an SEO improvement', 'addsynapse-store-assistant' ),
				'description'         => 'Plans new SEO title / meta description / focus keyword (active SEO plugin: Rank Math or Yoast) for up to 10 products (with WooCommerce; also short description / description), pages or posts, and stores it as a DRAFT change set for the manager to approve. Changes nothing on the site.',
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
		$text = static function ( int $max ): array {
			return array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => $max,
			);
		};
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'products' ),
			'properties'           => array(
				'products' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => SeoPlanner::MAX_PRODUCTS,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'id' ),
						'properties'           => array(
							'id'                => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'seo_title'         => $text( SeoText::TITLE_MAX ),
							'seo_description'   => $text( SeoText::DESCRIPTION_MAX ),
							'focus_keyword'     => $text( SeoText::KEYWORD_MAX ),
							'short_description' => $text( SeoText::SHORT_MAX ),
							'description'       => $text( SeoText::LONG_MAX ),
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
		return ( new SeoPlanner() )->plan( is_array( $input ) ? $input : array(), get_current_user_id(), ToolRegistry::current_conversation_id() );
	}
}
