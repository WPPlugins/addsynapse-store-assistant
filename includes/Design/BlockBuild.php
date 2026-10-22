<?php
/**
 * New blocks from a structured description: the exact markup the block editor saves (WordPress 7 save functions).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * {name, attrs, text, url, image_id, alt, inner[]} → serialized block markup that the editor validates: the wrapper
 * classes / inline styles come from the same support mirror as attribute changes (BlockAttrs), the block's own markup
 * from its save() in WordPress 7.x. Texts are sanitised (ContentText), images come from the media library only.
 * Other blocks: plan_blocks_edit accepts complete `markup` (checked by BlockMarkup) or a pattern.
 */
final class BlockBuild {

	/**
	 * Blocks built here → whether they hold inner blocks.
	 */
	public const BLOCKS = array(
		'core/paragraph' => false,
		'core/heading'   => false,
		'core/list'      => true,
		'core/list-item' => false,
		'core/quote'     => true,
		'core/buttons'   => true,
		'core/button'    => false,
		'core/group'     => true,
		'core/columns'   => true,
		'core/column'    => true,
		'core/image'     => false,
		'core/cover'     => true,
		'core/spacer'    => false,
		'core/separator' => false,
	);

	public const MAX_BLOCKS = 60;

	/**
	 * Number of blocks built in the current call.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * External links found.
	 *
	 * @var array<int,string>
	 */
	public array $external = array();

