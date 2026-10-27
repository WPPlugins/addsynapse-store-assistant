<?php
/**
 * Design units of the remote route: what read-state reads and apply-changes writes for appearance changes.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Adapters\Content\ElementorAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * One unit = one place of the site's look, identified by a string `key` (never a raw storage location sent by
 * AddSynapse: the site resolves the key itself, with the current user's permissions):
 *  - styles   key = active theme (stylesheet): the user layer of theme.json, values {raw: JSON|null} (null = no styles
 *             post; Undo of the first change deletes the post it created);
 *  - css      key = active theme: Additional CSS, values {css} (null = no CSS post; Undo of the first CSS deletes it);
 *  - setting  key = Customizer setting id (or a theme option key such as astra-settings[…]): values {value: JSON text};
 *  - widgets  key = widgets: the block widget options, values {value: JSON text of {sidebars_widgets, widget_block}};
 *  - doc      key = post:<id> (block content), elementor:<id> (Elementor data), wp_template:<slug> /
 *             wp_template_part:<slug> (the template WordPress uses now): values {raw} (+ {custom} for templates: false = the
 *             theme's / plugin's own file, so Undo of a first customisation deletes the customised copy);
 *  - kit      key = Elementor kit id: the Site Settings, values {raw: JSON|null}.
 * The planners (GlobalStyles, BlocksEdit, ThemeSettings, ElementorEdit, ElementorKit) compute the target and write nothing;
 * from_lines() turns their lines into units {kind, key, name, before, after, preview}.
 */
final class DesignUnits {

	public const KINDS = array( 'styles', 'css', 'setting', 'widgets', 'doc', 'kit' );

	/**
	 * Tags / attributes / URLs that count as active content (a user without «unfiltered_html» may not add any).
	 */
	private const ACTIVE = array(
		'/<\s*(script|style|iframe|frame|frameset|object|embed|applet|form|link|meta|base)\b/i',
		'/[\s"\'\/]on[a-z]+\s*=/i',
		'/(?:java|vb)script\s*:/i',
	);

	/**
	 * Whether a kind is a design unit.
	 *
	 * @param string $kind Kind.
	 */
	public static function handles( string $kind ): bool {
		return in_array( $kind, self::KINDS, true );
	}

	/**
	 * Current state of one unit.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 * @return array<string,mixed> {kind, id 0, key, exists, name, type, values} or {exists false, reason}.
	 */
	public static function read( string $kind, string $key ): array {
		try {
			$unit = self::read_unit( $kind, $key );
		} catch ( \InvalidArgumentException $e ) {
			return self::missing( $kind, $key, $e->getMessage() );
		}
		return array(
			'kind'   => $kind,
			'id'     => 0,
			'key'    => $key,
			'exists' => true,
			'name'   => $unit['name'],
			'type'   => $unit['type'],
			'values' => $unit['values'],
		);
	}

