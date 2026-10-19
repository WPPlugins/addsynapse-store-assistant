<?php
/**
 * Gutenberg / FSE content adapter (block markup in post_content).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Content;

use Addsynapse\StoreAssistant\Content\ContentText;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks are scanned with the same delimiter grammar as core's WP_Block_Parser, but keeping byte
 * offsets, so an edit rewrites only the edited block's own markup (and, for an image, its own
 * comment attributes) — the rest of post_content stays byte-identical. (parse_blocks() +
 * serialize_blocks() is not byte-exact: attribute JSON escaping and whitespace change.)
 *
 * Editable leaf blocks: core/heading, core/paragraph (text), core/button (text + href), core/image
 * (media-library image, alt, link href when it has one). Containers (group, columns, buttons,
 * cover…) only give the path. Other leaf blocks, reusable blocks (core/block) and patterns are
 * listed read-only. Node id = "g" + position path + "-" + hash(name + attributes), e.g.
 * `g0_2_1-a1b2c3`: a moved or changed block gets a new id. Read-only in this plugin: it never writes pages.
 */
class GutenbergAdapter implements ContentAdapterInterface {

	public const KIND = 'gutenberg';

	/**
	 * Post types whose post_content may be edited.
	 */
	public const POST_TYPES = array( 'page', 'post', 'wp_template', 'wp_template_part', 'wp_block' );

	private const TOKEN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Block name → node type.
	 */
	public const BLOCKS = array(
		'core/heading'   => 'heading',
		'core/paragraph' => 'text',
		'core/button'    => 'button',
		'core/image'     => 'image',
	);

