<?php
/**
 * The ONE place that decides which WordPress capability each part of the plugin needs.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Mapping (docs/03 → «Capabilities»):
 *
 * | Context      | WooCommerce active     | Plain WordPress site  | Used for                                      |
 * |--------------|------------------------|-----------------------|-----------------------------------------------|
 * | `base`       | manage_woocommerce     | edit_theme_options    | admin page, chat/REST, every ability          |
 * | `settings`   | manage_woocommerce     | manage_options        | Settings tab (AI key, images, voice)          |
 * | `pages`      | + edit_pages           | + edit_pages          | describe/plan page content, picker, preview   |
 * | `menus`      | + edit_theme_options   | + edit_theme_options  | plan/approve/undo menus                       |
 * | `uploads`    | + upload_files         | + upload_files        | images from the chat                          |
 * | `post_seo`   | + edit_post (each id)  | + edit_post (each id) | SEO of pages/posts (checked per object)       |
 * | overview()   | manage_woocommerce     | manage_options        | shared «AddSynapse» top-level + Overview      |
 *
 * `base` on a plain site is `edit_theme_options` (administrators by default): the assistant
 * spends the site's AI budget and edits the site's look, so it is not given to editors/authors.
 * The extra capabilities are checked ON TOP of `base` (never instead of it). Filter
 * `addsynapse_assistant_capability` ($cap, $context, $has_wc) lets a site owner widen/narrow a context.
 */
final class Capabilities {

	public const BASE     = 'base';
	public const SETTINGS = 'settings';

	/**
	 * Capability of a context.
	 *
	 * @param string $context base | settings.
	 */
	public static function resolve( string $context = self::BASE ): string {
		$has_wc = Environment::has_woocommerce();
		if ( $has_wc ) {
			$cap = 'manage_woocommerce';
		} else {
			$cap = self::SETTINGS === $context ? 'manage_options' : 'edit_theme_options';
		}
		$filtered = apply_filters( 'addsynapse_assistant_capability', $cap, $context, $has_wc );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : $cap;
	}

	/**
	 * Capability of the SHARED «AddSynapse» top-level menu + Overview page (docs/ADMIN-MENU.md). It is a
	 * cross-plugin contract — every AddSynapse plugin must compute exactly the same value (whichever
	 * plugin creates the menu wins) — so it is NOT filterable and ignores `addsynapse_assistant_capability`.
	 */
	public static function overview(): string {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Whether the current user has the capability of a context.
	 *
	 * @param string $context Context.
	 */
	public static function current_user_can( string $context = self::BASE ): bool {
		return current_user_can( self::resolve( $context ) );
	}
}
