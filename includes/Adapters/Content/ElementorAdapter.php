<?php
/**
 * Elementor content adapter (`_elementor_data`).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Content;

use Addsynapse\StoreAssistant\Content\ContentText;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Elementor pages: the free widgets heading (title, link url), text-editor (editor), button (text, link url) and
 * image (media-library image id; link url when it has one). Containers/sections/columns are structure (they give the
 * path). Everything else — Elementor Pro / theme / third-party widgets, global widgets, atomic (v4) elements — is
 * listed as `unsupported`. Read-only in this plugin: it never writes pages.
 */
class ElementorAdapter implements ContentAdapterInterface {

	public const KIND = 'elementor';

	public const META = '_elementor_data';

	/**
	 * Widget type → node type.
	 */
	public const WIDGETS = array(
		'heading'     => 'heading',
		'text-editor' => 'text',
		'button'      => 'button',
		'image'       => 'image',
	);

	/**
	 * Structural element types.
	 */
	private const STRUCTURE = array( 'container', 'section', 'column' );

	/**
	 * {@inheritDoc}
	 */
	public function kind(): string {
		return self::KIND;
	}

	/**
	 * Whether Elementor is active (filter `addsynapse_assistant_elementor_active` for tests).
	 */
	public static function active(): bool {
		return (bool) apply_filters( 'addsynapse_assistant_elementor_active', defined( 'ELEMENTOR_VERSION' ) );
	}

