<?php
/**
 * 1.3.11 unit of the remote route (read-state / apply-changes): product_new — ONE new WooCommerce product.
 * id 0 → created on Approve (published, or a draft when the owner asked), marked as ours. Undo moves exactly that
 * product to the trash (never deletes it for good), and only a product this plugin created. Images: an existing media
 * library item only (no uploads).
 * 1.3.13: type variable — attributes (a global attribute pa_* of the same name when the shop has one, its terms by name,
 * missing terms created and marked as ours; else a product-only attribute) and variations with their own price, stock and
 * SKU. Undo trashes the product and its variations and deletes the terms it created while nothing else uses them.
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

	public const FIELDS = array( 'type', 'name', 'regular_price', 'sale_price', 'description', 'short_description', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'categories', 'status', 'image_id', 'attributes', 'variations' );

	public const VARIATION_FIELDS = array( 'attributes', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status' );

	public const MAX_ATTRIBUTES = 3;
	public const MAX_OPTIONS    = 30;
	public const MAX_VARIATIONS = 50;
	public const MAX_LABEL      = 100;

	/** Product meta: the attribute terms this plugin created for the product ([taxonomy, term id] pairs) — Undo deletes them. */
	public const TERMS_META = '_addsynapse_assistant_created_terms';

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
		) + ( $product->is_type( 'variable' ) ? self::variable_values( $product ) : array() );
	}

	/**
	 * Attributes and variations of a variable product, by the names people read (attribute label, option name).
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array{attributes: list<array<string,mixed>>, variations: list<array<string,mixed>>}
	 */
	private static function variable_values( \WC_Product $product ): array {
		$attributes = array();
		$keys       = array();
		foreach ( $product->get_attributes( 'edit' ) as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$taxonomy = $attribute->get_name();
				$global   = wc_get_attribute( (int) $attribute->get_id() );
				$name     = $global ? html_entity_decode( (string) $global->name, ENT_QUOTES, 'UTF-8' ) : wc_attribute_label( $taxonomy );
				$options  = array();
				$slugs    = array();
				foreach ( $attribute->get_options() as $term_id ) {
					$term = get_term( (int) $term_id, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						$options[]            = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
						$slugs[ $term->slug ] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
					}
				}
				$keys[ $taxonomy ] = array( $name, $slugs );
			} else {
				$name                            = (string) $attribute->get_name();
				$options                         = array_map( 'strval', $attribute->get_options() );
				$keys[ sanitize_title( $name ) ] = array( $name, null );
			}
			$attributes[] = array(
				'name'    => $name,
				'options' => $options,
			);
		}//end foreach
		$variations = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}
			$map = array();
			foreach ( $variation->get_attributes( 'edit' ) as $key => $value ) {
				if ( ! isset( $keys[ $key ] ) ) {
					continue;
				}
				list( $name, $slugs ) = $keys[ $key ];
				$map[ $name ]         = null === $slugs ? (string) $value : ( $slugs[ $value ] ?? (string) $value );
			}
			$qty          = $variation->get_stock_quantity( 'edit' );
			$variations[] = array(
				'attributes'     => $map,
				'regular_price'  => (string) $variation->get_regular_price( 'edit' ),
				'sale_price'     => (string) $variation->get_sale_price( 'edit' ),
				'sku'            => (string) $variation->get_sku( 'edit' ),
				'manage_stock'   => (bool) $variation->get_manage_stock( 'edit' ),
				'stock_quantity' => null === $qty ? null : (int) $qty,
				'stock_status'   => (string) $variation->get_stock_status( 'edit' ),
			);
		}//end foreach
		return array(
			'attributes' => $attributes,
			'variations' => $variations,
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
		$type = $set['type'] ?? 'simple';
		if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			return 'type is simple or variable.';
		}
		$name = $set['name'] ?? null;
		if ( ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > self::MAX_NAME ) {
			return 'A new product needs a name (1 to ' . self::MAX_NAME . ' characters).';
		}
		if ( 'variable' === $type ) {
			$invalid = self::invalid_variable( $set );
			if ( '' !== $invalid ) {
				return $invalid;
			}
			$set = array_merge( $set, array( 'regular_price' => '0' ) );
		} elseif ( isset( $set['attributes'] ) || isset( $set['variations'] ) ) {
			return 'attributes and variations are only for type variable.';
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
	 * The attributes and variations of a new variable product ('' = fine): 1–3 attributes with 1–30 distinct options,
	 * 1–50 variations, each naming one option of every attribute (no combination twice) with a regular price.
	 *
	 * @param array<string,mixed> $set Values.
	 */
	private static function invalid_variable( array $set ): string {
		foreach ( array( 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status' ) as $field ) {
			if ( isset( $set[ $field ] ) && '' !== $set[ $field ] && false !== $set[ $field ] ) {
				return 'A variable product keeps ' . $field . ' on its variations.';
			}
		}
		$attributes = $set['attributes'] ?? null;
		if ( ! is_array( $attributes ) || ! $attributes || count( $attributes ) > self::MAX_ATTRIBUTES ) {
			return 'A variable product needs 1 to ' . self::MAX_ATTRIBUTES . ' attributes.';
		}
		$names = array();
		foreach ( $attributes as $attribute ) {
			$name    = is_array( $attribute ) ? ( $attribute['name'] ?? null ) : null;
			$options = is_array( $attribute ) ? ( $attribute['options'] ?? null ) : null;
			if ( ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > self::MAX_LABEL || isset( $names[ mb_strtolower( $name ) ] ) ) {
				return 'Each attribute needs a name (once, at most ' . self::MAX_LABEL . ' characters).';
			}
			if ( ! is_array( $options ) || ! $options || count( $options ) > self::MAX_OPTIONS ) {
				return 'The attribute ' . $name . ' needs 1 to ' . self::MAX_OPTIONS . ' options.';
			}
			$seen = array();
			foreach ( $options as $option ) {
				if ( ! is_string( $option ) || '' === trim( $option ) || mb_strlen( $option ) > self::MAX_LABEL || isset( $seen[ mb_strtolower( $option ) ] ) ) {
					return 'The options of ' . $name . ' are distinct texts of at most ' . self::MAX_LABEL . ' characters.';
				}
				$seen[ mb_strtolower( $option ) ] = true;
			}
			$names[ mb_strtolower( $name ) ] = array( $name, $options );
		}
		$variations = $set['variations'] ?? null;
		if ( ! is_array( $variations ) || ! $variations || count( $variations ) > self::MAX_VARIATIONS ) {
			return 'A variable product needs 1 to ' . self::MAX_VARIATIONS . ' variations.';
		}
		$combos = array();
		$skus   = array();
		foreach ( $variations as $n => $variation ) {
			$label = 'Variation ' . ( (int) $n + 1 ) . ': ';
			if ( ! is_array( $variation ) || array_diff( array_keys( $variation ), self::VARIATION_FIELDS ) ) {
				return $label . 'only ' . implode( ', ', self::VARIATION_FIELDS ) . '.';
			}
			$map = $variation['attributes'] ?? null;
			if ( ! is_array( $map ) || count( $map ) !== count( $names ) ) {
				return $label . 'needs one option of every attribute.';
			}
			foreach ( $map as $name => $option ) {
				$known = $names[ mb_strtolower( (string) $name ) ] ?? null;
				if ( null === $known || ! in_array( $option, $known[1], true ) ) {
					return $label . wp_json_encode( array( $name => $option ) ) . ' is not an option of the product.';
				}
			}
			ksort( $map );
			$key = mb_strtolower( (string) wp_json_encode( $map ) );
			if ( isset( $combos[ $key ] ) ) {
				return $label . 'the same combination is listed twice.';
			}
			$combos[ $key ] = true;
			$regular        = self::price( $variation['regular_price'] ?? '' );
			$sale           = self::price( $variation['sale_price'] ?? '' );
			if ( null === $regular || '' === $regular || null === $sale || ( '' !== $sale && (float) $sale >= (float) $regular ) ) {
				return $label . 'needs a regular price, and a sale price lower than it (or none).';
			}
			if ( isset( $variation['manage_stock'] ) && ! is_bool( $variation['manage_stock'] ) ) {
				return $label . 'manage_stock is true or false.';
			}
			if ( isset( $variation['stock_quantity'] ) && ( ! is_int( $variation['stock_quantity'] ) || $variation['stock_quantity'] < 0 || $variation['stock_quantity'] > 10000000 ) ) {
				return $label . 'stock_quantity is a whole number, 0 or more.';
			}
			if ( isset( $variation['stock_status'] ) && ! in_array( $variation['stock_status'], self::STOCK_STATUSES, true ) ) {
				return $label . 'stock_status is instock, outofstock or onbackorder.';
			}
			if ( isset( $variation['sku'] ) && '' !== $variation['sku'] ) {
				if ( ! is_string( $variation['sku'] ) || mb_strlen( $variation['sku'] ) > self::MAX_SKU || isset( $skus[ mb_strtolower( $variation['sku'] ) ] ) || mb_strtolower( $variation['sku'] ) === mb_strtolower( (string) ( $set['sku'] ?? '' ) ) ) {
					return $label . 'the SKU is text of at most ' . self::MAX_SKU . ' characters, used once.';
				}
				$skus[ mb_strtolower( $variation['sku'] ) ] = true;
			}
		}//end foreach
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
			$skus = array( (string) ( $set['sku'] ?? '' ) );
			foreach ( (array) ( $set['variations'] ?? array() ) as $variation ) {
				$skus[] = (string) ( $variation['sku'] ?? '' );
			}
			foreach ( array_filter( $skus, 'strlen' ) as $sku ) {
				if ( wc_get_product_id_by_sku( $sku ) ) {
					return 'Another product already has the SKU ' . $sku . '.';
				}
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
			$product = self::created( $id );
			if ( null === $product ) {
				throw new \RuntimeException( 'No product AddSynapse created with this id.' );
			}
			foreach ( $product->is_type( 'variable' ) ? $product->get_children() : array() as $child_id ) {
				wp_trash_post( (int) $child_id );
			}
			if ( ! wp_trash_post( $id ) ) {
				throw new \RuntimeException( 'WordPress did not move the product to the trash.' );
			}
			self::delete_created_terms( $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
			return $id;
		}
		if ( 'variable' === ( $set['type'] ?? 'simple' ) ) {
			return self::write_variable( $set, $changeset );
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

	/**
	 * Creates a variable product, its attributes and variations. Any failure: what was created is removed again.
	 *
	 * @param array<string,mixed> $set       Values (checked by invalid()).
	 * @param string              $changeset Change set id.
	 * @return int Product id.
	 * @throws \RuntimeException When WooCommerce refuses.
	 */
	private static function write_variable( array $set, string $changeset ): int {
		$marker  = '' !== $changeset ? $changeset : '1';
		$created = array();
		$id      = 0;
		try {
			$product    = new \WC_Product_Variable();
			$attributes = array();
			$keys       = array();
			foreach ( $set['attributes'] as $position => $row ) {
				$name      = TextGuard::text( (string) $row['name'] );
				$taxonomy  = self::global_attribute( $name );
				$attribute = new \WC_Product_Attribute();
				$attribute->set_position( (int) $position );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$values = array();
				if ( '' !== $taxonomy ) {
					$ids = array();
					foreach ( $row['options'] as $option ) {
						$term                                        = self::attribute_term( $taxonomy, TextGuard::text( (string) $option ), $marker, $created );
						$ids[]                                       = (int) $term->term_id;
						$values[ mb_strtolower( (string) $option ) ] = $term->slug;
					}
					$attribute->set_id( (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) );
					$attribute->set_name( $taxonomy );
					$attribute->set_options( $ids );
					$key = $taxonomy;
				} else {
					$options = array_map(
						static function ( $option ) {
							return TextGuard::text( (string) $option );
						},
						$row['options']
					);
					foreach ( $row['options'] as $n => $option ) {
						$values[ mb_strtolower( (string) $option ) ] = $options[ $n ];
					}
					$attribute->set_id( 0 );
					$attribute->set_name( $name );
					$attribute->set_options( $options );
					$key = sanitize_title( $name );
				}//end if
				$attributes[]                                   = $attribute;
				$keys[ mb_strtolower( (string) $row['name'] ) ] = array( $key, $values );
			}//end foreach
			$product->set_attributes( $attributes );
			$product->set_name( TextGuard::text( (string) $set['name'] ) );
			$product->set_description( TextGuard::html( (string) ( $set['description'] ?? '' ) ) );
			$product->set_short_description( TextGuard::html( (string) ( $set['short_description'] ?? '' ) ) );
			if ( ! empty( $set['sku'] ) ) {
				$product->set_sku( TextGuard::text( (string) $set['sku'] ) );
			}
			if ( isset( $set['categories'] ) ) {
				$product->set_category_ids( array_map( 'intval', (array) $set['categories'] ) );
			}
			if ( ! empty( $set['image_id'] ) ) {
				$product->set_image_id( (int) $set['image_id'] );
			}
			$product->set_status( (string) $set['status'] );
			$id = (int) $product->save();
			if ( $id <= 0 ) {
				throw new \RuntimeException( 'WooCommerce did not create the product.' );
			}
			update_post_meta( $id, ProductTerms::CREATED_META, $marker );
			if ( $created ) {
				update_post_meta( $id, self::TERMS_META, $created );
			}
			foreach ( $set['variations'] as $order => $row ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $id );
				$map = array();
				foreach ( $row['attributes'] as $name => $option ) {
					list( $key, $values ) = $keys[ mb_strtolower( (string) $name ) ];
					$map[ $key ]          = $values[ mb_strtolower( (string) $option ) ];
				}
				$variation->set_attributes( $map );
				$variation->set_menu_order( (int) $order );
				$variation->set_regular_price( (string) $row['regular_price'] );
				$variation->set_sale_price( (string) ( $row['sale_price'] ?? '' ) );
				if ( ! empty( $row['sku'] ) ) {
					$variation->set_sku( TextGuard::text( (string) $row['sku'] ) );
				}
				$manage = ! empty( $row['manage_stock'] ) || isset( $row['stock_quantity'] );
				$variation->set_manage_stock( $manage );
				if ( $manage ) {
					$variation->set_stock_quantity( (int) ( $row['stock_quantity'] ?? 0 ) );
				} elseif ( isset( $row['stock_status'] ) ) {
					$variation->set_stock_status( (string) $row['stock_status'] );
				}
				$variation->set_status( 'publish' );
				if ( (int) $variation->save() <= 0 ) {
					throw new \RuntimeException( 'WooCommerce did not create a variation.' );
				}
			}//end foreach
			\WC_Product_Variable::sync( $id );
		} catch ( \Throwable $e ) {
			if ( $id > 0 ) {
				$made = wc_get_product( $id );
				foreach ( $made ? $made->get_children() : array() as $child_id ) {
					wp_delete_post( (int) $child_id, true );
				}
				wp_delete_post( $id, true );
			}
			foreach ( $created as $pair ) {
				wp_delete_term( (int) $pair[1], (string) $pair[0] );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as data, never printed raw.
			throw new \RuntimeException( $e->getMessage() );
		}//end try
		clean_post_cache( $id );
		wc_delete_product_transients( $id );
		return $id;
	}

	/**
	 * The shop's global attribute (pa_*) with this name (its label or slug, case-insensitive), or ''.
	 *
	 * @param string $name Attribute name.
	 */
	public static function global_attribute( string $name ): string {
		$want = mb_strtolower( trim( $name ) );
		foreach ( wc_get_attribute_taxonomies() as $row ) {
			$taxonomy = wc_attribute_taxonomy_name( (string) $row->attribute_name );
			if ( ( in_array( mb_strtolower( (string) $row->attribute_label ), array( $want ), true ) || sanitize_title( $name ) === (string) $row->attribute_name ) && taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}
		return '';
	}

	/**
	 * The term of a global attribute with this name (or slug), created and marked as ours when it is missing.
	 *
	 * @param string                           $taxonomy Taxonomy.
	 * @param string                           $name     Option.
	 * @param string                           $marker   Change set id.
	 * @param array<int,array{0:string,1:int}> $created  Terms created (appended).
	 * @throws \RuntimeException When WordPress refuses the term.
	 */
	private static function attribute_term( string $taxonomy, string $name, string $marker, array &$created ): \WP_Term {
		foreach ( array( 'name', 'slug' ) as $field ) {
			$term = get_term_by( $field, 'slug' === $field ? sanitize_title( $name ) : $name, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}
		$made = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $made ) ) {
			throw new \RuntimeException( $made->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- data.
		}
		update_term_meta( (int) $made['term_id'], ProductTerms::CREATED_META, $marker );
		$created[] = array( $taxonomy, (int) $made['term_id'] );
		return get_term( (int) $made['term_id'], $taxonomy );
	}

	/**
	 * Deletes the attribute terms created with this product, while no other product uses them.
	 *
	 * @param int $id Product id.
	 */
	private static function delete_created_terms( int $id ): void {
		foreach ( (array) get_post_meta( $id, self::TERMS_META, true ) as $pair ) {
			if ( ! is_array( $pair ) || 2 !== count( $pair ) ) {
				continue;
			}
			$objects = get_objects_in_term( (int) $pair[1], (string) $pair[0] );
			if ( is_array( $objects ) && ! array_diff( array_map( 'intval', $objects ), array( $id ) ) ) {
				wp_delete_term( (int) $pair[1], (string) $pair[0] );
			}
		}
	}
}
