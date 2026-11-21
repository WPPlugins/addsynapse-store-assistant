<?php
/**
 * 1.3.16 unit of the remote route (read-state / apply-changes): the images of one product or variation.
 *  - product (any type): image_id (main image, 0 = none) and gallery_image_ids (the gallery in order; null = the product
 *    has no gallery row at all, so Undo removes the row again instead of leaving an empty one) — the same values and
 *    rules as the wp-admin chat's plan-product-update (CoreFields).
 *  - variation: image_id only (WooCommerce variations have no gallery).
 * An image is an id of the media library, or "@N": the image that item N of the same change set adds (kind media,
 * id 0, downloaded on Approve). The site answers the ids it stored, so Undo expects exactly those.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Changesets\CoreFields;

defined( 'ABSPATH' ) || exit;

/**
 * Main image and gallery of products and variations.
 */
final class ProductImages {

	public const KIND = 'product_images';

	public const FIELDS = array( 'image_id', 'gallery_image_ids' );

	/** New images (kind media, id 0) one change set may add. */
	public const MAX_NEW = 10;

	/**
	 * Whether this class handles the kind.
	 *
	 * @param string $kind Kind.
	 */
	public static function handles( string $kind ): bool {
		return self::KIND === $kind;
	}

	/**
	 * The product or variation, when this user may edit it.
	 *
	 * @param int $id Id.
	 */
	private static function product( int $id ): ?\WC_Product {
		if ( $id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product || in_array( $product->get_status( 'edit' ), array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		$owner = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $id;
		return current_user_can( 'edit_post', $owner ) ? $product : null;
	}

	/**
	 * Current values of a product (or variation).
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	public static function values( \WC_Product $product ): array {
		$values = array( 'image_id' => CoreFields::read( $product, 'image_id' ) );
		if ( ! $product->is_type( 'variation' ) ) {
			$values['gallery_image_ids'] = CoreFields::read( $product, 'gallery_image_ids' );
		}
		return $values;
	}

	/**
	 * Read-state of one unit, with small facts about each image (id, file name, thumbnail address) for the draft.
	 *
	 * @param int $id Product or variation id.
	 * @return array<string,mixed>
	 */
	public static function read( int $id ): array {
		$product = self::product( $id );
		if ( null === $product ) {
			return array(
				'kind'   => self::KIND,
				'id'     => $id,
				'exists' => false,
				'reason' => 'Product or variation not found (or not editable).',
				'values' => array(),
			);
		}
		$values = self::values( $product );
		$name   = wp_strip_all_tags( (string) $product->get_name( 'edit' ) );
		return array(
			'kind'      => self::KIND,
			'id'        => $id,
			'exists'    => true,
			'name'      => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
			'type'      => (string) $product->get_type(),
			'parent_id' => (int) $product->get_parent_id(),
			'values'    => $values,
			'images'    => self::describe( array_merge( array( (int) $values['image_id'] ), (array) ( $values['gallery_image_ids'] ?? array() ) ) ),
		);
	}

	/**
	 * Facts about images of the media library: [{id, file, title, thumb}] (never HTML). Unknown ids are left out.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array<int,array<string,mixed>>
	 */
	public static function describe( array $ids ): array {
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $image ) {
			if ( ! wp_attachment_is_image( $image ) ) {
				continue;
			}
			$thumb = wp_get_attachment_image_url( $image, 'thumbnail' );
			$out[] = array(
				'id'    => $image,
				'file'  => wp_basename( (string) get_attached_file( $image ) ),
				'title' => html_entity_decode( wp_strip_all_tags( (string) get_the_title( $image ) ), ENT_QUOTES, 'UTF-8' ),
				'thumb' => $thumb ? esc_url_raw( (string) $thumb ) : '',
			);
		}
		return $out;
	}

	/**
	 * Whether the current values hold the expected ones (the gallery in order; absent ≠ empty).
	 *
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( array $now, array $expect ): bool {
		foreach ( $expect as $field => $value ) {
			if ( ! in_array( $field, self::FIELDS, true ) || ! array_key_exists( $field, $now ) || ! CoreFields::same( $field, $now[ $field ], $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is a reference to an image of the same change set ("@N").
	 *
	 * @param mixed $value Value.
	 */
	public static function is_ref( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^@[0-9]{1,3}$/', $value );
	}

	/**
	 * Validates new values ('' = fine). Ids or "@N" references; the gallery at most CoreFields::MAX_GALLERY, no repeats.
	 *
	 * @param array<string,mixed> $set Values.
	 */
	public static function invalid( array $set ): string {
		if ( ! $set ) {
			return 'Nothing to change.';
		}
		foreach ( $set as $field => $value ) {
			if ( ! in_array( $field, self::FIELDS, true ) ) {
				return 'Field not allowed: ' . $field;
			}
			if ( 'image_id' === $field && ! self::is_ref( $value ) && ( ! is_int( $value ) || $value < 0 ) ) {
				return 'image_id is a media library image id (0 = no image).';
			}
			if ( 'gallery_image_ids' === $field && null !== $value ) {
				if ( ! is_array( $value ) || count( $value ) > CoreFields::MAX_GALLERY ) {
					return 'The gallery is a list of at most ' . CoreFields::MAX_GALLERY . ' images.';
				}
				$seen = array();
				foreach ( $value as $image ) {
					if ( ! self::is_ref( $image ) && ( ! is_int( $image ) || $image < 1 ) ) {
						return 'Each gallery image is a media library image id.';
					}
					if ( isset( $seen[ (string) $image ] ) ) {
						return 'An image appears twice in the gallery.';
					}
					$seen[ (string) $image ] = true;
				}
			}
		}//end foreach
		return '';
	}

	/**
	 * Why the unit may not be written now ('' = it may).
	 *
	 * @param int                      $id     Product or variation id.
	 * @param array<string,mixed>|null $expect Expected values.
	 * @param array<string,mixed>|null $set    New values.
	 * @param int[]                    $refs   Indexes of the items of this change set that add an image (kind media, id 0).
	 */
	public static function problem( int $id, ?array $expect, ?array $set, array $refs ): string {
		if ( null === $expect || null === $set ) {
			return 'Expected and new values are required.';
		}
		$invalid = self::invalid( $set );
		if ( '' !== $invalid ) {
			return $invalid;
		}
		$product = self::product( $id );
		if ( null === $product ) {
			return 'Product or variation not found (or not editable).';
		}
		if ( $product->is_type( 'variation' ) && array_key_exists( 'gallery_image_ids', $set ) ) {
			return 'A variation has only a main image (no gallery).';
		}
		if ( ! self::holds( self::values( $product ), $expect ) ) {
			return 'Changed since the draft.';
		}
		$images = array_merge( array( $set['image_id'] ?? 0 ), (array) ( $set['gallery_image_ids'] ?? array() ) );
		foreach ( $images as $image ) {
			if ( self::is_ref( $image ) ) {
				if ( ! in_array( (int) substr( $image, 1 ), $refs, true ) ) {
					return 'An image of this change set is missing.';
				}
				continue;
			}
			$known = array_map( 'intval', array_merge( array( $expect['image_id'] ?? 0 ), (array) ( $expect['gallery_image_ids'] ?? array() ) ) );
			// Undo puts back exactly what was there; a new image must be an image of the media library.
			if ( (int) $image > 0 && ! in_array( (int) $image, $known, true ) ) {
				$problem = CoreFields::image_problem( (int) $image );
				if ( '' !== $problem ) {
					return $problem;
				}
			}
		}
		return '';
	}

	/**
	 * "@N" → the id of the image item N added; ids stay as they are.
	 *
	 * @param array<string,mixed> $set     Values.
	 * @param array<int,int>      $created Item index → attachment id.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When a reference has no image.
	 */
	public static function resolve( array $set, array $created ): array {
		$one = static function ( $value ) use ( $created ) {
			if ( ! self::is_ref( $value ) ) {
				return (int) $value;
			}
			$index = (int) substr( $value, 1 );
			if ( empty( $created[ $index ] ) ) {
				throw new \RuntimeException( 'An image of this change set was not added.' );
			}
			return (int) $created[ $index ];
		};
		if ( array_key_exists( 'image_id', $set ) ) {
			$set['image_id'] = $one( $set['image_id'] );
		}
		if ( array_key_exists( 'gallery_image_ids', $set ) && null !== $set['gallery_image_ids'] ) {
			$set['gallery_image_ids'] = array_values( array_map( $one, (array) $set['gallery_image_ids'] ) );
		}
		return $set;
	}

	/**
	 * Writes the (resolved) values through WooCommerce, then clears the caches of the product.
	 *
	 * @param int                 $id  Product or variation id.
	 * @param array<string,mixed> $set Values (ids, no references).
	 * @return int The id written.
	 * @throws \RuntimeException When the product is gone.
	 */
	public static function write( int $id, array $set ): int {
		$product = self::product( $id );
		if ( null === $product ) {
			throw new \RuntimeException( 'Product or variation not found.' );
		}
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $set ) ) {
				CoreFields::write( $product, $field, $set[ $field ] );
			}
		}
		$product->save();
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $set ) ) {
				CoreFields::purge_absent( $product, $field, $set[ $field ] );
			}
		}
		clean_post_cache( $id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product->get_parent_id() ? (int) $product->get_parent_id() : $id );
		}
		return $id;
	}

	/**
	 * The attachment ids a set of values uses.
	 *
	 * @param mixed $values Values.
	 * @return int[]
	 */
	public static function ids( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$list = array_merge( array( $values['image_id'] ?? 0 ), (array) ( $values['gallery_image_ids'] ?? array() ) );
		return array_values( array_filter( array_map( 'intval', array_filter( $list, 'is_numeric' ) ) ) );
	}
}
