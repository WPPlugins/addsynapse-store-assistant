<?php
/**
 * Byte-preserving block document: the tree of a block markup string, edited in place.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Adapters\Content\GutenbergAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * A block document (page, post, template, template part, synced pattern) as a tree whose untouched blocks keep their exact
 * bytes: only the blocks an operation changes (and the separators around a moved / inserted / removed block) are rebuilt,
 * so an Undo can restore the exact previous markup and the editor's block validation sees the other blocks unchanged.
 *
 * Node: {id, name, attrs, void, open (opening comment bytes), close (closing comment bytes), inner [string | node], orig
 * (bytes of the whole block as read), dirty (rebuild from parts), attrs_dirty (new opening comment)}. The document root
 * has only `inner`. Node ids are those of describe_page / describe_blocks (GutenbergAdapter::node_id of the ORIGINAL
 * markup) and stay valid for every operation of the same plan.
 */
final class BlockDoc {

	/**
	 * Root.
	 *
	 * @var array<string,mixed>
	 */
	private array $root;

	/**
	 * Original markup.
	 *
	 * @var string
	 */
	private string $raw;

	/**
	 * Counter of new nodes.
	 *
	 * @var int
	 */
	private int $new = 0;

	/**
	 * Parses markup.
	 *
	 * @param string $raw Markup.
	 * @throws \RuntimeException On unbalanced block delimiters.
	 */
	public function __construct( string $raw ) {
		$this->raw  = $raw;
		$top        = GutenbergAdapter::scan( $raw );
		$this->root = array(
			'id'       => '',
			'name'     => '',
			'root'     => true,
			'inner'    => self::inner( $raw, 0, strlen( $raw ), $top, array() ),
			'dirty'    => false,
			'children' => count( $top ),
		);
	}

	/**
	 * Inner parts between [start, end) with the given child blocks.
	 *
	 * @param string                         $raw    Markup.
	 * @param int                            $start  Start.
	 * @param int                            $end    End.
	 * @param array<int,array<string,mixed>> $blocks Scanned children.
	 * @param array<int,int>                 $path   Parent path.
	 * @return array<int,mixed>
	 */
	private static function inner( string $raw, int $start, int $end, array $blocks, array $path ): array {
		$parts = array();
		$at    = $start;
		foreach ( $blocks as $index => $block ) {
			if ( $block['open'][0] > $at ) {
				$parts[] = substr( $raw, $at, $block['open'][0] - $at );
			}
			$parts[] = self::node( $raw, $block, array_merge( $path, array( (int) $index ) ) );
			$at      = $block['close'][1];
		}
		if ( $end > $at ) {
			$parts[] = substr( $raw, $at, $end - $at );
		}
		return $parts;
	}

	/**
	 * Node of a scanned block.
	 *
	 * @param string              $raw   Markup.
	 * @param array<string,mixed> $block Scanned block.
	 * @param array<int,int>      $path  Path.
	 * @return array<string,mixed>
	 */
	private static function node( string $raw, array $block, array $path ): array {
		$void = (bool) $block['void'];
		return array(
			'id'          => GutenbergAdapter::node_id( $path, $block ),
			'name'        => (string) $block['name'],
			'attrs'       => (array) $block['attrs'],
			'void'        => $void,
			'open'        => substr( $raw, $block['open'][0], $block['open'][1] - $block['open'][0] ),
			'close'       => $void ? '' : substr( $raw, $block['close'][0], $block['close'][1] - $block['close'][0] ),
			'inner'       => $void ? array() : self::inner( $raw, $block['open'][1], $block['close'][0], $block['children'], $path ),
			'orig'        => substr( $raw, $block['open'][0], $block['close'][1] - $block['open'][0] ),
			'dirty'       => false,
			'attrs_dirty' => false,
		);
	}

	/**
	 * The markup now.
	 */
	public function serialize(): string {
		return self::serialize_parts( $this->root['inner'] );
	}

	/**
	 * Markup of parts.
	 *
	 * @param array<int,mixed> $parts Parts.
	 */
	private static function serialize_parts( array $parts ): string {
		$out = '';
		foreach ( $parts as $part ) {
			$out .= is_array( $part ) ? self::serialize_node( $part ) : (string) $part;
		}
		return $out;
	}

