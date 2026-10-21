<?php
/**
 * Pure helpers over normalized menu snapshots (see MenuAdapterInterface).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Menus;

defined( 'ABSPATH' ) || exit;

/**
 * No WordPress writes here: tree conversion, comparison and the before → after preview.
 */
final class MenuTree {

	/**
	 * Item keys that describe what is STORED (display title/link are derived → not compared).
	 */
	public const STORED_KEYS = array( 'id', 'label', 'type', 'object', 'object_id', 'url', 'parent', 'extra' );

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
	 * Stored form of the items (order + stored keys only) for comparisons.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	public static function stored( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$row = array();
			foreach ( self::STORED_KEYS as $key ) {
				$value = $item[ $key ] ?? null;
				if ( in_array( $key, array( 'id', 'parent', 'object_id' ), true ) && is_numeric( $value ) ) {
					$value = (int) $value;
				}
				if ( 'extra' === $key ) {
					$value = is_array( $value ) ? $value : array();
					unset( $value['block'] );
					ksort( $value );
				}
				$row[ $key ] = $value;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Location keys that matter for a change between two snapshots: those that differ, or
	 * point at this menu on either side.
	 *
	 * @param array<string,mixed> $a Snapshot.
	 * @param array<string,mixed> $b Snapshot.
	 * @return array<int,string>
	 */
	public static function location_keys( array $a, array $b ): array {
		$la   = (array) ( $a['locations'] ?? array() );
		$lb   = (array) ( $b['locations'] ?? array() );
		$keys = array();
		foreach ( array_unique( array_merge( array_keys( $la ), array_keys( $lb ) ) ) as $slug ) {
			$va = $la[ $slug ] ?? 0;
			$vb = $lb[ $slug ] ?? 0;
			if ( (string) $va !== (string) $vb || 'self' === $va || 'self' === $vb ) {
				$keys[] = (string) $slug;
			}
		}
		sort( $keys );
		return $keys;
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
	 * Preview for the card / tool result: both trees with per-node states, plus location
	 * changes. States — after: new | renamed | moved | unchanged (renamed+moved possible);
	 * before: removed | unchanged. `$moved` = ids explicitly moved by the plan.
	 *
	 * @param array<string,mixed> $before Snapshot before.
	 * @param array<string,mixed> $after  Snapshot after.
	 * @param array<int,int>      $moved  Explicitly moved ids.
	 * @param int                 $limit  Max nodes per tree.
	 * @return array<string,mixed>
	 */
	public static function preview( array $before, array $after, array $moved = array(), int $limit = 200 ): array {
		$old = array();
		foreach ( (array) $before['items'] as $index => $item ) {
			$old[ (string) $item['id'] ] = $item + array( 'index' => $index );
		}
		$new_ids = array();
		foreach ( (array) $after['items'] as $item ) {
			$new_ids[ (string) $item['id'] ] = true;
		}
		$counts = array(
			'new'       => 0,
			'renamed'   => 0,
			'moved'     => 0,
			'removed'   => 0,
			'unchanged' => 0,
		);
		$moved  = array_map( 'strval', $moved );
		$tree_a = array();
		foreach ( (array) $after['items'] as $item ) {
			$id     = (string) $item['id'];
			$states = array();
			if ( ! isset( $old[ $id ] ) ) {
				$states[] = 'new';
			} else {
				if ( (string) $old[ $id ]['label'] !== (string) $item['label'] ) {
					$states[] = 'renamed';
				}
				if ( (string) $old[ $id ]['parent'] !== (string) $item['parent'] || in_array( $id, $moved, true ) ) {
					$states[] = 'moved';
				}
			}
			foreach ( $states as $state ) {
				++$counts[ $state ];
			}
			if ( ! $states ) {
				++$counts['unchanged'];
			}
			$tree_a[] = self::node( $item, $states, isset( $old[ $id ] ) ? (string) $old[ $id ]['title'] : '' );
		}//end foreach
		$tree_b = array();
		foreach ( (array) $before['items'] as $item ) {
			$removed = ! isset( $new_ids[ (string) $item['id'] ] );
			if ( $removed ) {
				++$counts['removed'];
			}
			$tree_b[] = self::node( $item, $removed ? array( 'removed' ) : array(), '' );
		}
		$locations = array();
		foreach ( self::location_keys( $before, $after ) as $slug ) {
			$from = $before['locations'][ $slug ] ?? 0;
			$to   = $after['locations'][ $slug ] ?? 0;
			if ( (string) $from === (string) $to ) {
				continue;
			}
			$locations[] = array(
				'slug' => $slug,
				'from' => $from,
				'to'   => $to,
			);
		}
		return array(
			'adapter'     => (string) ( $after['adapter'] ?? $before['adapter'] ?? '' ),
			'menu'        => array(
				'id'          => (int) ( $after['menu']['id'] ?? 0 ) ? (int) $after['menu']['id'] : (int) ( $before['menu']['id'] ?? 0 ),
				'name'        => (string) ( $after['menu']['name'] ?? $before['menu']['name'] ?? '' ),
				'name_before' => (string) ( $before['menu']['name'] ?? '' ),
				'is_new'      => empty( $before['exists'] ),
				'deleted'     => empty( $after['exists'] ),
			),
			'counts'      => $counts,
			'tree_before' => array_slice( $tree_b, 0, $limit ),
			'tree_after'  => array_slice( $tree_a, 0, $limit ),
			'truncated'   => count( $tree_a ) > $limit || count( $tree_b ) > $limit,
			'locations'   => $locations,
		);
	}

	/**
	 * One preview node.
	 *
	 * @param array<string,mixed> $item      Item.
	 * @param array<int,string>   $states    States.
	 * @param string              $old_title Title before (renamed).
	 * @return array<string,mixed>
	 */
	private static function node( array $item, array $states, string $old_title ): array {
		$node = array(
			'id'     => $item['id'],
			'title'  => (string) $item['title'],
			'type'   => (string) $item['type'],
			'object' => (string) $item['object'],
			'depth'  => (int) ( $item['depth'] ?? 0 ),
			'parent' => $item['parent'],
			'states' => $states,
		);
		if ( in_array( 'renamed', $states, true ) ) {
			$node['title_before'] = $old_title;
		}
		if ( 'custom' === $item['type'] ) {
			$node['url'] = (string) $item['url'];
		}
		return $node;
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
