<?php
/**
 * Read tool describe_blocks: the block / element tree of one design document, with ids.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Abilities\Shared;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only. Blocks: {id, name, depth, parent, attrs (design attributes, long values cut), text, children}. Elementor
 * documents: ElementorDoc::describe(). At most MAX nodes (a `block` narrows to one subtree).
 */
final class DescribeBlocks {

	public const MAX = 220;

	/**
	 * Attribute keys shown in full (others: listed by name only when long).
	 */
	private const DESIGN = array( 'style', 'textColor', 'backgroundColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'className', 'align', 'textAlign', 'layout', 'level', 'tagName', 'width', 'height', 'minHeight', 'minHeightUnit', 'dimRatio', 'overlayColor', 'customOverlayColor', 'url', 'id', 'sizeSlug', 'slug', 'area', 'ref', 'metadata', 'overlayMenu', 'verticalAlignment', 'isStackedOnMobile', 'contentPosition', 'isDark', 'linkTarget', 'query', 'namespace', 'columns', 'displayLayout', 'anchor' );

	/**
	 * Primitive entry point.
	 *
	 * @param mixed $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$doc   = Docs::resolve( $input );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( 'elementor' === $doc['kind'] ) {
			return ElementorDoc::describe( $doc, (string) ( $input['block'] ?? '' ) );
		}
		try {
			$tree = new BlockDoc( BlockMarkup::expand( (string) $doc['raw'] ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'addsynapse_assistant_design_markup', 'The block markup of this content is broken (unbalanced blocks): it cannot be read safely.' );
		}
		$only   = (string) ( $input['block'] ?? '' );
		$nodes  = $tree->nodes();
		$blocks = array();
		$cut    = false;
		foreach ( $nodes as $node ) {
			if ( '' !== $only && $node['id'] !== $only && ! in_array( $only, $node['ancestors'], true ) ) {
				continue;
			}
			if ( count( $blocks ) >= self::MAX ) {
				$cut = true;
				break;
			}
			$blocks[] = array(
				'id'       => $node['id'],
				'name'     => $node['name'],
				'depth'    => $node['depth'],
				'parent'   => $node['parent'],
				'attrs'    => self::attrs( $node['attrs'] ),
				'text'     => self::text( $tree, $node ),
				'children' => $node['children'],
			);
		}
		if ( '' !== $only && ! $blocks ) {
			return new WP_Error( 'addsynapse_assistant_design_block', sprintf( 'Block %s was not found (the content may have changed).', $only ) );
		}
		$template = (array) ( $doc['template'] ?? array() );
		return array(
			'document'  => array_filter(
				array(
					'kind'    => $doc['kind'],
					'title'   => $doc['title'],
					'post_id' => (int) $doc['post_id'],
					'type'    => (string) ( $doc['type'] ?? '' ),
					'slug'    => (string) ( $template['slug'] ?? '' ),
					'source'  => (string) ( $template['source'] ?? '' ),
					'area'    => (string) ( $template['area'] ?? '' ),
					'url'     => (string) $doc['url'],
				),
				static fn( $v ) => '' !== $v
			),
			'blocks'    => $blocks,
			'count'     => count( $nodes ),
			'truncated' => $cut,
			'note'      => 'Use these block ids with plan_blocks_edit. core/template-part blocks point to template parts (template_part = their slug); core/block = a synced pattern (page_id = its ref); core/navigation = a menu (plan_menu_update).' . ( 'template' === $doc['kind'] && 'custom' !== ( $template['source'] ?? '' ) ? ' This is the theme\'s own version: the first approved change stores a customised copy (Undo goes back to the theme\'s).' : '' ),
			'_data'     => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Design attributes, values cut.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array<string,mixed>
	 */
	private static function attrs( array $attrs ): array {
		$out = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! in_array( $key, self::DESIGN, true ) && is_string( $value ) && strlen( $value ) > 80 ) {
				$value = mb_substr( $value, 0, 80 ) . '…';
			}
			$json = wp_json_encode( $value );
			if ( is_string( $json ) && strlen( $json ) > 600 ) {
				$value = '(long ' . gettype( $value ) . ')';
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Text of a block: its own text (leaf) or an excerpt.
	 *
	 * @param BlockDoc            $tree Tree.
	 * @param array<string,mixed> $node Node.
	 */
	private static function text( BlockDoc $tree, array $node ): string {
		return $node['children'] ? '' : DesignText::excerpt( (string) $node['html'], 120 );
	}
}
