<?php
/**
 * Ability: the exact current values of products/variations (prices, stock), SEO texts and coupons that
 * AddSynapse needs to prepare a change set (BEFORE values) and to verify it after Approve/Undo. Read-only.
 * Only offered to the remote route.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/read-state.
 */
final class ReadStateAbility {

	public const NAME      = 'addsynapse-assistant/read-state';
	public const MAX_ITEMS = 100;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Current values for a change set', 'addsynapse-store-assistant' ),
				'description'         => 'Returns the current price/stock fields of products or variations, the SEO title and meta description (active SEO plugin) of products, pages or posts, and coupons (by id or code). Read-only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'items' ),
					'properties'           => array(
						'items' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX_ITEMS,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'kind' ),
								'properties'           => array(
									'kind' => array(
										'type' => 'string',
										'enum' => StateUnits::KINDS,
									),
									'id'   => array(
										'type'    => 'integer',
										'minimum' => 0,
									),
									'code' => array(
										'type'      => 'string',
										'maxLength' => 100,
									),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				// Relay only: NOT an agent tool (no addsynapse_assistant_agent meta) — the in-admin chat keeps its own toolset.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	public function execute( $input ) {
		$out = array();
		foreach ( (array) $input['items'] as $item ) {
			$out[] = StateUnits::read( (array) $item );
		}
		$adapter = SeoAdapters::current();
		return array(
			'items'      => $out,
			'seo_plugin' => array(
				'key'      => $adapter->key(),
				'label'    => $adapter->label(),
				'supports' => $adapter->supports_seo(),
			),
		);
	}
}