	/**
	 * Markup of a list of blocks.
	 *
	 * @param array<int,mixed> $blocks Blocks.
	 * @param int              $depth  Depth.
	 * @param string           $parent Parent block name.
	 * @throws \InvalidArgumentException When a block is not valid.
	 */
	public function markup( array $blocks, int $depth = 0, string $parent = '' ): string {
		if ( $depth > 6 ) {
			throw new \InvalidArgumentException( 'Blocks are nested too deeply.' );
		}
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				throw new \InvalidArgumentException( 'Each block is an object {name, attrs, text, …}.' );
			}
			$out[] = $this->block( $block, $depth, $parent );
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Markup of one block.
	 *
	 * @param array<string,mixed> $block  Block.
	 * @param int                 $depth  Depth.
	 * @param string              $parent Parent.
	 * @throws \InvalidArgumentException When not valid.
	 */
	private function block( array $block, int $depth, string $parent ): string {
		if ( ++$this->count > self::MAX_BLOCKS ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'At most %d new blocks per operation.', self::MAX_BLOCKS ) ) );
		}
		$name = (string) ( $block['name'] ?? '' );
		if ( false === strpos( $name, '/' ) ) {
			$name = 'core/' . $name;
		}
		if ( ! array_key_exists( $name, self::BLOCKS ) ) {
			throw new \InvalidArgumentException( sprintf( '%1$s cannot be built from a description: use one of %2$s, a pattern, or complete markup.', esc_html( $name ), esc_html( implode( ', ', array_keys( self::BLOCKS ) ) ) ) );
		}
		$needs = array(
			'core/list-item' => 'core/list',
			'core/button'    => 'core/buttons',
			'core/column'    => 'core/columns',
		);
		if ( isset( $needs[ $name ] ) && $needs[ $name ] !== $parent && $depth > 0 ) {
			throw new \InvalidArgumentException( sprintf( 'A %1$s block goes inside %2$s.', esc_html( $name ), esc_html( $needs[ $name ] ) ) );
		}
		foreach ( $needs as $child => $container ) {
			if ( $container === $parent && $child !== $name ) {
				throw new \InvalidArgumentException( sprintf( 'A %1$s block holds only %2$s blocks.', esc_html( $container ), esc_html( $child ) ) );
			}
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$value = BlockAttrs::value_problem( $attrs );
		if ( '' !== $value ) {
			throw new \InvalidArgumentException( esc_html( $value ) );
		}
		$type  = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$attrs = BlockAttrs::normalise( $name, $attrs, $type );
		foreach ( array_keys( $attrs ) as $key ) {
			if ( isset( $type->attributes[ $key ]['source'] ) && 'anchor' !== $key ) {
				throw new \InvalidArgumentException( sprintf( '"%1$s" of %2$s is given with text / url / image_id, not in attrs.', esc_html( $key ), esc_html( $name ) ) );
			}
		}
		BlockAttrs::check_values( $attrs );
		$inner = '';
		if ( self::BLOCKS[ $name ] ) {
			$children = is_array( $block['inner'] ?? null ) ? $block['inner'] : array();
			if ( ! $children && in_array( $name, array( 'core/list', 'core/buttons', 'core/columns' ), true ) ) {
				throw new \InvalidArgumentException( sprintf( 'A %s block needs inner blocks.', esc_html( $name ) ) );
			}
			$inner = $children ? $this->markup( $children, $depth + 1, $name ) : '';
		} elseif ( ! empty( $block['inner'] ) ) {
			throw new \InvalidArgumentException( sprintf( 'A %s block has no inner blocks.', esc_html( $name ) ) );
		}
		$text = isset( $block['text'] ) ? (string) $block['text'] : '';
		switch ( $name ) {
			case 'core/paragraph':
				return $this->wrap( $name, $attrs, 'p', $this->rich( $text, 'text' ) );
			case 'core/heading':
				$level = (int) ( $attrs['level'] ?? 2 );
				if ( $level < 1 || $level > 6 ) {
					throw new \InvalidArgumentException( 'A heading level is 1 to 6.' );
				}
				if ( 2 === $level ) {
					unset( $attrs['level'] );
				}
				return $this->wrap( $name, $attrs, 'h' . $level, $this->rich( $text, 'heading' ) );
			case 'core/list':
				return $this->wrap( $name, $attrs, empty( $attrs['ordered'] ) ? 'ul' : 'ol', "\n" . $inner . "\n" );
			case 'core/list-item':
				return $this->wrap( $name, $attrs, 'li', $this->rich( $text, 'text' ) );
			case 'core/quote':
				$cite = '' !== $text ? '<cite>' . $this->rich( $text, 'heading' ) . '</cite>' : '';
				return $this->wrap( $name, $attrs, 'blockquote', "\n" . $inner . "\n" . $cite );
			case 'core/buttons':
			case 'core/group':
			case 'core/columns':
			case 'core/column':
				$tag = 'core/group' === $name ? (string) ( $attrs['tagName'] ?? 'div' ) : 'div';
				if ( ! in_array( $tag, array( 'div', 'header', 'main', 'section', 'article', 'aside', 'footer' ), true ) ) {
					throw new \InvalidArgumentException( 'tagName of a group is div, header, main, section, article, aside or footer.' );
				}
				if ( 'core/group' === $name && ! isset( $attrs['layout'] ) ) {
					$attrs['layout'] = array( 'type' => 'constrained' );
				}
				return $this->wrap( $name, $attrs, $tag, '' !== $inner ? $inner : '' );
			case 'core/button':
				return $this->button( $attrs, $text, isset( $block['url'] ) ? (string) $block['url'] : '' );
			case 'core/image':
				return $this->image( $attrs, (int) ( $block['image_id'] ?? 0 ), isset( $block['alt'] ) ? (string) $block['alt'] : null, $text, isset( $block['url'] ) ? (string) $block['url'] : '' );
			case 'core/cover':
				return $this->cover( $attrs, (int) ( $block['image_id'] ?? 0 ), $inner );
			case 'core/spacer':
				$attrs['height'] = isset( $attrs['height'] ) ? ( is_numeric( $attrs['height'] ) ? (int) $attrs['height'] . 'px' : (string) $attrs['height'] ) : '40px';
				$open            = $this->open_tag( $name, $attrs, 'div', array( 'aria-hidden' => 'true' ) );
				return $this->comment( $name, $attrs, $open . '</div>' );
			case 'core/separator':
				$attrs += array( 'opacity' => 'alpha-channel' );
				return $this->separator( $attrs );
		}//end switch
		throw new \InvalidArgumentException( 'Unknown block.' );
	}

	/**
	 * Sanitised rich text.
	 *
	 * @param string $text    Text.
	 * @param string $context heading|text.
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private function rich( string $text, string $context ): string {
		if ( '' === trim( $text ) ) {
			throw new \InvalidArgumentException( 'This block needs a text.' );
		}
		$clean = DesignText::rich( $text, $context );
		if ( is_wp_error( $clean ) ) {
			throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
		}
		$this->external = array_merge( $this->external, $clean['external'] );
		return $clean['html'];
	}

	/**
	 * Block comment around HTML.
	 *
	 * @param string              $name  Block.
	 * @param array<string,mixed> $attrs Attributes.
	 * @param string              $html  HTML.
	 */
	private function comment( string $name, array $attrs, string $html ): string {
		return BlockDoc::opener( $name, $attrs, false ) . "\n" . $html . "\n" . '<!-- /wp:' . BlockDoc::short( $name ) . ' -->';
	}

	/**
	 * Opening tag with the wrapper props (generated class + supports + block-specific).
	 *
	 * @param string               $name  Block.
	 * @param array<string,mixed>  $attrs Attributes.
	 * @param string               $tag   Tag.
	 * @param array<string,string> $extra Extra HTML attributes.
	 */
	private function open_tag( string $name, array $attrs, string $tag, array $extra = array() ): string {
		$type    = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$props   = BlockAttrs::wrapper_props( $name, $attrs );
		$classes = $props['classes'];
		if ( ! $type || ! array_key_exists( 'className', (array) $type->supports ) || false !== $type->supports['className'] ) {
			array_unshift( $classes, 'wp-block-' . str_replace( array( 'core/', '/' ), array( '', '-' ), $name ) );
		}
		$out = '<' . $tag;
		foreach ( $props['attrs'] as $key => $value ) {
			if ( null !== $value ) {
				$out .= ' ' . $key . '="' . esc_attr( $value ) . '"';
			}
		}
		if ( $classes ) {
			$out .= ' class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '"';
		}
		if ( $props['styles'] ) {
			$css = '';
			foreach ( $props['styles'] as $prop => $value ) {
				$css .= $prop . ':' . $value . ';';
			}
			$out .= ' style="' . esc_attr( $css ) . '"';
		}
		foreach ( $extra as $key => $value ) {
			$out .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}
		return $out . '>';
	}

	/**
	 * A wrapped block: <tag …>inner</tag> in its comment.
	 *
	 * @param string              $name  Block.
	 * @param array<string,mixed> $attrs Attributes.
	 * @param string              $tag   Tag.
	 * @param string              $inner Inner HTML.
	 */
	private function wrap( string $name, array $attrs, string $tag, string $inner ): string {
		return $this->comment( $name, $attrs, $this->open_tag( $name, $attrs, $tag ) . $inner . '</' . $tag . '>' );
	}

	/**
	 * Button.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @param string              $text  Label.
	 * @param string              $url   Link.
	 * @throws \InvalidArgumentException When not valid.
	 */
	private function button( array $attrs, string $text, string $url ): string {
		$label = DesignText::plain( $text, 'button' );
		if ( is_wp_error( $label ) ) {
			throw new \InvalidArgumentException( esc_html( $label->get_error_message() ) );
		}
		if ( ! empty( $attrs['style']['typography']['fontSize'] ) ) {
			throw new \InvalidArgumentException( 'A custom button text size is set site-wide (plan_global_styles) or with a fontSize preset slug.' );
		}
		$href = '';
		if ( '' !== $url ) {
			$clean = DesignText::url( $url );
			if ( is_wp_error( $clean ) ) {
				throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
			}
			$href = $clean['url'];
			if ( $clean['external'] ) {
				$this->external[] = $href;
			}
		}
		$inner = BlockAttrs::inner_props( 'core/button', $attrs );
		$class = array_merge( array( 'wp-block-button__link' ), $inner['classes'], array( 'wp-element-button' ) );
		$link  = '<a class="' . esc_attr( implode( ' ', array_unique( $class ) ) ) . '"' . ( '' !== $href ? ' href="' . esc_url( $href ) . '"' : '' );
		if ( $inner['styles'] ) {
			$css = '';
			foreach ( $inner['styles'] as $prop => $value ) {
				$css .= $prop . ':' . $value . ';';
			}
			$link .= ' style="' . esc_attr( $css ) . '"';
		}
		$link .= '>' . esc_html( $label ) . '</a>';
		return $this->comment( 'core/button', $attrs, $this->open_tag( 'core/button', $attrs, 'div' ) . $link . '</div>' );
	}

	/**
	 * Image of the media library.
	 *
	 * @param array<string,mixed> $attrs    Attributes.
	 * @param int                 $image_id Attachment.
	 * @param string|null         $alt      Alt (null = the image's own).
	 * @param string              $caption  Caption.
	 * @param string              $url      Link.
	 * @throws \InvalidArgumentException When not valid.
	 */
	private function image( array $attrs, int $image_id, ?string $alt, string $caption, string $url ): string {
		$size  = (string) ( $attrs['sizeSlug'] ?? 'large' );
		$image = DesignText::image( $image_id, $size );
		if ( is_wp_error( $image ) ) {
			throw new \InvalidArgumentException( esc_html( $image->get_error_message() ) );
		}
		$alt = null === $alt ? (string) $image['alt'] : $alt;
		$alt = DesignText::plain( $alt, 'alt' );
		if ( is_wp_error( $alt ) ) {
			throw new \InvalidArgumentException( esc_html( $alt->get_error_message() ) );
		}
		$attrs = array_merge(
			array(
				'id'       => $image_id,
				'sizeSlug' => $size,
			),
			$attrs
		);
		unset( $attrs['width'], $attrs['height'] );
		$img = '<img src="' . esc_url( (string) $image['image_url'] ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $image_id . '"/>';
		if ( '' !== $url ) {
			$clean = DesignText::url( $url );
			if ( is_wp_error( $clean ) ) {
				throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
			}
			$attrs['linkDestination'] = 'custom';
			$img                      = '<a href="' . esc_url( $clean['url'] ) . '">' . $img . '</a>';
			if ( $clean['external'] ) {
				$this->external[] = $clean['url'];
			}
		}
		$figcaption = '' !== trim( $caption ) ? '<figcaption class="wp-element-caption">' . $this->rich( $caption, 'heading' ) . '</figcaption>' : '';
		return $this->comment( 'core/image', $attrs, $this->open_tag( 'core/image', $attrs, 'figure' ) . $img . $figcaption . '</figure>' );
	}

	/**
	 * Cover with a media library image (or a color when no image).
	 *
	 * @param array<string,mixed> $attrs    Attributes.
	 * @param int                 $image_id Attachment (0 = color only).
	 * @param string              $inner    Inner blocks markup.
	 * @throws \InvalidArgumentException When not valid.
	 */
	private function cover( array $attrs, int $image_id, string $inner ): string {
		$img = '';
		if ( $image_id > 0 ) {
			$image = DesignText::image( $image_id, (string) ( $attrs['sizeSlug'] ?? 'full' ) );
			if ( is_wp_error( $image ) ) {
				throw new \InvalidArgumentException( esc_html( $image->get_error_message() ) );
			}
			$attrs = array_merge(
				array(
					'url'      => (string) $image['image_url'],
					'id'       => $image_id,
					'dimRatio' => 50,
					'isDark'   => true,
				),
				$attrs
			);
			$size  = isset( $attrs['sizeSlug'] ) ? ' size-' . sanitize_html_class( (string) $attrs['sizeSlug'] ) : '';
			$img   = '<img class="wp-block-cover__image-background wp-image-' . $image_id . $size . '" alt="" src="' . esc_url( (string) $image['image_url'] ) . '" data-object-fit="cover"/>';
		} else {
			$attrs += array(
				'dimRatio' => 100,
				'isDark'   => true,
			);
			if ( empty( $attrs['overlayColor'] ) && empty( $attrs['customOverlayColor'] ) ) {
				throw new \InvalidArgumentException( 'A cover needs image_id or overlayColor / customOverlayColor.' );
			}
		}//end if
		if ( ! isset( $attrs['minHeight'] ) ) {
			$attrs['minHeight']     = 420;
			$attrs['minHeightUnit'] = 'px';
		}
		if ( ! isset( $attrs['layout'] ) ) {
			$attrs['layout'] = array( 'type' => 'constrained' );
		}
		$overlay = BlockAttrs::overlay_props( $attrs );
		$span    = '<span aria-hidden="true" class="' . esc_attr( implode( ' ', array_merge( array( 'wp-block-cover__background' ), $overlay['classes'] ) ) ) . '"';
		if ( $overlay['styles'] ) {
			$span .= ' style="background-color:' . esc_attr( $overlay['styles']['background-color'] ) . '"';
		}
		$span .= '></span>';
		$html  = $this->open_tag( 'core/cover', $attrs, 'div' ) . $img . $span . '<div class="wp-block-cover__inner-container">' . ( '' !== $inner ? "\n" . $inner . "\n" : '' ) . '</div></div>';
		return $this->comment( 'core/cover', $attrs, $html );
	}

	/**
	 * Separator (save: has-text-color + color class from backgroundColor, opacity class).
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private function separator( array $attrs ): string {
		$extra = array();
		$css   = array();
		if ( ! empty( $attrs['backgroundColor'] ) || ! empty( $attrs['style']['color']['background'] ) ) {
			$extra[] = 'has-text-color';
		}
		if ( ! empty( $attrs['backgroundColor'] ) ) {
			$extra[] = 'has-' . _wp_to_kebab_case( (string) $attrs['backgroundColor'] ) . '-color';
		} elseif ( ! empty( $attrs['style']['color']['background'] ) ) {
			$css['color'] = BlockAttrs::css_color( (string) $attrs['style']['color']['background'] );
		}
		$extra[] = 'css' === ( $attrs['opacity'] ?? '' ) ? 'has-css-opacity' : 'has-alpha-channel-opacity';
		if ( ! empty( $attrs['backgroundColor'] ) ) {
			$extra[] = 'has-' . _wp_to_kebab_case( (string) $attrs['backgroundColor'] ) . '-background-color';
			$extra[] = 'has-background';
		} elseif ( ! empty( $attrs['style']['color']['background'] ) ) {
			$extra[]                 = 'has-background';
			$css['background-color'] = BlockAttrs::css_color( (string) $attrs['style']['color']['background'] );
		}
		$tag   = (string) ( $attrs['tagName'] ?? 'hr' );
		$props = BlockAttrs::wrapper_props( 'core/separator', $attrs );
		$class = array_unique( array_merge( array( 'wp-block-separator' ), $props['classes'], $extra ) );
		$style = array_merge( $props['styles'], $css );
		$out   = '<' . ( 'div' === $tag ? 'div' : 'hr' ) . ' class="' . esc_attr( implode( ' ', $class ) ) . '"';
		if ( $style ) {
			$s = '';
			foreach ( $style as $prop => $value ) {
				$s .= $prop . ':' . $value . ';';
			}
			$out .= ' style="' . esc_attr( $s ) . '"';
		}
		$out .= 'div' === $tag ? '></div>' : '/>';
		return $this->comment( 'core/separator', $attrs, $out );
	}
}
