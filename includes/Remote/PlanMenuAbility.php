<?php
/**
 * Ability: computes what a list of menu operations (add / rename / move / remove items, assign a theme location) would make
 * of ONE navigation menu — the current snapshot, the target snapshot and a preview. Writes nothing and stores nothing:
 * AddSynapse keeps the draft and, after the owner approved it, sends it to apply-changes (kind «menu»).
 * Only offered to the remote route.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Menus\MenuPlanner;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/plan-menu.
 */
final class PlanMenuAbility {

	public const NAME = 'addsynapse-assistant/plan-menu';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		$str = static function ( int $max ): array {
			return array(
				'type'      => 'string',
				'maxLength' => $max,
			);
		};
		$ref = array( 'type' => array( 'integer', 'string' ) );
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Preview a menu change', 'addsynapse-store-assistant' ),
				'description'         => 'Computes the menu tree that a list of operations would give (add, rename, move or remove items, assign a theme location) with a before/after preview. Writes nothing.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'operations' ),
					'properties'           => array(
						'menu'       => $str( 100 ),
						'operations' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => MenuPlanner::MAX_OPS,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'op' ),
								'properties'           => array(
									'op'           => array(
										'type' => 'string',
										'enum' => array( 'add_item', 'rename_item', 'move_item', 'remove_item', 'assign_location' ),
									),
									'item_id'      => $ref,
									'item_title'   => $str( 200 ),
									'ref'          => $str( 40 ),
									'title'        => $str( 200 ),
									'object'       => array(
										'type' => 'string',
										'enum' => MenuPlanner::OBJECTS,
									),
									'object_id'    => array( 'type' => 'integer' ),
									'object_name'  => $str( 200 ),
									'url'          => $str( 2000 ),
									'parent_id'    => $ref,
									'parent_title' => $str( 200 ),
									'parent_ref'   => $str( 40 ),
									'position'     => array(
										'type'    => 'integer',
										'minimum' => 0,
									),
									'location'     => $str( 100 ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
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
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( $input ) {
		return ( new MenuPlanner() )->plan( (array) $input );
	}
}
