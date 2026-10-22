<?php
/**
 * Read tool describe_site_design: how this site looks and what the assistant can change.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Abilities\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only map: theme, block-theme styles / templates / patterns, classic Customizer / widgets / Additional CSS
 * (ClassicSite), Elementor (ElementorSite) — plus `tools`: which tool changes what on THIS site.
 */
final class DescribeDesign {

	public const SECTIONS = array( 'all', 'styles', 'templates', 'patterns', 'customizer', 'widgets', 'css', 'elementor' );

	/**
	 * Primitive entry point.
	 *
	 * @param mixed $input Input.
	 * @return array<string,mixed>
	 */
	public function execute( $input = null ): array {
		$input   = is_array( $input ) ? $input : array();
		$section = in_array( $input['section'] ?? 'all', self::SECTIONS, true ) ? (string) ( $input['section'] ?? 'all' ) : 'all';
		$want    = static fn( string $s ): bool => 'all' === $section || $s === $section;
		$block   = wp_is_block_theme();
		$theme   = wp_get_theme();
		$out     = array(
			'theme' => array(
				'name'        => (string) $theme->get( 'Name' ),
				'stylesheet'  => get_stylesheet(),
				'parent'      => $theme->parent() ? (string) $theme->parent()->get( 'Name' ) : '',
				'block_theme' => $block,
				'version'     => (string) $theme->get( 'Version' ),
			),
		);
		if ( $block && $want( 'styles' ) ) {
			$out['styles'] = self::styles();
		}
		if ( $block && $want( 'templates' ) ) {
			$out['templates']      = self::templates( 'wp_template' );
			$out['template_parts'] = self::templates( 'wp_template_part' );
			$out['navigation']     = self::navigation();
		}
		if ( $want( 'patterns' ) ) {
			$out['patterns'] = self::patterns();
		}
		if ( ! $block && ( $want( 'customizer' ) || $want( 'widgets' ) || $want( 'css' ) ) && class_exists( ClassicSite::class ) ) {
			$out += ClassicSite::describe( $section );
		}
		if ( $block && $want( 'css' ) && class_exists( ClassicSite::class ) ) {
			$out += ClassicSite::describe( 'css' );
		}
		if ( $want( 'elementor' ) && class_exists( ElementorSite::class ) ) {
			$elementor = ElementorSite::describe();
			if ( $elementor ) {
				$out['elementor'] = $elementor;
			}
		}
		$out['tools'] = self::tools( $block, ! empty( $out['elementor']['active'] ) );
		$out['_data'] = Shared::DATA_NOTICE;
		return $out;
	}

	/**
	 * Which tool changes what here.
	 *
	 * @param bool $block     Block theme.
	 * @param bool $elementor Elementor active.
	 * @return array<string,string>
	 */
	private static function tools( bool $block, bool $elementor ): array {
		$tools = array();
		if ( $block ) {
			$tools['site_colors_fonts_spacing'] = 'plan_global_styles (palette, typography, spacing, elements, per-block styles, style variations, site CSS)';
			$tools['header_footer_templates']   = 'describe_blocks + plan_blocks_edit with template_part (header, footer…) or template (single-product, archive-product, page…)';
		} else {
			$tools['site_colors_fonts_header_footer'] = 'plan_theme_settings (Customizer settings listed in `customizer`, logo, site title, widgets, Additional CSS)';
		}
		$tools['page_content']            = 'describe_blocks + plan_blocks_edit (block pages)' . ( $elementor ? '; plan_elementor_edit (Elementor pages and templates)' : '' );
		$tools['site_title_tagline_logo'] = 'plan_theme_settings (blogname, blogdescription, custom_logo, site_icon)';
		if ( $elementor ) {
			$tools['elementor_global_colors_fonts'] = 'plan_elementor_kit';
		}
		$tools['menus']       = 'describe_menus + plan_menu_update';
		$tools['last_resort'] = 'Additional CSS through plan_theme_settings (add_css with a label), only when no setting or block attribute does it';
		return $tools;
	}

