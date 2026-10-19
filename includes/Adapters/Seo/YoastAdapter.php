<?php
/**
 * Yoast SEO: _yoast_wpseo_title / _yoast_wpseo_metadesc / _yoast_wpseo_focuskw.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Templates come from the `wpseo_titles` option (title-product / metadesc-product).
 * Variables are `%%name%%`; one focus keyword (Premium's extra keywords live elsewhere and
 * are left alone).
 */
final class YoastAdapter extends AbstractSeoAdapter {

	public const KEYS = array(
		'title'         => '_yoast_wpseo_title',
		'description'   => '_yoast_wpseo_metadesc',
		'focus_keyword' => '_yoast_wpseo_focuskw',
	);

	private const VARIABLES = array( 'title', 'sep', 'sitename', 'sitedesc', 'excerpt', 'excerpt_only', 'category', 'primary_category', 'tag', 'focuskw', 'currentyear', 'currentmonth', 'currentday', 'currentdate', 'currenttime', 'date', 'modified', 'id', 'name', 'page', 'pagenumber', 'pagetotal', 'pt_single', 'pt_plural', 'term_title', 'wc_price', 'wc_sku', 'wc_shortdesc', 'wc_brand', 'permalink' );

	private const SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Yoast SEO';
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
		$options = (array) get_option( 'wpseo_titles', array() );
		$type    = sanitize_key( $post_type );
		if ( 'title' === $part ) {
			return (string) ( $options[ 'title-' . $type ] ?? '%%title%% %%page%% %%sep%% %%sitename%%' );
		}
		return 'description' === $part ? (string) ( $options[ 'metadesc-' . $type ] ?? '' ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $current Current.
	 * @param string      $keyword Keyword.
	 */
	public function focus_after( ?string $current, string $keyword ): string {
		unset( $current );
		return $keyword;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $value Value.
	 */
	public function focus_primary( ?string $value ): string {
		return trim( (string) $value );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function token_pattern(): string {
		return '/%%([a-z0-9_]+)(?:\([^%()]*\))?%%/i';
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
		$options = (array) get_option( 'wpseo_titles', array() );
		return self::SEPARATORS[ (string) ( $options['separator'] ?? 'sc-dash' ) ] ?? '-';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $text Text.
	 * @param \WP_Post $post Post.
	 */
	protected function native_render( string $text, \WP_Post $post ): ?string {
		if ( ! function_exists( 'wpseo_replace_vars' ) ) {
			return null;
		}
		try {
			return (string) wpseo_replace_vars( $text, $post );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}
}
