<?php
/**
 * Rank Math SEO: rank_math_title / rank_math_description / rank_math_focus_keyword.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Templates come from the `rank-math-options-titles` option (pt_product_title /
 * pt_product_description). Variables are `%name%` (e.g. %title% %sep% %sitename%); several
 * focus keywords are stored comma-separated, the first one is the primary keyword.
 */
final class RankMathAdapter extends AbstractSeoAdapter {

	public const KEYS = array(
		'title'         => 'rank_math_title',
		'description'   => 'rank_math_description',
		'focus_keyword' => 'rank_math_focus_keyword',
	);

	private const VARIABLES = array( 'title', 'sep', 'sitename', 'sitedesc', 'excerpt', 'excerpt_only', 'seo_title', 'seo_description', 'category', 'primary_category', 'categories', 'tag', 'tags', 'term', 'focuskw', 'keywords', 'currentyear', 'currentmonth', 'currentday', 'currentdate', 'currenttime', 'date', 'modified', 'id', 'url', 'name', 'page', 'pagenumber', 'pagetotal', 'org_name', 'org_url', 'wc_price', 'wc_sku', 'wc_shortdesc', 'wc_brand', 'customfield', 'customterm', 'count' );

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'rank_math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_seo(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $part Part.
	 */
	public function meta_key( string $part ): string {
		return self::KEYS[ $part ] ?? '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $part      Part.
	 * @param string $post_type Post type.
	 */
	public function template( string $part, string $post_type = 'product' ): string {
		$options = (array) get_option( 'rank-math-options-titles', array() );
		$type    = sanitize_key( $post_type );
		if ( 'title' === $part ) {
			return (string) ( $options[ 'pt_' . $type . '_title' ] ?? '%title% %sep% %sitename%' );
		}
		return 'description' === $part ? (string) ( $options[ 'pt_' . $type . '_description' ] ?? '%excerpt%' ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $current Current.
	 * @param string      $keyword Keyword.
	 */
	public function focus_after( ?string $current, string $keyword ): string {
		$keep = array( $keyword );
		foreach ( explode( ',', (string) $current ) as $other ) {
			$other = trim( $other );
			if ( '' !== $other && mb_strtolower( $other ) !== mb_strtolower( $keyword ) ) {
				$keep[] = $other;
			}
		}
		return implode( ',', $keep );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $value Value.
	 */
	public function focus_primary( ?string $value ): string {
		$parts = explode( ',', (string) $value );
		return trim( (string) $parts[0] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function token_pattern(): string {
		return '/%([a-z0-9_]+)(?:\([^%()]*\))?%/i';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 */
	protected function known_variables(): array {
		return self::VARIABLES;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function separator(): string {
		$options = (array) get_option( 'rank-math-options-titles', array() );
		return html_entity_decode( (string) ( $options['title_separator'] ?? '-' ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $text Text.
	 * @param \WP_Post $post Post.
	 */
	protected function native_render( string $text, \WP_Post $post ): ?string {
		if ( ! class_exists( '\RankMath\Helper' ) || ! method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
			return null;
		}
		try {
			return (string) \RankMath\Helper::replace_vars( $text, $post );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}
}