	/**
	 * Name, type and values of a unit.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 * @return array{name:string,type:string,values:array<string,mixed>}
	 * @throws \InvalidArgumentException When it cannot be read (or not by this user).
	 */
	private static function read_unit( string $kind, string $key ): array {
		switch ( $kind ) {
			case 'styles':
				self::active_theme( $key );
				if ( ! wp_is_block_theme() ) {
					throw new \InvalidArgumentException( 'The theme is not a block theme any more.' );
				}
				self::can( 'edit_theme_options' );
				$post = GlobalStyles::post();
				return array(
					'name'   => sprintf( 'Site styles (%s)', wp_get_theme()->get( 'Name' ) ),
					'type'   => 'global_styles',
					'values' => array( 'raw' => $post ? (string) $post['post_content'] : null ),
				);
			case 'css':
				self::active_theme( $key );
				self::can( 'edit_css' );
				$post = wp_get_custom_css_post( $key );
				return array(
					'name'   => 'Additional CSS',
					'type'   => 'custom_css',
					'values' => array( 'css' => $post ? (string) $post->post_content : null ),
				);
			case 'setting':
				if ( SiteLogo::KEY === $key ) {
					if ( ! current_user_can( 'edit_theme_options' ) ) {
						throw new \InvalidArgumentException( 'You are not allowed to change the logo.' );
					}
					return array(
						'name'   => 'Site logo',
						'type'   => 'theme_setting',
						'values' => array( 'value' => (string) wp_json_encode( SiteLogo::read() ) ),
					);
				}
				$where = self::setting_where( $key );
				return array(
					'name'   => Customizer::label( $key ),
					'type'   => 'theme_setting',
					'values' => array( 'value' => (string) wp_json_encode( Customizer::read( $where ) ) ),
				);
			case 'widgets':
				if ( 'widgets' !== $key ) {
					throw new \InvalidArgumentException( 'Unknown widgets key.' );
				}
				self::can( 'edit_theme_options' );
				return array(
					'name'   => 'Widgets',
					'type'   => 'widgets',
					'values' => array( 'value' => (string) wp_json_encode( self::widget_options() ) ),
				);
			case 'doc':
				$ref    = self::doc_ref( $key );
				$raw    = Docs::read( $ref );
				$values = array( 'raw' => $raw );
				if ( null === $raw ) {
					throw new \InvalidArgumentException( 'The content is gone.' );
				}
				if ( 'template' === $ref['kind'] ) {
					$values['custom'] = Docs::is_customised( $ref );
				}
				return array(
					'name'   => (string) $ref['title'],
					'type'   => (string) $ref['kind'],
					'values' => $values,
				);
			case 'kit':
				$kit = (int) $key;
				if ( $kit < 1 || ElementorKit::kit_id() !== $kit ) {
					throw new \InvalidArgumentException( 'This is not the active Elementor kit.' );
				}
				self::can( 'edit_theme_options' );
				if ( ! current_user_can( 'edit_post', $kit ) ) {
					throw new \InvalidArgumentException( 'You are not allowed to change the Elementor Site Settings.' );
				}
				return array(
					'name'   => 'Elementor Site Settings',
					'type'   => 'elementor_kit',
					'values' => array( 'raw' => ElementorKit::snapshot( $kit ) ),
				);
		}//end switch
		throw new \InvalidArgumentException( 'Unknown kind.' );
	}

	/**
	 * Whether the current values hold the expected ones (the comparison WordPress' storage needs).
	 *
	 * @param string              $kind   Kind.
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( string $kind, array $now, array $expect ): bool {
		if ( ! $expect ) {
			return false;
		}
		foreach ( $expect as $field => $value ) {
			if ( ! array_key_exists( $field, $now ) ) {
				return false;
			}
			$have = $now[ $field ];
			if ( 'value' === $field && in_array( $kind, array( 'setting', 'widgets' ), true ) ) {
				if ( ! is_string( $have ) || ! is_string( $value ) || ! Customizer::same( json_decode( $have, true ), json_decode( $value, true ) ) ) {
					return false;
				}
				continue;
			}
			if ( 'custom' === $field ) {
				if ( (bool) $have !== (bool) $value ) {
					return false;
				}
				continue;
			}
			if ( null === $have || null === $value ) {
				if ( $have !== $value ) {
					return false;
				}
				continue;
			}
			if ( ! is_string( $value ) || str_replace( "\r\n", "\n", (string) $have ) !== str_replace( "\r\n", "\n", $value ) ) {
				return false;
			}
		}//end foreach
		return true;
	}

	/**
	 * Why the values may not be written ('' = fine). Every write is checked again here, whatever planned it.
	 *
	 * @param string              $kind   Kind.
	 * @param string              $key    Key.
	 * @param array<string,mixed> $set    Values to write.
	 * @param array<string,mixed> $expect Values the unit holds now (expected).
	 */
	public static function invalid( string $kind, string $key, array $set, array $expect ): string {
		$fields = array(
			'styles'  => array( 'raw' ),
			'css'     => array( 'css' ),
			'setting' => array( 'value' ),
			'widgets' => array( 'value' ),
			'doc'     => array( 'raw', 'custom' ),
			'kit'     => array( 'raw' ),
		);
		foreach ( array_keys( $set ) as $field ) {
			if ( ! in_array( $field, $fields[ $kind ] ?? array(), true ) ) {
				return 'Field not allowed: ' . $field;
			}
		}
		$need = 'doc' === $kind ? array( 'raw' ) : $fields[ $kind ];
		foreach ( $need as $field ) {
			if ( ! array_key_exists( $field, $set ) ) {
				return 'Missing ' . $field;
			}
		}
		try {
			switch ( $kind ) {
				case 'styles':
					return self::invalid_styles( $set['raw'], $expect['raw'] ?? null );
				case 'css':
					if ( null === $set['css'] ) {
						return '';
					}
					if ( ! is_string( $set['css'] ) ) {
						return 'Invalid css';
					}
					if ( strlen( $set['css'] ) > ThemeSettings::MAX_CSS ) {
						return 'The Additional CSS would be too large.';
					}
					$problem = '' === trim( $set['css'] ) ? '' : CssText::problem( $set['css'] );
					return '' !== $problem ? 'css: ' . $problem : self::adds_active( (string) ( $expect['css'] ?? '' ), $set['css'] );
				case 'setting':
					return self::invalid_setting( $key, $set['value'] );
				case 'widgets':
					return self::invalid_widgets( $set['value'], (string) ( $expect['value'] ?? '' ) );
				case 'doc':
					return self::invalid_doc( $key, $set, (string) ( $expect['raw'] ?? '' ) );
				case 'kit':
					if ( null === $set['raw'] ) {
						return '';
					}
					if ( ! is_string( $set['raw'] ) || ! is_array( json_decode( $set['raw'], true ) ) ) {
						return 'Invalid Site Settings.';
					}
					return self::adds_active( (string) ( $expect['raw'] ?? '' ), $set['raw'] );
			}//end switch
		} catch ( \InvalidArgumentException $e ) {
			return $e->getMessage();
		}//end try
		return 'Unknown kind.';
	}

