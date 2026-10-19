<?php
/**
 * Core product fields of a changeset: the product name and its images, written ONLY through the WooCommerce setters.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

defined( 'ABSPATH' ) || exit;

/**
 * `plan-product-update` changes the name and the images (main image of a product or variation, gallery of a product)
 * here, and the short description / description (SeoFields' `content` fields), of ONE product. Prices / stock quantity
 * stay in ProductFields (their own tools). NEVER here: slug, product type, status, SKU, trash/delete.
 *  - name: string; image_id: int (0 = none); gallery_image_ids: list of ints (order matters), null = the product has
 *    no gallery row at all (undo removes the row again instead of leaving an empty one).
 */
final class CoreFields {

	/**
	 * Field → where it exists: product (parent/simple only).
	 */
	public const FIELDS = array(
		'name'              => 'product',
		'image_id'          => 'both',
		'gallery_image_ids' => 'product',
	);

	public const MAX_NAME    = 200;
	public const MAX_GALLERY = 30;

	/**
	 * Meta row of the gallery (absent ≠ empty).
	 */
	public const GALLERY_META = '_product_image_gallery';

	/**
	 * Whether a field belongs to this family.
	 *
	 * @param string $field Field.
	 */
	public static function is_field( string $field ): bool {
		return isset( self::FIELDS[ $field ] );
	}

	/**
	 * Whether the field exists on this kind of object.
	 *
	 * @param string $field       Field.
	 * @param string $object_type product|variation.
	 */
	public static function on( string $field, string $object_type ): bool {
		$scope = self::FIELDS[ $field ] ?? '';
		return 'both' === $scope || $scope === $object_type;
	}

	/**
	 * Write order inside one product (before prices).
	 *
	 * @param string $field Field.
	 */
	public static function order( string $field ): int {
		return in_array( $field, array( 'image_id', 'gallery_image_ids' ), true ) ? 40 : 20;
	}

	/**
	 * Current (edit-context) value.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $field   Field.
	 * @return mixed
	 * @throws \InvalidArgumentException For a field outside the allow-list.
	 */
	public static function read( \WC_Product $product, string $field ) {
		switch ( $field ) {
			case 'name':
				return (string) $product->get_name( 'edit' );
			case 'image_id':
				return (int) $product->get_image_id( 'edit' );
			case 'gallery_image_ids':
				$ids = array_values( array_map( 'intval', (array) $product->get_gallery_image_ids( 'edit' ) ) );
				if ( ! $ids && $product->get_id() > 0 && ! metadata_exists( 'post', (int) $product->get_id(), self::GALLERY_META ) ) {
					return null;
				}
				return $ids;
		}
		throw new \InvalidArgumentException( 'Field not allowed.' );
	}

	/**
	 * Sets a field through the WooCommerce setter (the caller saves once).
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $field   Field.
	 * @param mixed       $value   Normalized value.
	 * @throws \InvalidArgumentException For a field outside the allow-list / on the wrong object.
	 */
	public static function write( \WC_Product $product, string $field, $value ): void {
		if ( ! self::on( $field, ProductSelector::object_type( $product ) ) ) {
			throw new \InvalidArgumentException( 'Field not allowed on this product.' );
		}
		switch ( $field ) {
			case 'name':
				$product->set_name( (string) $value );
				return;
			case 'image_id':
				$product->set_image_id( (int) $value > 0 ? (int) $value : '' );
				return;
			case 'gallery_image_ids':
				$product->set_gallery_image_ids( array_values( array_map( 'intval', (array) $value ) ) );
				return;
		}
	}

