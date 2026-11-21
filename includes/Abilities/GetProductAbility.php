<?php
/**
 * Ability addsynapse-assistant/get-product — one product card with variations (read-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Full product card by id or SKU. Descriptions are reported as lengths only (no HTML)
 * to keep the payload small; all text is store data.
 */
final class GetProductAbility {

	public const NAME           = 'addsynapse-assistant/get-product';
	public const MAX_VARIATIONS = 100;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Product details', 'addsynapse-store-assistant' ),
				'description'         => 'Returns one WooCommerce product (read-only) by id or SKU: type, status, SKU, prices, stock, categories, tags, image presence, description lengths (not the text), attributes and up to 100 variations (attributes, SKU, prices, stock each). If the id/SKU belongs to a variation, the parent product is returned with matched_variation_id.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'sku' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
					),
				),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		$string_list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		$variation   = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'sku', 'attributes', 'price', 'stock_status' ),
			'properties'           => array(
				'id'             => array( 'type' => 'integer' ),
				'sku'            => array( 'type' => 'string' ),
				'attributes'     => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'name'  => array( 'type' => 'string' ),
							'value' => array( 'type' => 'string' ),
						),
					),
				),
				'price'          => array( 'type' => 'string' ),
				'regular_price'  => array( 'type' => 'string' ),
				'sale_price'     => array( 'type' => 'string' ),
				'stock_status'   => array( 'type' => 'string' ),
				'stock_quantity' => array( 'type' => array( 'integer', 'null' ) ),
				'enabled'        => array( 'type' => 'boolean' ),
				'has_image'      => array( 'type' => 'boolean' ),
				'image_id'       => array( 'type' => 'integer' ),
			),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'name', 'type', 'status', 'sku', 'price', 'stock_status', 'variations', 'edit_link' ),
			'properties'           => array(
				'id'                       => array( 'type' => 'integer' ),
				'matched_variation_id'     => array( 'type' => 'integer' ),
				'name'                     => array( 'type' => 'string' ),
				'type'                     => array( 'type' => 'string' ),
				'status'                   => array( 'type' => 'string' ),
				'sku'                      => array( 'type' => 'string' ),
				'price'                    => array( 'type' => 'string' ),
				'regular_price'            => array( 'type' => 'string' ),
				'sale_price'               => array( 'type' => 'string' ),
				'on_sale'                  => array( 'type' => 'boolean' ),
				'stock_status'             => array( 'type' => 'string' ),
				'manage_stock'             => array( 'type' => 'boolean' ),
				'stock_quantity'           => array( 'type' => array( 'integer', 'null' ) ),
				'backorders'               => array( 'type' => 'string' ),
				'categories'               => $string_list,
				'tags'                     => $string_list,
				'has_image'                => array( 'type' => 'boolean' ),
				'gallery_count'            => array( 'type' => 'integer' ),
				'image_id'                 => array( 'type' => 'integer' ),
				'gallery_image_ids'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'images'                   => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'id'    => array( 'type' => 'integer' ),
							'file'  => array( 'type' => 'string' ),
							'title' => array( 'type' => 'string' ),
							'thumb' => array( 'type' => 'string' ),
						),
					),
				),
				'description_length'       => array( 'type' => 'integer' ),
				'short_description_length' => array( 'type' => 'integer' ),
				'total_sales'              => array( 'type' => 'integer' ),
				'date_created'             => array( 'type' => 'string' ),
				'date_modified'            => array( 'type' => 'string' ),
				'attributes'               => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'name'      => array( 'type' => 'string' ),
							'options'   => $string_list,
							'variation' => array( 'type' => 'boolean' ),
							'visible'   => array( 'type' => 'boolean' ),
						),
					),
				),
				'variations_total'         => array( 'type' => 'integer' ),
				'variations_truncated'     => array( 'type' => 'boolean' ),
				'variations'               => array(
					'type'  => 'array',
					'items' => $variation,
				),
				'currency'                 => array( 'type' => 'string' ),
				'permalink'                => array( 'type' => 'string' ),
				'edit_link'                => array( 'type' => 'string' ),
				'_data'                    => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (int) ( $input['id'] ?? 0 );
		$sku   = trim( (string) ( $input['sku'] ?? '' ) );
		if ( ! $id && '' === $sku ) {
			return new WP_Error( 'addsynapse_assistant_missing_product', 'Give a product id or a SKU.' );
		}
		if ( ! $id ) {
			$id = (int) wc_get_product_id_by_sku( $sku );
		}
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product || 'trash' === $product->get_status() ) {
			return new WP_Error( 'addsynapse_assistant_product_not_found', 'Product not found.' );
		}

		$matched = 0;
		if ( $product->is_type( 'variation' ) ) {
			$matched = (int) $product->get_id();
			$product = wc_get_product( $product->get_parent_id() );
			if ( ! $product ) {
				return new WP_Error( 'addsynapse_assistant_product_not_found', 'Product not found.' );
			}
		}

		$quantity = $product->get_manage_stock() ? $product->get_stock_quantity() : null;
		$created  = $product->get_date_created();
		$modified = $product->get_date_modified();
		$card     = array(
			'id'                       => (int) $product->get_id(),
			'name'                     => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
			'type'                     => (string) $product->get_type(),
			'status'                   => (string) $product->get_status(),
			'sku'                      => (string) $product->get_sku(),
			'price'                    => Shared::price( $product->get_price() ),
			'regular_price'            => Shared::price( $product->get_regular_price() ),
			'sale_price'               => Shared::price( $product->get_sale_price() ),
			'on_sale'                  => (bool) $product->is_on_sale(),
			'stock_status'             => (string) $product->get_stock_status(),
			'manage_stock'             => (bool) $product->get_manage_stock(),
			'stock_quantity'           => null === $quantity ? null : (int) $quantity,
			'backorders'               => (string) $product->get_backorders(),
			'categories'               => Shared::term_names( $product, 'product_cat', 20 ),
			'tags'                     => Shared::term_names( $product, 'product_tag', 20 ),
			'has_image'                => (bool) $product->get_image_id(),
			'gallery_count'            => count( $product->get_gallery_image_ids() ),
			// 1.3.16: the ids of the main image and the gallery (in order), and small facts about each (no HTML).
			'image_id'                 => (int) $product->get_image_id( 'edit' ),
			'gallery_image_ids'        => array_values( array_map( 'intval', (array) $product->get_gallery_image_ids( 'edit' ) ) ),
			'images'                   => \Addsynapse\StoreAssistant\Remote\ProductImages::describe( array_merge( array( (int) $product->get_image_id( 'edit' ) ), (array) $product->get_gallery_image_ids( 'edit' ) ) ),
			'description_length'       => self::text_length( $product->get_description() ),
			'short_description_length' => self::text_length( $product->get_short_description() ),
			'total_sales'              => (int) $product->get_total_sales(),
			'date_created'             => $created ? $created->date( 'c' ) : '',
			'date_modified'            => $modified ? $modified->date( 'c' ) : '',
			'attributes'               => self::attributes( $product ),
			'variations_total'         => 0,
			'variations_truncated'     => false,
			'variations'               => array(),
			'currency'                 => Shared::currency(),
			'permalink'                => (string) get_permalink( $product->get_id() ),
			'edit_link'                => Shared::product_edit_link( (int) $product->get_id() ),
			'_data'                    => Shared::DATA_NOTICE,
		);
		if ( $matched ) {
			$card['matched_variation_id'] = $matched;
		}

		if ( $product->is_type( 'variable' ) ) {
			$children                     = array_map( 'intval', $product->get_children() );
			$card['variations_total']     = count( $children );
			$card['variations_truncated'] = count( $children ) > self::MAX_VARIATIONS;
			foreach ( array_slice( $children, 0, self::MAX_VARIATIONS ) as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation instanceof \WC_Product_Variation ) {
					$card['variations'][] = self::variation_row( $variation, $product );
				}
			}
		}
		return $card;
	}

	/**
	 * Plain-text length of a description (HTML stripped).
	 *
	 * @param string $html Description HTML.
	 */
	private static function text_length( string $html ): int {
		return mb_strlen( trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) ) );
	}

	/**
	 * Product attributes with readable option names.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attributes( \WC_Product $product ): array {
		$out = array();
		foreach ( $product->get_attributes() as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$options = array();
				foreach ( (array) $attribute->get_terms() as $term ) {
					$options[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
				}
			} else {
				$options = array_map( 'strval', $attribute->get_options() );
			}
			$out[] = array(
				'name'      => html_entity_decode( wc_attribute_label( $attribute->get_name(), $product ), ENT_QUOTES, 'UTF-8' ),
				'options'   => array_slice( $options, 0, 50 ),
				'variation' => (bool) $attribute->get_variation(),
				'visible'   => (bool) $attribute->get_visible(),
			);
			unset( $key );
		}
		return $out;
	}

	/**
	 * One variation row.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param \WC_Product           $parent_product Parent.
	 * @return array<string,mixed>
	 */
	private static function variation_row( \WC_Product_Variation $variation, \WC_Product $parent_product ): array {
		$attributes = array();
		foreach ( $variation->get_attributes() as $taxonomy => $value ) {
			$label = wc_attribute_label( $taxonomy, $parent_product );
			if ( '' === (string) $value ) {
				$shown = 'any';
			} elseif ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', (string) $value, $taxonomy );
				$shown = $term ? $term->name : (string) $value;
			} else {
				$shown = (string) $value;
			}
			$attributes[] = array(
				'name'  => html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ),
				'value' => html_entity_decode( (string) $shown, ENT_QUOTES, 'UTF-8' ),
			);
		}
		$quantity = $variation->get_manage_stock() ? $variation->get_stock_quantity() : null;
		return array(
			'id'             => (int) $variation->get_id(),
			'sku'            => (string) $variation->get_sku( 'edit' ),
			'attributes'     => $attributes,
			'price'          => Shared::price( $variation->get_price() ),
			'regular_price'  => Shared::price( $variation->get_regular_price() ),
			'sale_price'     => Shared::price( $variation->get_sale_price() ),
			'stock_status'   => (string) $variation->get_stock_status(),
			'stock_quantity' => null === $quantity ? null : (int) $quantity,
			'enabled'        => 'publish' === $variation->get_status(),
			'has_image'      => (bool) $variation->get_image_id( 'edit' ),
			'image_id'       => (int) $variation->get_image_id( 'edit' ),
		);
	}
}