	/**
	 * Writes one unit (already checked: invalid() and holds() of the expected values).
	 *
	 * @param string              $kind Kind.
	 * @param string              $key  Key.
	 * @param array<string,mixed> $set  Values.
	 * @throws \RuntimeException When WordPress refuses or changes the write.
	 */
	public static function write( string $kind, string $key, array $set ): void {
		switch ( $kind ) {
			case 'styles':
				$post = GlobalStyles::post();
				if ( null === $set['raw'] ) {
					if ( $post ) {
						wp_delete_post( (int) $post['ID'], true );
					}
				} else {
					GlobalStyles::write( $post ? (int) $post['ID'] : 0, (string) $set['raw'] );
				}
				GlobalStyles::flush();
				return;
			case 'css':
				if ( null === $set['css'] ) {
					// Undo of the first Additional CSS: no CSS post, as before (WordPress' own «none» marker in the theme mod).
					$post = wp_get_custom_css_post( $key );
					if ( $post ) {
						wp_delete_post( (int) $post->ID, true );
					}
					set_theme_mod( 'custom_css_post_id', -1 );
					return;
				}
				ThemeSettings::write_css( $key, (string) $set['css'] );
				return;
			case 'setting':
				if ( SiteLogo::KEY === $key ) {
					SiteLogo::write( (array) json_decode( (string) $set['value'], true ) );
					return;
				}
				Customizer::write( self::setting_where( $key ), json_decode( (string) $set['value'], true ) );
				return;
			case 'widgets':
				foreach ( (array) json_decode( (string) $set['value'], true ) as $name => $value ) {
					if ( Customizer::MISSING === $value ) {
						delete_option( $name );
					} else {
						update_option( $name, $value );
					}
				}
				return;
			case 'doc':
				$ref = self::doc_ref( $key );
				if ( 'template' === $ref['kind'] && array_key_exists( 'custom', $set ) && ! $set['custom'] ) {
					if ( Docs::is_customised( $ref ) ) {
						Docs::revert_customisation( $ref );
					}
					if ( Docs::read( $ref ) !== (string) $set['raw'] ) {
						throw new \RuntimeException( 'The theme\'s own version of this template differs from the one before the change.' );
					}
					return;
				}
				Docs::write( $ref, (string) $set['raw'] );
				if ( 'elementor' === $ref['kind'] && in_array( (string) ( $ref['elementor_type'] ?? '' ), ElementorSite::THEME_TYPES, true ) ) {
					ElementorKit::clear_css();
				}
				return;
			case 'kit':
				$kit = (int) $key;
				if ( null === $set['raw'] ) {
					delete_post_meta( $kit, ElementorKit::META );
				} else {
					update_post_meta( $kit, ElementorKit::META, wp_slash( (array) json_decode( (string) $set['raw'], true ) ) );
				}
				if ( ElementorKit::snapshot( $kit ) !== $set['raw'] ) {
					throw new \RuntimeException( 'WordPress did not store the Site Settings as planned.' );
				}
				ElementorKit::clear_css();
				return;
		}//end switch
		throw new \RuntimeException( 'Unknown kind.' );
	}

