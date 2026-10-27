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
 *  - the logo / site icon (`site_logo`, `site_icon`, the `custom_logo` theme mod),
 *  - the file's URL (any size: name, -WxH, -scaled, extension) in post content, post meta, theme mods and widgets.
 * Revisions, attachments' own meta, the renderer caches of Elementor and this plugin's own options and meta (its logs keep the
 * source links and file names — 1.3.7 refused every Undo because of them) are not uses.
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
		$mods = get_theme_mods();
		foreach ( array( get_option( 'site_logo' ), get_option( 'site_icon' ), is_array( $mods ) ? ( $mods['custom_logo'] ?? 0 ) : 0 ) as $used ) {
			if ( is_numeric( $used ) && (int) $used === $id ) {
				return true;
			}
		}
		// Widgets (block widgets keep block markup) and theme mods (header / background images by URL).
		$widgets = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value REGEXP %s",
				$wpdb->esc_like( 'widget_' ) . '%',
				$ids_re
			)
		);
		if ( $widgets > 0 ) {
			return true;
		}
		$base = self::file_stem( $id );
		if ( '' !== $base ) {
			// The file's URL, exactly: «/<name>.<ext>» or a size of it («/<name>-300x200.jpg», «-scaled»), never «/<name>-1.jpg».
			$like    = '%' . $wpdb->esc_like( $base ) . '%';
			$file_re = '/' . preg_quote( $base, '' ) . '(-[0-9]+x[0-9]+)?(-scaled)?[.](jpe?g|png|gif|webp|avif|svg)';
			$in      = implode( ', ', array_fill( 0, count( self::CACHE_KEYS ), '%s' ) );
			$posts   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID <> %d AND post_type NOT IN ('revision', 'attachment') AND post_content LIKE %s AND post_content REGEXP %s", $id, $like, $file_re ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only placeholders (one per CACHE_KEYS).
			$meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.post_id <> %d AND p.post_type NOT IN ('revision', 'attachment') AND m.meta_key NOT LIKE %s AND m.meta_key NOT LIKE %s AND m.meta_key NOT LIKE %s AND m.meta_key NOT IN ( {$in} ) AND m.meta_value LIKE %s AND m.meta_value REGEXP %s", array_merge( array( $id, $wpdb->esc_like( '_wp_attach' ) . '%', $wpdb->esc_like( '_addsynapse' ) . '%', $wpdb->esc_like( '_aiops' ) . '%' ), self::CACHE_KEYS, array( $like, $file_re ) ) ) );
			$opts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value LIKE %s AND option_value REGEXP %s", $wpdb->esc_like( 'theme_mods_' ) . '%', $wpdb->esc_like( 'widget_' ) . '%', $like, $file_re ) );
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
