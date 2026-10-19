<?php
/**
 * Picks the content adapter of a page, or reports that a human is needed.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Detection order: Elementor (edit mode "builder") → other known builders (WPBakery, Divi, Beaver,
 * Oxygen, Bricks, Brizy, SiteOrigin, Thrive…) → `needs_human` → block markup → Gutenberg →
 * classic HTML → `needs_human`. Never a guess: an unknown / unsupported builder is reported, not edited.
 */
final class ContentAdapters {

	/**
	 * Adapter by kind.
	 *
	 * @param string $kind gutenberg|elementor.
	 */
	public static function for_kind( string $kind ): ?ContentAdapterInterface {
		switch ( $kind ) {
			case GutenbergAdapter::KIND:
				return new GutenbergAdapter();
			case ElementorAdapter::KIND:
				return new ElementorAdapter();
		}
		return null;
	}

	/**
	 * Detection result for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{builder:string,adapter:?ContentAdapterInterface,reason:string}
	 */
	public static function detect( \WP_Post $post ): array {
		$id      = (int) $post->ID;
		$content = (string) $post->post_content;
		if ( ElementorAdapter::built_with( $id ) ) {
			$adapter = new ElementorAdapter();
			if ( $adapter->detect( $id ) ) {
				return self::result( ElementorAdapter::KIND, $adapter, '' );
			}
			return self::result( 'elementor', null, 'The page is built with Elementor, but Elementor is not active.' );
		}
		$other = self::other_builder( $id, $content );
		if ( '' !== $other ) {
			return self::result( $other, null, sprintf( 'The page is built with %s, which is not supported: a developer or the manager must edit it.', $other ) );
		}
		$gutenberg = new GutenbergAdapter();
		if ( $gutenberg->detect( $id ) ) {
			return self::result( GutenbergAdapter::KIND, $gutenberg, '' );
		}
		if ( '' === trim( $content ) ) {
			return self::result( 'empty', null, 'The page has no content of its own (the theme or a plugin renders it).' );
		}
		return self::result( 'classic', null, 'The page uses the classic editor (plain HTML/shortcodes), which is not supported: edit it in WordPress.' );
	}

	/**
	 * Known unsupported builders by their footprint ('' = none).
	 *
	 * @param int    $id      Post id.
	 * @param string $content Content.
	 */
	public static function other_builder( int $id, string $content ): string {
		$meta = array(
			'_wpb_vc_js_status'              => 'WPBakery',
			'_et_pb_use_builder'             => 'Divi',
			'_fl_builder_enabled'            => 'Beaver Builder',
			'ct_builder_shortcodes'          => 'Oxygen',
			'_bricks_page_content_2'         => 'Bricks',
			'brizy'                          => 'Brizy',
			'panels_data'                    => 'SiteOrigin Page Builder',
			'tcb_editor_enabled'             => 'Thrive Architect',
			'_themify_builder_settings_json' => 'Themify Builder',
		);
		foreach ( $meta as $key => $name ) {
			$value = get_post_meta( $id, $key, true );
			if ( '' !== $value && null !== $value && false !== $value && 'false' !== $value && 'off' !== $value ) {
				return $name;
			}
		}
		$shortcodes = array(
			'[vc_'     => 'WPBakery',
			'[et_pb_'  => 'Divi',
			'[fusion_' => 'Avada Fusion Builder',
			'[cs_'     => 'Cornerstone',
			'[mk_'     => 'Jupiter',
			'[tatsu_'  => 'Tatsu',
			'[fl_'     => 'Beaver Builder',
		);
		foreach ( $shortcodes as $needle => $name ) {
			if ( false !== strpos( $content, $needle ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Result shape.
	 *
	 * @param string                       $builder Builder key/name.
	 * @param ContentAdapterInterface|null $adapter Adapter.
	 * @param string                       $reason  Why a human is needed.
	 * @return array{builder:string,adapter:?ContentAdapterInterface,reason:string}
	 */
	private static function result( string $builder, ?ContentAdapterInterface $adapter, string $reason ): array {
		return array(
			'builder' => $builder,
			'adapter' => $adapter,
			'reason'  => $reason,
		);
	}
}
