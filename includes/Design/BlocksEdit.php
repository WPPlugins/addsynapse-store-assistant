<?php
/**
 * Block edits anywhere: pages, posts, synced patterns, block templates and template parts (header, footer…).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ONE document per plan, up to MAX_OPS operations applied in order on its block tree (ids from describe_blocks stay valid
 * for the whole plan). The draft line holds the exact markup before and after: Approve writes it only while the document
 * still has the previewed markup (else conflict), Undo restores the exact previous markup while the document still has
 * the applied one; a theme / plugin template customised by Approve is deleted again on Undo («revert to the theme's»).
 *
 * Line: object_type `design_doc`, object_id = post id (0 = template still a file), field `design:blocks`,
 * before {ref, hash, raw}, after {hash, raw, ops[{label, field, before, after}]}.
 */
final class BlocksEdit extends Planner {

	public const OPERATION = 'design_blocks';
	public const OBJECT    = 'design_doc';
	public const FIELD     = 'design:blocks';
	public const MAX_OPS   = 20;
	public const MAX_BYTES = 2000000;
	public const WHERE     = array( 'before', 'after', 'inside_first', 'inside_last', 'top', 'bottom' );

	/**
	 * Object type.
	 */
	public static function object(): string {
		return self::OBJECT;
	}

	/**
	 * Plans block operations on one document.
	 *
	 * @param array<string,mixed> $input           {page_id|template|template_part|front_page, operations[]}.
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$doc = Docs::resolve( $input );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( 'elementor' === $doc['kind'] ) {
			return new WP_Error( 'addsynapse_assistant_design_builder', sprintf( '"%s" is built with Elementor: use plan_elementor_edit for it.', $doc['title'] ) );
		}
		$ops = array_values( array_filter( (array) ( $input['operations'] ?? array() ), 'is_array' ) );
		if ( ! $ops || count( $ops ) > self::MAX_OPS ) {
			return new WP_Error( 'addsynapse_assistant_design_ops', sprintf( 'Give 1 to %d operations per plan (one document per plan).', self::MAX_OPS ) );
		}
		$raw      = (string) $doc['raw'];
		$expanded = BlockMarkup::expand( $raw );
		try {
			$tree = new BlockDoc( $expanded );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'addsynapse_assistant_design_markup', 'The block markup of this content is broken (unbalanced blocks): it cannot be edited safely from the chat.' );
		}
		$labels   = self::labels( $tree );
		$lines    = array();
		$external = array();
		$warnings = array();
		foreach ( $ops as $i => $op ) {
			try {
				$line = $this->run_op( $tree, $op, $labels );
			} catch ( \InvalidArgumentException $e ) {
				return new WP_Error( 'addsynapse_assistant_design_op', sprintf( 'Operation %1$d (%2$s): %3$s Nothing was planned.', $i + 1, (string) ( $op['op'] ?? '?' ), $e->getMessage() ) );
			}
			$external = array_merge( $external, (array) ( $line['external'] ?? array() ) );
			$warnings = array_merge( $warnings, (array) ( $line['warnings'] ?? array() ) );
			unset( $line['external'], $line['warnings'] );
			$lines[] = $line;
		}
		$new = $tree->serialize();
		if ( $new === $expanded ) {
			return $this->store( array(), self::OPERATION, sprintf( '%s: nothing would change.', $doc['title'] ), array(), $user_id, $conversation_id );
		}
		$problem = self::final_problem( $new );
		if ( '' !== $problem ) {
			return new WP_Error( 'addsynapse_assistant_design_markup', $problem . ' Nothing was planned.' );
		}
		if ( $external ) {
			$warnings[] = 'external_link';
		}
		$ref   = Docs::ref( $doc );
		$item  = array(
			'object_type' => self::OBJECT,
			'object_id'   => (int) $doc['post_id'],
			'parent_id'   => 0,
			'label'       => (string) $doc['title'],
			'sku'         => '',
			'field'       => self::FIELD,
			'before'      => array(
				'ref'  => $ref,
				'hash' => hash( 'sha256', $raw ),
				'raw'  => $raw,
			),
			'after'       => array(
				'hash' => hash( 'sha256', $new ),
				'raw'  => $new,
				'ops'  => $lines,
			),
		);
		$first = 'template' === $doc['kind'] && (int) $doc['post_id'] < 1;
		return $this->store(
			array( $item ),
			self::OPERATION,
			sprintf( '%1$s: %2$d change(s)%3$s.', $doc['title'], count( $lines ), $first ? ' (the theme\'s version stays available: Undo goes back to it)' : '' ),
			array(
				'title'    => (string) $doc['title'],
				'kind'     => (string) $doc['kind'],
				'url'      => (string) $doc['url'],
				'external' => array_values( array_unique( $external ) ),
			),
			$user_id,
			$conversation_id,
			array(
				'document' => array(
					'title' => (string) $doc['title'],
					'kind'  => (string) $doc['kind'],
					'url'   => (string) $doc['url'],
				),
				'changes'  => $lines,
				'warnings' => array_values( array_unique( $warnings ) ),
				'external' => array_values( array_unique( $external ) ),
			)
		);
	}

	/**
	 * Human labels of the original blocks (id → "Group › Heading").
	 *
	 * @param BlockDoc $tree Tree.
	 * @return array<string,string>
	 */
	private static function labels( BlockDoc $tree ): array {
		$names  = array();
		$labels = array();
		foreach ( $tree->nodes() as $node ) {
			$names[ $node['id'] ] = self::label( $node['name'], (array) $node['attrs'] );
			$path                 = array();
			foreach ( $node['ancestors'] as $ancestor ) {
				$path[] = $names[ $ancestor ] ?? '';
			}
			$path[]                = $names[ $node['id'] ];
			$labels[ $node['id'] ] = implode( ' › ', array_filter( $path ) );
		}
		return $labels;
	}