	/**
	 * Markup of one node.
	 *
	 * @param array<string,mixed> $node Node.
	 */
	public static function serialize_node( array $node ): string {
		if ( empty( $node['dirty'] ) && isset( $node['orig'] ) ) {
			return (string) $node['orig'];
		}
		$open = ! empty( $node['attrs_dirty'] ) || ! isset( $node['open'] ) ? self::opener( (string) $node['name'], (array) $node['attrs'], (bool) $node['void'] ) : (string) $node['open'];
		if ( $node['void'] ) {
			return $open;
		}
		$close = isset( $node['close'] ) && '' !== $node['close'] ? (string) $node['close'] : '<!-- /wp:' . self::short( (string) $node['name'] ) . ' -->';
		return $open . self::serialize_parts( (array) $node['inner'] ) . $close;
	}

	/**
	 * Opening comment of a block.
	 *
	 * @param string              $name  Name.
	 * @param array<string,mixed> $attrs Attributes.
	 * @param bool                $void  Self-closing.
	 */
	public static function opener( string $name, array $attrs, bool $void ): string {
		$json = $attrs ? serialize_block_attributes( $attrs ) . ' ' : '';
		return '<!-- wp:' . self::short( $name ) . ' ' . $json . ( $void ? '/-->' : '-->' );
	}

	/**
	 * Comment name of a block (core/ is implied).
	 *
	 * @param string $name Name.
	 */
	public static function short( string $name ): string {
		return 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
	}

	/**
	 * Every node in document order: {node (by reference path), path (ids of ancestors), depth}.
	 *
	 * @return array<int,array{id:string,name:string,attrs:array<string,mixed>,depth:int,parent:string,ancestors:array<int,string>,index:int,siblings:int,children:int,html:string}>
	 */
	public function nodes(): array {
		$out = array();
		$this->walk( $this->root, array(), $out );
		return $out;
	}

	/**
	 * Walks the tree.
	 *
	 * @param array<string,mixed>            $parent    Parent node.
	 * @param array<int,string>              $ancestors Ancestor ids.
	 * @param array<int,array<string,mixed>> $out       Out.
	 */
	private function walk( array $parent, array $ancestors, array &$out ): void {
		$children = array_values( array_filter( (array) $parent['inner'], 'is_array' ) );
		foreach ( $children as $index => $child ) {
			$out[] = array(
				'id'        => (string) $child['id'],
				'name'      => (string) $child['name'],
				'attrs'     => (array) $child['attrs'],
				'depth'     => count( $ancestors ),
				'parent'    => (string) ( end( $ancestors ) ? end( $ancestors ) : '' ),
				'ancestors' => $ancestors,
				'index'     => (int) $index,
				'siblings'  => count( $children ),
				'children'  => count( array_filter( (array) $child['inner'], 'is_array' ) ),
				'html'      => self::own_html( $child ),
			);
			$this->walk( $child, array_merge( $ancestors, array( (string) $child['id'] ) ), $out );
		}
	}

	/**
	 * The block's own HTML (inner HTML without the inner blocks).
	 *
	 * @param array<string,mixed> $node Node.
	 */
	public static function own_html( array $node ): string {
		$html = '';
		foreach ( (array) ( $node['inner'] ?? array() ) as $part ) {
			if ( is_string( $part ) ) {
				$html .= $part;
			}
		}
		return $html;
	}

	/**
	 * Plain-text excerpt of a block (its whole inner markup, inner blocks included).
	 *
	 * @param string $id  Node.
	 * @param int    $max Max length.
	 */
	public function excerpt( string $id, int $max = 120 ): string {
		$node = $this->get( $id );
		return null === $node ? '' : DesignText::excerpt( self::serialize_parts( (array) $node['inner'] ), $max );
	}

	/**
	 * A node (copy) by id.
	 *
	 * @param string $id Id.
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		$path = $this->locate( $id );
		if ( null === $path ) {
			return null;
		}
		$node = $this->root;
		foreach ( $path as $index ) {
			$node = $node['inner'][ $index ];
		}
		return $node;
	}

	/**
	 * Whether a node exists.
	 *
	 * @param string $id Id.
	 */
	public function has( string $id ): bool {
		return null !== $this->locate( $id );
	}

