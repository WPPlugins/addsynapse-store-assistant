<?php
/**
 * Ability addsynapse-assistant/coming-in-pro — the answer to a change only Store Assistant Pro makes (read-only).
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
 * the chat shows as a short «part of Store Assistant Pro» note with a «See Pro» link to the add-on's page. Information
 * only: nothing here checks for or unlocks anything. Not offered once another plugin provides the Pro features.
 */
final class ComingInProAbility {

	public const NAME = 'addsynapse-assistant/coming-in-pro';

	public const FEATURES = array( 'pages', 'bulk', 'attributes', 'terms', 'fields', 'coupons', 'orders', 'import', 'content', 'history', 'other' );

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Store Assistant Pro', 'addsynapse-store-assistant' ),
				'description'         => 'Call this INSTEAD of answering in text when the manager asks for a change only Store Assistant Pro (a separate add-on) makes: the text, headings, buttons, images or sections of a page (also the homepage), a post, a menu, banners or the look of the site (feature pages); many products at once (bulk); attributes or variations (attributes); creating/renaming categories, tags or brands (terms); other product settings (fields); coupons; orders; a CSV import (import); writing posts/pages or texts for many items (content); older changes (history); anything else (other). It changes nothing; the chat shows a See Pro button. Then say it in one sentence and offer what you can do now.',
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
