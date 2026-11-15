<?php
/**
 * 1.3.10 units of the remote route (read-state / apply-changes):
 *  - product_meta: the custom fields (post meta) of one product. Only public keys (no leading "_", except SAFE_PRIVATE),
 *    never prices, stock, SKU or other WooCommerce-managed keys (they have their own tools), never secrets. A value is
 *    one plain text (one row, not serialized); null = the field does not exist (Undo of a new field deletes it).
 *  - media_text: alt text, title, caption and description of one media library item.
 *  - product_text: the name, description and short description of one product (any type, not a variation). New HTML is
 *    cleaned by TextGuard (script/style removed, wp_kses_post); Undo writes back the exact previous HTML (TextGuard hash).
 *  - post: a NEW post or page (id 0 → created on Approve, as a draft or published, marked as ours). Undo moves exactly
 *    that post to the trash (never deletes it for good), and only a post this plugin created.
 *  - product_new (1.3.11): a NEW simple product, created on Approve and trashed on Undo (see ProductCreate).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Discovery\MetaPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Custom fields, media texts and new posts.
 */
final class FieldUnits {

	public const KINDS = array( 'product_meta', 'media_text', 'post', 'product_text', ProductCreate::KIND );

	public const TEXT_FIELDS = array( 'name', 'description', 'short_description' );
	public const MAX_NAME    = 400;

	/** Keys starting with "_" that may still be written (explicit; none by default — WooCommerce keeps its own). */
	public const SAFE_PRIVATE = array();

	/** Keys never written even without "_" (prices, stock, SKU, sales: their own tools). */
	private const DENY_PATTERN = '/(price|stock|sku|total_sales|backorder|tax_|_tax|download|order)/i';

	public const MAX_KEY   = 191;
	public const MAX_VALUE = 10000;
	public const MAX_KEYS  = 200;

	public const MEDIA_FIELDS = array( 'alt', 'title', 'caption', 'description' );
	public const MEDIA_MAX    = array(
		'alt'         => 250,
		'title'       => 200,
		'caption'     => 1000,
		'description' => 5000,
	);

	public const POST_TYPES    = array( 'post', 'page' );
	public const POST_STATUSES = array( 'draft', 'publish' );
	public const POST_FIELDS   = array( 'type', 'title', 'content', 'excerpt', 'status', 'categories', 'tags' );

	/**
	 * Whether this class handles the kind.
	 *
	 * @param string $kind Kind.
	 */
	public static function handles( string $kind ): bool {
		return in_array( $kind, self::KINDS, true );
	}

