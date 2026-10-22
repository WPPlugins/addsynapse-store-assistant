<?php
/**
 * Elementor section of describe_site_design.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Adapters\Content\ElementorAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor active / Pro, the kit's global colors and fonts, Theme Builder templates (Elementor Pro headers / footers /
 * singles / archives, or the Elementor Header & Footer Builder plugin) and how many documents are built with it.
 * Empty when Elementor is not active.
 */
final class ElementorSite {

	/**
	 * Theme Builder template types.
	 */
	public const THEME_TYPES = array( 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'product', 'product-archive', 'loop-item', 'popup' );


	/**
	 * Section(s) of the site design map.
	 *
	 * @param string $section Section.
	 * @return array<string,mixed>
	 */
	public static function describe( string $section = 'all' ): array {
		unset( $section );
		if ( ! ElementorAdapter::active() ) {
			return array();
		}
		$kit      = ElementorKit::kit_id();
		$settings = $kit ? ElementorKit::settings( $kit ) : array();
		$colors   = array();
		foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
			foreach ( ElementorKit::global_list( $settings, $group ) as $item ) {
				if ( is_array( $item ) && isset( $item['_id'] ) ) {
					$colors[] = array(
						'id'    => (string) $item['_id'],
						'title' => (string) ( $item['title'] ?? '' ),
						'color' => (string) ( $item['color'] ?? '' ),
					);
				}
			}
		}
		$fonts = array();
		foreach ( array( 'system_typography', 'custom_typography' ) as $group ) {
			foreach ( ElementorKit::global_list( $settings, $group ) as $item ) {
				if ( is_array( $item ) && isset( $item['_id'] ) ) {
					$fonts[] = array(
						'id'     => (string) $item['_id'],
						'title'  => (string) ( $item['title'] ?? '' ),
						'family' => (string) ( $item['typography_font_family'] ?? '' ),
						'weight' => (string) ( $item['typography_font_weight'] ?? '' ),
					);
				}
			}
		}
		$theme = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'elementor_library',
				'numberposts' => 40,
				'post_status' => 'publish',
				'meta_key'    => '_elementor_template_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => self::THEME_TYPES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $post ) {
			$theme[] = array(
				'id'         => (int) $post->ID,
				'title'      => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'type'       => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
				'conditions' => array_slice( array_map( 'strval', (array) get_post_meta( $post->ID, '_elementor_conditions', true ) ), 0, 10 ),
			);
		}
		if ( post_type_exists( 'elementor-hf' ) ) {
			foreach ( get_posts(
				array(
					'post_type'   => 'elementor-hf',
					'numberposts' => 20,
					'post_status' => 'publish',
				)
			) as $post ) {
				$theme[] = array(
					'id'         => (int) $post->ID,
					'title'      => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'type'       => (string) get_post_meta( $post->ID, 'ehf_template_type', true ),
					'conditions' => array(),
				);
			}
		}
		$built = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return array(
			'active'        => true,
			'pro'           => did_action( 'elementor_pro/init' ) > 0, // Elementor Pro (the page builder's own add-on) is loaded.
			'version'       => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'kit_id'        => $kit,
			'colors'        => $colors,
			'fonts'         => $fonts,
			'theme_builder' => $theme,
			'pages_built'   => (int) $built->found_posts,
			'note'          => $theme ? 'Header / footer / single templates are Elementor documents: describe_blocks + plan_elementor_edit with page_id = their id (shown on every page they apply to).' : 'No Elementor Theme Builder templates: the header and footer come from the theme (plan_theme_settings for its options).',
		);
	}
}