	/**
	 * Position path (indexes into `inner` arrays) of a node.
	 *
	 * @param string              $id   Id.
	 * @param array<string,mixed> $node Where to search (null = root).
	 * @return array<int,int>|null
	 */
	private function locate( string $id, ?array $node = null ): ?array {
		$node = $node ?? $this->root;
		foreach ( (array) $node['inner'] as $i => $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			if ( (string) $part['id'] === $id ) {
				return array( (int) $i );
			}
			$sub = $this->locate( $id, $part );
			if ( null !== $sub ) {
				return array_merge( array( (int) $i ), $sub );
			}
		}
		return null;
	}

	/**
	 * Runs $change on the node at $path (by reference) and marks it and its ancestors dirty.
	 *
	 * @param array<int,int> $path   Path.
	 * @param callable       $change function( array &$node ): void.
	 */
	private function mutate( array $path, callable $change ): void {
		$node = &$this->root;
		foreach ( $path as $index ) {
			$node['dirty'] = true;
			$node          = &$node['inner'][ $index ];
		}
		$node['dirty'] = true;
		$change( $node );
		unset( $node );
	}

	/**
	 * Path of a node or an exception.
	 *
	 * @param string $id Id.
	 * @return array<int,int>
	 * @throws \InvalidArgumentException When missing.
	 */
	private function must( string $id ): array {
		$path = $this->locate( $id );
		if ( null === $path ) {
			throw new \InvalidArgumentException( sprintf( 'Block %s was not found (it may have been removed by an earlier operation of this plan, or the content changed: read it again).', esc_html( $id ) ) );
		}
		return $path;
	}

	/**
	 * Replaces a node's attributes and its own HTML parts (the strings between its inner blocks).
	 *
	 * @param string                 $id    Node.
	 * @param array<string,mixed>    $attrs New attributes.
	 * @param array<int,string>|null $html  New own HTML strings (same count as now), null = unchanged.
	 * @throws \InvalidArgumentException When the HTML does not fit.
	 */
	public function update( string $id, array $attrs, ?array $html = null ): void {
		$path = $this->must( $id );
		$this->mutate(
			$path,
			static function ( array &$node ) use ( $attrs, $html ): void {
				if ( $attrs !== $node['attrs'] ) {
					$node['attrs']       = $attrs;
					$node['attrs_dirty'] = true;
				}
				if ( null === $html ) {
					return;
				}
				$k = 0;
				foreach ( $node['inner'] as $i => $part ) {
					if ( is_string( $part ) ) {
						if ( ! array_key_exists( $k, $html ) ) {
							throw new \InvalidArgumentException( 'Unexpected block markup.' );
						}
						$node['inner'][ $i ] = (string) $html[ $k ];
						++$k;
					}
				}
				if ( count( $html ) !== $k ) {
					throw new \InvalidArgumentException( 'Unexpected block markup.' );
				}
			}
		);
	}

	/**
	 * The own HTML strings of a node (between its inner blocks), in order.
	 *
	 * @param string $id Node.
	 * @return array<int,string>
	 */
	public function html_parts( string $id ): array {
		$node = $this->get( $id );
		return null === $node ? array() : array_values( array_filter( (array) $node['inner'], 'is_string' ) );
	}

	/**
	 * Removes a node and returns it (with the separator that followed or preceded it).
	 *
	 * @param string $id Node.
	 * @return array<string,mixed> The removed node.
	 */
	public function remove( string $id ): array {
		$path   = $this->must( $id );
		$index  = array_pop( $path );
		$parent = $path;
		$taken  = null;
		$this->mutate(
			$parent,
			static function ( array &$node ) use ( $index, &$taken ): void {
				$taken = $node['inner'][ $index ];
				$inner = $node['inner'];
				// Drop the whitespace separator next to it (blank lines between blocks), never real HTML.
				$next    = $inner[ $index + 1 ] ?? null;
				$prev    = $inner[ $index - 1 ] ?? null;
				$drop    = array( $index );
				$next_ws = is_string( $next ) && '' === trim( $next );
				$prev_ws = is_string( $prev ) && '' === trim( $prev );
				if ( $next_ws && ( is_array( $inner[ $index + 2 ] ?? null ) || null === $prev ) ) {
					$drop[] = $index + 1;
				} elseif ( $prev_ws && ( is_array( $inner[ $index - 2 ] ?? null ) || null === $next ) ) {
					$drop[] = $index - 1;
				}
				foreach ( $drop as $d ) {
					unset( $inner[ $d ] );
				}
				$node['inner'] = self::merge_strings( array_values( $inner ) );
			}
		);
		return $taken;
	}

