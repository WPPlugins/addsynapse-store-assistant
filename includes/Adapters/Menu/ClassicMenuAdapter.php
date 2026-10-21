<?php
/**
 * Classic menus: nav_menu terms + nav_menu_item posts + theme locations.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Menu;

use Addsynapse\StoreAssistant\Menus\MenuTree;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes through core APIs only: wp_create_nav_menu, wp_update_nav_menu_object,
 * wp_update_nav_menu_item, wp_delete_post, wp_delete_nav_menu, (get|set)_theme_mod
 * nav_menu_locations. Undo recreates removed items (new ids, same tree and fields).
 */
class ClassicMenuAdapter implements MenuAdapterInterface {

	public const KIND = 'classic';

	/**
	 * 'classic' | 'block'.
	 */
	public function kind(): string {
		return self::KIND;
	}

	/**
	 * Whether theme locations can be assigned.
	 */
	public function supports_locations(): bool {
		return true;
	}

	/**
	 * Registered locations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function locations(): array {
		$assigned = get_nav_menu_locations();
		$out      = array();
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$menu_id = (int) ( $assigned[ $slug ] ?? 0 );
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
			$out[]   = array(
				'slug'      => (string) $slug,
				'label'     => html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ),
				'menu_id'   => $menu ? (int) $menu->term_id : 0,
				'menu_name' => $menu ? html_entity_decode( (string) $menu->name, ENT_QUOTES, 'UTF-8' ) : '',
			);
		}
		return $out;
	}

	/**
	 * Menus.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function menus(): array {
		$out = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$out[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => html_entity_decode( (string) $menu->name, ENT_QUOTES, 'UTF-8' ),
				'slug'  => (string) $menu->slug,
				'count' => (int) $menu->count,
			);
		}
		return $out;
	}

	/**
	 * Menu id by reference, or null.
	 *
	 * @param string $ref Id, slug or name.
	 */
	public function find( string $ref ): ?int {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( preg_match( '/^#?\d{1,18}$/', $ref ) ) {
			$menu = wp_get_nav_menu_object( (int) ltrim( $ref, '#' ) );
			return $menu ? (int) $menu->term_id : null;
		}
		$key   = MenuTree::key( $ref );
		$found = array();
		foreach ( $this->menus() as $menu ) {
			if ( $menu['slug'] === $ref || MenuTree::key( $menu['name'] ) === $key || MenuTree::key( $menu['slug'] ) === $key ) {
				$found[ $menu['id'] ] = true;
			}
		}
		return 1 === count( $found ) ? (int) array_key_first( $found ) : null;
	}

	/**
	 * Snapshot of a menu that does not exist yet.
	 *
	 * @param string $name Name.
	 * @return array<string,mixed>
	 */
	public function blank( string $name ): array {
		return array(
			'adapter'   => self::KIND,
			'exists'    => false,
			'menu'      => array(
				'id'   => 0,
				'name' => $name,
				'slug' => '',
			),
			'items'     => array(),
			'locations' => $this->location_map( 0 ),
			'mod_set'   => self::mod_set(),
		);
	}

	/**
	 * Current snapshot.
	 *
	 * @param int $menu_id Menu id.
	 * @return array<string,mixed>
	 */
	public function read( int $menu_id ): array {
		$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
		if ( ! $menu ) {
			$blank               = $this->blank( '' );
			$blank['menu']['id'] = $menu_id;
			return $blank;
		}
		$posts = get_posts(
			array(
				'post_type'              => 'nav_menu_item',
				'post_status'            => 'any',
				'numberposts'            => -1,
				'orderby'                => 'menu_order',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one menu, bounded.
				'tax_query'              => array(
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_taxonomy_id',
						'terms'    => (int) $menu->term_taxonomy_id,
					),
				),
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			$item    = wp_setup_nav_menu_item( $post );
			$classes = array_filter( array_map( 'strval', (array) $item->classes ) );
			$items[] = array(
				'id'        => (int) $item->ID,
				'label'     => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'title'     => html_entity_decode( wp_strip_all_tags( (string) $item->title ), ENT_QUOTES, 'UTF-8' ),
				'type'      => (string) $item->type,
				'object'    => (string) $item->object,
				'object_id' => (int) $item->object_id,
				'url'       => 'custom' === $item->type ? (string) $item->url : '',
				'parent'    => (int) $item->menu_item_parent,
				'order'     => (int) $post->menu_order,
				'link'      => (string) $item->url,
				'extra'     => array(
					'target'      => (string) $item->target,
					'attr_title'  => (string) $post->post_excerpt,
					'classes'     => implode( ' ', $classes ),
					'xfn'         => (string) $item->xfn,
					'description' => (string) $post->post_content,
					'status'      => (string) $post->post_status,
				),
			);
		}//end foreach
		return array(
			'adapter'   => self::KIND,
			'exists'    => true,
			'menu'      => array(
				'id'   => (int) $menu->term_id,
				'name' => html_entity_decode( (string) $menu->name, ENT_QUOTES, 'UTF-8' ),
				'slug' => (string) $menu->slug,
			),
			'items'     => MenuTree::with_depth( $items ),
			'locations' => $this->location_map( (int) $menu->term_id ),
			'mod_set'   => self::mod_set(),
		);
	}