	/**
	 * Short label of a block.
	 *
	 * @param string              $name  Block.
	 * @param array<string,mixed> $attrs Attributes.
	 */
	public static function label( string $name, array $attrs = array() ): string {
		if ( is_string( $attrs['metadata']['name'] ?? null ) && '' !== $attrs['metadata']['name'] ) {
			return mb_substr( $attrs['metadata']['name'], 0, 40 );
		}
		$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$label = ucfirst( str_replace( array( '-', '/' ), array( ' ', ' / ' ), $short ) );
		if ( 'core/heading' === $name ) {
			$label .= ' H' . (int) ( $attrs['level'] ?? 2 );
		}
		if ( 'core/template-part' === $name && ! empty( $attrs['slug'] ) ) {
			$label .= ' "' . $attrs['slug'] . '"';
		}
		return $label;
	}

	/**
	 * Runs one operation.
	 *
	 * @param BlockDoc             $tree   Tree.
	 * @param array<string,mixed>  $op     Operation.
	 * @param array<string,string> $labels Labels of the original blocks.
	 * @return array<string,mixed> Card line {label, field, before, after, external?, warnings?}.
	 * @throws \InvalidArgumentException When it cannot be done.
	 */
	private function run_op( BlockDoc $tree, array $op, array $labels ): array {
		$kind  = (string) ( $op['op'] ?? '' );
		$block = (string) ( $op['block'] ?? '' );
		$label = static function ( string $id ) use ( $labels, $tree ): string {
			if ( isset( $labels[ $id ] ) ) {
				return $labels[ $id ];
			}
			$node = $tree->get( $id );
			return $node ? self::label( (string) $node['name'], (array) $node['attrs'] ) : $id;
		};
		$need  = static function () use ( $tree, $block ): array {
			$node = '' !== $block ? $tree->get( $block ) : null;
			if ( null === $node ) {
				throw new \InvalidArgumentException( '' === $block ? 'Say which block (its id from describe_blocks).' : sprintf( 'Block %s was not found (read the content again with describe_blocks).', esc_html( $block ) ) );
			}
			return $node;
		};
		switch ( $kind ) {
			case 'set_attrs':
				$need();
				if ( ! is_array( $op['attrs'] ?? null ) || ! $op['attrs'] ) {
					throw new \InvalidArgumentException( 'attrs must be an object of block attributes.' );
				}
				$diff = BlockAttrs::apply( $tree, $block, (array) $op['attrs'] );
				return array(
					'label'  => $label( $block ),
					'field'  => 'style',
					'before' => self::attrs_text( $diff['before'] ),
					'after'  => self::attrs_text( $diff['after'] ),
				);
			case 'set_text':
				$need();
				$diff = BlockText::set_text( $tree, $block, (string) ( $op['text'] ?? '' ) );
				return array(
					'label'    => $label( $block ),
					'field'    => 'text',
					'before'   => $diff['before'],
					'after'    => $diff['after'],
					'external' => (array) ( $diff['external'] ?? array() ),
				);
			case 'set_link':
				$need();
				$diff = BlockText::set_link( $tree, $block, (string) ( $op['url'] ?? '' ) );
				return array(
					'label'    => $label( $block ),
					'field'    => 'link',
					'before'   => $diff['before'],
					'after'    => $diff['after'],
					'external' => (array) ( $diff['external'] ?? array() ),
				);
			case 'set_image':
				$need();
				$diff = BlockText::set_image( $tree, $block, (int) ( $op['image_id'] ?? 0 ), isset( $op['alt'] ) ? (string) $op['alt'] : null );
				return array(
					'label'  => $label( $block ),
					'field'  => 'image',
					'before' => $diff['before'],
					'after'  => $diff['after'],
				);
			case 'remove':
				$node = $need();
				$text = $tree->excerpt( $block, 80 );
				$tree->remove( $block );
				return array(
					'label'  => $label( $block ),
					'field'  => 'removed',
					'before' => self::label( (string) $node['name'], (array) $node['attrs'] ) . ( '' !== $text ? ': ' . $text : '' ),
					'after'  => '',
				);
			case 'move':
				$node   = $need();
				$where  = self::where( $op );
				$anchor = (string) ( $op['anchor'] ?? '' );
				$tree->move( $block, $where, $anchor );
				self::check_place( $tree, $block );
				return array(
					'label'  => $label( $block ),
					'field'  => 'moved',
					'before' => '',
					'after'  => self::where_text( $where, $anchor, $label ),
				);
			case 'duplicate':
				$node = $need();
				$copy = $tree->fresh( $node );
				$tree->insert( array( $copy ), 'after', $block );
				return array(
					'label'  => $label( $block ),
					'field'  => 'duplicated',
					'before' => '',
					'after'  => self::label( (string) $node['name'], (array) $node['attrs'] ) . ' (copy below)',
				);
			case 'insert':
			case 'replace':
				$source = self::source( $op );
				$check  = BlockMarkup::check( $source['markup'] );
				$nodes  = $tree->parse_new( $check['markup'] );
				if ( 'replace' === $kind ) {
					$old = $need();
					$tree->replace( $block, $nodes );
					$what = self::label( (string) $old['name'], (array) $old['attrs'] );
				} else {
					$where  = self::where( $op );
					$anchor = (string) ( $op['anchor'] ?? '' );
					if ( ! in_array( $where, array( 'top', 'bottom' ), true ) && '' === $anchor ) {
						throw new \InvalidArgumentException( 'Say next to / inside which block (anchor = a block id), or use where top / bottom.' );
					}
					$tree->insert( $nodes, $where, $anchor );
					$what = self::where_text( $where, $anchor, $label );
				}
				foreach ( $nodes as $node ) {
					self::check_place( $tree, (string) $node['id'] );
				}
				$names = array_map( static fn( $n ) => self::label( (string) $n['name'], (array) $n['attrs'] ), $nodes );
				return array(
					'label'    => 'replace' === $kind ? $label( $block ) : implode( ', ', $names ),
					'field'    => 'replace' === $kind ? 'replaced' : 'added',
					'before'   => 'replace' === $kind ? $what : '',
					'after'    => ( '' !== $source['name'] ? $source['name'] . ': ' : '' ) . ( 'replace' === $kind ? implode( ', ', $names ) : $what ) . ' — ' . DesignText::excerpt( $check['markup'], 120 ),
					'external' => array_merge( $check['external'], $source['external'] ),
					'warnings' => $check['warnings'],
				);
		}//end switch
		throw new \InvalidArgumentException( 'Unknown op: use set_attrs, set_text, set_link, set_image, insert, replace, move, duplicate or remove.' );
	}

