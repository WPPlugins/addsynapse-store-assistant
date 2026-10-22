<?php
/**
 * Site-wide styles of a block theme (Styles in the Site Editor): colors, fonts, spacing, elements, per-block styles,
 * palette colors, style variations, site CSS.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The user layer of theme.json (the theme's wp_global_styles post — exactly what the Site Editor's Styles panel saves).
 * ONE line per plan: before {theme, exists, raw|null, hash}, after {raw, hash, ops}. Approve writes it only while the
 * styles are still the previewed ones; Undo restores the exact previous JSON (or removes the styles post it created).
 * Every value is a plain CSS value (no url(), no code); WordPress's own WP_Theme_JSON::remove_insecure_properties() must
 * keep the result unchanged.
 */
final class GlobalStyles extends Planner {

	public const OPERATION = 'design_styles';
	public const OBJECT    = 'global_styles';
	public const FIELD     = 'design:styles';
	public const MAX_OPS   = 40;

	/**
	 * Allowed style paths (below styles.).
	 */
	private const STYLE_PATH = '/^(blocks\.[a-z0-9-]+\/[a-z0-9-]+(\.variations\.[a-z0-9-]+)?\.)?(elements\.(link|heading|h[1-6]|button|caption|cite)(\.:(hover|focus|active|visited))?\.)?(color\.(text|background|gradient)|typography\.(fontFamily|fontSize|fontWeight|fontStyle|lineHeight|letterSpacing|textTransform|textDecoration)|spacing\.(padding|margin)(\.(top|right|bottom|left))?|spacing\.blockGap|border\.(radius|width|style|color)|border\.(top|right|bottom|left)\.(width|style|color)|border\.radius\.(topLeft|topRight|bottomLeft|bottomRight)|shadow|dimensions\.minHeight|outline\.(color|offset|style|width))$/';

	/**
	 * Allowed settings paths (below settings.).
	 */
	private const SETTING_PATH = '/^(layout\.(contentSize|wideSize)|typography\.fluid|color\.(link|text|background))$/';

	/**
	 * Object type.
	 */
	public static function object(): string {
		return self::OBJECT;
	}

	/**
	 * The user styles post of the active theme (null = none yet).
	 *
	 * @return array<string,mixed>|null {ID, post_content}
	 */
	public static function post(): ?array {
		$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		return empty( $post['ID'] ) ? null : $post;
	}

	/**
	 * Current user-layer JSON (decoded; empty layer when none).
	 *
	 * @return array<string,mixed>
	 */
	public static function current(): array {
		$post = self::post();
		$data = $post ? json_decode( (string) $post['post_content'], true ) : null;
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return $data + array(
			'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
			'isGlobalStylesUserThemeJSON' => true,
		);
	}

