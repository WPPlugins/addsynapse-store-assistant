<?php
/**
 * The Customizer settings of the active theme / plugins, read without opening the Customizer.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * A local WP_Customize_Manager (never the global $wp_customize, no preview, no changeset): the `customize_register`
 * hooks of core, the theme and plugins register their settings and controls on it, so the assistant knows every option
 * the theme offers, with its label, section, control type, choices, sanitizer and validator. Values are read and written
 * at their real storage (a theme mod or an option, nested keys of array options such as `astra-settings[…]`), never
 * through the Customizer's save.
 */
final class Customizer {

	/**
	 * Settings handled elsewhere (menus, widgets, Additional CSS) or too risky (front page switch).
	 */
	private const SKIP = '/^(nav_menu|nav_menus_created_posts|nav_menu_locations|widget_|sidebars_widgets|custom_css|show_on_front|page_on_front|page_for_posts|active_theme|old_sidebars_widgets_data|changeset)/';

	/**
	 * Marker of a missing stored value.
	 */
	public const MISSING = array( '__missing' => true );

	/**
	 * Manager of this request.
	 *
	 * @var \WP_Customize_Manager|null
	 */
	private static $manager = null;

	/**
	 * Stylesheet the manager was built for.
	 *
	 * @var string
	 */
	private static string $built_for = '';

