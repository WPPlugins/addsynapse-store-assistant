<?php
/**
 * Read-only search of the media library's images (banner / image candidates).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Content;

use Addsynapse\StoreAssistant\Media\ImageUploads;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded queries on image attachments only. Sources: `product` (featured + first gallery images
 * of the given products), `search` (title / alt / file name LIKE every word), `recent` (newest).
 * With no criteria → recent. Files missing on disk are skipped (a banner would show a broken
 * image) and counted in `missing_files`.
 */
final class MediaFinder {

	public const MAX_LIMIT = 24;

	public const DEFAULT_LIMIT = 8;

	public const MAX_PRODUCTS = 20;

	/**
	 * Candidates scanned per query before the file check.
	 */
	private const SCAN = 200;

	/**
	 * Finds images.
	 *
	 * @param array<string,mixed> $input query, product_ids, recent, limit.
	 * @return array<string,mixed>
	 */
	public static function find( array $input ): array {
		$limit    = max( 1, min( self::MAX_LIMIT, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$query    = trim( (string) ( $input['query'] ?? '' ) );
		$products = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) ( $input['product_ids'] ?? array() ) ) ) ) ), 0, self::MAX_PRODUCTS );
		$recent   = ! empty( $input['recent'] ) || ( '' === $query && ! $products );
		$images   = array();
		$missing  = 0;
		$seen     = array();

		$add = static function ( int $id, string $source, array $extra = array() ) use ( &$images, &$missing, &$seen, $limit ): void {
			if ( isset( $seen[ $id ] ) || count( $images ) >= $limit ) {
				return;
			}
			$seen[ $id ] = true;
			$row         = self::row( $id, $source );
			if ( null === $row ) {
				return;
			}
			if ( ! $row['file_exists'] ) {
				++$missing;
				return;
			}
			unset( $row['file_exists'] );
			$images[] = $row + $extra;
		};

		foreach ( $products as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			if ( ! $product || 'trash' === $product->get_status() ) {
				continue;
			}
			$ids = array_merge( array( (int) $product->get_image_id() ), array_slice( array_map( 'intval', $product->get_gallery_image_ids() ), 0, 2 ) );
			foreach ( array_filter( $ids ) as $id ) {
				$add(
					$id,
					'product',
					array(
						'product_id'   => (int) $product->get_id(),
						'product_name' => mb_substr( html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ), 0, 120 ),
					)
				);
			}
		}
		if ( '' !== $query ) {
			foreach ( self::search( $query ) as $id ) {
				$add( $id, 'search' );
			}
		}
		if ( $recent ) {
			foreach ( self::recent() as $id ) {
				$add( $id, 'recent' );
			}
		}
		return array(
			'images'        => $images,
			'count'         => count( $images ),
			'missing_files' => $missing,
			'note'          => 'Only media library images can be used (by id); the manager can upload a new one with the paperclip in the chat.',
		);
	}

	/**
	 * Ids of images whose title, alt text or file name contains every word.
	 *
	 * @param string $query Words.
	 * @return array<int,int>
	 */
	private static function search( string $query ): array {
		global $wpdb;
		$words = array_slice( array_filter( preg_split( '/\s+/u', mb_substr( $query, 0, 100 ) ) ), 0, 5 );
		if ( ! $words ) {
			return array();
		}
		$args = array( $wpdb->posts, $wpdb->postmeta, $wpdb->postmeta, 'image/%' );
		foreach ( $words as $word ) {
			$like = '%' . $wpdb->esc_like( $word ) . '%';
			array_push( $args, $like, $like, $like );
		}
		$args[] = self::SCAN;
		// Every word in the title, alt text or file name: one fixed condition per word (tables %i, values %s).
		// Parenthesized: Plugin Check's DirectDB sniff otherwise reads past a statement that ends with a string.
		$sql = ( "SELECT p.ID FROM %i p LEFT JOIN %i a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt' LEFT JOIN %i f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND "
			. implode( ' AND ', array_fill( 0, count( $words ), '(p.post_title LIKE %s OR a.meta_value LIKE %s OR f.meta_value LIKE %s)' ) )
			. ' GROUP BY p.ID ORDER BY p.post_date DESC LIMIT %d' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- bounded read-only search; $sql holds only placeholders built above.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Newest image ids.
	 *
	 * @return array<int,int>
	 */
	private static function recent(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only list.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY post_date DESC, ID DESC LIMIT %d", 'image/%', self::SCAN ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * One image row (null = not an image attachment).
	 *
	 * @param int    $id     Attachment id.
	 * @param string $source product|search|recent.
	 * @return array<string,mixed>|null
	 */
	private static function row( int $id, string $source ): ?array {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) || '' !== ImageUploads::usable_problem( $id ) ) {
			return null;
		}
		$meta  = wp_get_attachment_metadata( $id );
		$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
		$large = wp_get_attachment_image_url( $id, 'large' );
		return array(
			'id'          => $id,
			'title'       => mb_substr( html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ), 0, 120 ),
			'alt'         => mb_substr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), 0, 250 ),
			'caption'     => mb_substr( wp_strip_all_tags( (string) $post->post_excerpt ), 0, 300 ),
			'filename'    => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
			'thumb'       => $thumb ? (string) $thumb : '',
			'url'         => $large ? (string) $large : (string) wp_get_attachment_url( $id ),
			'width'       => (int) ( is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0 ),
			'height'      => (int) ( is_array( $meta ) ? ( $meta['height'] ?? 0 ) : 0 ),
			'source'      => $source,
			// Uploaded in the chat and not used yet (deleted automatically if never used).
			'pending'     => null !== ImageUploads::pending( $id ),
			'file_exists' => self::image_file_exists( $id ),
		);
	}

	/**
	 * Whether an attachment's file is on disk (a missing file would show a broken image).
	 * Filter `addsynapse_assistant_media_file_exists` (tests).
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function image_file_exists( int $attachment_id ): bool {
		$file = get_attached_file( $attachment_id );
		return (bool) apply_filters( 'addsynapse_assistant_media_file_exists', is_string( $file ) && '' !== $file && file_exists( $file ), $attachment_id );
	}
}
