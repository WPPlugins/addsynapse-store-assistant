<?php
/**
 * Ability addsynapse-assistant/get-product-raw — the full structured product (discovery, read-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Discovery\MetaPolicy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a later write needs to know about ONE product: core fields, taxonomies with
 * term ids, attributes (global vs local), custom meta (noise dropped, secrets redacted,
 * big values summarized), ACF values and variations. All text is store DATA.
 */
final class GetProductRawAbility {

	public const NAME           = 'addsynapse-assistant/get-product-raw';
	public const MAX_VARIATIONS = 50;
	public const MAX_META       = 80;
	public const MAX_TERMS      = 50;
	public const MAX_IDS        = 50;
	public const MAX_TEXT       = 3000;
	public const MAX_ACF        = 60;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Product raw data', 'addsynapse-store-assistant' ),
				'description'         => 'Returns the full structured data of one product (read-only) by id or SKU: core fields, taxonomies with term ids, attributes (global pa_* vs local, terms, visible/variation flags), custom meta (secrets redacted, large values summarized), ACF values and up to 50 variations.',
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
				'output_schema'       => array(
					'type'     => 'object',
					'required' => array( 'id', 'type', 'core', 'taxonomies', 'attributes', 'meta', 'variations' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
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

		$meta = self::meta( $product );
		$out  = array(
			'id'         => (int) $product->get_id(),
			'type'       => (string) $product->get_type(),
			'name'       => self::text( $product->get_name() ),
			'core'       => self::core( $product ),
			'taxonomies' => self::taxonomies( (int) $product->get_id() ),
			'attributes' => self::attributes( $product ),
			'meta'       => $meta['items'],
			'meta_info'  => $meta['info'],
			'acf'        => self::acf( (int) $product->get_id() ),
			'variations' => array(),
			'edit_link'  => Shared::product_edit_link( (int) $product->get_id() ),
			'_data'      => Shared::DATA_NOTICE,
		);
		if ( $matched ) {
			$out['matched_variation_id'] = $matched;
		}
		if ( $product->is_type( 'variable' ) ) {
			$children                    = array_map( 'intval', $product->get_children() );
			$out['variations_total']     = count( $children );
			$out['variations_truncated'] = count( $children ) > self::MAX_VARIATIONS;
			$ids                         = array_slice( $children, 0, self::MAX_VARIATIONS );
			if ( $matched && ! in_array( $matched, $ids, true ) ) {
				$ids[] = $matched;
			}
			foreach ( $ids as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation instanceof \WC_Product_Variation ) {
					$out['variations'][] = self::variation( $variation );
				}
			}
		}
		return $out;
	}

	/**
	 * Decoded plain text.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Description as plain text, capped.
	 *
	 * @param string $html HTML.
	 * @return array{text:string,length:int,truncated:bool}
	 */
	private static function long_text( string $html ): array {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', self::text( $html ) ) );
		$len  = mb_strlen( $text );
		return array(
			'text'      => $len > self::MAX_TEXT ? mb_substr( $text, 0, self::MAX_TEXT ) . '…' : $text,
			'length'    => $len,
			'truncated' => $len > self::MAX_TEXT,
		);
	}

