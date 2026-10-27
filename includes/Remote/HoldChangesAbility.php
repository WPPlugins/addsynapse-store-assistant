<?php
/**
 * Ability: AddSynapse hands over a draft that only a site administrator may approve, on this plugin's wp-admin approval
 * page (AdminApprovals) — a big or unbounded coupon (CouponBrake). Writes nothing to the shop: the draft waits there,
 * checked like an approval, until an administrator approves or discards it. Only on the remote route.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/hold-changes.
 */
final class HoldChangesAbility {

	public const NAME = 'addsynapse-assistant/hold-changes';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		$values = array(
			'type'                 => array( 'object', 'null' ),
			'additionalProperties' => true,
		);
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Send a draft to the wp-admin approval page', 'addsynapse-store-assistant' ),
				'description'         => 'Puts a coupon draft that needs an administrator (above 50% off, a fixed discount above 50, free shipping without a usage limit, or neither a usage limit nor an expiry date) on this site\'s wp-admin approval page (AddSynapse → Approvals), where a site administrator approves or discards it. Changes nothing in the shop. Returns approve_url. With status_only: only reports what an administrator decided there (held, applied, discarded, undone, expired or none).',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'changeset', 'items' ),
					'properties'           => array(
						'changeset' => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
						'summary'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'status_only' => array(
							'type' => 'boolean',
						),
						'items'     => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 5,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'kind', 'id', 'expect', 'set' ),
								'properties'           => array(
									'kind'   => array(
										'type' => 'string',
										'enum' => array( 'coupon' ),
									),
									'id'     => array(
										'type'    => 'integer',
										'minimum' => 0,
									),
									'expect' => $values,
									'set'    => $values,
								),
							),
						),
					),
				),
				'execute_callback'    => array( AdminApprovals::class, 'hold' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}
}
