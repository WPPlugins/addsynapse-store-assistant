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
 * Reads classic menus through core APIs only (wp_get_nav_menus, wp_get_nav_menu_items, get_theme_mod
 * nav_menu_locations). Read-only in this plugin: it never writes menus.
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
}
