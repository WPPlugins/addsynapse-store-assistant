<?php
/**
 * Shared logic of the SEO adapters: variable validation and a fallback renderer.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The render() method resolves the common variables itself (title, sep, sitename, sitedesc,
 * excerpt, category, currentyear…) — the plugins' replacers are unreliable outside the front end —
 * and asks the plugin's own replacer only for other variables (dropped when it cannot) — good
 * enough for length checks, never stored.
 */
abstract class AbstractSeoAdapter implements SeoAdapterInterface {

	/**
	 * Regex of one variable token; group 1 = the variable name.
	 */
	abstract protected function token_pattern(): string;

	/**
	 * Variable names this plugin knows (lower case, without arguments).
	 *
	 * @return array<int,string>
	 */
	abstract protected function known_variables(): array;

	/**
	 * Title separator character of the plugin settings.
	 */
	abstract protected function separator(): string;

	/**
	 * The plugin's own renderer (null when it is not available or failed).
	 *
	 * @param string   $text Text.
	 * @param \WP_Post $post The product's / page's / post's post.
	 */
	abstract protected function native_render( string $text, \WP_Post $post ): ?string;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $text Text.
	 */
	public function has_variables( string $text ): bool {
		return 1 === preg_match( $this->token_pattern(), $text );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	public function unknown_variables( string $text ): array {
		if ( ! preg_match_all( $this->token_pattern(), $text, $matches ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches[1] as $index => $name ) {
			if ( ! in_array( strtolower( (string) $name ), $this->known_variables(), true ) ) {
				$out[] = (string) $matches[0][ $index ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $text    Text.
	 * @param \WC_Product|\WP_Post $subject Product or page/post.
	 * @throws \InvalidArgumentException When it is neither a product nor a post.
	 */
	public function render( string $text, $subject ): string {
		if ( ! ( $subject instanceof \WC_Product || $subject instanceof \WP_Post ) ) {
			throw new \InvalidArgumentException( 'Expected a WC_Product or a WP_Post.' );
		}
		if ( '' === $text || ! $this->has_variables( $text ) ) {
			return self::clean( $text );
		}
		$values = $subject instanceof \WP_Post ? $this->post_values( $subject ) : $this->fallback_values( $subject );
		// Common variables are resolved here (the plugins' own replacers drop them in REST/CLI/cron
		// contexts, e.g. Rank Math returns '' for %sep% %sitename% outside the front end). Only
		// other variables go to the plugin's replacer, when it gives a clean result.
		preg_match_all( $this->token_pattern(), $text, $tokens );
		$other = array_diff( array_map( 'strtolower', $tokens[1] ), array_keys( $values ) );
		$post  = $subject instanceof \WP_Post ? $subject : get_post( $subject->get_id() );
		if ( $other && $post ) {
			$native = $this->native_render( $text, $post );
			if ( null !== $native && '' !== trim( self::clean( $native ) ) && ! $this->has_variables( $native ) ) {
				return self::clean( $native );
			}
		}
		$out = preg_replace_callback(
			$this->token_pattern(),
			static function ( $token ) use ( $values ) {
				return $values[ strtolower( (string) $token[1] ) ] ?? '';
			},
			$text
		);
		return self::clean( (string) $out );
	}

	/**
	 * Values of the common variables for the fallback renderer.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,string>
	 */
	protected function fallback_values( \WC_Product $product ): array {
		$name     = self::clean( (string) $product->get_name( 'edit' ) );
		$short    = self::clean( (string) $product->get_short_description( 'edit' ) );
		$excerpt  = '' !== $short ? $short : self::clean( (string) $product->get_description( 'edit' ) );
		$excerpt  = mb_strlen( $excerpt ) > 156 ? rtrim( mb_substr( $excerpt, 0, 156 ) ) : $excerpt;
		$terms    = get_the_terms( $product->get_id(), 'product_cat' );
		$category = is_array( $terms ) && $terms ? html_entity_decode( (string) $terms[0]->name, ENT_QUOTES, 'UTF-8' ) : '';
		$keyword  = $this->focus_primary( self::meta( $product, $this->meta_key( 'focus_keyword' ) ) );
		return array(
			'title'            => $name,
			'sep'              => $this->separator(),
			'sitename'         => self::clean( (string) get_bloginfo( 'name' ) ),
			'sitedesc'         => self::clean( (string) get_bloginfo( 'description' ) ),
			'excerpt'          => $excerpt,
			'excerpt_only'     => $short,
			'wc_shortdesc'     => $short,
			'category'         => $category,
			'primary_category' => $category,
			'categories'       => $category,
			'focuskw'          => $keyword,
			'currentyear'      => (string) wp_date( 'Y' ),
			'currentmonth'     => (string) wp_date( 'F' ),
			'wc_sku'           => (string) $product->get_sku( 'edit' ),
			'page'             => '',
			'pagenumber'       => '',
			'pagetotal'        => '',
		);
	}

	/**
	 * Values of the common variables for a page or post.
	 *
	 * @param \WP_Post $post Page or post.
	 * @return array<string,string>
	 */
	protected function post_values( \WP_Post $post ): array {
		$excerpt  = self::clean( (string) $post->post_excerpt );
		$excerpt  = '' !== $excerpt ? $excerpt : self::clean( (string) $post->post_content );
		$excerpt  = mb_strlen( $excerpt ) > 156 ? rtrim( mb_substr( $excerpt, 0, 156 ) ) : $excerpt;
		$terms    = 'post' === $post->post_type ? get_the_terms( $post->ID, 'category' ) : false;
		$category = is_array( $terms ) && $terms ? html_entity_decode( (string) $terms[0]->name, ENT_QUOTES, 'UTF-8' ) : '';
		$key      = $this->meta_key( 'focus_keyword' );
		$keyword  = '' !== $key ? $this->focus_primary( self::post_meta( (int) $post->ID, $key ) ) : '';
		return array(
			'title'            => self::clean( (string) $post->post_title ),
			'sep'              => $this->separator(),
			'sitename'         => self::clean( (string) get_bloginfo( 'name' ) ),
			'sitedesc'         => self::clean( (string) get_bloginfo( 'description' ) ),
			'excerpt'          => $excerpt,
			'excerpt_only'     => self::clean( (string) $post->post_excerpt ),
			'category'         => $category,
			'primary_category' => $category,
			'categories'       => $category,
			'focuskw'          => $keyword,
			'currentyear'      => (string) wp_date( 'Y' ),
			'currentmonth'     => (string) wp_date( 'F' ),
			'page'             => '',
			'pagenumber'       => '',
			'pagetotal'        => '',
		);
	}

	/**
	 * One raw meta value of a page/post (null = absent, several rows or not a plain value).
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 */
	public static function post_meta( int $post_id, string $key ): ?string {
		if ( '' === $key || ! metadata_exists( 'post', $post_id, $key ) ) {
			return null;
		}
		$rows = get_post_meta( $post_id, $key, false );
		return 1 === count( $rows ) && is_scalar( $rows[0] ) ? (string) $rows[0] : null;
	}

	/**
	 * One raw meta value of a product (null = absent or not a plain value).
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Meta key.
	 */
	protected static function meta( \WC_Product $product, string $key ): ?string {
		if ( '' === $key ) {
			return null;
		}
		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( (string) $data['key'] === $key && is_scalar( $data['value'] ) ) {
				return (string) $data['value'];
			}
		}
		return null;
	}

	/**
	 * Plain single-line text (tags stripped, entities decoded, whitespace collapsed).
	 *
	 * @param string $text Text.
	 */
	public static function clean( string $text ): string {
		// Block ends / line breaks become spaces so words of adjacent paragraphs do not merge.
		$text = (string) preg_replace( '#<(?:br|/p|/li|/h[1-6]|/div|/td|/th|/tr|/blockquote)\b[^>]*>#i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
