<?php
/**
 * No supported SEO plugin: only the product's own texts (short description / description).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing SEO-specific can be stored: no guessing of another plugin's keys. `$plugin` names
 * an active but unsupported SEO plugin (All in One SEO, SEOPress…) for the explanation.
 */
final class NoneAdapter extends AbstractSeoAdapter {

	/**
	 * Unsupported SEO plugin that is active ('' = none).
	 *
	 * @var string
	 */
	private string $plugin;

	/**
	 * Constructor.
	 *
	 * @param string $plugin Unsupported active SEO plugin name.
	 */
	public function __construct( string $plugin = '' ) {
		$this->plugin = $plugin;
	}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return '' !== $this->plugin ? $this->plugin . ' (unsupported)' : 'none';
	}

	/**
	 * Unsupported active SEO plugin ('' = none).
	 */
	public function unsupported_plugin(): string {
		return $this->plugin;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_seo(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $part Part.
	 */
	public function meta_key( string $part ): string {
		unset( $part );
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $part      Part.
	 * @param string $post_type Post type.
	 */
	public function template( string $part, string $post_type = 'product' ): string {
		unset( $part, $post_type );
		return '';
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
		return '/(?!)(.)/';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 */
	protected function known_variables(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function separator(): string {
		return '-';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $text Text.
	 * @param \WP_Post $post Post.
	 */
	protected function native_render( string $text, \WP_Post $post ): ?string {
		unset( $text, $post );
		return null;
	}
}
