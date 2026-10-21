<?php
/**
 * Ability: writes the regular/sale prices of a change set the shop owner approved on AddSynapse (or restores
 * them on Undo). Never offered to the in-admin chat (no agent meta) — only to the remote route.
 *
 * All-or-nothing pre-check: every product must still hold the expected values, otherwise nothing is written and
 * the conflicts are returned. Every write is recorded in the approval log (AddSynapse → Connect AI → Approval log).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/apply-price-changes.
 */
final class ApplyPricesAbility {

	public const NAME      = 'addsynapse-assistant/apply-price-changes';
	public const MAX_ITEMS = 100;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		$prices = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'regular_price', 'sale_price' ),
			'properties'           => array(
				'regular_price' => array(
					'type'    => 'string',
					'pattern' => '^([0-9]+(\\.[0-9]{1,6})?)?$',
				),
				'sale_price'    => array(
					'type'    => 'string',
					'pattern' => '^([0-9]+(\\.[0-9]{1,6})?)?$',
				),
			),
		);
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Apply approved price changes', 'addsynapse-store-assistant' ),
				'description'         => 'Writes the regular and sale prices of a change set the shop owner approved (or restores them on Undo). Refuses everything if any product no longer has the expected prices. An empty sale_price clears the sale price.',
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
								'required'             => array( 'id', 'expect', 'set' ),
								'properties'           => array(
									'id'     => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'expect' => $prices,
									'set'    => $prices,
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
						'idempotent'  => true,
					),
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
		$items     = (array) $input['items'];
		$products  = array();
		$conflicts = array();
		foreach ( $items as $item ) {
			$product = wc_get_product( (int) $item['id'] );
			if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation', 'external' ), true ) ) {
				$conflicts[] = array(
					'id'     => (int) $item['id'],
					'reason' => 'not a simple product or variation',
				);
				continue;
			}
			$now = self::state( $product );
			if ( self::norm( $item['expect'] ) !== $now ) {
				$conflicts[] = array(
					'id'       => (int) $item['id'],
					'reason'   => 'changed since the draft',
					'site_now' => $now,
				);
				continue;
			}
			$products[ (int) $item['id'] ] = $product;
		}
		if ( $conflicts ) {
			ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), array(), 'refused: ' . count( $conflicts ) . ' conflict(s)' );
			return array(
				'ok'        => false,
				'conflicts' => $conflicts,
			);
		}
		$after = array();
		$rows  = array();
		foreach ( $items as $item ) {
			$product = $products[ (int) $item['id'] ];
			$set     = self::norm( $item['set'] );
			$product->set_regular_price( $set['regular_price'] );
			$product->set_sale_price( $set['sale_price'] );
			$product->save();
			$fresh                       = wc_get_product( $product->get_id() );
			$after[ $product->get_id() ] = $fresh ? self::state( $fresh ) : null;
			$rows[]                      = array(
				'id'   => $product->get_id(),
				'name' => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'from' => self::norm( $item['expect'] ),
				'to'   => $after[ $product->get_id() ],
			);
		}
		ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), $rows, 'ok' );
		return array(
			'ok'    => true,
			'after' => $after,
		);
	}

	/**
	 * Current prices of a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{regular_price:string,sale_price:string}
	 */
	private static function state( $product ): array {
		return self::norm(
			array(
				'regular_price' => (string) $product->get_regular_price( 'edit' ),
				'sale_price'    => (string) $product->get_sale_price( 'edit' ),
			)
		);
	}

	/**
	 * Same money string for the same amount ("6.9" === "6.90", "" stays "").
	 *
	 * @param array<string,mixed> $p Prices.
	 * @return array{regular_price:string,sale_price:string}
	 */
	private static function norm( array $p ): array {
		$f = static function ( $v ) {
			$v = trim( (string) $v );
			return '' === $v ? '' : number_format( (float) $v, 2, '.', '' );
		};
		return array(
			'regular_price' => $f( $p['regular_price'] ?? '' ),
			'sale_price'    => $f( $p['sale_price'] ?? '' ),
		);
	}
}