	/**
	 * Units of a planner's lines: {kind, key, id 0, name, before, after, preview[{field, before, after}]}.
	 *
	 * @param array<int,array<string,mixed>> $lines Lines.
	 * @return array<int,array<string,mixed>>
	 */
	public static function from_lines( array $lines ): array {
		$units = array();
		foreach ( $lines as $line ) {
			$before = (array) ( $line['before'] ?? array() );
			$after  = (array) ( $line['after'] ?? array() );
			$name   = (string) ( $line['label'] ?? '' );
			switch ( $line['object_type'] ?? '' ) {
				case GlobalStyles::OBJECT:
					$unit = array(
						'kind'   => 'styles',
						'key'    => (string) $before['theme'],
						'before' => array( 'raw' => empty( $before['exists'] ) ? null : (string) $before['raw'] ),
						'after'  => array( 'raw' => (string) $after['raw'] ),
					);
					break;
				case BlocksEdit::OBJECT:
					$ref  = (array) $before['ref'];
					$unit = array(
						'kind'   => 'doc',
						'key'    => self::doc_key( $ref ),
						'before' => array( 'raw' => (string) $before['raw'] ),
						'after'  => array( 'raw' => (string) $after['raw'] ),
					);
					if ( 'template' === $ref['kind'] ) {
						$unit['before']['custom'] = (int) $ref['post_id'] > 0;
						$unit['after']['custom']  = true;
					}
					$unit['url'] = (string) ( $ref['url'] ?? '' );
					break;
				case ThemeSettings::OBJECT:
					$unit = self::theme_unit( $line, $before, $after );
					break;
				case ElementorKit::OBJECT:
					$unit = array(
						'kind'   => 'kit',
						'key'    => (string) (int) $before['kit'],
						'before' => array( 'raw' => empty( $before['exists'] ) ? null : (string) $before['raw'] ),
						'after'  => array( 'raw' => (string) $after['raw'] ),
					);
					break;
				default:
					continue 2;
			}//end switch
			$units[] = array(
				'kind' => $unit['kind'],
				'id'   => 0,
				'key'  => $unit['key'],
				'name' => $name,
			) + $unit + array( 'preview' => self::preview( $line ) );
		}//end foreach
		return $units;
	}

	/**
	 * Unit of a theme settings line.
	 *
	 * @param array<string,mixed> $line   Line.
	 * @param array<string,mixed> $before Before.
	 * @param array<string,mixed> $after  After.
	 * @return array<string,mixed>
	 */
	private static function theme_unit( array $line, array $before, array $after ): array {
		switch ( $line['field'] ?? '' ) {
			case ThemeSettings::CSS:
				return array(
					'kind'   => 'css',
					'key'    => (string) $before['stylesheet'],
					'before' => array( 'css' => wp_get_custom_css_post( (string) $before['stylesheet'] ) ? (string) $before['raw'] : null ),
					'after'  => array( 'css' => (string) $after['raw'] ),
				);
			case ThemeSettings::WIDGETS:
				return array(
					'kind'   => 'widgets',
					'key'    => 'widgets',
					'before' => array( 'value' => (string) wp_json_encode( $before['options'] ) ),
					'after'  => array( 'value' => (string) wp_json_encode( $after['options'] ) ),
				);
		}
		return array(
			'kind'   => 'setting',
			'key'    => (string) $before['id'],
			'before' => array( 'value' => (string) wp_json_encode( $before['value'] ) ),
			'after'  => array( 'value' => (string) wp_json_encode( $after['value'] ) ),
		);
	}