	/**
	 * {@inheritDoc}
	 */
	public function kind(): string {
		return self::KIND;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function detect( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post && in_array( $post->post_type, self::POST_TYPES, true ) && has_blocks( (string) $post->post_content );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function raw( int $post_id ): ?string {
		$post = get_post( $post_id );
		return $post ? (string) $post->post_content : null;
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
	 * Scans block markup into a tree with offsets.
	 *
	 * Block: name, attrs, attrs_raw [start,end] (null when none), open [s,e], close [s,e] (= open
	 * for void blocks), children.
	 *
	 * @param string $raw Markup.
	 * @return array<int,array<string,mixed>> Top-level blocks.
	 * @throws \RuntimeException On unbalanced block delimiters.
	 */
	public static function scan( string $raw ): array {
		preg_match_all( self::TOKEN, $raw, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$root  = array( 'children' => array() );
		$stack = array( &$root );
		foreach ( $matches as $m ) {
			$start  = (int) $m[0][1];
			$end    = $start + strlen( $m[0][0] );
			$name   = ( '' !== ( $m['namespace'][0] ?? '' ) ? $m['namespace'][0] : 'core/' ) . $m['name'][0];
			$closer = isset( $m['closer'] ) && -1 !== $m['closer'][1] && '' !== $m['closer'][0];
			$void   = isset( $m['void'] ) && -1 !== $m['void'][1] && '' !== $m['void'][0];
			if ( $closer ) {
				$top = count( $stack ) - 1;
				if ( $top < 1 || $stack[ $top ]['name'] !== $name ) {
					throw new \RuntimeException( 'The block markup of this page is not balanced.' );
				}
				$stack[ $top ]['close'] = array( $start, $end );
				array_pop( $stack );
				continue;
			}
			$attrs_raw = null;
			$attrs     = array();
			if ( isset( $m['attrs'] ) && -1 !== $m['attrs'][1] && '' !== $m['attrs'][0] ) {
				$json      = rtrim( $m['attrs'][0] );
				$attrs_raw = array( (int) $m['attrs'][1], (int) $m['attrs'][1] + strlen( $json ) );
				$decoded   = json_decode( $json, true );
				$attrs     = is_array( $decoded ) ? $decoded : array();
			}
			$block                       = array(
				'name'      => $name,
				'attrs'     => $attrs,
				'attrs_raw' => $attrs_raw,
				'open'      => array( $start, $end ),
				'close'     => $void ? array( $end, $end ) : null,
				'void'      => $void,
				'children'  => array(),
			);
			$top                         = count( $stack ) - 1;
			$stack[ $top ]['children'][] = $block;
			$index                       = count( $stack[ $top ]['children'] ) - 1;
			if ( ! $void ) {
				$stack[] = &$stack[ $top ]['children'][ $index ];
			}
		}//end foreach
		if ( count( $stack ) > 1 ) {
			throw new \RuntimeException( 'The block markup of this page is not balanced.' );
		}
		return $root['children'];
	}

	/**
	 * Node id of a block.
	 *
	 * @param array<int,int>      $path  Positions.
	 * @param array<string,mixed> $block Block.
	 */
	public static function node_id( array $path, array $block ): string {
		return 'g' . implode( '_', $path ) . '-' . substr( md5( $block['name'] . '|' . wp_json_encode( $block['attrs'] ) ), 0, 6 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $raw     Markup.
	 * @param int    $post_id Post id.
	 * @throws \RuntimeException On unbalanced markup.
	 */
	public function nodes_from( string $raw, int $post_id ): array {
		$nodes = array();
		foreach ( $this->flatten( self::scan( $raw ), array(), array() ) as $entry ) {
			$nodes[] = $this->node( $raw, $entry, $post_id, count( $nodes ) );
		}
		return $nodes;
	}

	/**
	 * Leaf blocks in reading order with their position path and structural path.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param array<int,int>                 $path   Positions.
	 * @param array<int,string>              $labels Ancestor labels.
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten( array $blocks, array $path, array $labels ): array {
		$out = array();
		foreach ( $blocks as $index => $block ) {
			$here = array_merge( $path, array( (int) $index ) );
			if ( $block['children'] ) {
				$out = array_merge( $out, $this->flatten( $block['children'], $here, array_merge( $labels, array( self::block_label( $block['name'] ) ) ) ) );
				continue;
			}
			$out[] = array(
				'block'  => $block,
				'path'   => $here,
				'labels' => $labels,
			);
		}
		return $out;
	}

	/**
	 * Short human label of a block name.
	 *
	 * @param string $name Block name.
	 */
	private static function block_label( string $name ): string {
		$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		return ucfirst( str_replace( '-', ' ', $short ) );
	}

	/**
	 * The markup between a block's opening and closing comments.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return array{0:int,1:int} [start, end].
	 */
	private static function inner_range( array $block ): array {
		return array( $block['open'][1], $block['close'][0] );
	}

	/**
	 * Splits a leaf's markup into [before, inner, after] around the editable text, or null.
	 *
	 * @param string $type heading|text|button.
	 * @param string $html Leaf markup.
	 * @return array{0:string,1:string,2:string}|null
	 */
	private static function text_parts( string $type, string $html ): ?array {
		if ( 'heading' === $type && preg_match( '/^(\s*<h([1-6])\b[^>]*>)(.*)(<\/h\2>\s*)$/s', $html, $m ) ) {
			return array( $m[1], $m[3], $m[4] );
		}
		if ( 'text' === $type && preg_match( '/^(\s*<p\b[^>]*>)(.*)(<\/p>\s*)$/s', $html, $m ) && false === stripos( $m[2], '<p' ) ) {
			return array( $m[1], $m[2], $m[3] );
		}
		if ( 'button' === $type && 1 === preg_match_all( '/<a\b/i', $html ) && preg_match( '/^(.*?<a\b[^>]*>)(.*?)(<\/a>.*)$/s', $html, $m ) ) {
			return array( $m[1], $m[2], $m[3] );
		}
		return null;
	}

	/**
	 * Normalized node of a leaf block.
	 *
	 * @param string              $raw     Markup.
	 * @param array<string,mixed> $entry   Flattened entry.
	 * @param int                 $post_id Post id.
	 * @param int                 $order   Order.
	 * @return array<string,mixed>
	 */
	private function node( string $raw, array $entry, int $post_id, int $order ): array {
		$block = $entry['block'];
		$range = self::inner_range( $block );
		$html  = substr( $raw, $range[0], $range[1] - $range[0] );
		$type  = self::BLOCKS[ $block['name'] ] ?? '';
		$base  = array(
			'id'       => self::node_id( $entry['path'], $block ),
			'type'     => $type,
			'label'    => self::block_label( $block['name'] ),
			'value'    => null,
			'text'     => ContentText::excerpt( $html ),
			'order'    => $order,
			'page_id'  => $post_id,
			'path'     => implode( ' › ', $entry['labels'] ),
			'editable' => array(),
			'reason'   => '',
			'block'    => $block['name'],
		);
		if ( '' === $type ) {
			$base['type']  = 'unsupported';
			$base['label'] = self::block_label( $block['name'] ) . ' (unsupported)';
			if ( 'core/block' === $block['name'] ) {
				$base['reason'] = sprintf( 'Synced pattern #%d: edit it on its own (it is shared by several pages).', (int) ( $block['attrs']['ref'] ?? 0 ) );
			} elseif ( 'core/pattern' === $block['name'] || 'core/template-part' === $block['name'] ) {
				$base['reason'] = 'Pattern / template part: edit it in the Site Editor.';
			} else {
				$base['reason'] = 'This block type is not supported yet; the manager can edit it in the block editor.';
			}
			return $base;
		}
		if ( 'image' === $type ) {
			$tags = new \WP_HTML_Tag_Processor( $html );
			$src  = '';
			$alt  = '';
			if ( $tags->next_tag( 'img' ) ) {
				$src = (string) $tags->get_attribute( 'src' );
				$alt = (string) $tags->get_attribute( 'alt' );
			}
			$links            = new \WP_HTML_Tag_Processor( $html );
			$link             = $links->next_tag( 'a' ) ? (string) $links->get_attribute( 'href' ) : null;
			$base['label']    = 'Image';
			$base['value']    = array(
				'image_id' => (int) ( $block['attrs']['id'] ?? 0 ),
				'url'      => $src,
				'alt'      => $alt,
				'link'     => $link,
				'size'     => (string) ( $block['attrs']['sizeSlug'] ?? 'full' ),
			);
			$base['text']     = $alt;
			$ok               = '' !== $src && 1 === substr_count( strtolower( $html ), '<img' );
			$base['editable'] = $ok ? ( null === $link ? array( 'image_id', 'alt' ) : array( 'image_id', 'alt', 'url' ) ) : array();
			$base['reason']   = $ok ? '' : 'Unexpected image markup; edit it in the block editor.';
			return $base;
		}//end if
		$parts = self::text_parts( $type, $html );
		if ( null === $parts ) {
			$base['type']   = 'unsupported';
			$base['label'] .= ' (unsupported markup)';
			$base['reason'] = 'Unexpected markup for this block; edit it in the block editor.';
			return $base;
		}
		if ( 'heading' === $type ) {
			$base['label'] = 'Heading H' . (int) ( $block['attrs']['level'] ?? ( preg_match( '/<h([1-6])/', $html, $lv ) ? $lv[1] : 2 ) );
		}
		if ( 'button' === $type ) {
			$tags             = new \WP_HTML_Tag_Processor( $html );
			$href             = $tags->next_tag( 'a' ) ? (string) $tags->get_attribute( 'href' ) : '';
			$base['value']    = array(
				'text' => html_entity_decode( wp_strip_all_tags( $parts[1] ), ENT_QUOTES, 'UTF-8' ),
				'url'  => $href,
			);
			$base['editable'] = array( 'text', 'url' );
			return $base;
		}
		$base['value']    = $parts[1];
		$base['editable'] = array( 'text' );
		return $base;
	}

	/**
	 * Blocks that may hold a NEW block (inside_first / inside_last, and as the parent of before/after).
	 */
	public const CONTAINERS = array( 'core/group', 'core/column', 'core/cover' );

	/**
	 * Every block (containers and leaves) in document order with its position path and ancestors.
	 *
	 * @param array<int,array<string,mixed>> $blocks    Blocks.
	 * @param array<int,int>                 $path      Positions.
	 * @param array<int,array<string,mixed>> $ancestors Ancestor blocks.
	 * @param array<int,string>              $labels    Ancestor labels.
	 * @return array<int,array<string,mixed>>
	 */
	private static function all_blocks( array $blocks, array $path = array(), array $ancestors = array(), array $labels = array() ): array {
		$out = array();
		foreach ( $blocks as $index => $block ) {
			$here  = array_merge( $path, array( (int) $index ) );
			$out[] = array(
				'block'     => $block,
				'path'      => $here,
				'ancestors' => $ancestors,
				'labels'    => $labels,
				'siblings'  => $blocks,
				'index'     => (int) $index,
			);
			if ( $block['children'] ) {
				$out = array_merge( $out, self::all_blocks( $block['children'], $here, array_merge( $ancestors, array( $block ) ), array_merge( $labels, array( self::block_label( $block['name'] ) ) ) ) );
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $raw     Markup.
	 * @param int    $post_id Post id.
	 * @throws \RuntimeException On unbalanced markup.
	 */
	public function sections( string $raw, int $post_id ): array {
		$out = array();
		foreach ( self::all_blocks( self::scan( $raw ) ) as $entry ) {
			$block = $entry['block'];
			$depth = count( $entry['path'] ) - 1;
			if ( $depth > 2 || ( $depth > 0 && ! $block['children'] ) ) {
				continue;
			}
			$types   = array();
			$excerpt = '';
			foreach ( $this->flatten( $block['children'] ? $block['children'] : array( $block ), array(), array() ) as $leaf ) {
				$name = (string) $leaf['block']['name'];
				if ( count( $types ) < 6 && ! in_array( $name, $types, true ) ) {
					$types[] = $name;
				}
				if ( '' === $excerpt ) {
					$range   = self::inner_range( $leaf['block'] );
					$excerpt = ContentText::excerpt( substr( $raw, $range[0], $range[1] - $range[0] ), 100 );
				}
			}
			$out[] = array(
				'id'          => self::node_id( $entry['path'], $block ),
				'type'        => (string) $block['name'],
				'label'       => self::block_label( $block['name'] ) . ( 0 === $depth ? ' ' . ( $entry['index'] + 1 ) : '' ),
				'title'       => is_string( $block['attrs']['metadata']['name'] ?? null ) ? ContentText::excerpt( $block['attrs']['metadata']['name'], 80 ) : '',
				'path'        => implode( ' › ', array_merge( $entry['labels'], array( self::block_label( $block['name'] ) ) ) ),
				'depth'       => $depth,
				'top_id'      => self::node_id( array( $entry['path'][0] ), 0 === $depth ? $block : $entry['ancestors'][0] ),
				'contains'    => $types,
				'excerpt'     => $excerpt,
				'can_contain' => in_array( $block['name'], self::CONTAINERS, true ) && (bool) $block['children'],
			);
		}//end foreach
		return $out;
	}
}