	/**
	 * Whether the post is built with Elementor (edit mode "builder" + data), active or not.
	 *
	 * @param int $post_id Post id.
	 */
	public static function built_with( int $post_id ): bool {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && '' !== (string) get_post_meta( $post_id, self::META, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function detect( int $post_id ): bool {
		return self::active() && self::built_with( $post_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function raw( int $post_id ): ?string {
		$raw = get_post_meta( $post_id, self::META, true );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function read_nodes( int $post_id ): array {
		$raw = $this->raw( $post_id );
		return null === $raw ? array() : $this->nodes_from( $raw, $post_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $raw     JSON.
	 * @param int    $post_id Post id.
	 * @throws \RuntimeException When the JSON is invalid.
	 */
	public function nodes_from( string $raw, int $post_id ): array {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'The Elementor data of this page is not valid JSON.' );
		}
		$nodes = array();
		$this->walk( $data, array(), $post_id, $nodes );
		return $nodes;
	}

	/**
	 * Depth-first walk (reading order).
	 *
	 * @param array<int,mixed>               $elements  Elements.
	 * @param array<int,string>              $path      Ancestor labels.
	 * @param int                            $post_id   Post id.
	 * @param array<int,array<string,mixed>> $nodes     Output.
	 * @param array<int,string>              $hidden_on Devices on which an ancestor is hidden.
	 */
	private function walk( array $elements, array $path, int $post_id, array &$nodes, array $hidden_on = array() ): void {
		$counters = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type     = (string) ( $element['elType'] ?? '' );
			$id       = (string) ( $element['id'] ?? '' );
			$children = is_array( $element['elements'] ?? null ) ? $element['elements'] : array();
			$hidden   = self::hidden_on( $element, $hidden_on );
			if ( in_array( $type, self::STRUCTURE, true ) || ( 'widget' !== $type && $children ) ) {
				// Containers/sections/columns and atomic (v4) layout elements: structure only.
				$name              = in_array( $type, self::STRUCTURE, true ) ? ucfirst( $type ) : ucfirst( (string) preg_replace( '/^e-/', '', $type ) );
				$counters[ $name ] = ( $counters[ $name ] ?? 0 ) + 1;
				$this->walk( $children, array_merge( $path, array( $name . ' ' . $counters[ $name ] ) ), $post_id, $nodes, $hidden );
				continue;
			}
			if ( 'widget' !== $type ) {
				// Atomic (v4) / unknown element without children: read-only.
				$node = $this->node( $id, 'unsupported', $type, null, '', $path, $post_id, count( $nodes ), array(), 'This element type is not supported yet.' );
			} else {
				$node = $this->widget_node( $element, $path, $post_id, count( $nodes ) );
			}
			if ( $hidden ) {
				$node['hidden_on'] = $hidden;
			}
			$nodes[] = $node;
		}//end foreach
	}

	/**
	 * Devices on which an element is not shown (Elementor «Responsive → Hide on …», inherited from its ancestors).
	 *
	 * @param array<string,mixed> $element   Element.
	 * @param array<int,string>   $inherited Devices hidden by an ancestor.
	 * @return array<int,string> Subset of desktop|tablet|mobile, in that order.
	 */
	private static function hidden_on( array $element, array $inherited ): array {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$out      = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			$flag = $settings[ 'hide_' . $device ] ?? '';
			if ( in_array( $device, $inherited, true ) || ( is_string( $flag ) && '' !== $flag ) ) {
				$out[] = $device;
			}
		}
		return $out;
	}

	/**
	 * Normalized node of a widget.
	 *
	 * @param array<string,mixed> $element Element.
	 * @param array<int,string>   $path    Path.
	 * @param int                 $post_id Post id.
	 * @param int                 $order   Order.
	 * @return array<string,mixed>
	 */
	private function widget_node( array $element, array $path, int $post_id, int $order ): array {
		$id       = (string) ( $element['id'] ?? '' );
		$widget   = (string) ( $element['widgetType'] ?? '' );
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$type     = self::WIDGETS[ $widget ] ?? '';
		if ( '' === $type || ! preg_match( '/^[a-z0-9]{1,20}$/i', $id ) ) {
			$excerpt = '';
			foreach ( array( 'title', 'text', 'editor', 'description', 'content', 'title_text', 'description_text' ) as $key ) {
				if ( is_string( $settings[ $key ] ?? null ) && '' !== trim( $settings[ $key ] ) ) {
					$excerpt = ContentText::excerpt( $settings[ $key ] );
					break;
				}
			}
			$reason = 'global' === $widget ? 'Global widget: edit it in Elementor (it is shared by several pages).' : 'This widget (Elementor Pro, theme or third-party) is not supported; the manager can edit it in Elementor.';
			return $this->node( $id, 'unsupported', $widget, null, $excerpt, $path, $post_id, $order, array(), $reason );
		}
		switch ( $type ) {
			case 'heading':
				$html     = is_string( $settings['title'] ?? null ) ? $settings['title'] : '';
				$link     = self::link_url( $settings );
				$editable = null === $link ? array( 'text' ) : array( 'text', 'url' );
				$value    = $html;
				$label    = 'Heading' . ( ! empty( $settings['header_size'] ) && is_string( $settings['header_size'] ) ? ' ' . strtoupper( $settings['header_size'] ) : '' );
				return $this->node( $id, 'heading', $label, $value, ContentText::excerpt( $html ), $path, $post_id, $order, $editable, '', null === $link ? array() : array( 'link' => $link ) );
			case 'text':
				$html = is_string( $settings['editor'] ?? null ) ? $settings['editor'] : '';
				return $this->node( $id, 'text', 'Text', $html, ContentText::excerpt( $html ), $path, $post_id, $order, array( 'text' ) );
			case 'button':
				$text = is_string( $settings['text'] ?? null ) ? $settings['text'] : '';
				return $this->node(
					$id,
					'button',
					'Button',
					array(
						'text' => $text,
						'url'  => (string) self::link_url( $settings ),
					),
					ContentText::excerpt( $text ),
					$path,
					$post_id,
					$order,
					array( 'text', 'url' )
				);
			default:
				$image    = is_array( $settings['image'] ?? null ) ? $settings['image'] : array();
				$link     = 'custom' === ( $settings['link_to'] ?? '' ) ? self::link_url( $settings ) : null;
				$editable = null === $link ? array( 'image_id' ) : array( 'image_id', 'url' );
				$alt      = (int) ( $image['id'] ?? 0 ) > 0 ? (string) get_post_meta( (int) $image['id'], '_wp_attachment_image_alt', true ) : (string) ( $image['alt'] ?? '' );
				return $this->node(
					$id,
					'image',
					'Image',
					array(
						'image_id' => (int) ( $image['id'] ?? 0 ),
						'url'      => (string) ( $image['url'] ?? '' ),
						'alt'      => $alt,
						'link'     => $link,
					),
					$alt,
					$path,
					$post_id,
					$order,
					$editable,
					'',
					array( 'alt_note' => 'Elementor takes the alt text from the media library (shared by every use of the image), so it is not edited here.' )
				);
		}//end switch
	}

	/**
	 * The link url of a widget (null = no link object at all).
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function link_url( array $settings ): ?string {
		if ( ! is_array( $settings['link'] ?? null ) ) {
			return null;
		}
		return (string) ( $settings['link']['url'] ?? '' );
	}

	/**
	 * Builds a normalized node.
	 *
	 * @param string              $id       Id.
	 * @param string              $type     Type.
	 * @param string              $label    Label.
	 * @param mixed               $value    Value.
	 * @param string              $text     Excerpt.
	 * @param array<int,string>   $path     Path.
	 * @param int                 $post_id  Post id.
	 * @param int                 $order    Order.
	 * @param array<int,string>   $editable Editable fields.
	 * @param string              $reason   Read-only reason.
	 * @param array<string,mixed> $extra    Extra keys.
	 * @return array<string,mixed>
	 */
	private function node( string $id, string $type, string $label, $value, string $text, array $path, int $post_id, int $order, array $editable, string $reason = '', array $extra = array() ): array {
		if ( 'unsupported' === $type ) {
			$label = ( '' !== $label ? $label : 'element' ) . ' (unsupported)';
		}
		return array(
			'id'       => $id,
			'type'     => $type,
			'label'    => $label,
			'value'    => $value,
			'text'     => $text,
			'order'    => $order,
			'page_id'  => $post_id,
			'path'     => implode( ' › ', $path ),
			'editable' => $editable,
			'reason'   => $reason,
		) + $extra;
	}

	/**
	 * Finds an element object node by id (depth-first).
	 *
	 * @param array<string,mixed> $node    Scanner node (array of elements or element).
	 * @param string              $node_id Id.
	 * @return array<string,mixed>|null
	 */
	private static function find( array $node, string $node_id ): ?array {
		if ( 'array' === $node['t'] ) {
			foreach ( $node['i'] as $child ) {
				$found = self::find( $child, $node_id );
				if ( null !== $found ) {
					return $found;
				}
			}
			return null;
		}
		if ( 'object' !== $node['t'] ) {
			return null;
		}
		if ( isset( $node['k']['id'], $node['k']['elType'] ) && ( $node['k']['id']['v'] ?? null ) === $node_id ) {
			return $node;
		}
		return isset( $node['k']['elements'] ) ? self::find( $node['k']['elements'], $node_id ) : null;
	}

	/**
	 * Whether new blocks are flexbox containers (else legacy section/column). The page decides
	 * first (its top-level elements), then Elementor's «Flexbox Container» feature. Filter
	 * `addsynapse_assistant_elementor_containers` (bool|null) for tests.
	 *
	 * @param array<int,mixed> $data Decoded page data.
	 */
	public static function uses_containers( array $data ): bool {
		$forced = apply_filters( 'addsynapse_assistant_elementor_containers', null, $data );
		if ( is_bool( $forced ) ) {
			return $forced;
		}
		$types = array();
		foreach ( $data as $element ) {
			if ( is_array( $element ) ) {
				$types[ (string) ( $element['elType'] ?? '' ) ] = true;
			}
		}
		if ( isset( $types['container'] ) ) {
			return true;
		}
		if ( isset( $types['section'] ) ) {
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ) {
			try {
				return (bool) \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return 'inactive' !== get_option( 'elementor_experiment-container', 'default' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $raw     JSON.
	 * @param int    $post_id Post id.
	 * @throws \RuntimeException When the JSON is invalid.
	 */
	public function sections( string $raw, int $post_id ): array {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'The Elementor data of this page is not valid JSON.' );
		}
		$containers = self::uses_containers( $data );
		$out        = array();
		$this->walk_sections( $data, array(), '', 0, $containers, $out, array() );
		return $out;
	}

	/**
	 * Structural elements in reading order (depth ≤ 3).
	 *
	 * @param array<int,mixed>               $elements   Elements.
	 * @param array<int,string>              $path       Ancestor labels.
	 * @param string                         $top        Top-level ancestor id ('' at the top).
	 * @param int                            $depth      Depth.
	 * @param bool                           $containers Container mode.
	 * @param array<int,array<string,mixed>> $out        Output.
	 * @param array<int,string>              $hidden_on  Inherited hidden devices.
	 */
	private function walk_sections( array $elements, array $path, string $top, int $depth, bool $containers, array &$out, array $hidden_on ): void {
		$counters = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = (string) ( $element['elType'] ?? '' );
			if ( ! in_array( $type, self::STRUCTURE, true ) ) {
				continue;
			}
			$name              = ucfirst( $type );
			$counters[ $name ] = ( $counters[ $name ] ?? 0 ) + 1;
			$label             = $name . ' ' . $counters[ $name ];
			$id                = (string) ( $element['id'] ?? '' );
			$settings          = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
			$children          = is_array( $element['elements'] ?? null ) ? $element['elements'] : array();
			$hidden            = self::hidden_on( $element, $hidden_on );
			$summary           = self::subtree_summary( $children );
			$row               = array(
				'id'          => $id,
				'type'        => $type,
				'label'       => $label,
				'title'       => is_string( $settings['_title'] ?? null ) ? ContentText::excerpt( $settings['_title'], 80 ) : '',
				'path'        => implode( ' › ', array_merge( $path, array( $label ) ) ),
				'depth'       => $depth,
				'top_id'      => '' === $top ? $id : $top,
				'contains'    => $summary['types'],
				'excerpt'     => $summary['excerpt'],
				'can_contain' => $containers && 'container' === $type,
			);
			if ( $hidden ) {
				$row['hidden_on'] = $hidden;
			}
			$out[] = $row;
			if ( $depth < 2 ) {
				$this->walk_sections( $children, array_merge( $path, array( $label ) ), '' === $top ? $id : $top, $depth + 1, $containers, $out, $hidden );
			}
		}//end foreach
	}

	/**
	 * Widget types (≤ 6) and the first text inside a subtree.
	 *
	 * @param array<int,mixed> $elements Elements.
	 * @return array{types:array<int,string>,excerpt:string}
	 */
	private static function subtree_summary( array $elements ): array {
		$types   = array();
		$excerpt = '';
		$stack   = array_reverse( $elements );
		while ( $stack ) {
			$element = array_pop( $stack );
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				$widget = (string) ( $element['widgetType'] ?? '' );
				if ( '' !== $widget && count( $types ) < 6 && ! in_array( $widget, $types, true ) ) {
					$types[] = $widget;
				}
				$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
				foreach ( array( 'title', 'editor', 'text', 'subtitle', 'description', 'content' ) as $key ) {
					if ( '' === $excerpt && is_string( $settings[ $key ] ?? null ) && '' !== trim( wp_strip_all_tags( $settings[ $key ] ) ) ) {
						$excerpt = ContentText::excerpt( $settings[ $key ], 100 );
					}
				}
			}
			foreach ( array_reverse( is_array( $element['elements'] ?? null ) ? $element['elements'] : array() ) as $child ) {
				$stack[] = $child;
			}
		}//end while
		return array(
			'types'   => $types,
			'excerpt' => $excerpt,
		);
	}

	/**
	 * Stores the exact Elementor JSON of a page (approved design change / its Undo) and refreshes Elementor's caches.
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     JSON.
	 * @throws \RuntimeException When the JSON is invalid or WordPress did not store it exactly.
	 */
	public function write( int $post_id, string $raw ): void {
		if ( null === json_decode( $raw ) ) {
			throw new \RuntimeException( 'Refusing to store invalid Elementor JSON.' );
		}
		if ( $this->raw( $post_id ) !== $raw ) {
			// update_post_meta() unslashes: slash the exact bytes first.
			if ( false === update_post_meta( $post_id, self::META, wp_slash( $raw ) ) ) {
				throw new \RuntimeException( 'The page data could not be saved.' );
			}
		}
		if ( $this->raw( $post_id ) !== $raw ) {
			throw new \RuntimeException( 'The page data was changed while saving.' );
		}
		self::refresh_caches( $post_id );
	}

	/**
	 * Drops this post's rendered-element cache and regenerates its CSS file.
	 *
	 * @param int $post_id Post id.
	 */
	public static function refresh_caches( int $post_id ): void {
		delete_post_meta( $post_id, '_elementor_element_cache' );
		clean_post_cache( $post_id );
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			} catch ( \Throwable $e ) {
				unset( $e );
				// The CSS is rebuilt on the next page view anyway.
			}
		}
	}
}