	/**
	 * Joins adjacent strings (after a removal).
	 *
	 * @param array<int,mixed> $parts Parts.
	 * @return array<int,mixed>
	 */
	private static function merge_strings( array $parts ): array {
		$out = array();
		foreach ( $parts as $part ) {
			$last = count( $out ) - 1;
			if ( is_string( $part ) && $last >= 0 && is_string( $out[ $last ] ) ) {
				$out[ $last ] .= $part;
				continue;
			}
			$out[] = $part;
		}
		return $out;
	}

	/**
	 * Inserts nodes at a position.
	 *
	 * @param array<int,array<string,mixed>> $nodes  Nodes (from parse_new()).
	 * @param string                         $where  before|after|inside_first|inside_last|top|bottom.
	 * @param string                         $anchor Anchor node (before/after/inside_*).
	 * @throws \InvalidArgumentException When the position does not fit.
	 */
	public function insert( array $nodes, string $where, string $anchor = '' ): void {
		if ( ! $nodes ) {
			return;
		}
		if ( 'top' === $where || 'bottom' === $where ) {
			$this->insert_into( array(), $nodes, 'top' === $where );
			return;
		}
		$path = $this->must( $anchor );
		if ( 'inside_first' === $where || 'inside_last' === $where ) {
			$target = $this->get( $anchor );
			if ( ! empty( $target['void'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Block %s cannot contain other blocks.', esc_html( $anchor ) ) );
			}
			$this->insert_into( $path, $nodes, 'inside_first' === $where );
			return;
		}
		if ( 'before' !== $where && 'after' !== $where ) {
			throw new \InvalidArgumentException( 'Unknown position: use before, after, inside_first, inside_last, top or bottom.' );
		}
		$index  = array_pop( $path );
		$parent = $path;
		$this->mutate(
			$parent,
			static function ( array &$node ) use ( $nodes, $index, $where ): void {
				$sep   = self::separator( $node['inner'] );
				$add   = array();
				$count = count( $nodes );
				foreach ( $nodes as $i => $new ) {
					$add[] = $new;
					if ( $i < $count - 1 ) {
						$add[] = $sep;
					}
				}
				if ( 'before' === $where ) {
					array_splice( $node['inner'], $index, 0, array_merge( $add, array( $sep ) ) );
				} else {
					array_splice( $node['inner'], $index + 1, 0, array_merge( array( $sep ), $add ) );
				}
				$node['inner'] = self::merge_strings( $node['inner'] );
			}
		);
	}

	/**
	 * Inserts nodes as the first / last children of the node at $path (root = array()).
	 *
	 * @param array<int,int>                 $path  Parent.
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @param bool                           $first First or last.
	 * @throws \InvalidArgumentException When the parent has no place for children.
	 */
	private function insert_into( array $path, array $nodes, bool $first ): void {
		$root = array() === $path;
		$this->mutate(
			$path,
			static function ( array &$node ) use ( $nodes, $first, $root ): void {
				$inner = $node['inner'];
				$sep   = self::separator( $inner );
				$add   = array();
				foreach ( $nodes as $i => $new ) {
					if ( $i > 0 ) {
						$add[] = $sep;
					}
					$add[] = $new;
				}
				$positions = array_keys( array_filter( $inner, 'is_array' ) );
				if ( $positions ) {
					if ( $first ) {
						array_splice( $inner, $positions[0], 0, array_merge( $add, array( $sep ) ) );
					} else {
						array_splice( $inner, end( $positions ) + 1, 0, array_merge( array( $sep ), $add ) );
					}
				} elseif ( $root ) {
					$text  = implode( '', $inner );
					$inner = '' === trim( $text ) ? $add : ( $first ? array_merge( $add, array( "\n\n", $text ) ) : array_merge( array( rtrim( $text ), "\n\n" ), $add ) );
				} else {
					// No child yet: split the block's own HTML before its last closing tag (<div class="wp-block-group"></div>).
					$html = implode( '', $inner );
					$cut  = strrpos( $html, '</' );
					if ( '' === trim( $html ) ) {
						$inner = array_merge( array( "\n" ), $add, array( "\n" ) );
					} elseif ( false === $cut ) {
						throw new \InvalidArgumentException( 'This block has no place for inner blocks.' );
					} else {
						$inner = array_merge( array( substr( $html, 0, $cut ) ), $add, array( substr( $html, $cut ) ) );
					}
				}//end if
				$node['inner'] = self::merge_strings( array_values( $inner ) );
			}
		);
	}

	/**
	 * Separator between sibling blocks in this list (blank line in the root, newline inside blocks).
	 *
	 * @param array<int,mixed> $inner Parts.
	 */
	private static function separator( array $inner ): string {
		$last = null;
		foreach ( $inner as $part ) {
			if ( is_array( $part ) && is_array( $last ) ) {
				return '';
			}
			if ( is_string( $part ) && is_array( $last ) && '' === trim( $part ) && '' !== $part ) {
				return $part;
			}
			$last = $part;
		}
		return "\n\n";
	}

	/**
	 * Moves a node.
	 *
	 * @param string $id     Node.
	 * @param string $where  before|after|inside_first|inside_last|top|bottom.
	 * @param string $anchor Anchor.
	 * @throws \InvalidArgumentException When the move does not fit.
	 */
	public function move( string $id, string $where, string $anchor = '' ): void {
		if ( '' !== $anchor ) {
			$this->must( $anchor );
			if ( $anchor === $id || $this->is_inside( $anchor, $id ) ) {
				throw new \InvalidArgumentException( 'A block cannot be moved next to or into itself.' );
			}
		}
		$node = $this->remove( $id );
		$this->insert( array( $node ), $where, $anchor );
	}

	/**
	 * Whether $id is inside $ancestor.
	 *
	 * @param string $id       Node.
	 * @param string $ancestor Possible ancestor.
	 */
	public function is_inside( string $id, string $ancestor ): bool {
		$node = $this->get( $ancestor );
		if ( null === $node ) {
			return false;
		}
		return null !== $this->locate( $id, $node );
	}

	/**
	 * A copy of a node with fresh ids (for duplicate / insert).
	 *
	 * @param array<string,mixed> $node Node.
	 * @return array<string,mixed>
	 */
	public function fresh( array $node ): array {
		$node['id'] = 'new' . ( ++$this->new );
		foreach ( $node['inner'] as $i => $part ) {
			if ( is_array( $part ) ) {
				$node['inner'][ $i ] = $this->fresh( $part );
			}
		}
		return $node;
	}

	/**
	 * Parses NEW block markup into nodes (fresh ids, kept bytes). Text outside blocks is not allowed.
	 *
	 * @param string $markup Markup.
	 * @return array<int,array<string,mixed>>
	 * @throws \InvalidArgumentException When the markup is not a list of blocks.
	 */
	public function parse_new( string $markup ): array {
		try {
			$doc = new self( $markup );
		} catch ( \RuntimeException $e ) {
			throw new \InvalidArgumentException( 'The new markup is not balanced block markup.' );
		}
		$nodes = array();
		foreach ( $doc->root['inner'] as $part ) {
			if ( is_string( $part ) ) {
				if ( '' !== trim( $part ) ) {
					throw new \InvalidArgumentException( 'The new markup must consist of blocks only (<!-- wp:… --> … <!-- /wp:… -->), with no HTML outside them.' );
				}
				continue;
			}
			$nodes[] = $this->fresh( $part );
		}
		if ( ! $nodes ) {
			throw new \InvalidArgumentException( 'The new markup has no block.' );
		}
		return $nodes;
	}

	/**
	 * Replaces a node by new nodes.
	 *
	 * @param string                         $id    Node.
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 */
	public function replace( string $id, array $nodes ): void {
		$path  = $this->must( $id );
		$index = array_pop( $path );
		$this->mutate(
			$path,
			static function ( array &$node ) use ( $index, $nodes ): void {
				$sep = self::separator( $node['inner'] );
				$add = array();
				foreach ( $nodes as $i => $new ) {
					if ( $i > 0 ) {
						$add[] = $sep;
					}
					$add[] = $new;
				}
				array_splice( $node['inner'], $index, 1, $add );
				$node['inner'] = self::merge_strings( $node['inner'] );
			}
		);
	}

	/**
	 * Names of every block of the markup (document order), for a structural check.
	 *
	 * @param string $markup Markup.
	 * @return array<int,string>
	 */
	public static function names( string $markup ): array {
		$names = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
			foreach ( $blocks as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				$names[] = (string) $block['blockName'];
				$walk( (array) $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( $markup ) );
		return $names;
	}
}