	/**
	 * After save(): a gallery written as null (absent) must not leave an EMPTY meta row behind.
	 *
	 * @param \WC_Product $product Saved product.
	 * @param string      $field   Field.
	 * @param mixed       $value   Written value.
	 */
	public static function purge_absent( \WC_Product $product, string $field, $value ): void {
		if ( null !== $value || 'gallery_image_ids' !== $field ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( $id > 0 && metadata_exists( 'post', $id, self::GALLERY_META ) && '' === (string) get_post_meta( $id, self::GALLERY_META, true ) ) {
			delete_post_meta( $id, self::GALLERY_META, '' );
		}
	}

	/**
	 * Same value?
	 *
	 * @param string $field Field.
	 * @param mixed  $a     Value.
	 * @param mixed  $b     Value.
	 */
	public static function same( string $field, $a, $b ): bool {
		if ( 'gallery_image_ids' === $field ) {
			if ( null === $a || null === $b ) {
				return $a === $b;
			}
			return is_array( $a ) && is_array( $b ) && array_map( 'intval', array_values( $a ) ) === array_map( 'intval', array_values( $b ) );
		}
		if ( 'image_id' === $field ) {
			return (int) $a === (int) $b;
		}
		return (string) $a === (string) $b;
	}

	/**
	 * Whether a stored before/after value has the right shape for the field.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public static function valid_value( string $field, $value ): bool {
		switch ( $field ) {
			case 'name':
				return is_string( $value ) && mb_strlen( $value ) <= 1000;
			case 'image_id':
				return is_int( $value ) && $value >= 0;
			case 'gallery_image_ids':
				if ( null === $value ) {
					return true;
				}
				if ( ! is_array( $value ) || count( $value ) > self::MAX_GALLERY ) {
					return false;
				}
				foreach ( $value as $id ) {
					if ( ! is_int( $id ) || $id < 1 ) {
						return false;
					}
				}
				return true;
		}
		return false;
	}

	/**
	 * Why an item may not be applied now ('' = it may).
	 *
	 * @param string $field     Field.
	 * @param mixed  $after     Value.
	 * @param int    $object_id Product.
	 */
	public static function apply_denial( string $field, $after, int $object_id ): string {
		unset( $object_id );
		if ( 'image_id' === $field && (int) $after > 0 ) {
			return self::image_problem( (int) $after );
		}
		if ( 'gallery_image_ids' === $field ) {
			foreach ( (array) $after as $id ) {
				$problem = self::image_problem( (int) $id );
				if ( '' !== $problem ) {
					return $problem;
				}
			}
		}
		return self::is_field( $field ) ? '' : 'Field not allowed.';
	}

	/**
	 * Why an attachment cannot be a product image ('' = it can).
	 *
	 * @param int $id Attachment id.
	 */
	public static function image_problem( int $id ): string {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return sprintf( 'Image %d is not in the media library.', $id );
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			return sprintf( 'Media item %d is not an image.', $id );
		}
		// Another manager's image uploaded in the chat and not used yet: not theirs to use.
		return \Addsynapse\StoreAssistant\Media\ImageUploads::usable_problem( $id );
	}

	/**
	 * Thumbnails for the card: [{id, url, title}] (never raw HTML).
	 *
	 * @param mixed $value image id or list of ids.
	 * @return array<int,array<string,mixed>>
	 */
	public static function images( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			$url   = wp_get_attachment_image_url( $id, 'thumbnail' );
			$out[] = array(
				'id'    => $id,
				'url'   => $url ? (string) $url : '',
				'title' => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return $out;
	}

	/**
	 * Short (English) text for reasons / the model.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public static function show( string $field, $value ): string {
		if ( 'gallery_image_ids' === $field ) {
			return $value ? implode( ', ', array_map( 'intval', (array) $value ) ) : 'empty';
		}
		if ( 'image_id' === $field ) {
			return 0 === (int) $value ? 'none' : '#' . (int) $value;
		}
		$text = wp_strip_all_tags( (string) $value );
		if ( '' === $text ) {
			return 'empty';
		}
		return mb_strlen( $text ) > 40 ? mb_substr( $text, 0, 40 ) . '…' : $text;
	}

	/**
	 * Value for the model (plan preview).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	public static function display( string $field, $value ) {
		unset( $field );
		return $value;
	}

	/**
	 * Text of a value for the card (the UI shows it as-is).
	 *
	 * @param string $field       Field.
	 * @param mixed  $value       Value.
	 * @param string $object_type product|variation.
	 */
	public static function text( string $field, $value, string $object_type = 'product' ): string {
		unset( $object_type );
		if ( 'image_id' === $field ) {
			return 0 === (int) $value ? __( 'No image', 'addsynapse-store-assistant' ) : '';
		}
		if ( 'gallery_image_ids' === $field ) {
			return ! $value ? __( 'No images', 'addsynapse-store-assistant' ) : '';
		}
		return (string) $value;
	}
}
