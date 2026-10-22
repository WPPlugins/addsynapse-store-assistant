<?php
/**
 * Elementor documents: the element tree of `_elementor_data`, read and edited as data.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Abilities\Shared;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor stores a page (or a Theme Builder header / footer, a saved template…) as a JSON list of elements:
 * {id, elType container|section|column|widget, widgetType, settings{…}, elements[…], isInner}. Element ids are stable
 * (Elementor's own 7-character ids) and new elements get fresh unique ids. Every setting value is plain data: code-like
 * keys (custom CSS / HTML / attributes / scripts) are refused, rich texts go through kses, links through the link checks,
 * images must come from the media library, colors are CSS colors or global color references.
 */
final class ElementorDoc {

	public const MAX_NEW = 30;

	/**
	 * Widgets that may be inserted.
	 */
	public const WIDGETS = array( 'heading', 'text-editor', 'button', 'image', 'icon-list', 'spacer', 'divider', 'icon', 'icon-box', 'image-box', 'testimonial', 'star-rating', 'video' );

	/**
	 * Main text setting of a widget (set_text).
	 */
	public const TEXT = array(
		'heading'     => array( 'title', 'rich' ),
		'text-editor' => array( 'editor', 'rich' ),
		'button'      => array( 'text', 'plain' ),
		'icon-box'    => array( 'title_text', 'rich' ),
		'image-box'   => array( 'title_text', 'rich' ),
		'testimonial' => array( 'testimonial_content', 'rich' ),
	);

	/**
	 * Keys allowed although they look code-like.
	 */
	private const SAFE_KEYS = array( '_css_classes', 'css_filters', 'css_filters_hover', 'html_tag' );

	/**
	 * Settings holding rich text.
	 */
	private const RICH = array( 'editor', 'title', 'title_text', 'description_text', 'testimonial_content', 'testimonial_name', 'testimonial_job', 'text', 'caption', 'description', 'tab_title', 'tab_content' );

	/**
	 * Decoded document (list of elements).
	 *
	 * @var array<int,mixed>
	 */
	public array $data;

	/**
	 * Id generator.
	 *
	 * @var ElementorIds
	 */
	private ElementorIds $ids;

	/**
	 * External links met.
	 *
	 * @var array<int,string>
	 */
	public array $external = array();