	/**
	 * Global styles summary.
	 *
	 * @return array<string,mixed>
	 */
	private static function styles(): array {
		$settings = wp_get_global_settings();
		$palette  = array();
		foreach ( (array) ( $settings['color']['palette'] ?? array() ) as $origin => $list ) {
			foreach ( (array) $list as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$palette[] = array(
						'slug'   => (string) $entry['slug'],
						'name'   => (string) ( $entry['name'] ?? '' ),
						'color'  => (string) ( $entry['color'] ?? '' ),
						'origin' => (string) $origin,
					);
				}
			}
		}
		$sizes = array();
		foreach ( (array) ( $settings['typography']['fontSizes'] ?? array() ) as $origin => $list ) {
			foreach ( (array) $list as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$sizes[] = array(
						'slug' => (string) $entry['slug'],
						'size' => is_scalar( $entry['size'] ?? null ) ? (string) $entry['size'] : '',
					);
				}
			}
		}
		$spacing = array();
		foreach ( (array) ( $settings['spacing']['spacingSizes'] ?? array() ) as $list ) {
			foreach ( (array) $list as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$spacing[] = array(
						'slug' => (string) $entry['slug'],
						'size' => is_scalar( $entry['size'] ?? null ) ? (string) $entry['size'] : '',
					);
				}
			}
		}
		$user = GlobalStyles::current();
		$flat = array();
		$walk = static function ( $value, string $path ) use ( &$walk, &$flat ): void {
			if ( count( $flat ) >= 80 ) {
				return;
			}
			if ( is_array( $value ) && ! wp_is_numeric_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$walk( $item, '' === $path ? (string) $key : $path . '.' . $key );
				}
				return;
			}
			$json          = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
			$flat[ $path ] = mb_strlen( $json ) > 160 ? mb_substr( $json, 0, 159 ) . '…' : $json;
		};
		$walk( array_intersect_key( $user, array_flip( array( 'styles', 'settings' ) ) ), '' );
		$variations = array();
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			if ( ! empty( $variation['title'] ) ) {
				$variations[] = (string) $variation['title'];
			}
		}
		return array(
			'palette'           => array_slice( $palette, 0, 60 ),
			'font_families'     => array_slice( GlobalStyles::font_families(), 0, 30 ),
			'font_sizes'        => array_slice( $sizes, 0, 20 ),
			'spacing_sizes'     => array_slice( $spacing, 0, 20 ),
			'layout'            => array(
				'content_size' => (string) ( $settings['layout']['contentSize'] ?? '' ),
				'wide_size'    => (string) ( $settings['layout']['wideSize'] ?? '' ),
			),
			'customised'        => $flat,
			'style_variations'  => array_slice( array_values( array_unique( $variations ) ), 0, 30 ),
			'current_variation' => (string) ( $user['title'] ?? '' ),
		);
	}

	/**
	 * Templates or template parts.
	 *
	 * @param string $type wp_template|wp_template_part.
	 * @return array<int,array<string,mixed>>
	 */
	private static function templates( string $type ): array {
		$out = array();
		foreach ( get_block_templates( array(), $type ) as $tpl ) {
			$row = array(
				'slug'   => (string) $tpl->slug,
				'title'  => is_string( $tpl->title ) ? html_entity_decode( $tpl->title, ENT_QUOTES, 'UTF-8' ) : (string) $tpl->slug,
				'source' => (string) $tpl->source,
			);
			if ( 'wp_template_part' === $type ) {
				$row['area'] = (string) $tpl->area;
			}
			$out[] = $row;
		}
		return array_slice( $out, 0, 80 );
	}

	/**
	 * Navigation menus of a block theme.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function navigation(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'numberposts' => 20,
				'post_status' => 'publish',
			)
		) as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return $out;
	}

	/**
	 * Registered patterns (theme first) and synced patterns.
	 *
	 * @return array<string,mixed>
	 */
	private static function patterns(): array {
		$theme = get_stylesheet();
		$all   = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
		usort(
			$all,
			static function ( $a, $b ) use ( $theme ) {
				return (int) ( 0 !== strpos( (string) $a['name'], $theme . '/' ) ) <=> (int) ( 0 !== strpos( (string) $b['name'], $theme . '/' ) );
			}
		);
		$registered = array();
		foreach ( $all as $pattern ) {
			if ( ! empty( $pattern['inserter'] ) || ! isset( $pattern['inserter'] ) ) {
				$registered[] = array(
					'name'       => (string) $pattern['name'],
					'title'      => (string) ( $pattern['title'] ?? '' ),
					'categories' => array_slice( (array) ( $pattern['categories'] ?? array() ), 0, 3 ),
				);
			}
			if ( count( $registered ) >= 120 ) {
				break;
			}
		}
		$synced = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wp_block',
				'numberposts' => 50,
				'post_status' => 'publish',
			)
		) as $post ) {
			$synced[] = array(
				'id'    => (int) $post->ID,
				'title' => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return array(
			'registered' => $registered,
			'synced'     => $synced,
		);
	}
}
