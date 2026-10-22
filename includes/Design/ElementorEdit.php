<?php
/**
 * Elementor edits: pages, saved templates, Theme Builder headers / footers — any document built with Elementor.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ONE document per plan, up to MAX_OPS operations on its element tree (ids from describe_blocks). The draft is the same
 * `design_doc` line as block edits (BlocksEdit applies / undoes / previews / shows it): before {ref (kind elementor), hash,
 * raw}, after {hash, raw, ops}. Approve writes the exact JSON only while the document still has the previewed data and
 * regenerates its CSS (ElementorAdapter::write); a Theme Builder header / footer is one document used on every page.
 */
final class ElementorEdit extends Planner {

	public const OPERATION = 'design_elementor';
	public const MAX_OPS   = 20;

	/**
	 * Id source (tests).
	 *
	 * @var callable|null
	 */
	public static $random = null;

	/**
	 * Plans Elementor operations on one document.
	 *
	 * @param array<string,mixed> $input           {page_id | front_page, operations[]}.
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$doc = Docs::resolve( $input );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( 'elementor' !== $doc['kind'] ) {
			return new WP_Error( 'addsynapse_assistant_design_builder', sprintf( '"%s" is not built with Elementor: use plan_blocks_edit for it.', $doc['title'] ) );
		}
		$ops = array_values( array_filter( (array) ( $input['operations'] ?? array() ), 'is_array' ) );
		if ( ! $ops || count( $ops ) > self::MAX_OPS ) {
			return new WP_Error( 'addsynapse_assistant_design_ops', sprintf( 'Give 1 to %d operations per plan (one document per plan).', self::MAX_OPS ) );
		}
		$raw = (string) $doc['raw'];
		try {
			$tree = new ElementorDoc( $raw, self::$random );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'addsynapse_assistant_design_elementor_data', $e->getMessage() . ' It cannot be edited safely from the chat.' );
		}
		$labels = array();
		foreach ( $tree->elements() as $entry ) {
			$labels[ $entry['id'] ] = ElementorDoc::label( $entry['element'] );
		}
		$lines = array();
		foreach ( $ops as $i => $op ) {
			try {
				$lines[] = self::run_op( $tree, $op, $labels );
			} catch ( \InvalidArgumentException $e ) {
				return new WP_Error( 'addsynapse_assistant_design_op', sprintf( 'Operation %1$d (%2$s): %3$s Nothing was planned.', $i + 1, (string) ( $op['op'] ?? '?' ), $e->getMessage() ) );
			}
		}
		$new = $tree->serialize();
		if ( $new === $raw || json_decode( $new, true ) === json_decode( $raw, true ) ) {
			return $this->store( array(), self::OPERATION, sprintf( '%s: nothing would change.', $doc['title'] ), array(), $user_id, $conversation_id );
		}
		if ( ! is_array( json_decode( $new, true ) ) ) {
			return new WP_Error( 'addsynapse_assistant_design_elementor_data', 'The change would break the Elementor data. Nothing was planned.' );
		}
		$external = array_values( array_unique( $tree->external ) );
		$item     = array(
			'object_type' => BlocksEdit::OBJECT,
			'object_id'   => (int) $doc['post_id'],
			'parent_id'   => 0,
			'label'       => (string) $doc['title'],
			'sku'         => '',
			'field'       => BlocksEdit::FIELD,
			'before'      => array(
				'ref'  => Docs::ref( $doc ),
				'hash' => hash( 'sha256', $raw ),
				'raw'  => $raw,
			),
			'after'       => array(
				'hash' => hash( 'sha256', $new ),
				'raw'  => $new,
				'ops'  => $lines,
			),
		);
		$site     = in_array( (string) ( $doc['elementor_type'] ?? '' ), array( 'header', 'footer' ), true );
		return $this->store(
			array( $item ),
			self::OPERATION,
			sprintf( '%1$s (Elementor): %2$d change(s)%3$s.', $doc['title'], count( $lines ), $site ? ', shown on every page that uses this ' . $doc['elementor_type'] : '' ),
			array(
				'title'    => (string) $doc['title'],
				'kind'     => 'elementor',
				'url'      => (string) $doc['url'],
				'external' => $external,
			),
			$user_id,
			$conversation_id,
			array(
				'document' => array(
					'title'          => (string) $doc['title'],
					'kind'           => 'elementor',
					'elementor_type' => (string) ( $doc['elementor_type'] ?? '' ),
					'url'            => (string) $doc['url'],
				),
				'changes'  => $lines,
				'warnings' => $external ? array( 'external_link' ) : array(),
				'external' => $external,
			)
		);
	}

	/**
	 * Runs one operation.
	 *
	 * @param ElementorDoc         $tree   Tree.
	 * @param array<string,mixed>  $op     Operation.
	 * @param array<string,string> $labels Labels of the original elements.
	 * @return array<string,string> Card line {label, field, before, after}.
	 * @throws \InvalidArgumentException When it cannot be done.
	 */
	private static function run_op( ElementorDoc $tree, array $op, array $labels ): array {
		$kind  = (string) ( $op['op'] ?? '' );
		$id    = (string) ( $op['element'] ?? '' );
		$label = static function ( string $element ) use ( $labels, $tree ): string {
			if ( isset( $labels[ $element ] ) ) {
				return $labels[ $element ];
			}
			return ElementorDoc::label( $tree->get( $element ) );
		};
		$where = (string) ( $op['where'] ?? 'after' );
		if ( in_array( $kind, array( 'move', 'insert' ), true ) && ! in_array( $where, BlocksEdit::WHERE, true ) ) {
			throw new \InvalidArgumentException( 'where is before, after, inside_first, inside_last, top or bottom.' );
		}
		$anchor = (string) ( $op['anchor'] ?? '' );
		switch ( $kind ) {
			case 'set_settings':
				if ( ! is_array( $op['settings'] ?? null ) || ! $op['settings'] ) {
					throw new \InvalidArgumentException( 'settings must be an object of Elementor settings.' );
				}
				$what = $label( $id );
				$diff = $tree->set_settings( $id, (array) $op['settings'] );
				return array(
					'label'  => $what,
					'field'  => 'settings',
					'before' => BlocksEdit::attrs_text( array_filter( $diff['before'], static fn( $v ) => null !== $v ) ),
					'after'  => BlocksEdit::attrs_text( array_filter( $diff['after'], static fn( $v ) => null !== $v ) ),
				);
			case 'set_text':
				$what = $label( $id );
				$diff = $tree->set_text( $id, (string) ( $op['text'] ?? '' ) );
				return array(
					'label'  => $what,
					'field'  => 'text',
					'before' => $diff['before'],
					'after'  => $diff['after'],
				);
			case 'set_link':
				$what = $label( $id );
				$diff = $tree->set_link( $id, (string) ( $op['url'] ?? '' ) );
				return array(
					'label'  => $what,
					'field'  => 'link',
					'before' => $diff['before'],
					'after'  => $diff['after'],
				);
			case 'set_image':
				$what = $label( $id );
				$diff = $tree->set_image( $id, (int) ( $op['image_id'] ?? 0 ), isset( $op['alt'] ) ? (string) $op['alt'] : null );
				return array(
					'label'  => $what,
					'field'  => 'image',
					'before' => $diff['before'],
					'after'  => $diff['after'],
				);
			case 'remove':
				$what = $label( $id );
				$tree->remove( $id );
				return array(
					'label'  => $what,
					'field'  => 'removed',
					'before' => $what,
					'after'  => '',
				);
			case 'move':
				$what = $label( $id );
				$tree->move( $id, $where, $anchor );
				return array(
					'label'  => $what,
					'field'  => 'moved',
					'before' => '',
					'after'  => trim( str_replace( '_', ' ', $where ) . ( '' !== $anchor ? ' ' . $label( $anchor ) : '' ) ),
				);
			case 'duplicate':
				$what = $label( $id );
				$tree->duplicate( $id );
				return array(
					'label'  => $what,
					'field'  => 'duplicated',
					'before' => '',
					'after'  => $what . ' (copy below)',
				);
			case 'insert':
				if ( ! in_array( $where, array( 'top', 'bottom' ), true ) && '' === $anchor ) {
					throw new \InvalidArgumentException( 'Say next to / inside which element (anchor = an element id), or use where top / bottom.' );
				}
				$elements = $tree->build( array_values( (array) ( $op['elements'] ?? array() ) ) );
				if ( ! $elements ) {
					throw new \InvalidArgumentException( 'elements must list the new elements.' );
				}
				$position = trim( str_replace( '_', ' ', $where ) . ( '' !== $anchor ? ' ' . $label( $anchor ) : '' ) );
				$tree->insert( $elements, $where, $anchor );
				return array(
					'label'  => implode( ', ', array_map( array( ElementorDoc::class, 'label' ), $elements ) ),
					'field'  => 'added',
					'before' => '',
					'after'  => $position,
				);
		}//end switch
		throw new \InvalidArgumentException( 'Unknown op: use set_settings, set_text, set_link, set_image, insert, move, duplicate or remove.' );
	}
}