	/**
	 * Plans style changes.
	 *
	 * @param array<string,mixed> $input           operations[{op: set|remove|palette_color|font_size|variation|css, …}].
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		if ( ! wp_is_block_theme() ) {
			return new WP_Error( 'addsynapse_assistant_design_styles_theme', 'This theme is not a block theme: its colors and fonts are Customizer settings (plan_theme_settings) — or Elementor Site Settings (plan_elementor_kit) when the site uses Elementor.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'addsynapse_assistant_design_styles_forbidden', 'Changing the site\'s styles needs the «edit theme options» permission (an administrator).' );
		}
		$ops = array_values( array_filter( (array) ( $input['operations'] ?? array() ), 'is_array' ) );
		if ( ! $ops || count( $ops ) > self::MAX_OPS ) {
			return new WP_Error( 'addsynapse_assistant_design_styles_ops', sprintf( 'Give 1 to %d operations.', self::MAX_OPS ) );
		}
		$post   = self::post();
		$raw    = $post ? (string) $post['post_content'] : null;
		$data   = self::current();
		$before = $data;
		$lines  = array();
		$probe  = array();
		foreach ( $ops as $i => $op ) {
			try {
				$lines[] = self::run_op( $data, $op, $probe );
			} catch ( \InvalidArgumentException $e ) {
				return new WP_Error( 'addsynapse_assistant_design_styles_op', sprintf( 'Operation %1$d (%2$s): %3$s Nothing was planned.', $i + 1, (string) ( $op['op'] ?? '?' ), $e->getMessage() ) );
			}
		}
		if ( $data === $before ) {
			return $this->store( array(), self::OPERATION, 'Site styles: nothing would change.', array(), $user_id, $conversation_id );
		}
		// Only the values this plan writes are checked by WordPress's sanitizer (theme-provided values such as a variation
		// or the theme palette are the theme's own).
		$problem = $probe ? self::insecure( $probe ) : '';
		if ( '' !== $problem ) {
			return new WP_Error( 'addsynapse_assistant_design_styles_unsafe', $problem . ' Nothing was planned.' );
		}
		$new  = (string) wp_json_encode( $data );
		$item = array(
			'object_type' => self::OBJECT,
			'object_id'   => $post ? (int) $post['ID'] : 0,
			'parent_id'   => 0,
			'label'       => sprintf( 'Site styles (%s)', wp_get_theme()->get( 'Name' ) ),
			'sku'         => '',
			'field'       => self::FIELD,
			'before'      => array(
				'theme'  => get_stylesheet(),
				'exists' => null !== $post,
				'raw'    => $raw,
				'hash'   => hash( 'sha256', (string) $raw ),
			),
			'after'       => array(
				'raw'  => $new,
				'hash' => hash( 'sha256', $new ),
				'ops'  => $lines,
			),
		);
		return $this->store(
			array( $item ),
			self::OPERATION,
			sprintf( 'Site styles: %d change(s) for the whole site.', count( $lines ) ),
			array( 'theme' => get_stylesheet() ),
			$user_id,
			$conversation_id,
			array( 'changes' => $lines )
		);
	}

	/**
	 * One operation on the decoded user layer.
	 *
	 * @param array<string,mixed> $data  Data (by reference).
	 * @param array<string,mixed> $op    Operation.
	 * @param array<string,mixed> $probe The new values alone (by reference), for the sanitizer check.
	 * @return array{label:string,field:string,before:string,after:string}
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function run_op( array &$data, array $op, array &$probe ): array {
		$kind = (string) ( $op['op'] ?? '' );
		switch ( $kind ) {
			case 'set':
			case 'remove':
				$path = trim( (string) ( $op['path'] ?? '' ) );
				$keys = self::path( $path );
				$old  = self::get( $data, $keys );
				if ( 'remove' === $kind ) {
					self::set( $data, $keys, null );
					$new = null;
				} else {
					if ( ! array_key_exists( 'value', $op ) ) {
						throw new \InvalidArgumentException( 'value is required.' );
					}
					$new = self::value( $keys, $op['value'] );
					self::set( $data, $keys, $new );
					self::set( $probe, $keys, $new );
				}
				return array(
					'label'  => self::human( $path ),
					'field'  => 'style',
					'before' => self::text( $old ),
					'after'  => self::text( $new ),
				);
			case 'palette_color':
				$slug  = sanitize_key( (string) ( $op['slug'] ?? '' ) );
				$color = BlockAttrs::css_color( (string) ( $op['color'] ?? '' ) );
				if ( '' === $slug || 0 === strpos( $color, 'var' ) ) {
					throw new \InvalidArgumentException( 'palette_color needs a slug and a color value (#hex, rgb() or hsl()).' );
				}
				return self::palette( $data, $slug, $color, isset( $op['name'] ) ? sanitize_text_field( (string) $op['name'] ) : '' );
			case 'variation':
				return self::variation( $data, (string) ( $op['title'] ?? '' ) );
			case 'css':
				$css     = (string) ( $op['css'] ?? '' );
				$problem = CssText::problem( $css );
				if ( '' !== $problem ) {
					throw new \InvalidArgumentException( esc_html( 'css: ' . $problem ) );
				}
				$block = (string) ( $op['block'] ?? '' );
				$keys  = '' !== $block ? array( 'styles', 'blocks', self::block_name( $block ), 'css' ) : array( 'styles', 'css' );
				$old   = self::get( $data, $keys );
				$empty = '' === trim( $css );
				self::set( $data, $keys, $empty ? null : $css );
				if ( ! $empty ) {
					self::set( $probe, $keys, $css );
				}
				return array(
					'label'  => '' !== $block ? 'CSS of every ' . $block . ' block' : 'Site CSS',
					'field'  => 'css',
					'before' => self::text( $old ),
					'after'  => self::text( $empty ? null : $css ),
				);
		}//end switch
		throw new \InvalidArgumentException( 'Unknown op: use set, remove, palette_color, variation or css.' );
	}

	/**
	 * Keys of an allowed path.
	 *
	 * @param string $path Path (styles.… / settings.…).
	 * @return array<int,string>
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function path( string $path ): array {
		if ( 0 === strpos( $path, 'styles.' ) && preg_match( self::STYLE_PATH, substr( $path, 7 ) ) ) {
			$rest = substr( $path, 7 );
			$keys = array( 'styles' );
			if ( preg_match( '/^blocks\.([a-z0-9-]+\/[a-z0-9-]+)\.(.*)$/', $rest, $m ) ) {
				$keys[] = 'blocks';
				$keys[] = self::block_name( $m[1] );
				$rest   = $m[2];
			}
			foreach ( explode( '.', $rest ) as $key ) {
				$keys[] = $key;
			}
			return $keys;
		}
		if ( 0 === strpos( $path, 'settings.' ) && preg_match( self::SETTING_PATH, substr( $path, 9 ) ) ) {
			return explode( '.', $path );
		}
		throw new \InvalidArgumentException( sprintf( 'The path "%s" cannot be changed here (styles.color.*, styles.typography.*, styles.spacing.*, styles.border.*, styles.elements.<link|heading|h1-h6|button>.*, styles.blocks.<core/…>.*, settings.layout.contentSize|wideSize).', esc_html( $path ) ) );
	}

	/**
	 * A registered block name.
	 *
	 * @param string $name Name.
	 * @throws \InvalidArgumentException When unknown.
	 */
	private static function block_name( string $name ): string {
		if ( false === strpos( $name, '/' ) ) {
			$name = 'core/' . $name;
		}
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'The block type %s is not available on this site.', esc_html( $name ) ) );
		}
		return $name;
	}

	/**
	 * A checked value for a path.
	 *
	 * @param array<int,string> $keys  Keys.
	 * @param mixed             $value Value.
	 * @return mixed
	 * @throws \InvalidArgumentException When not valid.
	 */
	private static function value( array $keys, $value ) {
		$last = end( $keys );
		if ( 'fluid' === $last ) {
			return (bool) $value;
		}
		if ( is_array( $value ) ) {
			// padding / margin / border sides as objects.
			$out = array();
			foreach ( $value as $side => $item ) {
				if ( ! in_array( $side, array( 'top', 'right', 'bottom', 'left', 'width', 'style', 'color', 'topLeft', 'topRight', 'bottomLeft', 'bottomRight' ), true ) ) {
					throw new \InvalidArgumentException( 'Unknown side / key: ' . esc_html( (string) $side ) );
				}
				$out[ $side ] = self::value( array_merge( $keys, array( (string) $side ) ), $item );
			}
			return $out;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( 'The value must be a non-empty text (a CSS value or a preset reference).' );
		}
		$value = trim( $value );
		$color = in_array( $last, array( 'text', 'background', 'color' ), true ) || ( 'gradient' === $last );
		if ( 'gradient' === $last ) {
			if ( ! preg_match( '/^(var:preset\|gradient\|[a-z0-9-]+|var\(--wp--preset--gradient--[a-z0-9-]+\)|(linear|radial|conic)-gradient\([#a-zA-Z0-9.%,()\s\/-]+\))$/', $value ) ) {
				throw new \InvalidArgumentException( 'A gradient is a preset (var:preset|gradient|slug) or a linear-/radial-gradient().' );
			}
			return $value;
		}
		if ( $color ) {
			return BlockAttrs::css_color( $value );
		}
		if ( 'fontFamily' === $last ) {
			if ( preg_match( '/^var:preset\|font-family\|([a-z0-9-]+)$/', $value, $m ) || preg_match( '/^var\(--wp--preset--font-family--([a-z0-9-]+)\)$/', $value, $m ) ) {
				$slugs = array_column( self::font_families(), 'slug' );
				if ( ! in_array( $m[1], $slugs, true ) ) {
					throw new \InvalidArgumentException( sprintf( 'Font family "%1$s" is not available (available: %2$s).', esc_html( $m[1] ), esc_html( implode( ', ', $slugs ) ) ) );
				}
				return $value;
			}
			if ( preg_match( '/^[A-Za-z0-9 ,"\'-]{1,200}$/', $value ) ) {
				return $value;
			}
			throw new \InvalidArgumentException( 'fontFamily is a preset (var:preset|font-family|slug) or a font stack ("Inter", sans-serif).' );
		}
		if ( 'shadow' === $last ) {
			if ( preg_match( '/^(var:preset\|shadow\|[a-z0-9-]+|var\(--wp--preset--shadow--[a-z0-9-]+\)|none|[#a-zA-Z0-9.%,()\s\/-]{1,200})$/', $value ) ) {
				return $value;
			}
			throw new \InvalidArgumentException( 'A shadow is a preset (var:preset|shadow|slug) or a box-shadow value.' );
		}
		if ( preg_match( '/^var:preset\|(spacing|font-size)\|[a-z0-9-]+$/', $value ) ) {
			return $value;
		}
		return BlockAttrs::css_value( $value );
	}

	/**
	 * Sets / adds a palette color (theme palette slug → overridden like the Site Editor does; new slug → custom palette).
	 *
	 * @param array<string,mixed> $data  Data.
	 * @param string              $slug  Slug.
	 * @param string              $color Color.
	 * @param string              $name  Name for a new color.
	 * @return array{label:string,field:string,before:string,after:string}
	 */
	private static function palette( array &$data, string $slug, string $color, string $name ): array {
		$theme  = (array) ( wp_get_global_settings( array( 'color', 'palette' ), array( 'origin' => 'theme' ) )['theme'] ?? array() );
		$user   = (array) ( $data['settings']['color']['palette']['theme'] ?? $theme );
		$custom = (array) ( $data['settings']['color']['palette']['custom'] ?? array() );
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			$list = 'theme' === $origin ? $user : $custom;
			foreach ( $list as $i => $entry ) {
				if ( ( $entry['slug'] ?? '' ) === $slug ) {
					$old                 = (string) ( $entry['color'] ?? '' );
					$list[ $i ]['color'] = $color;
					$data['settings']['color']['palette'][ $origin ] = array_values( $list );
					return array(
						'label'  => sprintf( 'Palette color "%s"', (string) ( $entry['name'] ?? $slug ) ),
						'field'  => 'style',
						'before' => $old,
						'after'  => $color,
					);
				}
			}
		}
		$custom[]                                       = array(
			'slug'  => $slug,
			'name'  => '' !== $name ? $name : $slug,
			'color' => $color,
		);
		$data['settings']['color']['palette']['custom'] = $custom;
		return array(
			'label'  => sprintf( 'New palette color "%s"', '' !== $name ? $name : $slug ),
			'field'  => 'style',
			'before' => '',
			'after'  => $color,
		);
	}

	/**
	 * Applies a style variation of the theme (as «Browse styles» in the Site Editor: its settings and styles replace the
	 * user layer's).
	 *
	 * @param array<string,mixed> $data  Data.
	 * @param string              $title Variation title.
	 * @return array{label:string,field:string,before:string,after:string}
	 * @throws \InvalidArgumentException When unknown.
	 */
	private static function variation( array &$data, string $title ): array {
		$titles = array();
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			$name     = (string) ( $variation['title'] ?? '' );
			$titles[] = $name;
			if ( 0 !== strcasecmp( $name, trim( $title ) ) ) {
				continue;
			}
			$old              = (string) ( $data['title'] ?? 'Default' );
			$data['settings'] = (array) ( $variation['settings'] ?? array() );
			$data['styles']   = (array) ( $variation['styles'] ?? array() );
			$data['title']    = $name;
			$data             = array_filter( $data, static fn( $v ) => array() !== $v );
			return array(
				'label'  => 'Style variation',
				'field'  => 'style',
				'before' => $old,
				'after'  => $name,
			);
		}
		throw new \InvalidArgumentException( sprintf( 'The theme has no style variation "%1$s" (available: %2$s).', esc_html( $title ), esc_html( implode( ', ', $titles ) ) ) );
	}

	/**
	 * Font families available (theme + custom).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function font_families(): array {
		$all = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		$out = array();
		foreach ( (array) $all as $origin => $list ) {
			foreach ( (array) $list as $family ) {
				if ( is_array( $family ) && ! empty( $family['slug'] ) ) {
					$out[] = array(
						'slug'   => (string) $family['slug'],
						'name'   => (string) ( $family['name'] ?? $family['slug'] ),
						'origin' => (string) $origin,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Reads a nested key.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param array<int,string>   $keys Keys.
	 * @return mixed
	 */
	private static function get( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}
		return $data;
	}

	/**
	 * Sets (null removes) a nested key, dropping emptied parents.
	 *
	 * @param array<string,mixed> $data  Data.
	 * @param array<int,string>   $keys  Keys.
	 * @param mixed               $value Value.
	 */
	private static function set( array &$data, array $keys, $value ): void {
		$key = array_shift( $keys );
		if ( ! $keys ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
			return;
		}
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			if ( null === $value ) {
				return;
			}
			$data[ $key ] = array();
		}
		self::set( $data[ $key ], $keys, $value );
		if ( array() === $data[ $key ] ) {
			unset( $data[ $key ] );
		}
	}

	/**
	 * Why WordPress would strip part of the styles ('' = nothing).
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function insecure( array $data ): string {
		$data += array(
			'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
			'isGlobalStylesUserThemeJSON' => true,
		);
		$clean = \WP_Theme_JSON::remove_insecure_properties( $data, 'custom' );
		$strip = static function ( array $config ): array {
			unset( $config['version'], $config['isGlobalStylesUserThemeJSON'], $config['title'] );
			return $config;
		};
		$a     = $strip( $data );
		$b     = $strip( (array) $clean );
		ksort( $a );
		ksort( $b );
		$path = self::first_difference( self::sorted( $a ), self::sorted( $b ), '' );
		if ( null !== $path ) {
			return sprintf( 'WordPress considers part of these styles unsafe (%s).', '' !== $path ? $path : 'styles' );
		}
		return '';
	}

	/**
	 * First path where two values differ (null = equal).
	 *
	 * @param mixed  $a    A.
	 * @param mixed  $b    B.
	 * @param string $path Path so far.
	 */
	private static function first_difference( $a, $b, string $path ): ?string {
		if ( is_array( $a ) && is_array( $b ) ) {
			foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
				$sub = self::first_difference( $a[ $key ] ?? null, $b[ $key ] ?? null, '' === $path ? (string) $key : $path . '.' . $key );
				if ( null !== $sub ) {
					return $sub;
				}
			}
			return null;
		}
		return $a === $b ? null : $path;
	}

	/**
	 * Recursively key-sorted copy.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( is_string( $value ) && preg_match( '/^var:preset\|([a-z0-9-]+)\|([a-z0-9-]+)$/', $value, $m ) ) {
			return 'var(--wp--preset--' . $m[1] . '--' . $m[2] . ')';
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! wp_is_numeric_array( $value ) ) {
			ksort( $value );
		}
		return array_map( array( self::class, 'sorted' ), $value );
	}

	/**
	 * A readable name of a style path ("styles.elements.button.border.radius" → "Buttons · corner radius").
	 *
	 * @param string $path Path.
	 */
	public static function human( string $path ): string {
		$words = array(
			'color.text'                => 'text color',
			'color.background'          => 'background color',
			'color.gradient'            => 'background gradient',
			'typography.fontFamily'     => 'font',
			'typography.fontSize'       => 'text size',
			'typography.fontWeight'     => 'font weight',
			'typography.fontStyle'      => 'font style',
			'typography.lineHeight'     => 'line height',
			'typography.letterSpacing'  => 'letter spacing',
			'typography.textTransform'  => 'letter case',
			'typography.textDecoration' => 'text decoration',
			'spacing.padding'           => 'inner spacing',
			'spacing.margin'            => 'outer spacing',
			'spacing.blockGap'          => 'space between blocks',
			'border.radius'             => 'corner radius',
			'border.width'              => 'border width',
			'border.color'              => 'border color',
			'border.style'              => 'border style',
			'shadow'                    => 'shadow',
			'dimensions.minHeight'      => 'minimum height',
			'layout.contentSize'        => 'content width',
			'layout.wideSize'           => 'wide width',
		);
		$scope = 'Whole site';
		$rest  = preg_replace( '/^(styles|settings)\./', '', $path );
		if ( preg_match( '/^blocks\.([a-z0-9-]+\/[a-z0-9-]+)\.(.*)$/', (string) $rest, $m ) ) {
			$scope = 'Every ' . BlocksEdit::label( $m[1] ) . ' block';
			$rest  = $m[2];
		}
		if ( preg_match( '/^elements\.([a-z0-9]+)(\.:(hover|focus|active|visited))?\.(.*)$/', (string) $rest, $m ) ) {
			$names = array(
				'link'    => 'Links',
				'heading' => 'Headings',
				'button'  => 'Buttons',
				'caption' => 'Captions',
				'cite'    => 'Quotes',
			);
			$scope = ( $names[ $m[1] ] ?? strtoupper( $m[1] ) . ' headings' ) . ( ! empty( $m[3] ) ? ' (' . $m[3] . ')' : '' );
			$rest  = $m[4];
		}
		foreach ( $words as $key => $word ) {
			if ( $rest === $key || 0 === strpos( (string) $rest, $key . '.' ) ) {
				$side = substr( (string) $rest, strlen( $key ) + 1 );
				return $scope . ' · ' . $word . ( '' !== (string) $side ? ' (' . $side . ')' : '' );
			}
		}
		return $scope . ' · ' . (string) $rest;
	}

	/**
	 * Short plain text of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		if ( null === $value ) {
			return '';
		}
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$text = (string) preg_replace( '/var:preset\|([a-z-]+)\|([a-z0-9-]+)/', 'preset $2', $text );
		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 299 ) . '…' : $text;
	}




	/**
	 * Stores the user layer JSON exactly (creates the styles post of the theme when needed).
	 *
	 * @param int    $post_id Post (0 = create).
	 * @param string $raw     JSON.
	 * @throws \RuntimeException When WordPress refuses.
	 */
	public static function write( int $post_id, string $raw ): void {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_global_styles_post' ) || false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			if ( $post_id < 1 ) {
				$post_id = wp_insert_post(
					array(
						'post_content' => wp_slash( $raw ),
						'post_status'  => 'publish',
						'post_title'   => 'Custom Styles',
						'post_type'    => 'wp_global_styles',
						'post_name'    => sprintf( 'wp-global-styles-%s', urlencode( get_stylesheet() ) ),
					),
					true
				);
				if ( ! is_wp_error( $post_id ) ) {
					wp_set_post_terms( (int) $post_id, array( get_stylesheet() ), 'wp_theme' );
				}
			} else {
				$post_id = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $raw ),
					),
					true
				);
			}//end if
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}//end try
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( esc_html( $post_id->get_error_message() ) );
		}
		clean_post_cache( (int) $post_id );
		if ( (string) get_post_field( 'post_content', (int) $post_id ) !== $raw ) {
			throw new \RuntimeException( 'WordPress changed the styles while saving.' );
		}
	}

	/**
	 * Drops the cached theme.json data and stylesheets.
	 */
	public static function flush(): void {
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		\WP_Theme_JSON_Resolver::clean_cached_data();
	}
}
