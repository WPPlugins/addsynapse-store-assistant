<?php
/**
 * 1.3.11 unit of the remote route (read-state / apply-changes): product_new — ONE new simple WooCommerce product.
 * id 0 → created on Approve (published, or a draft when the owner asked), marked as ours. Undo moves exactly that
 * product to the trash (never deletes it for good), and only a product this plugin created. Variable products are not
 * created here. Images: an existing media library item only (no uploads).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * New simple products.
 */
final class ProductCreate {

	public const KIND = 'product_new';

	public const FIELDS = array( 'type', 'name', 'regular_price', 'sale_price', 'description', 'short_description', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'categories', 'status', 'image_id' );

	public const STATUSES       = array( 'draft', 'publish' );
	public const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );
	public const MAX_NAME       = 400;
	public const MAX_SKU        = 100;
	public const MAX_CATEGORIES = 20;

	/**
	 * Whether the current user may create products.
	 */
	public static function can_create(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' );
	}

	/**
	 * A product this plugin created, not in the trash.
	 *
	 * @param int $id Id.
	 */
	public static function created( int $id ): ?\WC_Product {
		if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( ! $product || 'product' !== get_post_type( $id ) || in_array( $product->get_status( 'edit' ), array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		if ( '' === (string) get_post_meta( $id, ProductTerms::CREATED_META, true ) || ! self::can_create() ) {
			return null;
		}
		return $product;
	}

	/**
	 * Values of a created product, in the shape of the draft.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	public static function values( \WC_Product $product ): array {
		$cats = array_map( 'intval', (array) $product->get_category_ids( 'edit' ) );
		sort( $cats );
		$qty = $product->get_stock_quantity( 'edit' );
		return array(
			'type'              => (string) $product->get_type(),
			'name'              => (string) $product->get_name( 'edit' ),
			'regular_price'     => (string) $product->get_regular_price( 'edit' ),
			'sale_price'        => (string) $product->get_sale_price( 'edit' ),
			'description'       => (string) $product->get_description( 'edit' ),
			'short_description' => (string) $product->get_short_description( 'edit' ),
			'sku'               => (string) $product->get_sku( 'edit' ),
			'manage_stock'      => (bool) $product->get_manage_stock( 'edit' ),
			'stock_quantity'    => null === $qty ? null : (int) $qty,
			'stock_status'      => (string) $product->get_stock_status( 'edit' ),
			'categories'        => $cats,
			'status'            => (string) $product->get_status( 'edit' ),
			'image_id'          => (int) $product->get_image_id( 'edit' ),
		);
	}

	/**
	 * Current state of one created product.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>
	 */
	public static function read( int $id ): array {
		$product = self::created( $id );
		if ( null === $product ) {
			return array(
				'kind'   => self::KIND,
				'id'     => $id,
				'exists' => false,
				'reason' => 'No product AddSynapse created with this id (or it is in the trash).',
				'values' => array(),
			);
		}
		return array(
			'kind'   => self::KIND,
			'id'     => $id,
			'exists' => true,
			'name'   => wp_strip_all_tags( (string) $product->get_name( 'edit' ) ),
			'type'   => (string) $product->get_type(),
			'link'   => (string) get_permalink( $id ),
			'values' => self::values( $product ),
		);
	}

	/**
	 * A price as text ('' = none), or null when it is not a valid price.
	 *
	 * @param mixed $value Value.
	 */
	private static function price( $value ): ?string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 100000000 ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * Validates the values of a new product ('' = fine).
	 *
	 * @param array<string,mixed> $set Values.
	 */
	public static function invalid( array $set ): string {
		foreach ( $set as $field => $value ) {
			if ( ! in_array( $field, self::FIELDS, true ) ) {
				return 'Field not allowed: ' . $field;
			}
		}
		if ( 'simple' !== ( $set['type'] ?? 'simple' ) ) {
			return 'Only simple products are created here (variable products are not supported yet).';
		}
		$name = $set['name'] ?? null;
		if ( ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > self::MAX_NAME ) {
			return 'A new product needs a name (1 to ' . self::MAX_NAME . ' characters).';
		}
		$regular = self::price( $set['regular_price'] ?? '' );
		$sale    = self::price( $set['sale_price'] ?? '' );
		if ( null === $regular || '' === $regular ) {
			return 'A new product needs a regular price (a number, 0 or more).';
		}
		if ( null === $sale ) {
			return 'The sale price is a number, 0 or more.';
		}
		if ( '' !== $sale && (float) $sale >= (float) $regular ) {
			return 'The sale price must be lower than the regular price.';
		}
		foreach ( array( 'description', 'short_description' ) as $field ) {
			if ( isset( $set[ $field ] ) && ( ! is_string( $set[ $field ] ) || strlen( $set[ $field ] ) > StateUnits::MAX_CONTENT ) ) {
				return $field . ' is text of at most ' . StateUnits::MAX_CONTENT . ' bytes.';
			}
		}
		if ( isset( $set['sku'] ) && ( ! is_string( $set['sku'] ) || mb_strlen( $set['sku'] ) > self::MAX_SKU ) ) {
			return 'The SKU is text of at most ' . self::MAX_SKU . ' characters.';
		}
		if ( isset( $set['manage_stock'] ) && ! is_bool( $set['manage_stock'] ) ) {
			return 'manage_stock is true or false.';
		}
		if ( isset( $set['stock_quantity'] ) && ( ! is_int( $set['stock_quantity'] ) || $set['stock_quantity'] < 0 || $set['stock_quantity'] > 10000000 ) ) {
			return 'stock_quantity is a whole number, 0 or more.';
		}
		if ( isset( $set['stock_status'] ) && ! in_array( $set['stock_status'], self::STOCK_STATUSES, true ) ) {
			return 'stock_status is instock, outofstock or onbackorder.';
		}
		if ( ! in_array( $set['status'] ?? '', self::STATUSES, true ) ) {
			return 'status must be draft or publish.';
		}
		if ( isset( $set['categories'] ) ) {
			if ( ! is_array( $set['categories'] ) || count( $set['categories'] ) > self::MAX_CATEGORIES ) {
				return 'categories is a list of at most ' . self::MAX_CATEGORIES . ' category ids.';
			}
			foreach ( $set['categories'] as $term ) {
				if ( ! is_int( $term ) || ! term_exists( $term, 'product_cat' ) ) {
					return 'Unknown product category id: ' . wp_json_encode( $term );
				}
			}
		}
		if ( ! empty( $set['image_id'] ) ) {
			$image = is_int( $set['image_id'] ) ? get_post( $set['image_id'] ) : null;
			if ( ! $image || 'attachment' !== $image->post_type || ! wp_attachment_is_image( $image->ID ) ) {
				return 'image_id is not an image of the media library.';
			}
		}
		return '';
	}

	/**
	 * Why the unit may not be written now ('' = it may).
	 *
	 * @param int                      $id     Id.
	 * @param array<string,mixed>|null $expect Expected values.
	 * @param array<string,mixed>|null $set    New values.
	 */
	public static function problem( int $id, ?array $expect, ?array $set ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'WooCommerce is not active.';
		}
		if ( ! self::can_create() ) {
			return 'This user may not add products.';
		}
		if ( 0 === $id ) {
			if ( null !== $expect || null === $set ) {
				return 'A new product needs its values and no expected values.';
			}
			$invalid = self::invalid( $set );
			if ( '' !== $invalid ) {
				return $invalid;
			}
			if ( ! empty( $set['sku'] ) && wc_get_product_id_by_sku( (string) $set['sku'] ) ) {
				return 'Another product already has the SKU ' . $set['sku'] . '.';
			}
			return '';
		}
		if ( null !== $set ) {
			return 'A created product is only undone (moved to the trash) here.';
		}
		if ( null === $expect ) {
			return 'Expected values are required.';
		}
		$now = self::read( $id );
		if ( empty( $now['exists'] ) ) {
			return (string) $now['reason'];
		}
		return '';
	}

	/**
	 * Whether the product still is the one we created: only its type (its owner may edit it after the approval; Undo still
	 * moves exactly that product to the trash).
	 *
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( array $now, array $expect ): bool {
		return ! isset( $expect['type'] ) || ( $now['type'] ?? null ) === $expect['type'];
	}

	/**
	 * Creates the product (id 0) or moves it to the trash (null).
	 *
	 * @param int                      $id        Id.
	 * @param array<string,mixed>|null $set       Values.
	 * @param string                   $changeset Change set id (marker of a created product).
	 * @return int Id written.
	 * @throws \RuntimeException When WooCommerce refuses.
	 */
	public static function write( int $id, ?array $set, string $changeset ): int {
		if ( null === $set ) {
			if ( null === self::created( $id ) ) {
				throw new \RuntimeException( 'No product AddSynapse created with this id.' );
			}
			if ( ! wp_trash_post( $id ) ) {
				throw new \RuntimeException( 'WordPress did not move the product to the trash.' );
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
			return $id;
		}
		$product = new \WC_Product_Simple();
		try {
			$product->set_name( TextGuard::text( (string) $set['name'] ) );
			$product->set_regular_price( (string) $set['regular_price'] );
			$product->set_sale_price( (string) ( $set['sale_price'] ?? '' ) );
			$product->set_description( TextGuard::html( (string) ( $set['description'] ?? '' ) ) );
			$product->set_short_description( TextGuard::html( (string) ( $set['short_description'] ?? '' ) ) );
			if ( ! empty( $set['sku'] ) ) {
				$product->set_sku( TextGuard::text( (string) $set['sku'] ) );
			}
			$manage = ! empty( $set['manage_stock'] );
			$product->set_manage_stock( $manage );
			if ( $manage ) {
				$product->set_stock_quantity( (int) ( $set['stock_quantity'] ?? 0 ) );
			}
			if ( isset( $set['stock_status'] ) && ! $manage ) {
				$product->set_stock_status( (string) $set['stock_status'] );
			}
			if ( isset( $set['categories'] ) ) {
				$product->set_category_ids( array_map( 'intval', (array) $set['categories'] ) );
			}
			if ( ! empty( $set['image_id'] ) ) {
				$product->set_image_id( (int) $set['image_id'] );
			}
			$product->set_status( (string) $set['status'] );
			$id = (int) $product->save();
		} catch ( \WC_Data_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as data, never printed raw.
			throw new \RuntimeException( $e->getMessage() );
		}//end try
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'WooCommerce did not create the product.' );
		}
		update_post_meta( $id, ProductTerms::CREATED_META, '' !== $changeset ? $changeset : '1' );
		clean_post_cache( $id );
		wc_delete_product_transients( $id );
		return $id;
	}
}