	/**
	 * Whether the theme stores a nav_menu_locations mod at all (exact undo: an unset mod is
	 * removed again instead of being left as an empty array).
	 */
	private static function mod_set(): bool {
		return null !== get_theme_mod( 'nav_menu_locations', null );
	}

	/**
	 * {slug: "self" | other menu id | 0} for every registered location.
	 *
	 * @param int $menu_id This menu (0 = none).
	 * @return array<string,int|string>
	 */
	private function location_map( int $menu_id ): array {
		$assigned = get_nav_menu_locations();
		$out      = array();
		foreach ( array_keys( get_registered_nav_menus() ) as $slug ) {
			$id           = (int) ( $assigned[ $slug ] ?? 0 );
			$out[ $slug ] = $menu_id > 0 && $id === $menu_id ? 'self' : $id;
		}
		return $out;
	}

	/**
	 * Same stored state?
	 *
	 * @param array<string,mixed> $a             Snapshot.
	 * @param array<string,mixed> $b             Snapshot.
	 * @param array<int,string>   $location_keys Locations to compare.
	 */
	public function same( array $a, array $b, array $location_keys = array() ): bool {
		if ( ! empty( $a['exists'] ) !== ! empty( $b['exists'] ) ) {
			return false;
		}
		foreach ( $location_keys as $slug ) {
			if ( (string) ( $a['locations'][ $slug ] ?? 0 ) !== (string) ( $b['locations'][ $slug ] ?? 0 ) ) {
				return false;
			}
		}
		if ( empty( $a['exists'] ) ) {
			return true;
		}
		return (string) $a['menu']['name'] === (string) $b['menu']['name']
			&& MenuTree::stored( (array) $a['items'] ) === MenuTree::stored( (array) $b['items'] );
	}

	/**
	 * Fields of a new item.
	 *
	 * @param string $object_type product_cat|product|page|post|custom.
	 * @param int    $object_id   Object id.
	 * @param string $url         Custom URL.
	 * @return array<string,mixed>
	 */
	public function new_item( string $object_type, int $object_id, string $url ): array {
		$type = 'custom';
		if ( 'product_cat' === $object_type ) {
			$type = 'taxonomy';
		} elseif ( in_array( $object_type, array( 'product', 'page', 'post' ), true ) ) {
			$type = 'post_type';
		}
		return array(
			'type'      => $type,
			'object'    => 'custom' === $type ? 'custom' : $object_type,
			'object_id' => 'custom' === $type ? 0 : $object_id,
			'url'       => 'custom' === $type ? $url : '',
			'extra'     => array(
				'target'      => '',
				'attr_title'  => '',
				'classes'     => '',
				'xfn'         => '',
				'description' => '',
				'status'      => 'publish',
			),
		);
	}