	/**
	 * Card rows of a line: [{field, before, after}] (plain text).
	 *
	 * @param array<string,mixed> $line Line.
	 * @return array<int,array<string,string>>
	 */
	public static function preview( array $line ): array {
		$names = array(
			'style'      => 'style',
			'text'       => 'text',
			'link'       => 'link',
			'image'      => 'image',
			'removed'    => 'removed',
			'moved'      => 'moved',
			'duplicated' => 'duplicated',
			'added'      => 'added',
			'replaced'   => 'replaced',
			'settings'   => 'setting',
			'css'        => 'CSS',
		);
		$ops   = (array) ( $line['after']['ops'] ?? array() );
		if ( ThemeSettings::SETTING === ( $line['field'] ?? '' ) ) {
			$image  = ! empty( $line['before']['image'] );
			$before = $line['before']['value'] ?? null;
			$after  = $line['after']['value'] ?? null;
			if ( ! empty( $line['before']['logo'] ) ) {
				$before = SiteLogo::image( $before ) > 0 ? SiteLogo::image( $before ) : null;
				$after  = SiteLogo::image( $after ) > 0 ? SiteLogo::image( $after ) : null;
			}
			$ops = array(
				array(
					'label'  => (string) ( $line['label'] ?? '' ),
					'field'  => 'settings',
					'before' => ThemeSettings::text( $before, $image ),
					'after'  => ThemeSettings::text( $after, $image ),
				),
			);
		}
		// Site-wide styles and Elementor kit rows name the setting itself; block / widget / CSS rows say what happened to it.
		$plain = in_array( $line['object_type'] ?? '', array( GlobalStyles::OBJECT, ElementorKit::OBJECT ), true ) || ThemeSettings::SETTING === ( $line['field'] ?? '' );
		$rows  = array();
		foreach ( $ops as $op ) {
			$field  = (string) ( $op['field'] ?? '' );
			$label  = (string) ( $op['label'] ?? '' );
			$before = self::plain( $op['before'] ?? '' );
			if ( ThemeSettings::SETTING === ( $line['field'] ?? '' ) ) {
				// The row's name is already the setting's label.
				$label = 'Theme setting';
			}
			$rows[] = array(
				'field'  => $plain ? $label : trim( $label . ' · ' . ( $names[ $field ] ?? $field ), ' ·' ),
				'before' => $plain && '' === $before ? '(theme default)' : $before,
				'after'  => self::plain( $op['after'] ?? '' ),
			);
		}
		return array_slice( $rows, 0, 60 );
	}

	/**
	 * Short form for the approval log.
	 *
	 * @param string $kind   Kind.
	 * @param mixed  $values Values.
	 * @return mixed
	 */
	public static function for_log( string $kind, $values ) {
		if ( ! is_array( $values ) ) {
			return $values;
		}
		$out = array();
		foreach ( $values as $field => $value ) {
			$out[ $field ] = is_string( $value ) && strlen( $value ) > 120 ? strlen( $value ) . ' bytes' : $value;
		}
		unset( $kind );
		return $out;
	}

	/**
	 * Key of a document reference.
	 *
	 * @param array<string,mixed> $ref Reference.
	 */
	public static function doc_key( array $ref ): string {
		if ( 'template' === ( $ref['kind'] ?? '' ) ) {
			return (string) $ref['template']['type'] . ':' . (string) $ref['template']['slug'];
		}
		return ( 'elementor' === ( $ref['kind'] ?? '' ) ? 'elementor:' : 'post:' ) . (int) ( $ref['post_id'] ?? 0 );
	}

	/**
	 * Document reference of a key (resolved now, with the current user's permissions).
	 *
	 * @param string $key Key.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When it cannot be resolved.
	 */
	private static function doc_ref( string $key ): array {
		if ( ! preg_match( '/^(post|elementor|wp_template|wp_template_part):([A-Za-z0-9_\/-]{1,200})$/', $key, $m ) ) {
			throw new \InvalidArgumentException( 'Unknown document.' );
		}
		if ( in_array( $m[1], array( 'wp_template', 'wp_template_part' ), true ) ) {
			$doc = Docs::template( $m[2], $m[1] );
			$is  = 'template';
		} else {
			$doc = ctype_digit( $m[2] ) ? Docs::post( (int) $m[2] ) : new \WP_Error( 'x', 'Unknown document.' );
			$is  = 'elementor' === $m[1] ? 'elementor' : 'blocks';
		}
		if ( is_wp_error( $doc ) ) {
			throw new \InvalidArgumentException( esc_html( $doc->get_error_message() ) );
		}
		if ( $is !== $doc['kind'] ) {
			throw new \InvalidArgumentException( 'This content is now edited differently (block editor / Elementor / template) than when the draft was made.' );
		}
		return Docs::ref( $doc );
	}

