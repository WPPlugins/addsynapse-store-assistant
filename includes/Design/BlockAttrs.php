<?php
/**
 * Attribute changes of one block, with the saved markup kept in step (so the block editor still validates the block).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * A static block stores its look twice: in the comment attributes and in the saved HTML (classes, inline style, tag).
 * The editor re-runs save() on load and flags «unexpected content» when both disagree, so an attribute change must
 * change the saved HTML exactly as save() would. The block supports (block.json `supports`: color, typography, spacing,
 * border, shadow, dimensions, align, anchor, custom class, custom CSS — with their skip-serialization flags) are mirrored
 * from the block editor's own save hooks (WordPress 7.x: block-editor hooks addSaveProps, style-engine getCSSRules), for ANY
 * static block. Block-specific attributes are handled for the common core blocks (heading level, group tag, columns /
 * column, spacer, cover overlay / height / position, image size, paragraph drop cap). Blocks without saved HTML (dynamic
 * blocks: site title / logo, navigation, template parts, query, WooCommerce blocks…) take any attribute. Anything else that
 * lives in the markup is refused with a hint (set_text / set_image / replace with new blocks).
 */
final class BlockAttrs {

	/**
	 * Attributes that never appear in saved HTML.
	 */
	private const SAFE = array( 'lock', 'metadata', 'layout', 'templateLock', 'allowedBlocks' );

	/**
	 * Keys mirrored from the block supports.
	 */
	private const SUPPORT_KEYS = array( 'style', 'textColor', 'backgroundColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'className', 'align', 'anchor' );

	/**
	 * Block-specific attributes handled here.
	 */
	private const SPECIAL = array(
		'core/heading'   => array( 'level' ),
		'core/paragraph' => array( 'dropCap' ),
		'core/group'     => array( 'tagName' ),
		'core/columns'   => array( 'verticalAlignment', 'isStackedOnMobile' ),
		'core/column'    => array( 'width', 'verticalAlignment' ),
		'core/spacer'    => array( 'height' ),
		'core/cover'     => array( 'dimRatio', 'overlayColor', 'customOverlayColor', 'minHeight', 'minHeightUnit', 'contentPosition', 'isDark' ),
		'core/image'     => array( 'sizeSlug' ),
		'core/quote'     => array( 'textAlign' ),
	);

	/**
	 * Supports whose skipped serialization moves to an inner element this class knows (button → its link).
	 */
	private const INNER = array( 'core/button' => 'wp-block-button__link' );

	/**
	 * Merges a change into attributes: nested objects merge, null removes, lists replace.
	 *
	 * @param array<string,mixed> $attrs  Current.
	 * @param array<string,mixed> $change Change.
	 * @return array<string,mixed>
	 */
	public static function merge( array $attrs, array $change ): array {
		foreach ( $change as $key => $value ) {
			if ( null === $value ) {
				unset( $attrs[ $key ] );
				continue;
			}
			if ( is_array( $value ) && ! wp_is_numeric_array( $value ) ) {
				$base  = isset( $attrs[ $key ] ) && is_array( $attrs[ $key ] ) && ! wp_is_numeric_array( $attrs[ $key ] ) ? $attrs[ $key ] : array();
				$value = $value ? self::merge( $base, $value ) : $value;
				if ( array() === $value ) {
					unset( $attrs[ $key ] );
					continue;
				}
			}
			$attrs[ $key ] = $value;
		}
		return $attrs;
	}

	/**
	 * Problem with an attribute value ('' = fine): JSON data only, no markup / script / remote code.
	 *
	 * @param mixed  $value Value.
	 * @param int    $depth Depth.
	 * @param string $path  Path for the message.
	 */
	public static function value_problem( $value, int $depth = 0, string $path = 'attrs' ): string {
		if ( $depth > 8 ) {
			return $path . ' is nested too deeply.';
		}
		if ( is_array( $value ) ) {
			if ( count( $value ) > 200 ) {
				return $path . ' has too many entries.';
			}
			foreach ( $value as $key => $item ) {
				if ( ! is_int( $key ) && ! preg_match( '/^[A-Za-z0-9_:\-\/|.]{1,80}$/', (string) $key ) ) {
					return $path . ' has an invalid key.';
				}
				$problem = self::value_problem( $item, $depth + 1, $path . '.' . $key );
				if ( '' !== $problem ) {
					return $problem;
				}
			}
			return '';
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 8000 ) {
				return $path . ' is too long.';
			}
			if ( preg_match( '/<\s*\/?\s*(script|style|iframe|object|embed)\b|javascript\s*:|vbscript\s*:|expression\s*\(|@import|[\s"\'\/]on[a-z]+\s*=/i', $value ) ) {
				return $path . ' contains code, which is not allowed.';
			}
			if ( preg_match( '/url\s*\(\s*[\'"]?\s*(?!https?:|\/)/i', $value ) ) {
				return $path . ' has a url() that is not http(s).';
			}
			return '';
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			return '';
		}
		return $path . ' has an unsupported value.';
	}