	/**
	 * Whether a custom field key may be read and written.
	 *
	 * @param string $key Meta key.
	 */
	public static function editable_key( string $key ): bool {
		if ( '' === $key || strlen( $key ) > self::MAX_KEY || MetaPolicy::is_sensitive( $key ) || MetaPolicy::is_wc_internal( $key ) || MetaPolicy::is_noise( $key ) ) {
			return false;
		}
		if ( 1 === preg_match( self::DENY_PATTERN, $key ) ) {
			return false;
		}
		if ( in_array( $key, self::SAFE_PRIVATE, true ) ) {
			return true;
		}
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $key ) && ! is_protected_meta( $key, 'post' );
	}

	/**
	 * Custom fields of a product: editable (key => text) and the names of the others (sensitive ones are not named).
	 *
	 * @param int $id Product id.
	 * @return array{fields:array<string,string>,locked:array<int,string>}
	 */
	public static function custom_fields( int $id ): array {
		$fields = array();
		$locked = array();
		foreach ( (array) get_post_meta( $id ) as $key => $rows ) {
			$key = (string) $key;
			if ( MetaPolicy::is_sensitive( $key ) || MetaPolicy::is_noise( $key ) ) {
				continue;
			}
			$single = is_array( $rows ) && 1 === count( $rows ) ? $rows[0] : null;
			if ( self::editable_key( $key ) && is_string( $single ) && ! is_serialized( $single ) && strlen( $single ) <= self::MAX_VALUE && count( $fields ) < self::MAX_KEYS ) {
				$fields[ $key ] = $single;
			} elseif ( '_' !== substr( $key, 0, 1 ) && count( $locked ) < self::MAX_KEYS ) {
				$locked[] = $key;
			}
		}
		ksort( $fields );
		sort( $locked );
		return array(
			'fields' => $fields,
			'locked' => $locked,
		);
	}

	/**
	 * A product (or variation) whose custom fields may be changed.
	 *
	 * @param int $id Id.
	 */
	private static function product_post( int $id ): ?\WP_Post {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		return current_user_can( 'edit_post', $post->ID ) ? $post : null;
	}

	/**
	 * A media library item the user may edit.
	 *
	 * @param int $id Id.
	 */
	private static function attachment( int $id ): ?\WP_Post {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}
		return current_user_can( 'edit_post', $post->ID ) ? $post : null;
	}

	/**
	 * A product (not a variation) whose name and descriptions may be changed.
	 *
	 * @param int $id Id.
	 */
	private static function text_product( int $id ): ?\WC_Product {
		if ( ! function_exists( 'wc_get_product' ) || null === self::product_post( $id ) || 'product' !== get_post_type( $id ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Name, description and short description of a product, as stored.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,string>
	 */
	public static function text_values( \WC_Product $product ): array {
		return array(
			'name'              => (string) $product->get_name( 'edit' ),
			'description'       => (string) $product->get_description( 'edit' ),
			'short_description' => (string) $product->get_short_description( 'edit' ),
		);
	}

	/**
	 * Name, description and short description of a product id (null when it has none to change here).
	 *
	 * @param int $id Product id.
	 * @return array<string,string>|null
	 */
	public static function product_texts( int $id ): ?array {
		$product = self::text_product( $id );
		return null === $product ? null : self::text_values( $product );
	}

	/**
	 * A post or page this plugin created, not in the trash.
	 *
	 * @param int $id Id.
	 */
	private static function created_post( int $id ): ?\WP_Post {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, self::POST_TYPES, true ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		if ( '' === (string) get_post_meta( $post->ID, ProductTerms::CREATED_META, true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Values of a media item.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array<string,string>
	 */
	public static function media_values( \WP_Post $post ): array {
		return array(
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'title'       => (string) $post->post_title,
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
		);
	}

	/**
	 * Values of a created post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public static function post_values( \WP_Post $post ): array {
		$values = array(
			'type'    => (string) $post->post_type,
			'title'   => (string) $post->post_title,
			'content' => (string) $post->post_content,
			'excerpt' => (string) $post->post_excerpt,
			'status'  => (string) $post->post_status,
		);
		if ( 'post' === $post->post_type ) {
			foreach ( array(
				'categories' => 'category',
				'tags'       => 'post_tag',
			) as $field => $taxonomy ) {
				$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
				sort( $ids );
				$values[ $field ] = $ids;
			}
		}
		return $values;
	}

	/**
	 * Current state of one unit.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   Id.
	 * @return array<string,mixed>
	 */
	public static function read( string $kind, int $id ): array {
		if ( ProductCreate::KIND === $kind ) {
			return ProductCreate::read( $id );
		}
		if ( 'product_meta' === $kind ) {
			$post = self::product_post( $id );
			if ( null === $post ) {
				return self::missing( $kind, $id, 'Product not found (or not editable).' );
			}
			$cf = self::custom_fields( $id );
			return array(
				'kind'   => $kind,
				'id'     => $id,
				'exists' => true,
				'name'   => wp_strip_all_tags( (string) $post->post_title ),
				'type'   => (string) $post->post_type,
				'locked' => $cf['locked'],
				'values' => $cf['fields'],
			);
		}
		if ( 'product_text' === $kind ) {
			$product = self::text_product( $id );
			if ( null === $product ) {
				return self::missing( $kind, $id, 'Product not found (or not editable; a variation takes the texts of its parent product).' );
			}
			return array(
				'kind'   => $kind,
				'id'     => $id,
				'exists' => true,
				'name'   => wp_strip_all_tags( (string) $product->get_name( 'edit' ) ),
				'type'   => (string) $product->get_type(),
				'values' => self::text_values( $product ),
			);
		}
		if ( 'media_text' === $kind ) {
			$post = self::attachment( $id );
			if ( null === $post ) {
				return self::missing( $kind, $id, 'Media item not found (or not editable).' );
			}
			return array(
				'kind'   => $kind,
				'id'     => $id,
				'exists' => true,
				'name'   => wp_basename( (string) get_attached_file( $id ) ),
				'type'   => 'attachment',
				'values' => self::media_values( $post ),
			);
		}
		$post = self::created_post( $id );
		if ( null === $post ) {
			return self::missing( $kind, $id, 'No post or page AddSynapse created with this id (or it is in the trash).' );
		}
		return array(
			'kind'   => $kind,
			'id'     => $id,
			'exists' => true,
			'name'   => wp_strip_all_tags( (string) $post->post_title ),
			'type'   => (string) $post->post_type,
			'link'   => (string) get_permalink( $post ),
			'values' => self::post_values( $post ),
		);
	}

	/**
	 * Whether the current values hold the expected ones. A missing custom field = null. A created post: only its type
	 * (its owner may edit it after the approval; Undo still moves exactly that post to the trash).
	 *
	 * @param string              $kind   Kind.
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( string $kind, array $now, array $expect ): bool {
		if ( ProductCreate::KIND === $kind ) {
			return ProductCreate::holds( $now, $expect );
		}
		if ( 'post' === $kind ) {
			return ! isset( $expect['type'] ) || ( $now['type'] ?? null ) === $expect['type'];
		}
		foreach ( $expect as $field => $value ) {
			$have = $now[ $field ] ?? null;
			if ( null === $value || null === $have ) {
				if ( $value !== $have ) {
					return false;
				}
				continue;
			}
			if ( self::norm( (string) $have ) !== self::norm( (string) $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validates new values ('' = fine).
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $set  Values.
	 */
	public static function invalid( string $kind, array $set ): string {
		if ( ! $set ) {
			return 'Nothing to change.';
		}
		if ( ProductCreate::KIND === $kind ) {
			return ProductCreate::invalid( $set );
		}
		if ( 'product_meta' === $kind ) {
			if ( count( $set ) > 50 ) {
				return 'At most 50 custom fields at once.';
			}
			foreach ( $set as $key => $value ) {
				if ( ! self::editable_key( (string) $key ) ) {
					return 'This custom field may not be changed here: ' . $key;
				}
				if ( null !== $value && ( ! is_string( $value ) || strlen( $value ) > self::MAX_VALUE ) ) {
					return 'A custom field value is text (at most ' . self::MAX_VALUE . ' characters) or null to remove it: ' . $key;
				}
			}
			return '';
		}
		if ( 'product_text' === $kind ) {
			foreach ( $set as $field => $value ) {
				if ( ! in_array( $field, self::TEXT_FIELDS, true ) ) {
					return 'Field not allowed: ' . $field;
				}
				if ( ! is_string( $value ) || strlen( $value ) > StateUnits::MAX_CONTENT ) {
					return $field . ' is text of at most ' . StateUnits::MAX_CONTENT . ' bytes.';
				}
			}
			if ( isset( $set['name'] ) && ( '' === trim( $set['name'] ) || mb_strlen( $set['name'] ) > self::MAX_NAME ) ) {
				return 'A product name is 1 to ' . self::MAX_NAME . ' characters.';
			}
			return '';
		}
		if ( 'media_text' === $kind ) {
			foreach ( $set as $field => $value ) {
				if ( ! in_array( $field, self::MEDIA_FIELDS, true ) ) {
					return 'Field not allowed: ' . $field;
				}
				if ( ! is_string( $value ) || mb_strlen( $value ) > self::MEDIA_MAX[ $field ] ) {
					return $field . ' is text of at most ' . self::MEDIA_MAX[ $field ] . ' characters.';
				}
				if ( in_array( $field, array( 'alt', 'title' ), true ) && wp_strip_all_tags( $value ) !== $value ) {
					return $field . ' must be plain text.';
				}
			}
			return '';
		}
		foreach ( $set as $field => $value ) {
			if ( ! in_array( $field, self::POST_FIELDS, true ) ) {
				return 'Field not allowed: ' . $field;
			}
		}
		$type = $set['type'] ?? '';
		if ( ! in_array( $type, self::POST_TYPES, true ) ) {
			return 'type must be post or page.';
		}
		if ( ! isset( $set['title'] ) || ! is_string( $set['title'] ) || '' === trim( $set['title'] ) || mb_strlen( $set['title'] ) > 500 ) {
			return 'A new post needs a title (at most 500 characters).';
		}
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $set[ $field ] ) && ( ! is_string( $set[ $field ] ) || strlen( $set[ $field ] ) > StateUnits::MAX_CONTENT ) ) {
				return 'Invalid ' . $field;
			}
		}
		if ( ! in_array( $set['status'] ?? '', self::POST_STATUSES, true ) ) {
			return 'status must be draft or publish.';
		}
		foreach ( array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		) as $field => $taxonomy ) {
			if ( ! isset( $set[ $field ] ) ) {
				continue;
			}
			if ( 'post' !== $type ) {
				return 'Only posts have ' . $field . '.';
			}
			if ( ! is_array( $set[ $field ] ) || count( $set[ $field ] ) > 20 ) {
				return $field . ' is a list of at most 20 term ids.';
			}
			foreach ( $set[ $field ] as $term ) {
				if ( ! is_int( $term ) || ! term_exists( $term, $taxonomy ) ) {
					return 'Unknown ' . $taxonomy . ' id: ' . wp_json_encode( $term );
				}
			}
		}
		return '';
	}

	/**
	 * Why the unit may not be written now ('' = it may): permissions and the create / Undo rules.
	 *
	 * @param string                   $kind   Kind.
	 * @param int                      $id     Id.
	 * @param array<string,mixed>|null $expect Expected values.
	 * @param array<string,mixed>|null $set    New values.
	 */
	public static function problem( string $kind, int $id, ?array $expect, ?array $set ): string {
		if ( ProductCreate::KIND === $kind ) {
			return ProductCreate::problem( $id, $expect, $set );
		}
		if ( null !== $set ) {
			$invalid = self::invalid( $kind, $set );
			if ( '' !== $invalid ) {
				return $invalid;
			}
		}
		if ( 'post' === $kind && 0 === $id ) {
			if ( null !== $expect || null === $set ) {
				return 'A new post needs its values and no expected values.';
			}
			$object = get_post_type_object( (string) $set['type'] );
			if ( ! $object || ! current_user_can( $object->cap->edit_posts ) ) {
				return 'This user may not add this kind of content.';
			}
			if ( 'publish' === $set['status'] && ! current_user_can( $object->cap->publish_posts ) ) {
				return 'This user may not publish.';
			}
			return '';
		}
		if ( null === $expect || ( null === $set && 'post' !== $kind ) ) {
			return 'Expected and new values are required.';
		}
		if ( 'post' === $kind && null !== $set ) {
			return 'A created post is only undone (moved to the trash) here.';
		}
		$now = self::read( $kind, $id );
		if ( empty( $now['exists'] ) ) {
			return (string) ( $now['reason'] ?? 'Not found.' );
		}
		if ( ! self::holds( $kind, (array) $now['values'], $expect ) ) {
			return 'Changed since the draft.';
		}
		if ( 'product_meta' === $kind ) {
			foreach ( array_keys( (array) $set ) as $key ) {
				if ( ! array_key_exists( $key, (array) $now['values'] ) && metadata_exists( 'post', $id, (string) $key ) ) {
					return 'This custom field holds a structured value or several values, so it is not changed here: ' . $key;
				}
			}
		}
		return '';
	}

	/**
	 * Writes one unit. post + id 0 → creates it (returns the new id); post + null → moves it to the trash.
	 *
	 * @param string                   $kind      Kind.
	 * @param int                      $id        Id.
	 * @param array<string,mixed>|null $set       Values.
	 * @param string                   $changeset Change set id (marker of a created post).
	 * @param bool                     $undo      Undo: only the site's own previous values stay as they were (TextGuard).
	 * @return int Id written.
	 * @throws \RuntimeException When WordPress refuses.
	 */
	public static function write( string $kind, int $id, ?array $set, string $changeset, bool $undo = false ): int {
		if ( ProductCreate::KIND === $kind ) {
			return ProductCreate::write( $id, $set, $changeset );
		}
		if ( 'product_text' === $kind ) {
			$product = self::text_product( $id );
			if ( null === $product ) {
				throw new \RuntimeException( 'Product not found.' );
			}
			if ( ! $undo ) {
				TextGuard::remember( $id, $changeset, $kind, array_intersect_key( self::text_values( $product ), (array) $set ) );
			}
			foreach ( (array) $set as $field => $value ) {
				$value = TextGuard::value( (string) $value, 'name' !== $field, $undo, $id, $changeset, $kind . ':' . $field );
				if ( 'name' === $field ) {
					$product->set_name( $value );
				} elseif ( 'description' === $field ) {
					$product->set_description( $value );
				} else {
					$product->set_short_description( $value );
				}
			}
			$product->save();
			clean_post_cache( $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
			return $id;
		}//end if
		if ( 'product_meta' === $kind ) {
			foreach ( (array) $set as $key => $value ) {
				if ( null === $value ) {
					delete_post_meta( $id, (string) $key );
				} else {
					update_post_meta( $id, (string) $key, wp_slash( (string) $value ) );
				}
			}
			clean_post_cache( $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
			return $id;
		}
		if ( 'media_text' === $kind ) {
			$post  = array( 'ID' => $id );
			$map   = array(
				'title'       => 'post_title',
				'caption'     => 'post_excerpt',
				'description' => 'post_content',
			);
			$media = self::attachment( $id );
			if ( ! $undo && null !== $media ) {
				TextGuard::remember( $id, $changeset, $kind, array_intersect_key( self::media_values( $media ), (array) $set ) );
			}
			foreach ( (array) $set as $field => $value ) {
				$value = TextGuard::value( (string) $value, in_array( $field, array( 'caption', 'description' ), true ), $undo, $id, $changeset, $kind . ':' . $field );
				if ( 'alt' === $field ) {
					update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $value ) );
				} else {
					$post[ $map[ $field ] ] = $value;
				}
			}
			if ( count( $post ) > 1 ) {
				$result = wp_update_post( wp_slash( $post ), true );
				if ( is_wp_error( $result ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as data, never printed raw.
					throw new \RuntimeException( $result->get_error_message() );
				}
			}
			clean_post_cache( $id );
			return $id;
		}//end if
		if ( null === $set ) {
			if ( ! wp_trash_post( $id ) ) {
				throw new \RuntimeException( 'WordPress did not move the post to the trash.' );
			}
			return $id;
		}
		$post   = array(
			'post_type'    => (string) $set['type'],
			'post_title'   => TextGuard::html( (string) $set['title'] ),
			'post_content' => TextGuard::html( (string) ( $set['content'] ?? '' ) ),
			'post_excerpt' => TextGuard::html( (string) ( $set['excerpt'] ?? '' ) ),
			'post_status'  => (string) $set['status'],
			'post_author'  => get_current_user_id(),
		);
		$result = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as data, never printed raw.
			throw new \RuntimeException( $result->get_error_message() );
		}
		$id = (int) $result;
		update_post_meta( $id, ProductTerms::CREATED_META, $changeset );
		if ( 'post' === $set['type'] ) {
			if ( isset( $set['categories'] ) ) {
				wp_set_object_terms( $id, array_map( 'intval', (array) $set['categories'] ), 'category' );
			}
			if ( isset( $set['tags'] ) ) {
				wp_set_object_terms( $id, array_map( 'intval', (array) $set['tags'] ), 'post_tag' );
			}
		}
		clean_post_cache( $id );
		return $id;
	}

	/**
	 * Short values for the approvals log.
	 *
	 * @param mixed $values Values.
	 * @return mixed
	 */
	public static function for_log( $values ) {
		if ( ! is_array( $values ) ) {
			return $values;
		}
		foreach ( $values as $field => $value ) {
			if ( is_string( $value ) && mb_strlen( $value ) > 120 ) {
				$values[ $field ] = mb_substr( wp_strip_all_tags( $value ), 0, 117 ) . '…';
			}
		}
		return $values;
	}

	/**
	 * Text with Windows line ends as WordPress stores them.
	 *
	 * @param string $text Text.
	 */
	private static function norm( string $text ): string {
		return str_replace( "\r\n", "\n", $text );
	}

	/**
	 * A unit that is not there.
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
			'values' => array(),
		);
	}
}
