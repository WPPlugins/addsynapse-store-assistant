<?php
/**
 * Resolves the product of a plan (one id or one SKU). Read-only.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Abilities\Shared;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One product per change: the change tools take the id (or the SKU) of ONE product or variation. A call naming
 * several products gets the friendly «one item per change» answer (Planner::one_item_only()), never a partial plan.
 */
final class ProductSelector {

	/**
	 * WP_Error code of a call that names more than one product (ToolRegistry turns it into the friendly answer).
	 */
	public const ONE_ITEM_ERROR = 'addsynapse_assistant_one_item_only';

	/**
	 * Input schema fragments shared by the plan abilities.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema_properties(): array {
		return array(
			'ids'  => array(
				'type'        => 'array',
				'minItems'    => 1,
				'maxItems'    => 1,
				'items'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'description' => 'The id of the product or variation to change: exactly one (this tool edits one product at a time).',
			),
			'skus' => array(
				'type'        => 'array',
				'minItems'    => 1,
				'maxItems'    => 1,
				'items'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'description' => 'Or its SKU: exactly one.',
			),
		);
	}

	/**
	 * Error for a call that names more than one product.
	 */
	public static function one_item_error(): WP_Error {
		return new WP_Error( self::ONE_ITEM_ERROR, 'One change covers one product.' );
	}

	/**
	 * Resolves the selector into the ONE product (parent, simple product or variation).
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array{products:array<int,\WC_Product>,skipped:array<int,array<string,mixed>>,warnings:array<int,string>}|WP_Error
	 */
	public static function resolve( array $input ) {
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $input['ids'] ?? array() ) ) ) ) );
		$skus = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $input['skus'] ?? array() ) ), 'strlen' ) ) );
		if ( ! $ids && ! $skus ) {
			return new WP_Error( 'addsynapse_assistant_no_selection', 'Say which product: give its id (ids: [id]) or its SKU (skus: [sku]). Find it first with search_products.' );
		}
		if ( count( $ids ) + count( $skus ) > 1 ) {
			return self::one_item_error();
		}
		$skipped = array();
		if ( $skus ) {
			$id = (int) wc_get_product_id_by_sku( $skus[0] );
			if ( ! $id ) {
				$skipped[] = array(
					'id'     => 0,
					'name'   => $skus[0],
					'reason' => 'No product or variation has this SKU.',
				);
			}
			$ids = $id ? array( $id ) : array();
		}
		$found = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'trash' === $product->get_status() ) {
				$skipped[] = array(
					'id'     => $id,
					'name'   => '',
					'reason' => 'Product not found.',
				);
				continue;
			}
			$found[] = $product;
		}
		return array(
			'products' => $found,
			'skipped'  => $skipped,
			'warnings' => array(),
		);
	}

	/**
	 * Display name of a product; variations get "Parent — attribute values".
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function label( \WC_Product $product ): string {
		$name = html_entity_decode( wp_strip_all_tags( $product->get_name( 'edit' ) ), ENT_QUOTES, 'UTF-8' );
		if ( $product instanceof \WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$base   = $parent ? html_entity_decode( wp_strip_all_tags( $parent->get_name( 'edit' ) ), ENT_QUOTES, 'UTF-8' ) : $name;
			$attrs  = html_entity_decode( wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, false ) ), ENT_QUOTES, 'UTF-8' );
			$name   = '' !== $attrs ? $base . ' — ' . $attrs : $name;
		}
		return $name;
	}

	/**
	 * Object type for a product.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function object_type( \WC_Product $product ): string {
		return $product instanceof \WC_Product_Variation ? 'variation' : 'product';
	}

	/**
	 * Skipped-row helper.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $reason  Reason (English, for the model; the UI shows it as data).
	 * @return array<string,mixed>
	 */
	public static function skip( \WC_Product $product, string $reason ): array {
		return array(
			'id'     => (int) $product->get_id(),
			'name'   => self::label( $product ),
			'sku'    => (string) $product->get_sku( 'edit' ),
			'reason' => $reason,
		);
	}

	/**
	 * Data notice passthrough (kept here so planners need not import Shared).
	 */
	public static function data_notice(): string {
		return Shared::DATA_NOTICE;
	}
}