	/**
	 * Parses the stored JSON.
	 *
	 * @param string        $raw    JSON.
	 * @param callable|null $random Id source (tests).
	 * @throws \RuntimeException When not an Elementor element list.
	 */
	public function __construct( string $raw, ?callable $random = null ) {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ( array() !== $data && ! wp_is_numeric_array( $data ) ) ) {
			throw new \RuntimeException( 'The Elementor data of this document is not valid.' );
		}
		$this->data = array_values( $data );
		$this->ids  = new ElementorIds( ElementorIds::ids_in( $raw ), $random );
	}

	/**
	 * The JSON to store.
	 */
	public function serialize(): string {
		return (string) wp_json_encode( $this->data );
	}

	/**
	 * Whether the document uses flexbox containers (else legacy sections / columns).
	 */
	public function containers(): bool {
		foreach ( $this->data as $element ) {
			if ( is_array( $element ) && 'section' === ( $element['elType'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Every element in reading order: {id, element (copy), depth, parent, ancestors}.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function elements(): array {
		$out  = array();
		$walk = static function ( array $items_list, array $ancestors ) use ( &$walk, &$out ): void {
			foreach ( $items_list as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}
				$out[] = array(
					'id'        => (string) ( $element['id'] ?? '' ),
					'element'   => $element,
					'depth'     => count( $ancestors ),
					'parent'    => (string) ( $ancestors ? end( $ancestors ) : '' ),
					'ancestors' => $ancestors,
				);
				$walk( (array) ( $element['elements'] ?? array() ), array_merge( $ancestors, array( (string) ( $element['id'] ?? '' ) ) ) );
			}
		};
		$walk( $this->data, array() );
		return $out;
	}

	/**
	 * Path (indexes) of an element.
	 *
	 * @param string                $id   Id.
	 * @param array<int,mixed>|null $items_list List (null = document).
	 * @return array<int,int>|null
	 */
	private function locate( string $id, ?array $items_list = null ): ?array {
		foreach ( $items_list ?? $this->data as $i => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return array( (int) $i );
			}
			$sub = $this->locate( $id, (array) ( $element['elements'] ?? array() ) );
			if ( null !== $sub ) {
				return array_merge( array( (int) $i ), $sub );
			}
		}
		return null;
	}

	/**
	 * An element (copy) or an exception.
	 *
	 * @param string $id Id.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When missing.
	 */
	public function get( string $id ): array {
		$path = '' !== $id ? $this->locate( $id ) : null;
		if ( null === $path ) {
			throw new \InvalidArgumentException( sprintf( 'Element %s was not found (read the document again with describe_blocks).', esc_html( $id ) ) );
		}
		$element = array( 'elements' => $this->data );
		foreach ( $path as $i ) {
			$element = $element['elements'][ $i ];
		}
		return $element;
	}

	/**
	 * Runs $change on the element list that holds the element at $path (by reference).
	 *
	 * @param array<int,int> $path   Path.
	 * @param callable       $change function( array &$items_list, int $index ): void.
	 */
	private function in_list( array $path, callable $change ): void {
		$items_list = &$this->data;
		foreach ( array_slice( $path, 0, -1 ) as $i ) {
			$items_list = &$items_list[ $i ]['elements'];
		}
		$change( $items_list, (int) end( $path ) );
		unset( $items_list );
	}

	/**
	 * Replaces an element.
	 *
	 * @param string              $id      Id.
	 * @param array<string,mixed> $element New element.
	 */
	private function put( string $id, array $element ): void {
		$this->in_list(
			(array) $this->locate( $id ),
			static function ( array &$items_list, int $index ) use ( $element ): void {
				$items_list[ $index ] = $element;
			}
		);
	}

	/**
	 * Merges settings into an element (null removes a key).
	 *
	 * @param string              $id       Element.
	 * @param array<string,mixed> $settings Settings.
	 * @return array{before:array<string,mixed>,after:array<string,mixed>}
	 * @throws \InvalidArgumentException When a setting is not allowed.
	 */
	public function set_settings( string $id, array $settings ): array {
		$element = $this->get( $id );
		$current = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$before  = array();
		$after   = array();
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			self::check_key( $key );
			$before[ $key ] = $current[ $key ] ?? null;
			if ( null === $value ) {
				unset( $current[ $key ] );
				$after[ $key ] = null;
				continue;
			}
			$value           = $this->clean_value( $key, $value );
			$current[ $key ] = '__globals__' === $key && is_array( $current['__globals__'] ?? null ) ? array_merge( $current['__globals__'], $value ) : $value;
			$after[ $key ]   = $current[ $key ];
		}
		$element['settings'] = $current;
		$this->put( $id, $element );
		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Sets the main text of a widget.
	 *
	 * @param string $id   Element.
	 * @param string $text Text.
	 * @return array{before:string,after:string}
	 * @throws \InvalidArgumentException When the element has no text.
	 */
	public function set_text( string $id, string $text ): array {
		$element = $this->get( $id );
		$widget  = (string) ( $element['widgetType'] ?? '' );
		if ( ! isset( self::TEXT[ $widget ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Element %1$s (%2$s) has no main text: use set_settings with its setting key.', esc_html( $id ), esc_html( '' !== $widget ? $widget : (string) ( $element['elType'] ?? '' ) ) ) );
		}
		list( $key, $kind ) = self::TEXT[ $widget ];
		$old                = (string) ( $element['settings'][ $key ] ?? '' );
		if ( 'plain' === $kind ) {
			$clean = DesignText::plain( $text, 'button' );
			if ( is_wp_error( $clean ) ) {
				throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
			}
		} else {
			$clean = $this->rich( $text, 'heading' === $widget ? 'heading' : 'text' );
		}
		$settings            = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$settings[ $key ]    = $clean;
		$element['settings'] = $settings;
		$this->put( $id, $element );
		return array(
			'before' => DesignText::excerpt( $old, 300 ),
			'after'  => DesignText::excerpt( $clean, 300 ),
		);
	}

	/**
	 * Sets the link of a widget.
	 *
	 * @param string $id  Element.
	 * @param string $url URL.
	 * @return array{before:string,after:string}
	 * @throws \InvalidArgumentException When not valid.
	 */
	public function set_link( string $id, string $url ): array {
		$element = $this->get( $id );
		if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'Only widgets have a link.' );
		}
		$old                 = (string) ( $element['settings']['link']['url'] ?? '' );
		$link                = $this->clean_value( 'link', array( 'url' => $url ) );
		$settings            = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$base                = is_array( $settings['link'] ?? null ) ? $settings['link'] : array(
			'is_external' => '',
			'nofollow'    => '',
		);
		$settings['link']    = array_merge( $base, $link );
		$element['settings'] = $settings;
		$this->put( $id, $element );
		return array(
			'before' => $old,
			'after'  => (string) $link['url'],
		);
	}

	/**
	 * Sets an image (image / image-box widget, or a container / section / column background).
	 *
	 * @param string      $id       Element.
	 * @param int         $image_id Attachment.
	 * @param string|null $alt      Alt.
	 * @return array{before:string,after:string}
	 * @throws \InvalidArgumentException When not valid.
	 */
	public function set_image( string $id, int $image_id, ?string $alt = null ): array {
		$element  = $this->get( $id );
		$widget   = (string) ( $element['widgetType'] ?? '' );
		$type     = (string) ( $element['elType'] ?? '' );
		$image    = $this->clean_value(
			'image',
			array_filter(
				array(
					'id'  => $image_id,
					'alt' => $alt,
				),
				static fn( $v ) => null !== $v
			)
		);
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		if ( in_array( $widget, array( 'image', 'image-box' ), true ) ) {
			$key = 'image';
		} elseif ( in_array( $type, array( 'container', 'section', 'column' ), true ) ) {
			$key                               = 'background_image';
			$settings['background_background'] = 'classic';
		} else {
			throw new \InvalidArgumentException( sprintf( 'Element %s has no image (image / image-box widgets and container backgrounds do).', esc_html( $id ) ) );
		}
		$old                 = (string) ( $settings[ $key ]['url'] ?? '' );
		$settings[ $key ]    = $image;
		$element['settings'] = $settings;
		$this->put( $id, $element );
		return array(
			'before' => $old,
			'after'  => (string) $image['url'],
		);
	}

	/**
	 * Removes an element.
	 *
	 * @param string $id Element.
	 * @return array<string,mixed> The removed element.
	 */
	public function remove( string $id ): array {
		$element = $this->get( $id );
		$this->in_list(
			(array) $this->locate( $id ),
			static function ( array &$items_list, int $index ): void {
				array_splice( $items_list, $index, 1 );
			}
		);
		return $element;
	}

	/**
	 * Inserts elements at a position.
	 *
	 * @param array<int,array<string,mixed>> $elements Elements (normalised).
	 * @param string                         $where    before|after|inside_first|inside_last|top|bottom.
	 * @param string                         $anchor   Anchor element.
	 * @throws \InvalidArgumentException When the position does not fit.
	 */
	public function insert( array $elements, string $where, string $anchor = '' ): void {
		if ( 'top' === $where || 'bottom' === $where ) {
			$elements = array_map( array( $this, 'top_level' ), $elements );
			array_splice( $this->data, 'top' === $where ? 0 : count( $this->data ), 0, $elements );
			return;
		}
		$target = $this->get( $anchor );
		$path   = (array) $this->locate( $anchor );
		if ( 'inside_first' === $where || 'inside_last' === $where ) {
			$type = (string) ( $target['elType'] ?? '' );
			if ( 'widget' === $type ) {
				throw new \InvalidArgumentException( 'A widget cannot contain other elements: insert before / after it, or inside its container.' );
			}
			foreach ( $elements as $element ) {
				self::check_child( $type, $element );
			}
			$children           = (array) ( $target['elements'] ?? array() );
			$elements           = array_map( static fn( $e ) => self::inner_flag( $e, true ), $elements );
			$target['elements'] = 'inside_first' === $where ? array_merge( $elements, $children ) : array_merge( $children, $elements );
			$this->put( $anchor, $target );
			return;
		}
		if ( 'before' !== $where && 'after' !== $where ) {
			throw new \InvalidArgumentException( 'where is before, after, inside_first, inside_last, top or bottom.' );
		}
		if ( 1 === count( $path ) ) {
			$elements = array_map( array( $this, 'top_level' ), $elements );
		} else {
			$parent = $this->get( $this->parent_id( $anchor ) );
			foreach ( $elements as $element ) {
				self::check_child( (string) ( $parent['elType'] ?? '' ), $element );
			}
			$elements = array_map( static fn( $e ) => self::inner_flag( $e, true ), $elements );
		}
		$this->in_list(
			$path,
			static function ( array &$items_list, int $index ) use ( $elements, $where ): void {
				array_splice( $items_list, $index + ( 'after' === $where ? 1 : 0 ), 0, $elements );
			}
		);
	}

	/**
	 * Id of an element's parent ('' = top level).
	 *
	 * @param string $id Element.
	 */
	private function parent_id( string $id ): string {
		foreach ( $this->elements() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry['parent'];
			}
		}
		return '';
	}

	/**
	 * What a container may hold.
	 *
	 * @param string              $type    Container elType.
	 * @param array<string,mixed> $element New child.
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function check_child( string $type, array $element ): void {
		$child = (string) ( $element['elType'] ?? '' );
		if ( 'section' === $type && 'column' !== $child ) {
			throw new \InvalidArgumentException( 'A section holds columns only: insert inside one of its columns.' );
		}
		if ( 'column' === $type && ! in_array( $child, array( 'widget', 'section' ), true ) ) {
			throw new \InvalidArgumentException( 'A column holds widgets (or an inner section).' );
		}
		if ( 'container' === $type && ! in_array( $child, array( 'widget', 'container' ), true ) ) {
			throw new \InvalidArgumentException( 'A container holds widgets and containers.' );
		}
		if ( 'column' === $child && 'section' !== $type ) {
			throw new \InvalidArgumentException( 'A column goes inside a section.' );
		}
	}

	/**
	 * A top-level element: widgets are wrapped (container, or section › column on legacy pages).
	 *
	 * @param array<string,mixed> $element Element.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When it cannot be top level.
	 */
	public function top_level( array $element ): array {
		$type = (string) ( $element['elType'] ?? '' );
		if ( $this->containers() ) {
			if ( 'container' === $type ) {
				return self::inner_flag( $element, false );
			}
			if ( 'widget' !== $type ) {
				throw new \InvalidArgumentException( 'This page uses containers: a top-level element is a container.' );
			}
			return array(
				'id'       => $this->ids->id(),
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array( $element ),
				'isInner'  => false,
			);
		}
		if ( 'section' === $type ) {
			return self::inner_flag( $element, false );
		}
		if ( 'widget' !== $type ) {
			throw new \InvalidArgumentException( 'This page uses sections: a top-level element is a section (with columns).' );
		}
		return array(
			'id'       => $this->ids->id(),
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => $this->ids->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array( $element ),
					'isInner'  => false,
				),
			),
			'isInner'  => false,
		);
	}

	/**
	 * Sets isInner of a container / section (widgets keep theirs).
	 *
	 * @param array<string,mixed> $element Element.
	 * @param bool                $inner   Nested.
	 * @return array<string,mixed>
	 */
	private static function inner_flag( array $element, bool $inner ): array {
		if ( in_array( $element['elType'] ?? '', array( 'container', 'section' ), true ) ) {
			$element['isInner'] = $inner;
		}
		return $element;
	}

	/**
	 * Moves an element.
	 *
	 * @param string $id     Element.
	 * @param string $where  Where.
	 * @param string $anchor Anchor.
	 * @throws \InvalidArgumentException When not possible.
	 */
	public function move( string $id, string $where, string $anchor = '' ): void {
		$this->get( $id );
		if ( '' !== $anchor ) {
			$this->get( $anchor );
			if ( $anchor === $id || $this->is_inside( $anchor, $id ) ) {
				throw new \InvalidArgumentException( 'An element cannot be moved next to or into itself.' );
			}
		}
		$element = $this->remove( $id );
		$this->insert( array( $element ), $where, $anchor );
	}

	/**
	 * Whether $id is inside $ancestor.
	 *
	 * @param string $id       Element.
	 * @param string $ancestor Ancestor.
	 */
	private function is_inside( string $id, string $ancestor ): bool {
		foreach ( $this->elements() as $entry ) {
			if ( $entry['id'] === $id ) {
				return in_array( $ancestor, $entry['ancestors'], true );
			}
		}
		return false;
	}

	/**
	 * Duplicates an element (fresh ids) right after it.
	 *
	 * @param string $id Element.
	 * @return array<string,mixed> The copy.
	 */
	public function duplicate( string $id ): array {
		$copy = $this->fresh( $this->get( $id ) );
		$this->in_list(
			(array) $this->locate( $id ),
			static function ( array &$items_list, int $index ) use ( $copy ): void {
				array_splice( $items_list, $index + 1, 0, array( $copy ) );
			}
		);
		return $copy;
	}

	/**
	 * A copy with fresh ids.
	 *
	 * @param array<string,mixed> $element Element.
	 * @return array<string,mixed>
	 */
	private function fresh( array $element ): array {
		$element['id'] = $this->ids->id();
		foreach ( (array) ( $element['elements'] ?? array() ) as $i => $child ) {
			if ( is_array( $child ) ) {
				$element['elements'][ $i ] = $this->fresh( $child );
			}
		}
		return $element;
	}

	/**
	 * New elements from a description: [{elType, widgetType, settings, elements[]}].
	 *
	 * @param array<int,mixed> $items_list  Description.
	 * @param int              $depth Depth.
	 * @param int              $count Running count.
	 * @return array<int,array<string,mixed>>
	 * @throws \InvalidArgumentException When not valid.
	 */
	public function build( array $items_list, int $depth = 0, int &$count = 0 ): array {
		if ( $depth > 4 ) {
			throw new \InvalidArgumentException( 'New elements are nested too deeply (max 4 levels).' );
		}
		$out = array();
		foreach ( $items_list as $spec ) {
			if ( ! is_array( $spec ) ) {
				throw new \InvalidArgumentException( 'Each new element is an object {elType, widgetType, settings, elements}.' );
			}
			if ( ++$count > self::MAX_NEW ) {
				throw new \InvalidArgumentException( sprintf( 'At most %d new elements per operation.', (int) self::MAX_NEW ) );
			}
			$type = (string) ( $spec['elType'] ?? ( isset( $spec['widgetType'] ) ? 'widget' : '' ) );
			if ( ! in_array( $type, array( 'container', 'section', 'column', 'widget' ), true ) ) {
				throw new \InvalidArgumentException( 'elType is container, section, column or widget.' );
			}
			$element = array(
				'id'       => $this->ids->id(),
				'elType'   => $type,
				'settings' => array(),
				'elements' => array(),
			);
			if ( 'widget' === $type ) {
				$widget = (string) ( $spec['widgetType'] ?? '' );
				if ( ! in_array( $widget, self::WIDGETS, true ) ) {
					throw new \InvalidArgumentException( sprintf( 'Widget "%1$s" cannot be added (allowed: %2$s).', esc_html( $widget ), esc_html( implode( ', ', self::WIDGETS ) ) ) );
				}
				$element['widgetType'] = $widget;
				if ( ! empty( $spec['elements'] ) ) {
					throw new \InvalidArgumentException( 'A widget has no inner elements.' );
				}
			} else {
				$element['isInner'] = $depth > 0;
				$children           = $this->build( (array) ( $spec['elements'] ?? array() ), $depth + 1, $count );
				foreach ( $children as $child ) {
					self::check_child( $type, $child );
				}
				$element['elements'] = $children;
			}
			$settings = is_array( $spec['settings'] ?? null ) ? $spec['settings'] : array();
			foreach ( $settings as $key => $value ) {
				self::check_key( (string) $key );
				if ( null !== $value ) {
					$element['settings'][ (string) $key ] = $this->clean_value( (string) $key, $value );
				}
			}
			if ( 'video' === ( $element['widgetType'] ?? '' ) ) {
				$url = (string) ( $element['settings']['youtube_url'] ?? $element['settings']['vimeo_url'] ?? '' );
				if ( ! preg_match( '#^https://(www\.)?(youtube\.com|youtu\.be|vimeo\.com)/#', $url ) ) {
					throw new \InvalidArgumentException( 'A video widget needs youtube_url or vimeo_url (an https YouTube / Vimeo address).' );
				}
			}
			$out[] = $element;
		}//end foreach
		return $out;
	}

	/**
	 * Refuses code-like setting keys.
	 *
	 * @param string $key Key.
	 * @throws \InvalidArgumentException When not allowed.
	 */
	public static function check_key( string $key ): void {
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,80}$/', $key ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid setting key "%s".', esc_html( $key ) ) );
		}
		if ( in_array( $key, self::SAFE_KEYS, true ) ) {
			return;
		}
		if ( preg_match( '/css|html|script|(^|_)js($|_)|attributes|shortcode|_code$|^code$/i', $key ) ) {
			throw new \InvalidArgumentException( sprintf( 'The setting "%s" holds code (CSS / HTML / attributes / scripts), which the assistant does not write: use the site styles or Additional CSS instead.', esc_html( $key ) ) );
		}
	}

	/**
	 * A checked setting value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return mixed
	 * @throws \InvalidArgumentException When not allowed.
	 */
	public function clean_value( string $key, $value ) {
		$problem = BlockAttrs::value_problem( $value, 0, $key );
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( $problem ) );
		}
		if ( '__globals__' === $key ) {
			if ( ! is_array( $value ) ) {
				throw new \InvalidArgumentException( '__globals__ maps setting keys to global references (globals/colors?id=primary).' );
			}
			foreach ( $value as $k => $ref ) {
				self::check_key( (string) $k );
				if ( '' !== $ref && ! preg_match( '#^globals/(colors|typography)\?id=[A-Za-z0-9_-]{1,40}$#', (string) $ref ) ) {
					throw new \InvalidArgumentException( 'A global reference is globals/colors?id=<id> or globals/typography?id=<id> (or "" to unlink).' );
				}
			}
			return $value;
		}
		$is_image = 'image' === $key || (bool) preg_match( '/_image$/', $key );
		if ( is_array( $value ) && $is_image && ( array_key_exists( 'id', $value ) || array_key_exists( 'url', $value ) ) ) {
			return $this->image_value( $value );
		}
		if ( is_array( $value ) && array_key_exists( 'url', $value ) && ( 'link' === $key || preg_match( '/_link$/', $key ) ) ) {
			$url = (string) $value['url'];
			if ( '' !== $url ) {
				$clean = DesignText::url( $url );
				if ( is_wp_error( $clean ) ) {
					throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
				}
				$value['url'] = $clean['url'];
				if ( $clean['external'] ) {
					$this->external[] = $clean['url'];
				}
			}
			foreach ( array( 'is_external', 'nofollow' ) as $flag ) {
				if ( isset( $value[ $flag ] ) ) {
					$value[ $flag ] = $value[ $flag ] && 'off' !== $value[ $flag ] ? 'on' : '';
				}
			}
			unset( $value['custom_attributes'] );
			return $value;
		}
		if ( is_string( $value ) ) {
			if ( preg_match( '/(^|_)color$/', $key ) && '' !== $value ) {
				return BlockAttrs::css_color( $value );
			}
			if ( in_array( $key, self::RICH, true ) && '' !== trim( $value ) ) {
				return $this->rich( $value, 'title' === $key ? 'heading' : 'text' );
			}
			if ( preg_match( '/_url$/', $key ) && '' !== $value ) {
				$clean = DesignText::url( $value );
				if ( is_wp_error( $clean ) ) {
					throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
				}
				return $clean['url'];
			}
			if ( preg_match( '/<[a-z\/!]/i', $value ) ) {
				return $this->rich( $value, 'text' );
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $item ) {
				if ( is_string( $k ) ) {
					self::check_key( $k );
				}
				$out[ $k ] = $this->clean_value( is_string( $k ) ? $k : $key . '_item', $item );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Image setting {url, id, size, alt, source: library} from the media library.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When not a library image.
	 */
	private function image_value( array $value ): array {
		$id = (int) ( $value['id'] ?? 0 );
		if ( $id < 1 ) {
			if ( '' === (string) ( $value['url'] ?? '' ) ) {
				return array(
					'url' => '',
					'id'  => '',
				);
			}
			throw new \InvalidArgumentException( 'Images must come from the media library (give its id from find_media), not a URL.' );
		}
		$image = DesignText::image( $id );
		if ( is_wp_error( $image ) ) {
			throw new \InvalidArgumentException( esc_html( $image->get_error_message() ) );
		}
		$alt = (string) $image['alt'];
		if ( isset( $value['alt'] ) ) {
			$clean = DesignText::plain( (string) $value['alt'], 'alt' );
			if ( is_wp_error( $clean ) ) {
				throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
			}
			$alt = $clean;
		}
		return array(
			'url'    => (string) $image['image_url'],
			'id'     => $id,
			'size'   => '',
			'alt'    => $alt,
			'source' => 'library',
		);
	}

	/**
	 * Sanitised rich text.
	 *
	 * @param string $text    Text.
	 * @param string $context heading|text.
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private function rich( string $text, string $context ): string {
		$clean = DesignText::rich( $text, $context );
		if ( is_wp_error( $clean ) ) {
			throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
		}
		$this->external = array_merge( $this->external, $clean['external'] );
		return $clean['html'];
	}

	/**
	 * Short label of an element.
	 *
	 * @param array<string,mixed> $element Element.
	 */
	public static function label( array $element ): string {
		$type   = (string) ( $element['elType'] ?? '' );
		$widget = (string) ( $element['widgetType'] ?? '' );
		$label  = 'widget' === $type ? ucfirst( str_replace( '-', ' ', $widget ) ) : ucfirst( $type );
		$text   = self::text( $element, 40 );
		return $label . ( '' !== $text ? ' “' . $text . '”' : '' );
	}

	/**
	 * Main text of an element.
	 *
	 * @param array<string,mixed> $element Element.
	 * @param int                 $max     Max chars.
	 */
	public static function text( array $element, int $max = 120 ): string {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		foreach ( array( 'title', 'editor', 'text', 'title_text', 'testimonial_content', 'description_text' ) as $key ) {
			if ( is_string( $settings[ $key ] ?? null ) && '' !== trim( $settings[ $key ] ) ) {
				return DesignText::excerpt( $settings[ $key ], $max );
			}
		}
		if ( isset( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ) {
			$items = array();
			foreach ( $settings['icon_list'] as $item ) {
				$items[] = is_array( $item ) ? (string) ( $item['text'] ?? '' ) : '';
			}
			return DesignText::excerpt( implode( ' · ', $items ), $max );
		}
		return '';
	}

	/**
	 * Settings shown by describe_blocks (values cut, big lists summarised).
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	private static function shown_settings( array $settings ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( count( $out ) >= 30 ) {
				$out['more'] = sprintf( '%d more settings', count( $settings ) - 30 );
				break;
			}
			if ( is_string( $value ) ) {
				$value = mb_strlen( $value ) > 120 ? mb_substr( wp_strip_all_tags( $value ), 0, 119 ) . '…' : $value;
			} elseif ( is_array( $value ) ) {
				$json = (string) wp_json_encode( $value );
				if ( strlen( $json ) > 240 ) {
					$value = wp_is_numeric_array( $value ) ? sprintf( '(%d items)', count( $value ) ) : '(object: ' . implode( ', ', array_slice( array_map( 'strval', array_keys( $value ) ), 0, 8 ) ) . ')';
				}
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * The describe_blocks answer for an Elementor document.
	 *
	 * @param array<string,mixed> $doc   Document (Docs::resolve).
	 * @param string              $block Only this element and what is inside it.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function describe( array $doc, string $block = '' ) {
		try {
			$tree = new self( (string) $doc['raw'] );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'addsynapse_assistant_design_elementor_data', $e->getMessage() );
		}
		$elements = array();
		$cut      = false;
		$all      = $tree->elements();
		foreach ( $all as $entry ) {
			if ( '' !== $block && $entry['id'] !== $block && ! in_array( $block, $entry['ancestors'], true ) ) {
				continue;
			}
			if ( count( $elements ) >= DescribeBlocks::MAX ) {
				$cut = true;
				break;
			}
			$element    = $entry['element'];
			$elements[] = array_filter(
				array(
					'id'       => $entry['id'],
					'type'     => (string) ( $element['elType'] ?? '' ),
					'widget'   => (string) ( $element['widgetType'] ?? '' ),
					'depth'    => $entry['depth'],
					'parent'   => $entry['parent'],
					'settings' => self::shown_settings( is_array( $element['settings'] ?? null ) ? $element['settings'] : array() ),
					'text'     => self::text( $element ),
					'children' => count( (array) ( $element['elements'] ?? array() ) ),
				),
				static fn( $v ) => '' !== $v && array() !== $v
			);
		}//end foreach
		if ( '' !== $block && ! $elements ) {
			return new WP_Error( 'addsynapse_assistant_design_block', sprintf( 'Element %s was not found (the document may have changed).', $block ) );
		}
		return array(
			'document'  => array_filter(
				array(
					'kind'           => 'elementor',
					'title'          => (string) $doc['title'],
					'post_id'        => (int) $doc['post_id'],
					'elementor_type' => (string) ( $doc['elementor_type'] ?? '' ),
					'layout'         => $tree->containers() ? 'containers' : 'sections',
					'url'            => (string) $doc['url'],
				),
				static fn( $v ) => '' !== $v
			),
			'elements'  => $elements,
			'count'     => count( $all ),
			'truncated' => $cut,
			'note'      => 'Use these element ids with plan_elementor_edit. Colors may point to the site\'s global colors through settings.__globals__ (e.g. {"title_color":"globals/colors?id=primary"}); change the global colors / fonts themselves with plan_elementor_kit.',
			'_data'     => Shared::DATA_NOTICE,
		);
	}



	/**
	 * Elementor's CSS of a post generated in memory, added inline to our own style handle.
	 *
	 * @param int    $post_id Post.
	 * @param string $handle  Style handle.
	 */
	public static function inline_css( int $post_id, string $handle ): void {
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return;
		}
		try {
			$css = (string) ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->get_content();
		} catch ( \Throwable $e ) {
			return;
		}
		wp_register_style( $handle, false, array(), ADDSYNAPSE_PRO_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, str_ireplace( '</', '<\\/', $css ) );
	}
}
