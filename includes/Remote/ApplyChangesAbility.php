<?php
/**
 * Ability: writes a change set the shop owner approved on AddSynapse (or restores it on Undo) — price/stock
 * fields of products and variations, SEO title/description of products, pages and posts, coupons. Never
 * offered to the in-admin chat (no agent meta) — only to the remote route.
 *
 * All-or-nothing pre-check: every unit must still hold the expected («before») values, otherwise nothing is
 * written and the conflicts are returned. Every write is recorded in the approval log.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/apply-changes.
 */
final class ApplyChangesAbility {

	public const NAME      = 'addsynapse-assistant/apply-changes';
	public const MAX_ITEMS = 100;

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
				'label'               => __( 'Apply an approved change set', 'addsynapse-store-assistant' ),
				'description'         => 'Writes the values of a change set the shop owner approved (or restores them on Undo): price/stock fields of products and variations, SEO title/description, coupons. Refuses everything if anything no longer holds the expected values.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'changeset', 'action', 'items' ),
					'properties'           => array(
						'changeset' => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
						'action'    => array(
							'type' => 'string',
							'enum' => array( 'approve', 'undo' ),
						),
						'summary'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'items'     => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX_ITEMS,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'kind', 'id', 'expect', 'set' ),
								'properties'           => array(
									'kind'    => array(
										'type' => 'string',
										'enum' => StateUnits::KINDS,
									),
									'id'      => array(
										'type'    => 'integer',
										'minimum' => 0,
									),
									'adapter' => array( 'type' => 'string' ),
									'expect'  => $values,
									'set'     => $values,
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
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
		$items     = array_values( (array) $input['items'] );
		$conflicts = array();
		$adapter   = SeoAdapters::current()->key();
		foreach ( $items as $i => $item ) {
			$conflict = $this->check( (array) $item, $adapter );
			if ( '' !== $conflict ) {
				$conflicts[] = array(
					'index'  => $i,
					'kind'   => (string) $item['kind'],
					'id'     => (int) $item['id'],
					'reason' => $conflict,
				);
			}
		}
		if ( $conflicts ) {
			ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), array(), 'refused: ' . count( $conflicts ) . ' conflict(s)' );
			return array(
				'ok'        => false,
				'conflicts' => $conflicts,
			);
		}
		$ids  = array();
		$rows = array();
		foreach ( $items as $item ) {
			$kind   = (string) $item['kind'];
			$set    = is_array( $item['set'] ) ? $item['set'] : null;
			$before = (int) $item['id'] ? StateUnits::read(
				array(
					'kind' => $kind,
					'id'   => (int) $item['id'],
				)
			) : array();
			$id     = StateUnits::write( $kind, (int) $item['id'], $set );
			$ids[]  = $id;
			$rows[] = array(
				'id'   => $id,
				'kind' => $kind,
				'name' => ! empty( $before['exists'] ) ? (string) $before['name'] : (string) ( $set['code'] ?? '' ),
				'from' => $item['expect'],
				'to'   => $set,
			);
		}
		ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), $rows, 'ok' );
		return array(
			'ok'  => true,
			'ids' => $ids,
		);
	}

	/**
	 * Why this unit may not be written now ('' = it may).
	 *
	 * @param array<string,mixed> $item    Item.
	 * @param string              $adapter Active SEO adapter key.
	 */
	private function check( array $item, string $adapter ): string {
		$kind   = (string) $item['kind'];
		$id     = (int) $item['id'];
		$expect = is_array( $item['expect'] ) ? $item['expect'] : null;
		$set    = is_array( $item['set'] ) ? $item['set'] : null;
		if ( null !== $set ) {
			$invalid = StateUnits::invalid( $kind, $set );
			if ( '' !== $invalid ) {
				return $invalid;
			}
		}
		if ( 'coupon' === $kind && 0 === $id ) {
			if ( null !== $expect || null === $set || empty( $set['code'] ) ) {
				return 'A new coupon needs a code and no expected values.';
			}
			return wc_get_coupon_id_by_code( (string) $set['code'] ) ? 'A coupon with this code already exists.' : '';
		}
		if ( null === $expect || ( null === $set && 'coupon' !== $kind ) ) {
			return 'Expected and new values are required.';
		}
		if ( 'seo' === $kind && ( ! SeoAdapters::current()->supports_seo() || ( isset( $item['adapter'] ) && $item['adapter'] !== $adapter ) ) ) {
			return 'The SEO plugin changed since the draft (now: ' . $adapter . ').';
		}
		$now = StateUnits::read(
			array(
				'kind' => $kind,
				'id'   => $id,
			)
		);
		if ( empty( $now['exists'] ) ) {
			return (string) ( $now['reason'] ?? 'Not found.' );
		}
		return StateUnits::holds( $kind, (array) $now['values'], $expect ) ? '' : 'Changed since the draft.';
	}
}