	/**
	 * Where of an operation.
	 *
	 * @param array<string,mixed> $op Operation.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function where( array $op ): string {
		$where = (string) ( $op['where'] ?? 'after' );
		if ( ! in_array( $where, self::WHERE, true ) ) {
			throw new \InvalidArgumentException( 'where is before, after, inside_first, inside_last, top or bottom.' );
		}
		return $where;
	}

	/**
	 * Card text of a position.
	 *
	 * @param string   $where  Where.
	 * @param string   $anchor Anchor.
	 * @param callable $label  Labeller.
	 */
	private static function where_text( string $where, string $anchor, callable $label ): string {
		$words = array(
			'before'       => 'before',
			'after'        => 'after',
			'inside_first' => 'at the start of',
			'inside_last'  => 'at the end of',
			'top'          => 'at the top',
			'bottom'       => 'at the bottom',
		);
		return trim( $words[ $where ] . ( '' !== $anchor ? ' ' . $label( $anchor ) : '' ) );
	}

	/**
	 * Markup of an insert / replace.
	 *
	 * @param array<string,mixed> $op Operation.
	 * @return array{markup:string,name:string,external:array<int,string>}
	 * @throws \InvalidArgumentException When none / several are given.
	 */
	private static function source( array $op ): array {
		$given = array_filter(
			array(
				'blocks'         => ! empty( $op['blocks'] ),
				'markup'         => isset( $op['markup'] ) && '' !== $op['markup'],
				'pattern'        => ! empty( $op['pattern'] ),
				'synced_pattern' => ! empty( $op['synced_pattern'] ),
			)
		);
		if ( 1 !== count( $given ) ) {
			throw new \InvalidArgumentException( 'Give exactly one of blocks (structured new blocks), pattern (a pattern name), synced_pattern (an id) or markup (block markup).' );
		}
		if ( ! empty( $op['blocks'] ) ) {
			$build = new BlockBuild();
			return array(
				'markup'   => $build->markup( array_values( (array) $op['blocks'] ) ),
				'name'     => '',
				'external' => $build->external,
			);
		}
		if ( ! empty( $op['pattern'] ) ) {
			return array(
				'markup'   => BlockMarkup::pattern( (string) $op['pattern'] ),
				'name'     => 'Pattern ' . (string) $op['pattern'],
				'external' => array(),
			);
		}
		if ( ! empty( $op['synced_pattern'] ) ) {
			return array(
				'markup'   => BlockMarkup::synced( (int) $op['synced_pattern'] ),
				'name'     => 'Synced pattern #' . (int) $op['synced_pattern'],
				'external' => array(),
			);
		}
		return array(
			'markup'   => (string) $op['markup'],
			'name'     => '',
			'external' => array(),
		);
	}