	/**
	 * Storage of a Customizer setting / theme option key, for the current user.
	 *
	 * @param string $key Setting id.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When unknown or not allowed.
	 */
	private static function setting_where( string $key ): array {
		$setting = Customizer::setting( $key );
		if ( null !== $setting ) {
			if ( ! $setting->check_capabilities() ) {
				throw new \InvalidArgumentException( 'You are not allowed to change this setting.' );
			}
			return Customizer::storage( $setting );
		}
		$raw = Customizer::theme_option( $key );
		if ( null === $raw ) {
			throw new \InvalidArgumentException( 'This setting does not exist in the active theme.' );
		}
		self::can( 'edit_theme_options' );
		return (array) $raw['where'];
	}

	/**
	 * A setting value that WordPress (the setting's validator / sanitizer) would store as it is.
	 *
	 * @param string $key   Setting id.
	 * @param mixed  $value JSON text.
	 */
	private static function invalid_setting( string $key, $value ): string {
		if ( ! is_string( $value ) ) {
			return 'Invalid value';
		}
		$data = json_decode( $value, true );
		if ( null === $data && 'null' !== trim( $value ) ) {
			return 'Invalid value';
		}
		if ( SiteLogo::KEY === $key ) {
			return SiteLogo::problem( $data );
		}
		self::setting_where( $key );
		if ( Customizer::MISSING === $data ) {
			return '';
		}
		$problem = BlockAttrs::value_problem( $data, 0, 'value' );
		if ( '' !== $problem ) {
			return $problem;
		}
		$setting = Customizer::setting( $key );
		if ( null === $setting ) {
			$raw = Customizer::theme_option( $key );
			return Customizer::shape_problem( $raw['shape'] ?? null, $data );
		}
		$current = Customizer::read( Customizer::storage( $setting ) );
		if ( is_array( $data ) && ! is_array( $setting->default ) && ! is_array( $current ) ) {
			return 'The value of "' . $key . '" is a single value, not a list.';
		}
		$validity = $setting->validate( $data );
		if ( is_wp_error( $validity ) ) {
			return wp_strip_all_tags( $validity->get_error_message() );
		}
		$clean = $setting->sanitize( $data );
		if ( is_wp_error( $clean ) || ! Customizer::same( $clean, $data ) ) {
			return 'The value is not valid for "' . $key . '".';
		}
		return '';
	}