	/**
	 * The local manager with every setting registered.
	 */
	public static function manager(): \WP_Customize_Manager {
		if ( null !== self::$manager && get_stylesheet() === self::$built_for ) {
			return self::$manager;
		}
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$components = static fn() => array();
		add_filter( 'customize_loaded_components', $components, PHP_INT_MAX );
		$manager    = new \WP_Customize_Manager();
		remove_filter( 'customize_loaded_components', $components, PHP_INT_MAX );
		// The constructor hooks the manager for a real Customizer request; keep only what registers settings.
		foreach ( array( 'setup_theme', 'wp_loaded' ) as $hook ) {
			remove_action( $hook, array( $manager, $hook ) );
		}
		remove_filter( 'heartbeat_settings', array( $manager, 'add_customize_screen_to_heartbeat_settings' ) );
		remove_filter( 'heartbeat_received', array( $manager, 'check_changeset_lock_with_heartbeat' ), 10 );
		if ( ! has_action( 'init', 'wp_cron' ) && ! did_action( 'init' ) ) {
			add_action( 'init', 'wp_cron' );
		}
		// In a real Customizer request the manager exists before the theme loads, so core's controls are registered
		// before the theme's customize_register callbacks (Storefront moves core controls): keep that order.
		remove_action( 'customize_register', array( $manager, 'register_controls' ) );
		$manager->register_controls();
		self::theme_compat();
		// Some themes (Astra) register controls through the global manager: lend ours for the registration only.
		global $wp_customize;
		$previous     = $wp_customize;
		$wp_customize = $manager; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored right below.
		try {
			do_action( 'customize_register', $manager ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook, on a local manager.
		} catch ( \Throwable $e ) {
			// A theme callback failed outside a real Customizer: keep what was registered (core settings first).
			self::$error = $e->getMessage();
		} finally {
			$wp_customize = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
		}
		remove_action( 'customize_register', array( $manager, 'register_dynamic_settings' ), 11 );
		self::$manager   = $manager;
		self::$built_for = get_stylesheet();
		return $manager;
	}

	/**
	 * Themes that register their Customizer options only inside the Customizer / wp-admin (Astra: not on REST
	 * requests, where the chat runs): load their option definitions the way the Customizer does.
	 */
	private static function theme_compat(): void {
		if ( class_exists( '\\Astra_Customizer' ) && method_exists( '\\Astra_Customizer', 'get_instance' ) ) {
			$astra = \Astra_Customizer::get_instance();
			if ( ! has_action( 'customize_register', array( $astra, 'include_configurations' ) ) && method_exists( $astra, 'include_configurations' ) ) {
				add_action( 'customize_register', array( $astra, 'include_configurations' ), 1 );
			}
		}
	}

	/**
	 * Last theme error while registering (empty = none).
	 *
	 * @var string
	 */
	public static string $error = '';

	/**
	 * Forgets the manager (tests, theme switch).
	 */
	public static function reset(): void {
		self::$manager   = null;
		self::$built_for = '';
	}

	/**
	 * Whether a setting may be changed by the assistant.
	 *
	 * @param \WP_Customize_Setting $setting Setting.
	 */
	public static function usable( \WP_Customize_Setting $setting ): bool {
		return in_array( $setting->type, array( 'theme_mod', 'option' ), true ) && ! preg_match( self::SKIP, (string) $setting->id )
			&& ! $setting instanceof \WP_Customize_Filter_Setting;
	}

	/**
	 * Option arrays in which themes keep their settings (keys usable even when the theme registers its Customizer
	 * controls only inside the Customizer, as Astra does on REST requests).
	 */
	public const THEME_OPTIONS = array( 'astra-settings', 'generate_settings' );

	/**
	 * A raw key of a theme option array: {where, current} or null (the top key must already exist).
	 *
	 * @param string $id Id like astra-settings[header-bg-obj-responsive][desktop].
	 * @return array{where:array<string,mixed>,current:mixed}|null
	 */
	public static function theme_option( string $id ): ?array {
		if ( ! preg_match( '/^([a-z_-]+)((?:\[[A-Za-z0-9_-]{1,100}\]){1,4})$/', $id, $m ) || ! in_array( $m[1], self::THEME_OPTIONS, true ) ) {
			return null;
		}
		$root = get_option( $m[1] );
		preg_match_all( '/\[([A-Za-z0-9_-]+)\]/', $m[2], $k );
		$keys     = $k[1];
		$defaults = self::theme_option_defaults( $m[1] );
		if ( ( ! is_array( $root ) || ! array_key_exists( $keys[0], $root ) ) && ! array_key_exists( $keys[0], $defaults ) ) {
			return null;
		}
		$where   = array(
			'type'       => 'option',
			'base'       => $m[1],
			'keys'       => $keys,
			'stylesheet' => get_stylesheet(),
			'capability' => 'edit_theme_options',
		);
		$current = self::read( $where );
		$shape   = self::MISSING === $current ? self::dig( $defaults, $keys ) : $current;
		return self::MISSING === $shape ? null : array(
			'where'   => $where,
			'current' => $current,
			'shape'   => $shape,
		);
	}

	/**
	 * Defaults of a theme option array (keys the theme knows even before they are saved).
	 *
	 * @param string $option Option.
	 * @return array<string,mixed>
	 */
	public static function theme_option_defaults( string $option ): array {
		$defaults = array();
		if ( 'astra-settings' === $option && class_exists( '\\Astra_Theme_Options' ) && method_exists( '\\Astra_Theme_Options', 'defaults' ) ) {
			$defaults = \Astra_Theme_Options::defaults();
		} elseif ( 'generate_settings' === $option && function_exists( 'generate_get_defaults' ) ) {
			$defaults = generate_get_defaults();
		}
		return is_array( $defaults ) ? $defaults : array();
	}

	/**
	 * Why a value does not fit the shape of the current value of a raw theme option ('' = fits).
	 *
	 * @param mixed  $current Current.
	 * @param mixed  $value   New.
	 * @param string $path    Path.
	 */
	public static function shape_problem( $current, $value, string $path = 'value' ): string {
		if ( is_array( $current ) ) {
			if ( ! is_array( $value ) ) {
				return $path . ' must be an object shaped like the current value.';
			}
			foreach ( $value as $key => $item ) {
				if ( ! array_key_exists( $key, $current ) ) {
					return $path . '.' . $key . ' is not a key of this option.';
				}
				$problem = self::shape_problem( $current[ $key ], $item, $path . '.' . $key );
				if ( '' !== $problem ) {
					return $problem;
				}
			}
			return '';
		}
		if ( is_bool( $current ) ) {
			return is_bool( $value ) ? '' : $path . ' must be true or false.';
		}
		if ( is_int( $current ) || is_float( $current ) ) {
			return is_numeric( $value ) ? '' : $path . ' must be a number.';
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			return $path . ' must be a text.';
		}
		$value = (string) $value;
		if ( is_string( $current ) && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\(|hsla?\(|var\(--)/i', $current ) && '' !== $value ) {
			try {
				BlockAttrs::css_color( $value );
			} catch ( \InvalidArgumentException $e ) {
				return $path . ': ' . $e->getMessage();
			}
		}
		return strlen( $value ) > 2000 ? $path . ' is too long.' : '';
	}

	/**
	 * A usable setting by id (null = none).
	 *
	 * @param string $id Id.
	 */
	public static function setting( string $id ): ?\WP_Customize_Setting {
		$setting = self::manager()->get_setting( $id );
		return $setting instanceof \WP_Customize_Setting && self::usable( $setting ) ? $setting : null;
	}

	/**
	 * The control of a setting (label, section, type, choices).
	 *
	 * @param string $id Setting id.
	 */
	public static function control( string $id ): ?\WP_Customize_Control {
		foreach ( self::manager()->controls() as $control ) {
			foreach ( (array) $control->settings as $setting ) {
				if ( $setting instanceof \WP_Customize_Setting && $setting->id === $id ) {
					return $control;
				}
			}
		}
		return null;
	}

	/**
	 * Plain label of a setting: «Control label (Section)».
	 *
	 * @param string $id Setting id.
	 */
	public static function label( string $id ): string {
		$control = self::control( $id );
		$label   = $control && is_string( $control->label ) && '' !== trim( wp_strip_all_tags( $control->label ) ) ? wp_strip_all_tags( $control->label ) : $id;
		$section = $control ? self::manager()->get_section( (string) $control->section ) : null;
		$title   = $section && is_string( $section->title ) ? wp_strip_all_tags( $section->title ) : '';
		return trim( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) . ( '' !== $title ? ' (' . html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) . ')' : '' ) );
	}

	/**
	 * Where a setting is stored: {type, base, keys, stylesheet, capability}.
	 *
	 * @param \WP_Customize_Setting $setting Setting.
	 * @return array{type:string,base:string,keys:array<int,string>,stylesheet:string,capability:string}
	 */
	public static function storage( \WP_Customize_Setting $setting ): array {
		$data = $setting->id_data();
		return array(
			'type'       => (string) $setting->type,
			'base'       => (string) $data['base'],
			'keys'       => array_values( array_map( 'strval', (array) $data['keys'] ) ),
			'stylesheet' => get_stylesheet(),
			'capability' => (string) $setting->capability,
		);
	}

	/**
	 * Stored value at a storage location (MISSING when not stored).
	 *
	 * @param array<string,mixed> $where Storage.
	 * @return mixed
	 */
	public static function read( array $where ) {
		if ( 'theme_mod' === $where['type'] ) {
			$mods = get_option( 'theme_mods_' . $where['stylesheet'] );
			$root = is_array( $mods ) && array_key_exists( $where['base'], $mods ) ? $mods[ $where['base'] ] : self::MISSING;
		} else {
			$root = get_option( $where['base'], self::MISSING );
		}
		return self::dig( $root, (array) $where['keys'] );
	}

	/**
	 * Nested value (MISSING when absent).
	 *
	 * @param mixed             $root Root.
	 * @param array<int,string> $keys Keys.
	 * @return mixed
	 */
	public static function dig( $root, array $keys ) {
		foreach ( $keys as $key ) {
			if ( self::MISSING === $root || ! is_array( $root ) || ! array_key_exists( $key, $root ) ) {
				return self::MISSING;
			}
			$root = $root[ $key ];
		}
		return $root;
	}

	/**
	 * Root with a nested value set (MISSING removes it).
	 *
	 * @param mixed             $root  Root.
	 * @param array<int,string> $keys  Keys.
	 * @param mixed             $value Value.
	 * @return mixed
	 */
	public static function put( $root, array $keys, $value ) {
		if ( ! $keys ) {
			return $value;
		}
		$root = is_array( $root ) && self::MISSING !== $root ? $root : array();
		$key  = array_shift( $keys );
		if ( ! $keys && self::MISSING === $value ) {
			unset( $root[ $key ] );
			return $root;
		}
		$root[ $key ] = self::put( $root[ $key ] ?? array(), $keys, $value );
		return $root;
	}

	/**
	 * Writes a value at a storage location (only that key path).
	 *
	 * @param array<string,mixed> $where Storage.
	 * @param mixed               $value Value (MISSING = remove).
	 */
	public static function write( array $where, $value ): void {
		$keys = (array) $where['keys'];
		if ( 'theme_mod' === $where['type'] ) {
			$name = 'theme_mods_' . $where['stylesheet'];
			$mods = get_option( $name );
			$mods = is_array( $mods ) ? $mods : array();
			$root = array_key_exists( $where['base'], $mods ) ? $mods[ $where['base'] ] : self::MISSING;
			$root = self::put( $root, $keys, $value );
			if ( self::MISSING === $root ) {
				unset( $mods[ $where['base'] ] );
			} else {
				$mods[ $where['base'] ] = $root;
			}
			update_option( $name, $mods );
			return;
		}
		$root = get_option( $where['base'], self::MISSING );
		$root = self::put( $root, $keys, $value );
		if ( self::MISSING === $root ) {
			delete_option( $where['base'] );
		} else {
			update_option( $where['base'], $root );
		}
	}

	/**
	 * Equality of stored values (WordPress gives scalar options back as strings).
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 */
	public static function same( $a, $b ): bool {
		return wp_json_encode( self::norm( $a ) ) === wp_json_encode( self::norm( $b ) );
	}

	/**
	 * Normalised copy (scalars as strings, false as '').
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function norm( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'norm' ), $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return null === $value ? null : (string) $value;
	}
}
