<?php
/**
 * Is a media-library image referenced anywhere on the site? (cleanup safety check)
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Conservative, bounded read-only checks — a false «yes» only keeps an image longer, a false
 * «no» would delete media the site uses, so every doubt counts as «referenced»:
 *  - featured image of any post/product/variation (`_thumbnail_id`),
 *  - product galleries (`_product_image_gallery`),
 *  - block markup (`wp-image-ID`, `"id":ID`, `"mediaId":ID`) in any post content,
 *  - Elementor data (`"id":ID` / `"id":"ID"` in `_elementor_data`),
 *  - the file's unique base name (URLs of every size) in any post content, post meta or option.
 * Revisions and the renderer caches of Elementor are ignored (they are copies, rebuilt on demand).
 */
final class ImageUsage {

	/**
	 * Post meta keys that only cache a render (a stale copy is not a use).
	 */
	private const CACHE_KEYS = array( '_elementor_element_cache', '_elementor_page_assets', '_elementor_css' );

	/**
	 * Whether the attachment is used by any content of the site.
	 *
	 * @param int $id Attachment id.
	 */
	public static function referenced( int $id ): bool {
		global $wpdb;
		if ( $id < 1 ) {
			return false;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only safety checks, must not be cached.
		$thumb = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s AND m.post_id <> %d AND p.post_type <> 'revision'",
				(string) $id,
				$id
			)
		);
		if ( $thumb > 0 ) {
			return true;
		}
		$gallery = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND CONCAT(',', meta_value, ',') LIKE %s",
				'%,' . $id . ',%'
			)
		);
		if ( $gallery > 0 ) {
			return true;
		}
		$ids_re = '(wp-image-' . $id . '([^0-9]|$))|("(id|mediaId)":"?' . $id . '([^0-9]|$))';
		$blocks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_content REGEXP %s",
				$ids_re
			)
		);
		if ( $blocks > 0 ) {
			return true;
		}
		$elementor = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND m.meta_value REGEXP %s",
				'"id":"?' . $id . '([^0-9]|$)'
			)
		);
		if ( $elementor > 0 ) {
			return true;
		}
		$base = self::file_stem( $id );
		if ( '' !== $base ) {
			$like  = '%' . $wpdb->esc_like( $base ) . '%';
			$in    = implode( ', ', array_fill( 0, count( self::CACHE_KEYS ), '%s' ) );
			$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID <> %d AND post_type <> 'revision' AND post_content LIKE %s", $id, $like ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only placeholders (one per CACHE_KEYS).
			$meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.post_id <> %d AND p.post_type <> 'revision' AND m.meta_key NOT LIKE %s AND m.meta_key NOT IN ( {$in} ) AND m.meta_value LIKE %s", array_merge( array( $id, $wpdb->esc_like( '_wp_attach' ) . '%' ), self::CACHE_KEYS, array( $like ) ) ) );
			$opts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value LIKE %s", $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', $like ) );
			if ( $posts + $meta + $opts > 0 ) {
				return true;
			}
		}
		// phpcs:enable
		return false;
	}

	/**
	 * The file's unique base name without extension/size suffix ('' when too generic to search).
	 *
	 * @param int $id Attachment id.
	 */
	public static function file_stem( int $id ): string {
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$stem = (string) pathinfo( wp_basename( $file ), PATHINFO_FILENAME );
		$stem = (string) preg_replace( '/-scaled$/', '', $stem );
		// Our uploads are addsynapse-assistant-<16 hex>: unique. Shorter names could match unrelated files.
		return strlen( $stem ) >= 12 ? $stem : '';
	}
}