	/**
	 * The block may sit where it is now (parent / ancestor / allowedBlocks rules), else an exception.
	 *
	 * @param BlockDoc $tree Tree.
	 * @param string   $id   Block.
	 * @throws \InvalidArgumentException When not allowed.
	 */
	private static function check_place( BlockDoc $tree, string $id ): void {
		$names = array();
		foreach ( $tree->nodes() as $node ) {
			$names[ $node['id'] ] = $node['name'];
		}
		foreach ( $tree->nodes() as $node ) {
			if ( $node['id'] !== $id ) {
				continue;
			}
			$ancestors = array_map( static fn( $a ) => (string) ( $names[ $a ] ?? '' ), $node['ancestors'] );
			$problem   = BlockMarkup::placement_problem( (string) $node['name'], '' !== $node['parent'] ? (string) $names[ $node['parent'] ] : '', $ancestors );
			if ( '' !== $problem ) {
				throw new \InvalidArgumentException( esc_html( $problem ) );
			}
			return;
		}
	}

	/**
	 * The new markup must still parse the same way in WordPress ('' = fine).
	 *
	 * @param string $new Markup.
	 */
	public static function final_problem( string $new ): string {
		if ( strlen( $new ) > self::MAX_BYTES ) {
			return 'The content would be too large.';
		}
		try {
			$tree = new BlockDoc( $new );
		} catch ( \RuntimeException $e ) {
			return 'The change would break the block markup.';
		}
		$ours = array_map( static fn( $n ) => $n['name'], $tree->nodes() );
		if ( BlockDoc::names( $new ) !== $ours ) {
			return 'The change would make the block markup ambiguous for WordPress.';
		}
		return '';
	}

	/**
	 * Attribute values as short plain text for the card.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	public static function attrs_text( array $attrs ): string {
		if ( ! $attrs ) {
			return '';
		}
		$flat = array();
		$walk = static function ( $value, string $path ) use ( &$walk, &$flat ): void {
			if ( is_array( $value ) && ! wp_is_numeric_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$walk( $item, '' === $path ? (string) $key : $path . '.' . $key );
				}
				return;
			}
			$flat[] = $path . ': ' . ( is_scalar( $value ) ? ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ) : wp_json_encode( $value ) );
		};
		$walk( $attrs, '' );
		$text = implode( '; ', $flat );
		return mb_strlen( $text ) > 400 ? mb_substr( $text, 0, 399 ) . '…' : $text;
	}
}
