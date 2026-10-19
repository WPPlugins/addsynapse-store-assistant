<?php
/**
 * Pure helpers over normalized menu snapshots (see MenuAdapterInterface).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Menus;

defined( 'ABSPATH' ) || exit;

/**
 * No WordPress writes here: tree conversion for describe_menus (read-only).
 */
final class MenuTree {

	/**
	 * Flat depth-first list → nested nodes ('children'). Items whose parent is missing become
	 * top-level; order among siblings follows the flat order.
	 *
	 * @param array<int,array<string,mixed>> $items Flat items.
	 * @return array<int,array<string,mixed>>
	 */
	public static function to_tree( array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			$ids[ (string) $item['id'] ] = true;
		}
		$children = array();
		foreach ( $items as $item ) {
			$parent_key                = (string) ( $item['parent'] ?? 0 );
			$parent_key                = isset( $ids[ $parent_key ] ) && $parent_key !== (string) $item['id'] ? $parent_key : '0';
			$children[ $parent_key ][] = $item;
		}
		$build = static function ( string $parent_key, int $depth ) use ( &$build, $children ): array {
			$out = array();
			if ( $depth > 20 ) {
				return $out;
			}
			foreach ( $children[ $parent_key ] ?? array() as $item ) {
				$item['children'] = $build( (string) $item['id'], $depth + 1 );
				$out[]            = $item;
			}
			return $out;
		};
		return $build( '0', 0 );
	}

	/**
	 * Nested nodes → flat depth-first list with parent (and depth) set.
	 *
	 * @param array<int,array<string,mixed>> $nodes  Nodes.
	 * @param int|string                     $parent_id Parent id.
	 * @param int                            $depth  Depth.
	 * @return array<int,array<string,mixed>>
	 */
	public static function flatten( array $nodes, $parent_id = 0, int $depth = 0 ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			$children       = (array) ( $node['children'] ?? array() );
			$node['parent'] = $parent_id;
			$node['depth']  = $depth;
			unset( $node['children'] );
			$out[] = $node;
			foreach ( self::flatten( $children, $node['id'], $depth + 1 ) as $child ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * Normalized key for a title (case/accents/spaces).
	 *
	 * @param string $text Text.
	 */
	public static function key( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		return \Addsynapse\StoreAssistant\I18n\Locales::fold( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/**
	 * Depth of each item of a flat list (by parent chain).
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_depth( array $items ): array {
		return self::flatten( self::to_tree( $items ) );
	}
}