	/**
	 * Core WooCommerce fields (what set_core_field will edit through WC setters).
	 *
	 * @param \WC_Product $p Product.
	 * @return array<string,mixed>
	 */
	private static function core( \WC_Product $p ): array {
		$date     = static function ( $d ) {
			return $d ? $d->date( 'c' ) : null;
		};
		$shipping = $p->get_shipping_class_id() ? get_term( $p->get_shipping_class_id(), 'product_shipping_class' ) : null;
		$core     = array(
			'name'               => self::text( $p->get_name() ),
			'slug'               => rawurldecode( (string) $p->get_slug() ),
			'status'             => (string) $p->get_status(),
			'featured'           => (bool) $p->get_featured(),
			'catalog_visibility' => (string) $p->get_catalog_visibility(),
			'sku'                => (string) $p->get_sku( 'edit' ),
			'global_unique_id'   => method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id( 'edit' ) : '',
			'price'              => Shared::price( $p->get_price( 'edit' ) ),
			'regular_price'      => Shared::price( $p->get_regular_price( 'edit' ) ),
			'sale_price'         => Shared::price( $p->get_sale_price( 'edit' ) ),
			'date_on_sale_from'  => $date( $p->get_date_on_sale_from( 'edit' ) ),
			'date_on_sale_to'    => $date( $p->get_date_on_sale_to( 'edit' ) ),
			'tax_status'         => (string) $p->get_tax_status(),
			'tax_class'          => (string) $p->get_tax_class( 'edit' ),
			'manage_stock'       => (bool) $p->get_manage_stock( 'edit' ),
			'stock_quantity'     => null === $p->get_stock_quantity( 'edit' ) ? null : (int) $p->get_stock_quantity( 'edit' ),
			'stock_status'       => (string) $p->get_stock_status( 'edit' ),
			'backorders'         => (string) $p->get_backorders( 'edit' ),
			'low_stock_amount'   => '' === $p->get_low_stock_amount( 'edit' ) ? null : $p->get_low_stock_amount( 'edit' ),
			'sold_individually'  => (bool) $p->get_sold_individually(),
			'weight'             => (string) $p->get_weight( 'edit' ),
			'length'             => (string) $p->get_length( 'edit' ),
			'width'              => (string) $p->get_width( 'edit' ),
			'height'             => (string) $p->get_height( 'edit' ),
			'shipping_class'     => $shipping && ! is_wp_error( $shipping ) ? (string) $shipping->slug : '',
			'virtual'            => (bool) $p->get_virtual(),
			'downloadable'       => (bool) $p->get_downloadable(),
			'downloads_count'    => count( $p->get_downloads( 'edit' ) ),
			'reviews_allowed'    => (bool) $p->get_reviews_allowed(),
			'menu_order'         => (int) $p->get_menu_order(),
			'image_id'           => (int) $p->get_image_id( 'edit' ),
			'gallery_image_ids'  => array_slice( array_map( 'intval', $p->get_gallery_image_ids( 'edit' ) ), 0, self::MAX_IDS ),
			'upsell_ids'         => array_slice( array_map( 'intval', $p->get_upsell_ids( 'edit' ) ), 0, self::MAX_IDS ),
			'cross_sell_ids'     => array_slice( array_map( 'intval', $p->get_cross_sell_ids( 'edit' ) ), 0, self::MAX_IDS ),
			'total_sales'        => (int) $p->get_total_sales(),
			'date_created'       => $date( $p->get_date_created() ),
			'date_modified'      => $date( $p->get_date_modified() ),
			'description'        => self::long_text( (string) $p->get_description( 'edit' ) ),
			'short_description'  => self::long_text( (string) $p->get_short_description( 'edit' ) ),
			'purchase_note'      => self::long_text( (string) $p->get_purchase_note( 'edit' ) ),
		);
		if ( $p->is_type( 'grouped' ) ) {
			$core['children'] = array_slice( array_map( 'intval', $p->get_children() ), 0, self::MAX_IDS );
		}
		if ( $p->is_type( 'variable' ) ) {
			$core['default_attributes'] = (array) $p->get_default_attributes( 'edit' );
		}
		return $core;
	}