	/**
	 * Applies an attribute change to a block of the document (attributes + its saved HTML).
	 *
	 * @param BlockDoc            $doc    Document.
	 * @param string              $id     Block.
	 * @param array<string,mixed> $change Attribute change.
	 * @return array{before:array<string,mixed>,after:array<string,mixed>} Changed top-level keys.
	 * @throws \InvalidArgumentException When the change cannot be made safely.
	 */
	public static function apply( BlockDoc $doc, string $id, array $change ): array {
		$node = $doc->get( $id );
		if ( null === $node ) {
			throw new \InvalidArgumentException( sprintf( 'Block %s was not found.', esc_html( $id ) ) );
		}
		$problem = self::value_problem( $change );
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( $problem ) );
		}
		$name   = (string) $node['name'];
		$type   = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$change = self::normalise( $name, $change, $type );
		$old    = (array) $node['attrs'];
		$new    = self::merge( $old, $change );
		$keys   = array_values( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) );
		$diff   = array_values( array_filter( $keys, static fn( $k ) => ( $old[ $k ] ?? null ) !== ( $new[ $k ] ?? null ) ) );
		$shown  = array(
			'before' => array_intersect_key( $old, array_flip( $diff ) ),
			'after'  => array_intersect_key( $new, array_flip( $diff ) ),
		);
		if ( ! $diff ) {
			return $shown;
		}
		self::check_values( $new );
		$html  = $doc->html_parts( $id );
		$saved = '' !== trim( implode( '', $html ) );
		if ( ! $saved ) {
			$doc->update( $id, $new );
			return $shown;
		}
		if ( ! $type ) {
			throw new \InvalidArgumentException( sprintf( 'The block type %s is not available on this site, so its markup cannot be changed safely.', esc_html( $name ) ) );
		}
		foreach ( $diff as $key ) {
			if ( in_array( $key, self::SAFE, true ) || in_array( $key, self::SPECIAL[ $name ] ?? array(), true ) ) {
				continue;
			}
			if ( isset( $type->attributes[ $key ]['source'] ) && 'anchor' !== $key ) {
				throw new \InvalidArgumentException( sprintf( '"%1$s" of %2$s is stored in the block\'s HTML: use set_text / set_image / set_link, or replace the block with new blocks.', esc_html( $key ), esc_html( $name ) ) );
			}
			if ( ! in_array( $key, self::SUPPORT_KEYS, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Changing "%1$s" of a %2$s block is not supported as an attribute change: replace the block with new blocks instead.', esc_html( $key ), esc_html( $name ) ) );
			}
		}
		self::check_skipped( $name, $type, $old, $new );
		$doc->update( $id, $new, self::sync( $name, $old, $new, $html ) );
		return $shown;
	}

	/**
	 * Gentle normalisation of a change: `textAlign` on blocks that align text through the typography support
	 * (heading, paragraph in WordPress 7) → style.typography.textAlign.
	 *
	 * @param string              $name   Block.
	 * @param array<string,mixed> $change Change.
	 * @param mixed               $type   Block type.
	 * @return array<string,mixed>
	 */
	public static function normalise( string $name, array $change, $type ): array {
		if ( array_key_exists( 'textAlign', $change ) && $type && ! isset( $type->attributes['textAlign'] ) && self::support( $type, array( 'typography', 'textAlign' ) ) ) {
			$change['style']['typography']['textAlign'] = $change['textAlign'];
			unset( $change['textAlign'] );
		}
		if ( 'core/paragraph' === $name && array_key_exists( 'align', $change ) && in_array( $change['align'], array( 'left', 'center', 'right' ), true ) ) {
			$change['style']['typography']['textAlign'] = $change['align'];
			unset( $change['align'] );
		}
		return $change;
	}

	/**
	 * Colors / lengths of the design attributes are plain CSS values.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @throws \InvalidArgumentException When not.
	 */
	public static function check_values( array $attrs ): void {
		foreach ( array( 'textColor', 'backgroundColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'overlayColor' ) as $slug ) {
			if ( isset( $attrs[ $slug ] ) && ( ! is_string( $attrs[ $slug ] ) || ! preg_match( '/^[a-z0-9-]{1,60}$/i', $attrs[ $slug ] ) ) ) {
				throw new \InvalidArgumentException( sprintf( '%s takes a preset slug (from describe_site_design); custom values go in style.', esc_html( $slug ) ) );
			}
		}
		if ( isset( $attrs['className'] ) && ( ! is_string( $attrs['className'] ) || ! preg_match( '/^[A-Za-z0-9_\- ]{0,200}$/', $attrs['className'] ) ) ) {
			throw new \InvalidArgumentException( 'className is a space-separated list of CSS class names.' );
		}
		if ( isset( $attrs['anchor'] ) && ( ! is_string( $attrs['anchor'] ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,80}$/', $attrs['anchor'] ) ) ) {
			throw new \InvalidArgumentException( 'anchor is an HTML id (letters, digits, - and _).' );
		}
		$walk = static function ( $value, string $path ) use ( &$walk ): void {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$walk( $item, $path . '.' . $key );
				}
				return;
			}
			if ( is_string( $value ) && 'style.css' !== $path && preg_match( '/[;{}<>]|\/\*/', $value ) ) {
				throw new \InvalidArgumentException( sprintf( '%s must be a single CSS value.', esc_html( $path ) ) );
			}
		};
		if ( isset( $attrs['style'] ) ) {
			if ( ! is_array( $attrs['style'] ) ) {
				throw new \InvalidArgumentException( 'style is an object.' );
			}
			$walk( $attrs['style'], 'style' );
			if ( isset( $attrs['style']['css'] ) ) {
				$problem = CssText::problem( (string) $attrs['style']['css'] );
				if ( '' !== $problem ) {
					throw new \InvalidArgumentException( esc_html( 'style.css: ' . $problem ) );
				}
			}
		}
	}

	/**
	 * A support whose serialization is skipped on the wrapper can only change where this class knows the inner element.
	 *
	 * @param string              $name Block.
	 * @param \WP_Block_Type      $type Type.
	 * @param array<string,mixed> $old  Old.
	 * @param array<string,mixed> $new  New.
	 * @throws \InvalidArgumentException When the change cannot be mirrored.
	 */
	private static function check_skipped( string $name, \WP_Block_Type $type, array $old, array $new ): void {
		$features = array(
			'color'                => array( array( 'textColor', 'backgroundColor', 'gradient' ), array( 'color' ) ),
			'__experimentalBorder' => array( array( 'borderColor' ), array( 'border' ) ),
			'typography'           => array( array( 'fontSize', 'fontFamily' ), array( 'typography' ) ),
			'spacing'              => array( array(), array( 'spacing' ) ),
			'shadow'               => array( array(), array( 'shadow' ) ),
			'dimensions'           => array( array(), array( 'dimensions' ) ),
		);
		foreach ( $features as $support => $keys ) {
			$changed = false;
			foreach ( $keys[0] as $key ) {
				$changed = $changed || ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null );
			}
			foreach ( $keys[1] as $key ) {
				$changed = $changed || ( $old['style'][ $key ] ?? null ) !== ( $new['style'][ $key ] ?? null );
			}
			if ( ! $changed || ! self::skip( $type, $support ) ) {
				continue;
			}
			if ( isset( self::INNER[ $name ] ) && 'dimensions' !== $support ) {
				if ( 'typography' === $support && ( $old['style']['typography']['fontSize'] ?? null ) !== ( $new['style']['typography']['fontSize'] ?? null ) ) {
					throw new \InvalidArgumentException( 'A custom button text size is set site-wide (plan_global_styles: styles.elements.button.typography.fontSize) or with a fontSize preset slug.' );
				}
				continue;
			}
			throw new \InvalidArgumentException( sprintf( 'The %1$s of a %2$s block is drawn by an inner element: replace the block with new blocks instead, or change it site-wide with plan_global_styles.', esc_html( 'color' === $support ? 'colors' : str_replace( '__experimental', '', $support ) ), esc_html( $name ) ) );
		}
	}

	/**
	 * Rewrites the saved HTML of a static block for new attributes.
	 *
	 * @param string              $name Block.
	 * @param array<string,mixed> $old  Old attributes.
	 * @param array<string,mixed> $new  New attributes.
	 * @param array<int,string>   $html Own HTML parts.
	 * @return array<int,string>
	 * @throws \InvalidArgumentException When the markup is not the expected one.
	 */
	public static function sync( string $name, array $old, array $new, array $html ): array {
		$tags = new \WP_HTML_Tag_Processor( (string) $html[0] );
		if ( ! $tags->next_tag() ) {
			throw new \InvalidArgumentException( 'Unexpected block markup.' );
		}
		self::swap( $tags, self::wrapper_props( $name, $old ), self::wrapper_props( $name, $new ) );
		if ( isset( self::INNER[ $name ] ) ) {
			if ( 1 !== count( $html ) || ! $tags->next_tag( array( 'class_name' => self::INNER[ $name ] ) ) ) {
				throw new \InvalidArgumentException( 'Unexpected block markup.' );
			}
			self::swap( $tags, self::inner_props( $name, $old ), self::inner_props( $name, $new ) );
		}
		$html[0] = self::tidy( $tags->get_updated_html() );

		if ( 'core/heading' === $name && (int) ( $old['level'] ?? 2 ) !== (int) ( $new['level'] ?? 2 ) ) {
			$level = (int) ( $new['level'] ?? 2 );
			if ( $level < 1 || $level > 6 ) {
				throw new \InvalidArgumentException( 'A heading level is 1 to 6.' );
			}
			$html = self::rename( $html, 'h' . (int) ( $old['level'] ?? 2 ), 'h' . $level );
		}
		if ( 'core/group' === $name && ( $old['tagName'] ?? 'div' ) !== ( $new['tagName'] ?? 'div' ) ) {
			$tag = (string) ( $new['tagName'] ?? 'div' );
			if ( ! in_array( $tag, array( 'div', 'header', 'main', 'section', 'article', 'aside', 'footer' ), true ) ) {
				throw new \InvalidArgumentException( 'tagName of a group is div, header, main, section, article, aside or footer.' );
			}
			$html = self::rename( $html, (string) ( $old['tagName'] ?? 'div' ), $tag );
		}
		if ( 'core/cover' === $name ) {
			$html = self::cover_overlay( $old, $new, $html );
		}
		return $html;
	}

	/**
	 * Whitespace left in tags by removed attributes (`<p  >` → `<p>`).
	 *
	 * @param string $html HTML.
	 */
	public static function tidy( string $html ): string {
		$html = (string) preg_replace( '/(<[a-zA-Z][a-zA-Z0-9-]*)\s{2,}(?=[a-zA-Z])/', '$1 ', $html );
		return (string) preg_replace( '/(<[a-zA-Z][^<>]*?)\s+(\/?>)/', '$1$2', $html );
	}

	/**
	 * Renames the outer tag of a block (first opening tag, last closing tag).
	 *
	 * @param array<int,string> $html Parts.
	 * @param string            $from Old tag.
	 * @param string            $to   New tag.
	 * @return array<int,string>
	 * @throws \InvalidArgumentException When the tag is not there.
	 */
	private static function rename( array $html, string $from, string $to ): array {
		$last = count( $html ) - 1;
		if ( ! preg_match( '/^(\s*<)' . preg_quote( $from, '/' ) . '\b/i', $html[0] ) || ! preg_match( '/<\/' . preg_quote( $from, '/' ) . '>(\s*)$/i', $html[ $last ] ) ) {
			throw new \InvalidArgumentException( 'Unexpected block markup.' );
		}
		$html[0]       = (string) preg_replace( '/^(\s*<)' . preg_quote( $from, '/' ) . '\b/i', '${1}' . $to, $html[0], 1 );
		$html[ $last ] = (string) preg_replace( '/<\/' . preg_quote( $from, '/' ) . '>(\s*)$/i', '</' . $to . '>${1}', $html[ $last ], 1 );
		return $html;
	}

	/**
	 * A block support value (path below `supports`), false when absent.
	 *
	 * @param \WP_Block_Type|null $type Type.
	 * @param array<int,string>   $path Path.
	 * @return mixed
	 */
	public static function support( $type, array $path ) {
		$value = $type ? (array) $type->supports : array();
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return false;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * Whether a support's (feature's) serialization is skipped on the wrapper.
	 *
	 * @param \WP_Block_Type|null $type    Type.
	 * @param string              $support Support (color, typography, spacing, __experimentalBorder, shadow, dimensions).
	 * @param string              $feature Feature ('' = any feature of the support).
	 */
	public static function skip( $type, string $support, string $feature = '' ): bool {
		$skip = self::support( $type, array( $support, '__experimentalSkipSerialization' ) );
		if ( true === $skip ) {
			return true;
		}
		if ( ! is_array( $skip ) ) {
			return false;
		}
		return '' === $feature ? (bool) $skip : in_array( $feature, $skip, true );
	}

	/**
	 * Classes / inline styles / attributes of the wrapper (what useBlockProps.save() adds for the supports, plus the
	 * block-specific ones of save()).
	 *
	 * @param string              $name  Block.
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array{classes:array<int,string>,styles:array<string,string>,attrs:array<string,string|null>}
	 */
	public static function wrapper_props( string $name, array $attrs ): array {
		$type     = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$supports = $type ? (array) $type->supports : array();
		$props    = self::support_props( $type, $attrs, false );
		$classes  = $props['classes'];
		$styles   = $props['styles'];
		if ( ! empty( $attrs['align'] ) && is_string( $attrs['align'] ) && ! empty( $supports['align'] ) ) {
			$valid = true === $supports['align'] ? array( 'left', 'center', 'right', 'wide', 'full' ) : (array) $supports['align'];
			if ( array_key_exists( 'alignWide', $supports ) && ! $supports['alignWide'] ) {
				$valid = array_diff( $valid, array( 'wide', 'full' ) );
			}
			if ( in_array( $attrs['align'], $valid, true ) ) {
				array_unshift( $classes, 'align' . $attrs['align'] );
			}
		}
		if ( ( ! array_key_exists( 'customClassName', $supports ) || false !== $supports['customClassName'] ) && ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
			foreach ( preg_split( '/\s+/', trim( $attrs['className'] ) ) as $class ) {
				$classes[] = $class;
			}
		}
		if ( ( ! array_key_exists( 'customCSS', $supports ) || false !== $supports['customCSS'] ) && is_string( $attrs['style']['css'] ?? null ) && '' !== trim( $attrs['style']['css'] ) ) {
			$classes[] = 'has-custom-css';
		}
		$extra = array();
		if ( ! empty( $supports['anchor'] ) ) {
			$extra['id'] = ! empty( $attrs['anchor'] ) && is_string( $attrs['anchor'] ) ? $attrs['anchor'] : null;
		}
		switch ( $name ) {
			case 'core/paragraph':
				$align = (string) ( $attrs['style']['typography']['textAlign'] ?? '' );
				if ( ! empty( $attrs['dropCap'] ) && ! in_array( $align, array( is_rtl() ? 'left' : 'right', 'center' ), true ) ) {
					$classes[] = 'has-drop-cap';
				}
				break;
			case 'core/quote':
				if ( ! empty( $attrs['textAlign'] ) ) {
					$classes[] = 'has-text-align-' . sanitize_html_class( (string) $attrs['textAlign'] );
				}
				break;
			case 'core/columns':
				if ( ! empty( $attrs['verticalAlignment'] ) ) {
					$classes[] = 'are-vertically-aligned-' . sanitize_html_class( (string) $attrs['verticalAlignment'] );
				}
				if ( isset( $attrs['isStackedOnMobile'] ) && false === $attrs['isStackedOnMobile'] ) {
					$classes[] = 'is-not-stacked-on-mobile';
				}
				break;
			case 'core/column':
				if ( ! empty( $attrs['verticalAlignment'] ) ) {
					$classes[] = 'is-vertically-aligned-' . sanitize_html_class( (string) $attrs['verticalAlignment'] );
				}
				if ( ! empty( $attrs['width'] ) ) {
					$styles['flex-basis'] = self::css_value( (string) $attrs['width'] );
				}
				break;
			case 'core/spacer':
				if ( isset( $attrs['height'] ) ) {
					$styles['height'] = is_numeric( $attrs['height'] ) ? (int) $attrs['height'] . 'px' : self::css_value( (string) $attrs['height'] );
				}
				break;
			case 'core/image':
				if ( ! empty( $attrs['sizeSlug'] ) ) {
					$classes[] = 'size-' . sanitize_html_class( (string) $attrs['sizeSlug'] );
				}
				break;
			case 'core/cover':
				if ( isset( $attrs['minHeight'] ) && '' !== $attrs['minHeight'] ) {
					// save(): `${minHeight}${minHeightUnit}`, or the bare number (React writes px).
					$styles['min-height'] = is_numeric( $attrs['minHeight'] ) ? ( 0 + $attrs['minHeight'] ) . ( ! empty( $attrs['minHeightUnit'] ) ? (string) $attrs['minHeightUnit'] : 'px' ) : self::css_value( (string) $attrs['minHeight'] );
				}
				$position = (string) ( $attrs['contentPosition'] ?? '' );
				if ( '' !== $position && 'center center' !== $position && 'center' !== $position ) {
					$classes[] = 'has-custom-content-position';
					$classes[] = 'is-position-' . sanitize_html_class( str_replace( ' ', '-', $position ) );
				}
				if ( isset( $attrs['isDark'] ) && false === $attrs['isDark'] ) {
					$classes[] = 'is-light';
				}
				break;
		}//end switch
		return array(
			'classes' => array_values( array_unique( array_filter( $classes ) ) ),
			'styles'  => $styles,
			'attrs'   => $extra,
		);
	}

	/**
	 * Classes + inline styles of the supports (colors, border color, font size / family, text align, inline styles).
	 *
	 * @param \WP_Block_Type|null $type  Type.
	 * @param array<string,mixed> $attrs Attributes.
	 * @param bool                $inner For the inner element that carries the skipped supports.
	 * @return array{classes:array<int,string>,styles:array<string,string>}
	 */
	public static function support_props( $type, array $attrs, bool $inner ): array {
		$classes = array();
		$style   = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
		$kebab   = static fn( $slug ) => _wp_to_kebab_case( (string) $slug );
		// A feature is serialized here when: on the wrapper, it is not skipped; on the inner element, it is skipped.
		$here = static function ( string $support, string $feature ) use ( $type, $inner ): bool {
			$skipped = self::skip( $type, $support, $feature );
			return $inner ? $skipped : ! $skipped;
		};
		if ( self::support( $type, array( 'color' ) ) ) {
			$text     = $here( 'color', 'text' );
			$back     = $here( 'color', 'background' );
			$grads    = $here( 'color', 'gradients' );
			$link     = $here( 'color', 'link' );
			$has_grad = (bool) self::support( $type, array( 'color', 'gradients' ) );
			if ( $text && ! empty( $attrs['textColor'] ) ) {
				$classes[] = 'has-' . $kebab( $attrs['textColor'] ) . '-color';
			}
			if ( $grads && ! empty( $attrs['gradient'] ) ) {
				$classes[] = 'has-' . $attrs['gradient'] . '-gradient-background';
			}
			$no_back_class = $inner ? ( ! empty( $attrs['gradient'] ) || ! empty( $style['color']['gradient'] ) ) : ( $has_grad && ! empty( $style['color']['gradient'] ) );
			if ( $back && ! empty( $attrs['backgroundColor'] ) && ! $no_back_class ) {
				$classes[] = 'has-' . $kebab( $attrs['backgroundColor'] ) . '-background-color';
			}
			if ( $text && ( ! empty( $attrs['textColor'] ) || ! empty( $style['color']['text'] ) ) ) {
				$classes[] = 'has-text-color';
			}
			$has_back = ! empty( $attrs['backgroundColor'] ) || ! empty( $style['color']['background'] ) || ( ( $has_grad || $inner ) && ( ! empty( $attrs['gradient'] ) || ! empty( $style['color']['gradient'] ) ) );
			if ( ( $back || $grads ) && $has_back ) {
				$classes[] = 'has-background';
			}
			if ( $link && ! empty( $style['elements']['link']['color'] ) ) {
				$classes[] = 'has-link-color';
			}
		}//end if
		if ( self::support( $type, array( '__experimentalBorder', 'color' ) ) && $here( '__experimentalBorder', 'color' ) ) {
			if ( ! empty( $attrs['borderColor'] ) || ! empty( $style['border']['color'] ) ) {
				$classes[] = 'has-border-color';
			}
			if ( ! empty( $attrs['borderColor'] ) ) {
				$classes[] = 'has-' . $kebab( $attrs['borderColor'] ) . '-border-color';
			}
		}
		if ( ! empty( $attrs['fontFamily'] ) && self::support( $type, array( 'typography', '__experimentalFontFamily' ) ) && $here( 'typography', 'fontFamily' ) ) {
			$classes[] = 'has-' . $kebab( $attrs['fontFamily'] ) . '-font-family';
		}
		if ( ! empty( $style['typography']['textAlign'] ) && in_array( $style['typography']['textAlign'], array( 'left', 'center', 'right' ), true ) && self::support( $type, array( 'typography', 'textAlign' ) ) && $here( 'typography', 'textAlign' ) ) {
			$classes[] = 'has-text-align-' . $style['typography']['textAlign'];
		}
		if ( ! empty( $attrs['fontSize'] ) && self::support( $type, array( 'typography', 'fontSize' ) ) && $here( 'typography', 'fontSize' ) ) {
			$classes[] = 'has-' . $kebab( $attrs['fontSize'] ) . '-font-size';
		}
		// Inline styles (style-engine), minus what is never saved inline and what is serialized on the other element.
		$inline = $style;
		unset( $inline['elements'], $inline['css'], $inline['background'], $inline['dimensions']['aspectRatio'], $inline['typography']['textAlign'], $inline['spacing']['blockGap'] );
		$map = array(
			'color'                => 'color',
			'__experimentalBorder' => 'border',
			'typography'           => 'typography',
			'dimensions'           => 'dimensions',
			'spacing'              => 'spacing',
			'shadow'               => 'shadow',
		);
		foreach ( $map as $support => $key ) {
			if ( ! isset( $inline[ $key ] ) ) {
				continue;
			}
			$skip = self::support( $type, array( $support, '__experimentalSkipSerialization' ) );
			if ( $inner ) {
				if ( true === $skip ) {
					continue;
				}
				$keep = array();
				foreach ( is_array( $skip ) ? $skip : array() as $feature ) {
					$feature = 'gradients' === $feature ? 'gradient' : $feature;
					if ( is_array( $inline[ $key ] ) && isset( $inline[ $key ][ $feature ] ) ) {
						$keep[ $feature ] = $inline[ $key ][ $feature ];
					}
				}
				$inline[ $key ] = $keep;
				continue;
			}
			if ( true === $skip ) {
				unset( $inline[ $key ] );
			} elseif ( is_array( $skip ) && is_array( $inline[ $key ] ) ) {
				foreach ( $skip as $feature ) {
					unset( $inline[ $key ][ 'gradients' === $feature ? 'gradient' : $feature ] );
				}
			}
		}//end foreach
		if ( $inner ) {
			$inline = array_intersect_key( $inline, array_flip( array_values( $map ) ) );
		}
		$styles = array();
		$inline = array_filter( $inline );
		if ( $inline ) {
			$css = wp_style_engine_get_styles( $inline );
			foreach ( (array) ( $css['declarations'] ?? array() ) as $prop => $value ) {
				$styles[ (string) $prop ] = (string) $value;
			}
		}
		return array(
			'classes' => $classes,
			'styles'  => $styles,
		);
	}

	/**
	 * Inner element of a block whose supports skip the wrapper (button link: color, border, spacing, shadow, typography +
	 * `has-custom-font-size`, `no-border-radius`).
	 *
	 * @param string              $name  Block.
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array{classes:array<int,string>,styles:array<string,string>,attrs:array<string,string|null>}
	 */
	public static function inner_props( string $name, array $attrs ): array {
		$type  = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$props = self::support_props( $type, $attrs, true );
		if ( 'core/button' === $name ) {
			if ( ! empty( $attrs['fontSize'] ) || ! empty( $attrs['style']['typography']['fontSize'] ) ) {
				$props['classes'][] = 'has-custom-font-size';
			}
			if ( isset( $attrs['style']['border']['radius'] ) && 0 === $attrs['style']['border']['radius'] ) {
				$props['classes'][] = 'no-border-radius';
			}
		}
		return array(
			'classes' => array_values( array_unique( $props['classes'] ) ),
			'styles'  => $props['styles'],
			'attrs'   => array(),
		);
	}

	/**
	 * Swaps the managed classes / styles / attributes of one tag (others untouched).
	 *
	 * @param \WP_HTML_Tag_Processor $tags Processor on the tag.
	 * @param array<string,mixed>    $old  Old managed props.
	 * @param array<string,mixed>    $new  New managed props.
	 */
	private static function swap( \WP_HTML_Tag_Processor $tags, array $old, array $new ): void {
		foreach ( array_diff( $old['classes'], $new['classes'] ) as $class ) {
			$tags->remove_class( $class );
		}
		foreach ( array_diff( $new['classes'], $old['classes'] ) as $class ) {
			$tags->add_class( $class );
		}
		if ( $old['styles'] !== $new['styles'] ) {
			$current = self::parse_style( (string) $tags->get_attribute( 'style' ) );
			foreach ( array_keys( $old['styles'] ) as $prop ) {
				unset( $current[ $prop ] );
			}
			foreach ( $new['styles'] as $prop => $value ) {
				$current[ $prop ] = $value;
			}
			if ( $current ) {
				$css = '';
				foreach ( $current as $prop => $value ) {
					$css .= $prop . ':' . $value . ';';
				}
				$tags->set_attribute( 'style', $css );
			} else {
				$tags->remove_attribute( 'style' );
			}
		}
		foreach ( (array) $new['attrs'] as $attr => $value ) {
			if ( ( $old['attrs'][ $attr ] ?? null ) === $value ) {
				continue;
			}
			if ( null === $value ) {
				$tags->remove_attribute( $attr );
			} else {
				$tags->set_attribute( $attr, $value );
			}
		}
		$class = $tags->get_attribute( 'class' );
		if ( is_string( $class ) && '' === trim( $class ) ) {
			$tags->remove_attribute( 'class' );
		}
	}

	/**
	 * Inline style → property map (order kept).
	 *
	 * @param string $style Style attribute.
	 * @return array<string,string>
	 */
	public static function parse_style( string $style ): array {
		$out = array();
		foreach ( explode( ';', $style ) as $declaration ) {
			$pos = strpos( $declaration, ':' );
			if ( false === $pos ) {
				continue;
			}
			$prop = strtolower( trim( substr( $declaration, 0, $pos ) ) );
			if ( '' !== $prop ) {
				$out[ $prop ] = trim( substr( $declaration, $pos + 1 ) );
			}
		}
		return $out;
	}

	/**
	 * A CSS value (length, %, var()) or an exception.
	 *
	 * @param string $value Value.
	 * @throws \InvalidArgumentException When the value is not a plain CSS value.
	 */
	public static function css_value( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^[A-Za-z0-9.%#(),+\-*\/ ]{1,100}$/', $value ) || preg_match( '/url\s*\(|expression/i', $value ) ) {
			throw new \InvalidArgumentException( sprintf( '"%s" is not a plain CSS value.', esc_html( $value ) ) );
		}
		return $value;
	}

	/**
	 * Cover overlay span (WordPress 7 cover save: dimRatioToClass, has-background-dim, overlay color).
	 *
	 * @param array<string,mixed> $old  Old.
	 * @param array<string,mixed> $new  New.
	 * @param array<int,string>   $html Parts.
	 * @return array<int,string>
	 * @throws \InvalidArgumentException When the cover has no overlay span.
	 */
	private static function cover_overlay( array $old, array $new, array $html ): array {
		$o = self::overlay_props( $old );
		$n = self::overlay_props( $new );
		if ( $o === $n ) {
			return $html;
		}
		$all  = implode( "\x01", $html );
		$tags = new \WP_HTML_Tag_Processor( $all );
		if ( ! $tags->next_tag( array( 'class_name' => 'wp-block-cover__background' ) ) ) {
			throw new \InvalidArgumentException( 'This cover has no overlay to change: replace it with new blocks instead.' );
		}
		self::swap( $tags, $o, $n );
		return explode( "\x01", self::tidy( $tags->get_updated_html() ) );
	}

	/**
	 * Managed classes / styles of the cover overlay span.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array{classes:array<int,string>,styles:array<string,string>,attrs:array<string,string|null>}
	 */
	public static function overlay_props( array $attrs ): array {
		$type    = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/cover' );
		$default = $type && isset( $type->attributes['dimRatio']['default'] ) ? $type->attributes['dimRatio']['default'] : null;
		$dim     = array_key_exists( 'dimRatio', $attrs ) ? $attrs['dimRatio'] : $default;
		$classes = array();
		$styles  = array();
		if ( ! empty( $attrs['overlayColor'] ) ) {
			$classes[] = 'has-' . _wp_to_kebab_case( (string) $attrs['overlayColor'] ) . '-background-color';
		}
		if ( null !== $dim && 50 !== (int) $dim ) {
			$classes[] = 'has-background-dim-' . ( 10 * (int) round( (int) $dim / 10 ) );
		}
		if ( null !== $dim ) {
			$classes[] = 'has-background-dim';
		}
		if ( empty( $attrs['overlayColor'] ) && ! empty( $attrs['customOverlayColor'] ) ) {
			$styles['background-color'] = self::css_color( (string) $attrs['customOverlayColor'] );
		}
		return array(
			'classes' => $classes,
			'styles'  => $styles,
			'attrs'   => array(),
		);
	}

	/**
	 * A CSS color (hex, rgb[a], hsl[a], a palette variable, a CSS color name) or an exception.
	 *
	 * @param string $color Color.
	 * @throws \InvalidArgumentException When it is not a color.
	 */
	public static function css_color( string $color ): string {
		$color = trim( $color );
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $color ) || preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.%,\s\/deg-]+\)$/i', $color ) || preg_match( '/^var\(--wp--preset--color--[a-z0-9-]+\)$/', $color ) || preg_match( '/^var:preset\|color\|[a-z0-9-]+$/', $color ) || preg_match( '/^[a-z]{3,20}$/i', $color ) ) {
			return $color;
		}
		throw new \InvalidArgumentException( sprintf( '"%s" is not a color (use #hex, rgb(), hsl() or a palette color).', esc_html( $color ) ) );
	}
}