	/**
	 * Makes the store match the target; returns the stored snapshot.
	 *
	 * @param array<string,mixed> $current Current snapshot.
	 * @param array<string,mixed> $target  Target snapshot.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When WordPress refuses a write.
	 */
	public function write( array $current, array $target ): array {
		$menu_id = (int) ( $current['menu']['id'] ?? 0 );
		if ( empty( $target['exists'] ) ) {
			// Undo of create_menu: delete what we created, give the locations back.
			if ( ! empty( $current['exists'] ) && $menu_id > 0 ) {
				foreach ( (array) $current['items'] as $item ) {
					wp_delete_post( (int) $item['id'], true );
				}
				$deleted = wp_delete_nav_menu( $menu_id );
				if ( is_wp_error( $deleted ) || ! $deleted ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored as the item's error text, never printed raw.
					throw new \RuntimeException( 'The menu could not be deleted.' );
				}
				// After the delete: core itself rewrites the locations when a menu goes away.
				$this->write_locations( $current, $target, $menu_id );
			}
			$blank               = $this->blank( (string) ( $target['menu']['name'] ?? '' ) );
			$blank['menu']['id'] = $menu_id;
			return $blank;
		}
		if ( empty( $current['exists'] ) ) {
			$created = wp_create_nav_menu( wp_slash( (string) $target['menu']['name'] ) );
			if ( is_wp_error( $created ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored as the item's error text, never printed raw.
				throw new \RuntimeException( $created->get_error_message() );
			}
			$menu_id = (int) $created;
		} elseif ( (string) $current['menu']['name'] !== (string) $target['menu']['name'] ) {
			$renamed = wp_update_nav_menu_object( $menu_id, wp_slash( array( 'menu-name' => (string) $target['menu']['name'] ) ) );
			if ( is_wp_error( $renamed ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored as the item's error text, never printed raw.
				throw new \RuntimeException( $renamed->get_error_message() );
			}
		}

		$existing = array();
		foreach ( (array) $current['items'] as $item ) {
			$existing[ (string) $item['id'] ] = $item;
		}
		$wanted = array();
		foreach ( (array) $target['items'] as $item ) {
			$wanted[ (string) $item['id'] ] = true;
		}
		foreach ( $existing as $id => $item ) {
			if ( ! isset( $wanted[ $id ] ) ) {
				wp_delete_post( (int) $id, true );
			}
		}

		$map      = array();
		$position = 0;
		foreach ( (array) $target['items'] as $item ) {
			++$position;
			$key    = (string) $item['id'];
			$parent = (string) ( $item['parent'] ?? 0 );
			if ( isset( $map[ $parent ] ) ) {
				$parent_id = $map[ $parent ];
			} elseif ( isset( $existing[ $parent ] ) && isset( $wanted[ $parent ] ) ) {
				$parent_id = (int) $parent;
			} else {
				$parent_id = 0;
			}
			$old = $existing[ $key ] ?? null;
			if ( $old && (string) $old['label'] === (string) $item['label'] && (int) $old['parent'] === $parent_id && (int) ( $old['order'] ?? 0 ) === $position ) {
				continue;
				// Nothing to write for this one.
			}
			$args   = self::args( $old ? $old : $item, (string) $item['label'], $parent_id, $position );
			$result = wp_update_nav_menu_item( $menu_id, $old ? (int) $key : 0, wp_slash( $args ) );
			if ( is_wp_error( $result ) || ! $result ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored as the item's error text, never printed raw.
				throw new \RuntimeException( is_wp_error( $result ) ? $result->get_error_message() : 'A menu item could not be saved.' );
			}
			if ( ! $old ) {
				$map[ $key ] = (int) $result;
			}
		}//end foreach

		$this->write_locations( $current, $target, $menu_id );
		return $this->read( $menu_id );
	}

	/**
	 * Arguments for wp_update_nav_menu_item (every field, since it resets missing ones).
	 *
	 * @param array<string,mixed> $item      Item (stored fields).
	 * @param string              $label     Label.
	 * @param int                 $parent_id Parent item id.
	 * @param int                 $position  Menu order.
	 * @return array<string,mixed>
	 */
	private static function args( array $item, string $label, int $parent_id, int $position ): array {
		$extra = (array) ( $item['extra'] ?? array() );
		return array(
			'menu-item-object-id'   => (int) $item['object_id'],
			'menu-item-object'      => (string) $item['object'],
			'menu-item-parent-id'   => $parent_id,
			'menu-item-position'    => $position,
			'menu-item-type'        => (string) $item['type'],
			'menu-item-title'       => $label,
			'menu-item-url'         => 'custom' === $item['type'] ? (string) $item['url'] : '',
			'menu-item-description' => (string) ( $extra['description'] ?? '' ),
			'menu-item-attr-title'  => (string) ( $extra['attr_title'] ?? '' ),
			'menu-item-target'      => (string) ( $extra['target'] ?? '' ),
			'menu-item-classes'     => (string) ( $extra['classes'] ?? '' ),
			'menu-item-xfn'         => (string) ( $extra['xfn'] ?? '' ),
			'menu-item-status'      => 'draft' === ( $extra['status'] ?? '' ) ? 'draft' : 'publish',
		);
	}

	/**
	 * Applies the location assignments of $target that differ from $current.
	 *
	 * @param array<string,mixed> $current Current snapshot.
	 * @param array<string,mixed> $target  Target snapshot.
	 * @param int                 $menu_id This menu.
	 */
	private function write_locations( array $current, array $target, int $menu_id ): void {
		$original  = get_theme_mod( 'nav_menu_locations', array() );
		$original  = is_array( $original ) ? $original : array();
		$locations = $original;
		foreach ( MenuTree::location_keys( $current, $target ) as $slug ) {
			$want = $target['locations'][ $slug ] ?? 0;
			$want = 'self' === $want ? $menu_id : (int) $want;
			if ( $want > 0 ) {
				$locations[ $slug ] = $want;
			} else {
				// Core leaves "slug => 0" behind when a menu is deleted; the exact previous state has no key.
				unset( $locations[ $slug ] );
			}
		}
		$changed = $locations !== $original;
		if ( ! $locations && isset( $target['mod_set'] ) && false === $target['mod_set'] ) {
			if ( self::mod_set() ) {
				remove_theme_mod( 'nav_menu_locations' );
			}
			return;
		}
		if ( $changed ) {
			set_theme_mod( 'nav_menu_locations', $locations );
		}
	}
}
