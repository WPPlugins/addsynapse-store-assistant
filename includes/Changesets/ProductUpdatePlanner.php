<?php
/**
 * Product details plan: the name, short description, description and images of ONE product (or the description /
 * main image of one variation) — one draft changeset through the WooCommerce setters.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * `set` {name, short_description, description} and/or `images` {featured, gallery_add} (media-library ids, e.g. an image
 * the manager uploaded in the chat) for the product given by ids/skus. Any other product setting gets the
 * friendly «part of Pro» answer. NEVER: prices / stock quantity (their own tools), the slug, the product type,
 * trashing/deleting. Writes nothing: only the draft.
 */
final class ProductUpdatePlanner extends Planner {

	public const OPERATION = 'product_update';

	/**
	 * `set` keys (model-facing) → engine field.
	 */
	public const SET_KEYS = array(
		'name'              => 'name',
		'short_description' => 'short_description',
		'description'       => 'description',
	);

	/**
	 * Builds the plan and stores a draft changeset.
	 *
	 * @param array<string,mixed> $input           Validated ability input.
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$set = is_array( $input['set'] ?? null ) ? $input['set'] : array();
		foreach ( array_keys( $set ) as $key ) {
			if ( ! isset( self::SET_KEYS[ $key ] ) ) {
				return self::one_item_only( 'fields' );
			}
		}
		$images = is_array( $input['images'] ?? null ) ? $input['images'] : array();
		foreach ( array_keys( $images ) as $key ) {
			if ( ! in_array( $key, array( 'featured', 'gallery_add' ), true ) ) {
				return self::one_item_only( 'fields' );
			}
		}
		foreach ( array_merge( isset( $images['featured'] ) && (int) $images['featured'] > 0 ? array( (int) $images['featured'] ) : array(), array_map( 'intval', (array) ( $images['gallery_add'] ?? array() ) ) ) as $image_id ) {
			$problem = CoreFields::image_problem( $image_id );
			if ( '' !== $problem ) {
				return new WP_Error( 'addsynapse_assistant_invalid_image', $problem . ' Use the attachment_id of an image uploaded in the chat, or find_media.' );
			}
		}
		$changes = array();
		foreach ( $set as $key => $value ) {
			$clean = self::clean( self::SET_KEYS[ $key ], $value );
			if ( is_wp_error( $clean ) ) {
				return new WP_Error( $clean->get_error_code(), 'set.' . $key . ': ' . $clean->get_error_message() );
			}
			$changes[ self::SET_KEYS[ $key ] ] = $clean;
		}
		if ( ! $changes && ! $images ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Say what changes: set {name, short_description, description} and/or images {featured, gallery_add}.' );
		}
		$selected = ProductSelector::resolve( $input );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$skipped   = $selected['skipped'];
		$warnings  = $selected['warnings'];
		$rows      = array();
		$unchanged = 0;
		foreach ( $selected['products'] as $product ) {
			$type = ProductSelector::object_type( $product );
			if ( 'variation' === $type && ( array_diff( array_keys( $changes ), array( 'description' ) ) || isset( $images['gallery_add'] ) ) ) {
				$skipped[] = ProductSelector::skip( $product, 'A variation has only its own description and main image here; its name and gallery come from the parent product.' );
				continue;
			}
			$wanted = $changes;
			if ( array_key_exists( 'featured', $images ) ) {
				$wanted['image_id'] = max( 0, (int) $images['featured'] );
			}
			if ( ! empty( $images['gallery_add'] ) ) {
				$current = (array) CoreFields::read( $product, 'gallery_image_ids' );
				$gallery = array_values( array_unique( array_merge( array_map( 'intval', $current ), array_map( 'intval', (array) $images['gallery_add'] ) ) ) );
				if ( count( $gallery ) > CoreFields::MAX_GALLERY ) {
					return new WP_Error( 'addsynapse_assistant_invalid_image', sprintf( 'At most %d gallery images.', CoreFields::MAX_GALLERY ) );
				}
				$wanted['gallery_image_ids'] = $gallery;
			}
			$list = array();
			foreach ( $wanted as $field => $after ) {
				$before = Fields::read( $product, $field );
				if ( Fields::same( $field, $before, $after ) ) {
					continue;
				}
				$list[] = array(
					'field'  => $field,
					'before' => $before,
					'after'  => $after,
				);
			}
			if ( ! $list ) {
				++$unchanged;
				continue;
			}
			$rows[] = self::row( $product, $list );
		}//end foreach
		if ( $skipped ) {
			$warnings[] = 'The product was skipped (see skipped).';
		}
		$fields = array_keys( $changes );
		if ( array_key_exists( 'featured', $images ) ) {
			$fields[] = 'image_id';
		}
		if ( ! empty( $images['gallery_add'] ) ) {
			$fields[] = 'gallery_image_ids';
		}
		$params = array( 'fields' => $fields ) + self::selector_params( $input );
		return $this->finish(
			array(
				'operation'       => self::OPERATION,
				'params'          => $params,
				'summary'         => sprintf( 'Product details (%s).', implode( ', ', $fields ) ),
				'rows'            => $rows,
				'skipped'         => $skipped,
				'warnings'        => $warnings,
				'unchanged'       => $unchanged,
				'totals'          => array( 'products' => count( $rows ) ),
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
			)
		);
	}

	/**
	 * Validates / normalizes one value.
	 *
	 * @param string $field Engine field.
	 * @param mixed  $value Input.
	 * @return string|WP_Error
	 */
	private static function clean( string $field, $value ) {
		if ( 'name' === $field ) {
			$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
			if ( '' === $text || mb_strlen( $text ) > CoreFields::MAX_NAME ) {
				return new WP_Error( 'addsynapse_assistant_invalid_value', sprintf( 'the name must be plain text of 1-%d characters.', CoreFields::MAX_NAME ) );
			}
			return $text;
		}
		$raw = (string) $value;
		if ( preg_match( '/<\s*(script|iframe|style|form|object|embed)\b|\son[a-z]+\s*=|javascript:/i', $raw ) ) {
			return new WP_Error( 'addsynapse_assistant_invalid_value', 'scripts, iframes, styles, forms and event attributes are not allowed; use simple HTML.' );
		}
		if ( mb_strlen( $raw ) > SeoText::LONG_STORED_MAX ) {
			return new WP_Error( 'addsynapse_assistant_invalid_value', sprintf( 'at most %d characters.', SeoText::LONG_STORED_MAX ) );
		}
		return trim( wp_kses_post( $raw ) );
	}
}
