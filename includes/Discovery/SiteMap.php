<?php
/**
 * The «site map»: how a WordPress site WITHOUT WooCommerce is built (pages, builders, menus,
 * SEO plugin, languages). The describe-store answer and the summary source on plain sites.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Discovery;

use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\Adapters\Content\ContentAdapters;
use Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapters;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use Addsynapse\StoreAssistant\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only and bounded: counts via wp_count_posts, at most MAX_PAGES pages listed (newest
 * modified first, builder detected per page), menus/locations from the menu adapter. Nothing
 * about products (there are none) and never user data. Built on demand (cheap); the summary
 * that uses it is cached by StoreSummary.
 */
final class SiteMap {

	public const MAX_PAGES = 30;

	/**
	 * Builds the map.
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		$theme   = wp_get_theme();
		$adapter = SeoAdapters::detect();
		$menus   = MenuAdapters::current();
		$pages   = self::pages();
		return array(
			'generated_at' => gmdate( 'c' ),
			'site_type'    => Environment::site_type(),
			'woocommerce'  => Environment::has_woocommerce(),
			'site'         => array(
				'name'           => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'content_locale' => (string) get_locale(),
				'languages'      => self::languages(),
				'timezone'       => wp_timezone_string(),
			),
			'theme'        => array(
				'name' => html_entity_decode( (string) $theme->get( 'Name' ), ENT_QUOTES, 'UTF-8' ),
				'type' => MenuAdapters::is_block_theme() ? 'block' : 'classic',
			),
			'content'      => array(
				'pages'      => self::counts( 'page' ),
				'posts'      => self::counts( 'post' ),
				'front_page' => self::front_page(),
				'posts_page' => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_for_posts' ) > 0 ? (int) get_option( 'page_for_posts' ) : null,
			),
			'pages'        => $pages,
			'builders'     => self::builders( $pages ),
			'seo_plugin'   => array(
				'key'       => $adapter['adapter']->key(),
				'label'     => $adapter['adapter']->label(),
				'supported' => $adapter['adapter']->supports_seo(),
				'others'    => $adapter['others'],
			),
			'menus'        => array(
				'storage'   => $menus->kind(),
				'locations' => $menus->locations(),
				'menus'     => $menus->menus(),
			),
			'plugins'      => StoreMap::plugins(),
			'notes'        => array(
				'This is a WordPress site without WooCommerce: there are no products, orders, stock or prices.',
				'Pages built with an unsupported builder cannot be edited from the chat (builder needs_human).',
			),
			'_data'        => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Published / draft / private counts of a post type.
	 *
	 * @param string $type Post type.
	 * @return array<string,int>
	 */
	public static function counts( string $type ): array {
		$counts = wp_count_posts( $type );
		return array(
			'publish' => (int) ( $counts->publish ?? 0 ),
			'draft'   => (int) ( $counts->draft ?? 0 ),
			'private' => (int) ( $counts->private ?? 0 ),
		);
	}

	/**
	 * The static front page ({id, title}) or null (latest posts).
	 *
	 * @return array{id:int,title:string}|null
	 */
	public static function front_page(): ?array {
		$id   = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		return $post ? array(
			'id'    => (int) $post->ID,
			'title' => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
		) : null;
	}

	/**
	 * Newest modified pages with their builder.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function pages(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => self::MAX_PAGES,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		) as $post ) {
			$detected = ContentAdapters::detect( $post );
			$out[]    = array(
				'id'      => (int) $post->ID,
				'title'   => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'status'  => (string) $post->post_status,
				'builder' => $detected['adapter'] ? $detected['builder'] : 'needs_human (' . $detected['builder'] . ')',
			);
		}
		return $out;
	}

	/**
	 * Page builders among the listed pages: builder → count.
	 *
	 * @param array<int,array<string,mixed>> $pages Listed pages.
	 * @return array<string,int>
	 */
	private static function builders( array $pages ): array {
		$out = array();
		foreach ( $pages as $page ) {
			$key         = (string) $page['builder'];
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
		}
		return $out;
	}

	/**
	 * Content languages: the site locale, plus a multilingual plugin's languages when present.
	 *
	 * @return array<int,string>
	 */
	public static function languages(): array {
		$out = array( (string) get_locale() );
		if ( function_exists( 'pll_languages_list' ) ) {
			$out = array_merge( $out, array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'locale' ) ) ) );
		}
		$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API filter.
		if ( is_array( $wpml ) ) {
			foreach ( $wpml as $language ) {
				if ( is_array( $language ) && ! empty( $language['default_locale'] ) ) {
					$out[] = (string) $language['default_locale'];
				}
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}
}
