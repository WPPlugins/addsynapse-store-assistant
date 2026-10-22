<?php
/**
 * The «units» AddSynapse may read and (after the owner approved a change set) write through the remote route:
 *  - product: price/stock fields of a product or variation (Changesets\ProductFields),
 *  - seo:     SEO title / meta description of a product, page or post through the active SEO plugin
 *             (Rank Math or Yoast — Changesets\SeoFields; nothing when neither is active),
 *  - coupon:  a WooCommerce coupon (fixed list of fields; create, update, delete only for Undo of a create),
 *  - content: title / excerpt / content / status of a page, post or other editable post type (also without WooCommerce),
 *  - featured: the featured image of a page, post or other post type (attachment id or none),
 *  - menu:    a whole navigation menu (classic menu or block Navigation) as a snapshot — written by its menu adapter only
 *             when the menu still matches the expected snapshot (Undo restores the previous tree).
 *  - styles / css / setting / widgets / doc / kit: the site's look (Design\DesignUnits — site styles, Additional CSS, theme
 *             settings, widgets, pages / templates / template parts / Elementor documents, Elementor Site Settings), identified
 *             by a string `key` the site resolves itself.
 * product and coupon need WooCommerce; on a plain WordPress site they read as «not found».
 *
 * Reads return normalized values; writes take the same shape, so a change set is just expect(BEFORE)/set(AFTER).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapters;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use Addsynapse\StoreAssistant\Design\DesignUnits;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\Menus\MenuTree;
use Addsynapse\StoreAssistant\Changesets\ProductFields;
use Addsynapse\StoreAssistant\Changesets\SeoFields;

defined( 'ABSPATH' ) || exit;

/**
 * Read / compare / write of remote units.
 */
final class StateUnits {

	public const KINDS = array( 'product', 'seo', 'coupon', 'content', 'featured', 'menu', 'styles', 'css', 'setting', 'widgets', 'doc', 'kit' );

	/** Content fields the remote route may change. */
	public const CONTENT_FIELDS = array( 'title', 'excerpt', 'content', 'status' );

	/** Post statuses a change set may set. */
	public const CONTENT_STATUSES = array( 'draft', 'pending', 'publish', 'private' );

	/** Largest post_content a change set may write (bytes). */
	public const MAX_CONTENT = 500000;

	/** Product fields the remote route may change (no sale dates). */
	public const PRODUCT_FIELDS = array( 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status' );

	public const SEO_PARTS = array( 'title', 'description' );

	public const COUPON_FIELDS = array(
		'code',
		'description',
		'discount_type',
		'amount',
		'date_expires',
		'usage_limit',
		'usage_limit_per_user',
		'individual_use',
		'free_shipping',
		'minimum_amount',
		'maximum_amount',
		'product_ids',
		'excluded_product_ids',
		'product_categories',
		'excluded_product_categories',
	);

	public const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );
	public const COUPON_TYPES   = array( 'percent', 'fixed_cart', 'fixed_product' );