	/**
	 * Product taxonomies (pa_* attributes and WC internals excluded) with term ids.
	 *
	 * @param int $id Product id.
	 * @return array<string,array<int,array{id:int,name:string,slug:string}>>
	 */
	private static function taxonomies( int $id ): array {
		$out = array();
		foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
			if ( 0 === strpos( $taxonomy, 'pa_' ) || in_array( $taxonomy, array( 'product_type' ), true ) ) {
				continue;
			}
			$terms = get_the_terms( $id, $taxonomy );
			$rows  = array();
			if ( is_array( $terms ) ) {
				foreach ( array_slice( $terms, 0, self::MAX_TERMS ) as $term ) {
					$rows[] = array(
						'id'   => (int) $term->term_id,
						'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
						'slug' => rawurldecode( (string) $term->slug ),
					);
				}
			}
			$out[ $taxonomy ] = $rows;
		}
		return $out;
	}

	/**
	 * Attributes: global (pa_* with terms) vs local (free-text options).
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attributes( \WC_Product $product ): array {
		$out = array();
		foreach ( $product->get_attributes( 'edit' ) as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$row = array(
				'key'       => rawurldecode( (string) $key ),
				'label'     => html_entity_decode( wc_attribute_label( $attribute->get_name(), $product ), ENT_QUOTES, 'UTF-8' ),
				'global'    => (bool) $attribute->is_taxonomy(),
				'taxonomy'  => $attribute->is_taxonomy() ? (string) $attribute->get_name() : null,
				'visible'   => (bool) $attribute->get_visible(),
				'variation' => (bool) $attribute->get_variation(),
				'position'  => (int) $attribute->get_position(),
			);
			if ( $attribute->is_taxonomy() ) {
				$row['attribute_id'] = (int) $attribute->get_id();
				$row['terms']        = array();
				foreach ( array_slice( (array) $attribute->get_terms(), 0, self::MAX_TERMS ) as $term ) {
					$row['terms'][] = array(
						'id'   => (int) $term->term_id,
						'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
						'slug' => rawurldecode( (string) $term->slug ),
					);
				}
			} else {
				$row['options'] = array_slice( array_map( array( self::class, 'text' ), array_map( 'strval', $attribute->get_options() ) ), 0, self::MAX_TERMS );
			}
			$out[] = $row;
		}//end foreach
		return $out;
	}

	/**
	 * Custom meta through WC CRUD (WC-internal keys are already in `core`).
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param int         $max     Max keys.
	 * @return array{items:array<int,array<string,mixed>>,info:array<string,int|bool>}
	 */
	private static function meta( \WC_Product $product, int $max = self::MAX_META ): array {
		$items  = array();
		$hidden = 0;
		$total  = 0;
		$seen   = array();
		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) $data['key'];
			if ( MetaPolicy::is_noise( $key ) ) {
				++$hidden;
				continue;
			}
			// ACF value references ("_field" => "field_abc…") only add noise; ACF is listed separately.
			if ( '_' === substr( $key, 0, 1 ) && is_string( $data['value'] ) && preg_match( '/^field_[0-9a-f]{6,}$/', $data['value'] ) ) {
				++$hidden;
				continue;
			}
			++$total;
			if ( count( $items ) >= $max ) {
				continue;
			}
			$described = MetaPolicy::describe( $key, $data['value'] );
			$row       = array(
				'key'  => $key,
				'kind' => MetaPolicy::kind( $key ),
			) + $described;
			if ( isset( $seen[ $key ] ) ) {
				$row['duplicate'] = true;
			}
			$seen[ $key ] = true;
			$items[]      = $row;
		}//end foreach
		return array(
			'items' => $items,
			'info'  => array(
				'total'     => $total,
				'hidden'    => $hidden,
				'truncated' => $total > count( $items ),
			),
		);
	}

	/**
	 * ACF values (raw, unformatted) when ACF is active.
	 *
	 * @param int $id Post id.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function acf( int $id ): ?array {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return null;
		}
		$objects = get_field_objects( $id, false );
		$out     = array();
		foreach ( array_slice( is_array( $objects ) ? $objects : array(), 0, self::MAX_ACF ) as $field ) {
			$name  = (string) ( $field['name'] ?? '' );
			$out[] = array(
				'name'  => $name,
				'label' => (string) ( $field['label'] ?? '' ),
				'type'  => (string) ( $field['type'] ?? '' ),
			) + MetaPolicy::describe( $name, $field['value'] ?? null );
		}
		return $out;
	}

	/**
	 * One variation.
	 *
	 * @param \WC_Product_Variation $v Variation.
	 * @return array<string,mixed>
	 */
	private static function variation( \WC_Product_Variation $v ): array {
		$attributes = array();
		foreach ( $v->get_attributes( 'edit' ) as $key => $value ) {
			$attributes[ rawurldecode( (string) $key ) ] = '' === (string) $value ? 'any' : rawurldecode( (string) $value );
		}
		$meta = self::meta( $v, 15 );
		return array(
			'id'             => (int) $v->get_id(),
			'sku'            => (string) $v->get_sku( 'edit' ),
			'status'         => (string) $v->get_status(),
			'attributes'     => $attributes,
			'regular_price'  => Shared::price( $v->get_regular_price( 'edit' ) ),
			'sale_price'     => Shared::price( $v->get_sale_price( 'edit' ) ),
			'manage_stock'   => $v->get_manage_stock( 'edit' ),
			'stock_quantity' => null === $v->get_stock_quantity( 'edit' ) ? null : (int) $v->get_stock_quantity( 'edit' ),
			'stock_status'   => (string) $v->get_stock_status( 'edit' ),
			'weight'         => (string) $v->get_weight( 'edit' ),
			'image_id'       => (int) $v->get_image_id( 'edit' ),
			'description'    => mb_substr( self::text( (string) $v->get_description( 'edit' ) ), 0, 300 ),
			'meta'           => $meta['items'],
		);
	}
}
