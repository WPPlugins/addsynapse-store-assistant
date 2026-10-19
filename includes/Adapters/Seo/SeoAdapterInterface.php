<?php
/**
 * SEO plugin adapter: where THIS site keeps the SEO title, meta description and focus keyword
 * of a product, page or post, and how its templates/variables render.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Parts: `title`, `description`, `focus_keyword`. Values are the raw meta rows of the
 * product (null = absent, '' = empty); an absent/empty title or description falls back to
 * the plugin's product template (e.g. "%title% %sep% %sitename%"), which render() resolves.
 * Only the fixed keys of the adapter can ever be written (never the generic meta tool).
 */
interface SeoAdapterInterface {

	/**
	 * Stable key: rank_math | yoast | none.
	 */
	public function key(): string;

	/**
	 * Human name (e.g. "Rank Math").
	 */
	public function label(): string;

	/**
	 * Whether SEO title/description/keyword can be stored (false = no supported SEO plugin).
	 */
	public function supports_seo(): bool;

	/**
	 * Meta key of a part ('' when unsupported).
	 *
	 * @param string $part title|description|focus_keyword.
	 */
	public function meta_key( string $part ): string;

	/**
	 * The plugin's default template for a post type ('' = none): products, or pages/posts on
	 * any site (with or without WooCommerce).
	 *
	 * @param string $part      title|description.
	 * @param string $post_type product | page | post.
	 */
	public function template( string $part, string $post_type = 'product' ): string;

	/**
	 * Resolves the plugin's variables for a product, page or post (the text shown in search results).
	 *
	 * @param string               $text    Text with variables.
	 * @param \WC_Product|\WP_Post $subject Product (WooCommerce) or page/post.
	 */
	public function render( string $text, $subject ): string;

	/**
	 * Variable tokens in a text that this plugin does not know (empty = all fine).
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	public function unknown_variables( string $text ): array;

	/**
	 * Whether a text contains variables of this plugin.
	 *
	 * @param string $text Text.
	 */
	public function has_variables( string $text ): bool;

	/**
	 * Stored focus keyword after setting a new primary keyword (secondary keywords are kept
	 * where the plugin supports several).
	 *
	 * @param string|null $current Current stored value.
	 * @param string      $keyword New primary keyword.
	 */
	public function focus_after( ?string $current, string $keyword ): string;

	/**
	 * Primary focus keyword of a stored value ('' = none).
	 *
	 * @param string|null $value Stored value.
	 */
	public function focus_primary( ?string $value ): string;
}