	/**
	 * Current state of one unit.
	 *
	 * @param array<string,mixed> $item {kind, id, code?}.
	 * @return array<string,mixed> {kind, id, exists, name, values, [adapter]} or {error}.
	 */
	public static function read( array $item ): array {
		$kind = (string) ( $item['kind'] ?? '' );
		$id   = (int) ( $item['id'] ?? 0 );
		if ( DesignUnits::handles( $kind ) ) {
			return DesignUnits::read( $kind, (string) ( $item['key'] ?? '' ) );
		}
		if ( in_array( $kind, array( 'product', 'coupon' ), true ) && ! Environment::has_woocommerce() ) {
			return self::missing( $kind, $id, 'WooCommerce is not active on this site.' );
		}
		if ( 'content' === $kind || 'featured' === $kind ) {
			$post = self::editable_post( $id );
			if ( null === $post ) {
				return self::missing( $kind, $id, 'Page or post not found (or not editable).' );
			}
			$values = 'content' === $kind ? array(
				'title'   => (string) $post->post_title,
				'excerpt' => (string) $post->post_excerpt,
				'content' => (string) $post->post_content,
				'status'  => (string) $post->post_status,
			) : array( 'image_id' => self::thumbnail( $post->ID ) );
			return array(
				'kind'   => $kind,
				'id'     => $id,
				'exists' => true,
				'name'   => self::text( (string) $post->post_title ),
				'type'   => (string) $post->post_type,
				'values' => $values,
			);
		}
		if ( 'menu' === $kind ) {
			$adapter  = MenuAdapters::current();
			$snapshot = $id > 0 ? $adapter->read( $id ) : array();
			if ( empty( $snapshot['exists'] ) ) {
				return self::missing( $kind, $id, 'Menu not found.' );
			}
			return array(
				'kind'    => $kind,
				'id'      => $id,
				'exists'  => true,
				'name'    => (string) $snapshot['menu']['name'],
				'type'    => $adapter->kind(),
				'adapter' => $adapter->kind(),
				'values'  => array( 'menu' => $snapshot ),
			);
		}
		if ( 'product' === $kind ) {
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product || 'trash' === $product->get_status( 'edit' ) || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
				return self::missing( $kind, $id, $product ? 'Not a simple product or a variation (change the variations of a variable product).' : 'Product not found.' );
			}
			$values = array();
			foreach ( self::PRODUCT_FIELDS as $field ) {
				$values[ $field ] = ProductFields::read( $product, $field );
			}
			return array(
				'kind'      => $kind,
				'id'        => $id,
				'exists'    => true,
				'name'      => self::text( $product->get_name() ),
				'type'      => (string) $product->get_type(),
				'parent_id' => (int) $product->get_parent_id(),
				'values'    => $values,
			);
		}
		if ( 'seo' === $kind ) {
			$adapter = SeoAdapters::current();
			$subject = self::seo_subject( $id );
			if ( null === $subject ) {
				return self::missing( $kind, $id, 'Product, page or post not found (variations take the SEO of their parent product).' );
			}
			$values = array();
			if ( $adapter->supports_seo() ) {
				foreach ( self::SEO_PARTS as $part ) {
					$value           = SeoFields::read( $subject, SeoFields::field( $adapter->key(), $part ) );
					$values[ $part ] = is_array( $value ) ? null : $value;
				}
			}
			return array(
				'kind'    => $kind,
				'id'      => $id,
				'exists'  => true,
				'name'    => self::text( $subject instanceof \WC_Product ? $subject->get_name() : $subject->post_title ),
				'type'    => $subject instanceof \WC_Product ? 'product' : $subject->post_type,
				'adapter' => $adapter->key(),
				'values'  => $values,
			);
		}//end if
		if ( 'coupon' === $kind ) {
			if ( ! $id && ! empty( $item['code'] ) ) {
				$id = (int) wc_get_coupon_id_by_code( (string) $item['code'] );
			}
			$coupon = $id ? new \WC_Coupon( $id ) : null;
			if ( ! $coupon || ! $coupon->get_id() || 'shop_coupon' !== get_post_type( $coupon->get_id() ) || 'trash' === get_post_status( $coupon->get_id() ) ) {
				return self::missing( $kind, $id, 'Coupon not found.' );
			}
			return array(
				'kind'   => $kind,
				'id'     => (int) $coupon->get_id(),
				'exists' => true,
				'name'   => (string) $coupon->get_code(),
				'values' => self::coupon_values( $coupon ),
			);
		}
		return self::missing( $kind, $id, 'Unknown kind.' );
	}

	/**
	 * Whether the current values hold every expected value (only the keys of $expect are compared).
	 *
	 * @param string              $kind   Kind.
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( string $kind, array $now, array $expect ): bool {
		if ( DesignUnits::handles( $kind ) ) {
			return DesignUnits::holds( $kind, $now, $expect );
		}
		if ( 'menu' === $kind ) {
			$a = is_array( $now['menu'] ?? null ) ? $now['menu'] : array();
			$b = is_array( $expect['menu'] ?? null ) ? $expect['menu'] : array();
			return $a && $b && MenuAdapters::current()->same( $a, $b, MenuTree::location_keys( $a, $b ) );
		}
		if ( 'featured' === $kind ) {
			return array_key_exists( 'image_id', $expect ) && (int) ( $now['image_id'] ?? 0 ) === (int) $expect['image_id'];
		}
		foreach ( $expect as $field => $value ) {
			if ( ! array_key_exists( $field, $now ) ) {
				return false;
			}
			if ( 'product' === $kind ) {
				if ( ! ProductFields::same( $field, $now[ $field ], $value ) ) {
					return false;
				}
				continue;
			}
			if ( 'coupon' === $kind ) {
				$a = self::coupon_norm( $field, $now[ $field ] );
				$b = self::coupon_norm( $field, $value );
				if ( $a !== $b ) {
					return false;
				}
				continue;
			}
			if ( $now[ $field ] !== $value && ! ( null !== $now[ $field ] && null !== $value && str_replace( "\r\n", "\n", (string) $now[ $field ] ) === str_replace( "\r\n", "\n", (string) $value ) ) ) {
				return false;
			}
		}//end foreach
		return true;
	}

	/**
	 * Validates the values a change set wants to write ('' = fine).
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $set  Values.
	 */
	public static function invalid( string $kind, array $set ): string {
		$fields  = array(
			'product'  => self::PRODUCT_FIELDS,
			'seo'      => self::SEO_PARTS,
			'coupon'   => self::COUPON_FIELDS,
			'content'  => self::CONTENT_FIELDS,
			'featured' => array( 'image_id' ),
			'menu'     => array( 'menu' ),
		);
		$allowed = $fields[ $kind ] ?? array();
		foreach ( $set as $field => $value ) {
			if ( ! in_array( $field, $allowed, true ) ) {
				return 'Field not allowed: ' . $field;
			}
		}
		if ( 'product' === $kind ) {
			foreach ( array( 'regular_price', 'sale_price' ) as $f ) {
				if ( isset( $set[ $f ] ) && '' !== (string) $set[ $f ] && ! preg_match( '/^[0-9]+(\.[0-9]{1,6})?$/', (string) $set[ $f ] ) ) {
					return 'Invalid ' . $f;
				}
			}
			if ( isset( $set['stock_status'] ) && ! in_array( $set['stock_status'], self::STOCK_STATUSES, true ) ) {
				return 'Invalid stock_status';
			}
			if ( array_key_exists( 'stock_quantity', $set ) && null !== $set['stock_quantity'] && ! is_int( $set['stock_quantity'] ) ) {
				return 'Invalid stock_quantity';
			}
		}
		if ( 'seo' === $kind ) {
			foreach ( $set as $value ) {
				if ( null !== $value && ( ! is_string( $value ) || mb_strlen( $value ) > 320 ) ) {
					return 'SEO texts must be strings of at most 320 characters.';
				}
			}
		}
		if ( 'content' === $kind ) {
			foreach ( $set as $field => $value ) {
				if ( ! is_string( $value ) ) {
					return 'Invalid ' . $field;
				}
			}
			if ( isset( $set['status'] ) && ! in_array( $set['status'], self::CONTENT_STATUSES, true ) ) {
				return 'status must be draft, pending, publish or private';
			}
			if ( isset( $set['title'] ) && mb_strlen( $set['title'] ) > 500 ) {
				return 'The title is too long.';
			}
			if ( isset( $set['content'] ) && strlen( $set['content'] ) > self::MAX_CONTENT ) {
				return 'The content is too long.';
			}
			if ( isset( $set['status'] ) && 'publish' === $set['status'] && ! current_user_can( 'publish_posts' ) ) {
				return 'This user cannot publish.';
			}
		}
		if ( 'featured' === $kind ) {
			$image = $set['image_id'] ?? null;
			if ( null !== $image && ( ! is_int( $image ) || ! wp_attachment_is_image( $image ) ) ) {
				return 'image_id must be an image of the media library (or null for none).';
			}
		}
		if ( 'menu' === $kind && ( ! is_array( $set['menu'] ?? null ) || ! is_array( $set['menu']['items'] ?? null ) || empty( $set['menu']['exists'] ) ) ) {
			return 'Invalid menu snapshot.';
		}
		if ( 'coupon' === $kind ) {
			if ( isset( $set['discount_type'] ) && ! in_array( $set['discount_type'], self::COUPON_TYPES, true ) ) {
				return 'Invalid discount_type';
			}
			if ( isset( $set['date_expires'] ) && null !== $set['date_expires'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $set['date_expires'] ) ) {
				return 'date_expires must be YYYY-MM-DD';
			}
			if ( isset( $set['amount'] ) && ! preg_match( '/^[0-9]+(\.[0-9]{1,4})?$/', (string) $set['amount'] ) ) {
				return 'Invalid amount';
			}
		}
		return '';
	}

	/**
	 * Writes one unit. Coupons: $id 0 + $set → create; $set null → delete (Undo of a create).
	 *
	 * @param string                   $kind Kind.
	 * @param int                      $id   Id (0 = new coupon).
	 * @param array<string,mixed>|null $set  Values (null = delete coupon).
	 * @return int The id written.
	 * @throws \RuntimeException On a write that cannot be done.
	 */
	public static function write( string $kind, int $id, ?array $set ): int {
		if ( 'content' === $kind ) {
			$map  = array(
				'title'   => 'post_title',
				'excerpt' => 'post_excerpt',
				'content' => 'post_content',
				'status'  => 'post_status',
			);
			$post = array( 'ID' => $id );
			foreach ( (array) $set as $field => $value ) {
				$post[ $map[ $field ] ] = (string) $value;
			}
			$result = wp_update_post( wp_slash( $post ), true );
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as data, never printed raw.
				throw new \RuntimeException( $result->get_error_message() );
			}
			return $id;
		}
		if ( 'featured' === $kind ) {
			$image = $set['image_id'] ?? null;
			if ( null === $image ) {
				delete_post_thumbnail( $id );
			} else {
				set_post_thumbnail( $id, (int) $image );
			}
			return $id;
		}
		if ( 'menu' === $kind ) {
			$adapter = MenuAdapters::current();
			$adapter->write( $adapter->read( $id ), (array) $set['menu'] );
			return $id;
		}
		if ( 'product' === $kind ) {
			$product = wc_get_product( $id );
			$fields  = array_keys( (array) $set );
			usort(
				$fields,
				static function ( $a, $b ) {
					return ProductFields::order( $a ) <=> ProductFields::order( $b );
				}
			);
			foreach ( $fields as $field ) {
				ProductFields::write( $product, $field, $set[ $field ] );
			}
			$product->save();
			return $id;
		}
		if ( 'seo' === $kind ) {
			$adapter = SeoAdapters::current();
			$subject = self::seo_subject( $id );
			foreach ( (array) $set as $part => $value ) {
				$field = SeoFields::field( $adapter->key(), $part );
				if ( $subject instanceof \WC_Product ) {
					SeoFields::write( $subject, $field, $value );
					continue;
				}
				$key = SeoFields::meta_key( $field );
				if ( null === $value ) {
					delete_post_meta( $id, $key );
				} else {
					update_post_meta( $id, $key, wp_slash( (string) $value ) );
				}
			}
			if ( $subject instanceof \WC_Product ) {
				$subject->save();
			} else {
				clean_post_cache( $id );
			}
			return $id;
		}//end if
		// Coupon.
		if ( null === $set ) {
			$coupon = new \WC_Coupon( $id );
			$coupon->delete( true );
			return $id;
		}
		$coupon = $id ? new \WC_Coupon( $id ) : new \WC_Coupon();
		foreach ( $set as $field => $value ) {
			self::coupon_set( $coupon, $field, $value );
		}
		return (int) $coupon->save();
	}

	/**
	 * Normalized coupon values.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return array<string,mixed>
	 */
	public static function coupon_values( \WC_Coupon $coupon ): array {
		$expires = $coupon->get_date_expires( 'edit' );
		$out     = array(
			'code'                        => (string) $coupon->get_code( 'edit' ),
			'description'                 => (string) $coupon->get_description( 'edit' ),
			'discount_type'               => (string) $coupon->get_discount_type( 'edit' ),
			'amount'                      => (string) $coupon->get_amount( 'edit' ),
			'date_expires'                => $expires ? $expires->date( 'Y-m-d' ) : null,
			'usage_limit'                 => $coupon->get_usage_limit( 'edit' ) ? (int) $coupon->get_usage_limit( 'edit' ) : null,
			'usage_limit_per_user'        => $coupon->get_usage_limit_per_user( 'edit' ) ? (int) $coupon->get_usage_limit_per_user( 'edit' ) : null,
			'individual_use'              => (bool) $coupon->get_individual_use( 'edit' ),
			'free_shipping'               => (bool) $coupon->get_free_shipping( 'edit' ),
			'minimum_amount'              => (string) $coupon->get_minimum_amount( 'edit' ),
			'maximum_amount'              => (string) $coupon->get_maximum_amount( 'edit' ),
			'product_ids'                 => array_map( 'intval', $coupon->get_product_ids( 'edit' ) ),
			'excluded_product_ids'        => array_map( 'intval', $coupon->get_excluded_product_ids( 'edit' ) ),
			'product_categories'          => array_map( 'intval', $coupon->get_product_categories( 'edit' ) ),
			'excluded_product_categories' => array_map( 'intval', $coupon->get_excluded_product_categories( 'edit' ) ),
		);
		foreach ( $out as $field => $value ) {
			$out[ $field ] = self::coupon_norm( $field, $value );
		}
		$out['usage_count'] = (int) $coupon->get_usage_count( 'edit' );
		return $out;
	}

	/**
	 * Same representation for the same coupon value.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private static function coupon_norm( string $field, $value ) {
		switch ( $field ) {
			case 'amount':
			case 'minimum_amount':
			case 'maximum_amount':
				$value = trim( (string) $value );
				return '' === $value ? '' : wc_format_decimal( $value, 2 );
			case 'usage_limit':
			case 'usage_limit_per_user':
				return empty( $value ) ? null : (int) $value;
			case 'individual_use':
			case 'free_shipping':
				return (bool) $value;
			case 'product_ids':
			case 'excluded_product_ids':
			case 'product_categories':
			case 'excluded_product_categories':
				$list = array_values( array_unique( array_map( 'intval', (array) $value ) ) );
				sort( $list );
				return $list;
			case 'code':
				return wc_format_coupon_code( (string) $value );
			case 'date_expires':
				return empty( $value ) ? null : (string) $value;
		}//end switch
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Sets one coupon field.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @param string     $field  Field.
	 * @param mixed      $value  Value.
	 */
	private static function coupon_set( \WC_Coupon $coupon, string $field, $value ): void {
		$value = self::coupon_norm( $field, $value );
		switch ( $field ) {
			case 'date_expires':
				$coupon->set_date_expires( null === $value ? null : strtotime( $value . ' 23:59:59 ' . wp_timezone_string() ) );
				return;
			case 'usage_limit':
			case 'usage_limit_per_user':
				$coupon->{'set_' . $field}( null === $value ? 0 : $value );
				return;
		}
		$coupon->{'set_' . $field}( $value );
	}

	/**
	 * Product (not a variation), page or post for SEO.
	 *
	 * @param int $id Id.
	 * @return \WC_Product|\WP_Post|null
	 */
	private static function seo_subject( int $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'trash' === $post->post_status ) {
			return null;
		}
		if ( in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return current_user_can( 'edit_post', $post->ID ) ? $post : null;
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );
			return $product ? $product : null;
		}
		return null;
	}

	/**
	 * A page, post or other post type the current user may edit (no attachments, menu items or block templates).
	 *
	 * @param int $id Id.
	 */
	public static function editable_post( int $id ): ?\WP_Post {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'trash' === $post->post_status || 'auto-draft' === $post->post_status ) {
			return null;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->show_ui || in_array( $post->post_type, array( 'attachment', 'nav_menu_item', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_block', 'wp_global_styles', 'shop_order', 'shop_coupon' ), true ) ) {
			return null;
		}
		return current_user_can( 'edit_post', $post->ID ) ? $post : null;
	}

	/**
	 * Featured image id (null = none).
	 *
	 * @param int $id Post id.
	 */
	private static function thumbnail( int $id ): ?int {
		$image = (int) get_post_thumbnail_id( $id );
		return $image > 0 ? $image : null;
	}

	/**
	 * Short form of values for the approval log (long texts clipped, a menu as its item count).
	 *
	 * @param string $kind   Kind.
	 * @param mixed  $values Values.
	 * @return mixed
	 */
	public static function for_log( string $kind, $values ) {
		if ( ! is_array( $values ) ) {
			return $values;
		}
		if ( DesignUnits::handles( $kind ) ) {
			return DesignUnits::for_log( $kind, $values );
		}
		if ( 'menu' === $kind ) {
			return array( 'items' => isset( $values['menu']['items'] ) ? count( (array) $values['menu']['items'] ) : 0 );
		}
		foreach ( $values as $field => $value ) {
			if ( is_string( $value ) && mb_strlen( $value ) > 120 ) {
				$values[ $field ] = mb_substr( wp_strip_all_tags( $value ), 0, 117 ) . '…';
			}
		}
		return $values;
	}

	/**
	 * A unit that cannot be read.
	 *
	 * @param string $kind   Kind.
	 * @param int    $id     Id.
	 * @param string $reason Why.
	 * @return array<string,mixed>
	 */
	private static function missing( string $kind, int $id, string $reason ): array {
		return array(
			'kind'   => $kind,
			'id'     => $id,
			'exists' => false,
			'reason' => $reason,
			'values' => null,
		);
	}

	/**
	 * Plain text.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}
}