	/**
	 * A theme.json user layer whose new / changed values are plain style values (CSS only under `css`, checked by CssText).
	 * Values the change does not touch (the theme's own palette, a style variation's values) are not judged again.
	 *
	 * @param mixed $raw    JSON (null = no styles post).
	 * @param mixed $expect JSON now.
	 */
	private static function invalid_styles( $raw, $expect ): string {
		if ( null === $raw ) {
			return '';
		}
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['isGlobalStylesUserThemeJSON'] ) ) {
			return 'Invalid styles.';
		}
		$now = is_string( $expect ) ? json_decode( $expect, true ) : null;
		foreach ( self::changed_leaves( $data, is_array( $now ) ? $now : array(), '' ) as $path => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$key = (string) substr( (string) strrchr( '.' . $path, '.' ), 1 );
			if ( 'css' === $key ) {
				$problem = '' === trim( $value ) ? '' : CssText::problem( $value );
				if ( '' !== $problem ) {
					return 'styles ' . $path . ': ' . $problem;
				}
				continue;
			}
			if ( preg_match( '/[<>{};\\\\]|expression\s*\(|javascript\s*:|@import|url\(\s*[\'"]?(?!https:\/\/)/i', $value ) ) {
				return 'styles ' . $path . ': not a plain style value.';
			}
		}
		return '';
	}

	/**
	 * Leaves of $new that are not the same in $old: path → value.
	 *
	 * @param mixed  $new  New.
	 * @param mixed  $old  Old.
	 * @param string $path Path so far.
	 * @return array<string,mixed>
	 */
	private static function changed_leaves( $new, $old, string $path ): array {
		if ( ! is_array( $new ) ) {
			return $new === $old ? array() : array( $path => $new );
		}
		$out = array();
		foreach ( $new as $key => $value ) {
			$sub = '' === $path ? (string) $key : $path . '.' . $key;
			$out = array_merge( $out, self::changed_leaves( $value, is_array( $old ) && array_key_exists( $key, $old ) ? $old[ $key ] : null, $sub ) );
		}
		return $out;
	}

	/**
	 * Widget options of the expected shape.
	 *
	 * @param mixed  $value  JSON text.
	 * @param string $expect JSON text now.
	 */
	private static function invalid_widgets( $value, string $expect ): string {
		$data = is_string( $value ) ? json_decode( $value, true ) : null;
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'sidebars_widgets', 'widget_block' ) ) {
			return 'Invalid widgets.';
		}
		foreach ( $data as $option ) {
			if ( Customizer::MISSING !== $option && ! is_array( $option ) ) {
				return 'Invalid widgets.';
			}
		}
		return self::adds_active( $expect, $value );
	}

	/**
	 * Document content that keeps the block markup sound (and adds no active content for a user who may not).
	 *
	 * @param string              $key    Key.
	 * @param array<string,mixed> $set    Values.
	 * @param string              $expect Content now.
	 */
	private static function invalid_doc( string $key, array $set, string $expect ): string {
		if ( ! is_string( $set['raw'] ) ) {
			return 'Invalid content.';
		}
		$ref = self::doc_ref( $key );
		if ( 'template' !== $ref['kind'] && array_key_exists( 'custom', $set ) ) {
			return 'Field not allowed: custom';
		}
		if ( 'elementor' === $ref['kind'] ) {
			if ( ! is_array( json_decode( $set['raw'], true ) ) ) {
				return 'Invalid Elementor data.';
			}
			if ( strlen( $set['raw'] ) > BlocksEdit::MAX_BYTES ) {
				return 'The content would be too large.';
			}
		} else {
			$problem = BlocksEdit::final_problem( $set['raw'] );
			if ( '' !== $problem ) {
				return $problem;
			}
		}
		return self::adds_active( $expect, $set['raw'] );
	}

	/**
	 * A user without «unfiltered_html» may not add scripts, styles, frames, forms, event handlers or script URLs.
	 *
	 * @param string $old Content now.
	 * @param string $new Content to write.
	 */
	private static function adds_active( string $old, string $new ): string {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return '';
		}
		$flat = static function ( string $text ): string {
			return html_entity_decode( stripslashes( (string) preg_replace( '/\\\\u003c/i', '<', $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		};
		foreach ( self::ACTIVE as $pattern ) {
			if ( preg_match_all( $pattern, $flat( $new ) ) > preg_match_all( $pattern, $flat( $old ) ) ) {
				return 'Scripts, frames, forms and event handlers cannot be added by this user.';
			}
		}
		return '';
	}

	/**
	 * Block widget options now (MISSING when not stored).
	 *
	 * @return array<string,mixed>
	 */
	private static function widget_options(): array {
		return array(
			'sidebars_widgets' => get_option( 'sidebars_widgets', Customizer::MISSING ),
			'widget_block'     => get_option( 'widget_block', Customizer::MISSING ),
		);
	}

	/**
	 * The key must be the active theme.
	 *
	 * @param string $key Stylesheet.
	 * @throws \InvalidArgumentException When another theme is active now.
	 */
	private static function active_theme( string $key ): void {
		if ( get_stylesheet() !== $key ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'The active theme changed since the draft (now: %s).', get_stylesheet() ) ) );
		}
	}

	/**
	 * The current user must have a capability.
	 *
	 * @param string $cap Capability.
	 * @throws \InvalidArgumentException When not.
	 */
	private static function can( string $cap ): void {
		if ( ! current_user_can( $cap ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- core capabilities only.
			throw new \InvalidArgumentException( esc_html( sprintf( 'This needs the «%s» permission (an administrator).', $cap ) ) );
		}
	}

	/**
	 * Plain text.
	 *
	 * @param mixed $value Value.
	 */
	private static function plain( $value ): string {
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 299 ) . '…' : $text;
	}

	/**
	 * A unit that cannot be read.
	 *
	 * @param string $kind   Kind.
	 * @param string $key    Key.
	 * @param string $reason Why.
	 * @return array<string,mixed>
	 */
	private static function missing( string $kind, string $key, string $reason ): array {
		return array(
			'kind'   => $kind,
			'id'     => 0,
			'key'    => $key,
			'exists' => false,
			'reason' => $reason,
			'values' => null,
		);
	}
}
