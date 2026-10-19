<?php
/**
 * Ability addsynapse-assistant/coming-in-pro — the answer to a change this plugin cannot make (read-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Changesets\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * When the manager asks for something this free plugin does not change — a page, post, menu, section or the site's
 * appearance; many products at once; attributes, coupons, orders, imports … — the model calls this tool instead of
 * pretending or failing silently. It changes nothing: it returns the `pro_feature` answer (Planner::one_item_only), which
 * the chat shows as a short «coming in Store Assistant Pro» note (with an optional «Get notified» link once per
 * conversation). Information only: nothing here checks for, unlocks or sells anything.
 */
final class ComingInProAbility {

	public const NAME = 'addsynapse-assistant/coming-in-pro';

	public const FEATURES = array( 'pages', 'bulk', 'attributes', 'terms', 'fields', 'other' );

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Coming in Store Assistant Pro', 'addsynapse-store-assistant' ),
				'description'         => 'Call this INSTEAD of answering in text when the manager asks for a change this plugin cannot make: editing the text, headings, buttons, images or sections of a page (also the homepage), a post/article, a menu, banners or the site\'s appearance (feature: pages); a change of many products at once (bulk); product attributes or variations (attributes); creating/renaming categories, tags or brands (terms); other product settings (fields); coupons, orders, CSV imports or anything else (other). It changes nothing and returns a short message for the manager; then say it in one sentence and offer what you can do now (reading the page/menu, or changing ONE product).',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'feature' ),
					'properties'           => array(
						'feature' => array(
							'type' => 'string',
							'enum' => self::FEATURES,
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'object',
					'required' => array( 'status', 'message' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Executes.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,string>
	 */
	public function execute( $input = null ): array {
		$feature = is_array( $input ) ? (string) ( $input['feature'] ?? 'other' ) : 'other';
		return Planner::one_item_only( in_array( $feature, self::FEATURES, true ) ? $feature : 'other' );
	}
}
