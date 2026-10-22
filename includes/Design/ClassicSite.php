<?php
/**
 * Classic-theme sections of describe_site_design: Customizer settings, widget areas, Additional CSS.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * What a classic theme lets the manager change, as the theme registered it in the Customizer (ids for
 * plan_theme_settings), plus widgets and the assistant's Additional CSS blocks.
 */
final class ClassicSite {

	public const MAX_SETTINGS = 150;

	/**
	 * Words that put a setting first (what managers ask for most).
	 */
	private const FIRST = '/logo|title|tagline|header|footer|color|colour|typography|font|button|background|menu|nav|site_icon|blogname|blogdescription|copyright|credit/i';

	/**
	 * Section(s) of the site design map.
	 *
	 * @param string $section all|customizer|widgets|css.
	 * @return array<string,mixed>
	 */
	public static function describe( string $section = 'all' ): array {
		$out = array();
		if ( in_array( $section, array( 'all', 'customizer' ), true ) && current_user_can( 'edit_theme_options' ) ) {
			$out['customizer'] = self::settings();
		}
		if ( in_array( $section, array( 'all', 'widgets' ), true ) && current_user_can( 'edit_theme_options' ) && ! wp_is_block_theme() ) {
			$out['widgets'] = self::widgets();
		}
		if ( in_array( $section, array( 'all', 'css' ), true ) ) {
			$out['css'] = self::css();
		}
		return $out;
	}

	/**
	 * Customizer settings with their controls.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$manager = Customizer::manager();
		$rows    = array();
		foreach ( $manager->controls() as $control ) {
			$setting = $control->settings['default'] ?? null;
			if ( ! $setting instanceof \WP_Customize_Setting || ! Customizer::usable( $setting ) || isset( $rows[ $setting->id ] ) || ! $setting->check_capabilities() ) {
				continue;
			}
			$section = $manager->get_section( (string) $control->section );
			$value   = $setting->value();
			$json    = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );
			$row     = array(
				'id'      => (string) $setting->id,
				'label'   => html_entity_decode( wp_strip_all_tags( (string) $control->label ), ENT_QUOTES, 'UTF-8' ),
				'section' => $section ? html_entity_decode( wp_strip_all_tags( (string) $section->title ), ENT_QUOTES, 'UTF-8' ) : (string) $control->section,
				'type'    => (string) $control->type,
				'value'   => mb_strlen( $json ) > 160 ? mb_substr( $json, 0, 159 ) . '…' : $json,
			);
			if ( is_array( $control->choices ) && $control->choices && count( $control->choices ) <= 20 ) {
				$row['choices'] = array_map( static fn( $c ) => is_scalar( $c ) ? wp_strip_all_tags( (string) $c ) : '', $control->choices );
			}
			if ( '' === $row['label'] ) {
				unset( $row['label'] );
			}
			$rows[ $setting->id ] = $row;
		}//end foreach
		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) {
				$fa = preg_match( self::FIRST, $a['id'] . ' ' . ( $a['label'] ?? '' ) . ' ' . $a['section'] ) ? 0 : 1;
				$fb = preg_match( self::FIRST, $b['id'] . ' ' . ( $b['label'] ?? '' ) . ' ' . $b['section'] ) ? 0 : 1;
				return $fa <=> $fb;
			}
		);
		$known = array_column( $rows, 'id' );
		$extra = array();
		foreach ( Customizer::THEME_OPTIONS as $option ) {
			$values = array_replace( Customizer::theme_option_defaults( $option ), is_array( get_option( $option ) ) ? get_option( $option ) : array() );
			if ( ! $values ) {
				continue;
			}
			foreach ( $values as $key => $value ) {
				$id = $option . '[' . $key . ']';
				if ( in_array( $id, $known, true ) || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/', (string) $key ) || ! preg_match( self::FIRST, (string) $key ) ) {
					continue;
				}
				$json    = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );
				$extra[] = array(
					'id'    => $id,
					'value' => mb_strlen( $json ) > 160 ? mb_substr( $json, 0, 159 ) . '…' : $json,
				);
				if ( count( $extra ) >= 120 ) {
					break 2;
				}
			}
		}
		return array(
			'theme_options' => $extra,
			'settings'      => array_slice( $rows, 0, self::MAX_SETTINGS ),
			'count'         => count( $rows ),
			'truncated'     => count( $rows ) > self::MAX_SETTINGS,
			'note'          => 'Change any of these with plan_theme_settings {op: set, setting: id, value}. Image settings (custom_logo, site_icon) take a media library image id (find_media). theme_options are stored theme option keys (no label): set them with the same id; an object value changes only the keys given and keeps its shape (e.g. {"desktop": {"background-color": "#112233"}}).',
		);
	}

	/**
	 * Widget areas and their widgets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function widgets(): array {
		$sidebars = (array) get_option( 'sidebars_widgets', array() );
		$blocks   = (array) get_option( 'widget_block', array() );
		$out      = array();
		foreach ( ThemeSettings::sidebars() as $id => $name ) {
			$list = array();
			foreach ( (array) ( $sidebars[ $id ] ?? array() ) as $widget ) {
				$widget = (string) $widget;
				$row    = array(
					'id'   => $widget,
					'type' => (string) preg_replace( '/-\d+$/', '', $widget ),
				);
				if ( preg_match( '/^block-(\d+)$/', $widget, $m ) ) {
					$row['text'] = DesignText::excerpt( (string) ( $blocks[ (int) $m[1] ]['content'] ?? '' ), 120 );
				} else {
					$base     = (string) preg_replace( '/-\d+$/', '', $widget );
					$instance = (array) get_option( 'widget_' . $base, array() );
					$number   = (int) substr( $widget, strlen( $base ) + 1 );
					if ( isset( $instance[ $number ]['title'] ) && is_string( $instance[ $number ]['title'] ) ) {
						$row['text'] = DesignText::excerpt( $instance[ $number ]['title'], 120 );
					}
				}
				$list[] = $row;
			}
			$out[] = array(
				'id'      => $id,
				'name'    => $name,
				'widgets' => $list,
			);
		}//end foreach
		return $out;
	}

	/**
	 * The assistant's Additional CSS blocks.
	 *
	 * @return array<string,mixed>
	 */
	public static function css(): array {
		$post   = wp_get_custom_css_post();
		$raw    = $post ? (string) $post->post_content : '';
		$blocks = array();
		$own    = 0;
		foreach ( ThemeSettings::css_blocks( $raw ) as $slug => $block ) {
			$blocks[] = array(
				'slug'  => $slug,
				'label' => $block['label'],
				'bytes' => strlen( $block['css'] ),
				'css'   => mb_substr( $block['css'], 0, 400 ),
			);
			$own     += $block['end'] - $block['start'];
		}
		return array(
			'assistant_blocks' => $blocks,
			'other_css_bytes'  => max( 0, strlen( $raw ) - $own ),
			'note'             => 'Additional CSS is the last resort (plan_theme_settings add_css / replace_css / remove_css with a label); prefer theme settings, site styles or block attributes.',
		);
	}
}
