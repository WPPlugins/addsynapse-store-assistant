<?php
/**
 * Block themes: wp_navigation posts with core/navigation-link / core/navigation-submenu blocks.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Menu;

use Addsynapse\StoreAssistant\Menus\MenuTree;

defined( 'ABSPATH' ) || exit;

/**
 * Reads navigation blocks (block themes). Item ids are depth-first POSITIONS (1..n) of the current tree: blocks have
 * no ids. Blocks other than links/submenus (page list, search, social icons…) are listed as opaque items. Read-only in
 * this plugin: it never writes menus.
 */
class BlockNavigationAdapter implements MenuAdapterInterface {

	public const KIND = 'block';

	public const POST_TYPE = 'wp_navigation';

	private const LINK    = 'core/navigation-link';
	private const SUBMENU = 'core/navigation-submenu';

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
		return false;
	}

	/**
	 * Registered locations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function locations(): array {
		return array();
	}

	/**
	 * Navigation posts.
	 *
	 * @return array<int,\WP_Post>
	 */
	private function posts(): array {
		return get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 100,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
	}

	/**
	 * Menus.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function menus(): array {
		$out = array();
		foreach ( $this->posts() as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'name'  => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'slug'  => (string) $post->post_name,
				'count' => count( $this->read( (int) $post->ID )['items'] ),
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
			$post = get_post( (int) ltrim( $ref, '#' ) );
			return $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ? (int) $post->ID : null;
		}
		$key   = MenuTree::key( $ref );
		$found = array();
		foreach ( $this->posts() as $post ) {
			if ( $post->post_name === $ref || MenuTree::key( (string) $post->post_title ) === $key ) {
				$found[ (int) $post->ID ] = true;
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
		$post = $menu_id > 0 ? get_post( $menu_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			$blank               = $this->blank( '' );
			$blank['menu']['id'] = $menu_id;
			return $blank;
		}
		$counter = 0;
		$items   = $this->items_from_blocks( self::clean( parse_blocks( (string) $post->post_content ) ), 0, 0, $counter );
		return array(
			'adapter'   => self::KIND,
			'exists'    => true,
			'menu'      => array(
				'id'   => (int) $post->ID,
				'name' => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'slug' => (string) $post->post_name,
			),
			'items'     => $items,
			'locations' => array(),
			'raw'       => (string) $post->post_content,
		);
	}

	/**
	 * Drops whitespace-only "null" blocks from a parse_blocks() result.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function clean( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				static function ( $block ) {
					return ! empty( $block['blockName'] ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) );
				}
			)
		);
	}

	/**
	 * Blocks → flat items (depth-first; ids = positions).
	 *
	 * @param array<int,array<string,mixed>> $blocks  Blocks.
	 * @param int                            $parent_id  Parent position.
	 * @param int                            $depth   Depth.
	 * @param int                            $counter Position counter (by ref).
	 * @return array<int,array<string,mixed>>
	 */
	private function items_from_blocks( array $blocks, int $parent_id, int $depth, int &$counter ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			++$counter;
			$id     = $counter;
			$name   = (string) ( $block['blockName'] ?? '' );
			$attrs  = (array) ( $block['attrs'] ?? array() );
			$is_nav = in_array( $name, array( self::LINK, self::SUBMENU ), true );
			$kind   = (string) ( $attrs['kind'] ?? '' );
			$label  = html_entity_decode( wp_strip_all_tags( (string) ( $attrs['label'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
			$shell  = $block;
			$inner  = self::clean( (array) ( $block['innerBlocks'] ?? array() ) );
			if ( $is_nav ) {
				// Children are rebuilt from the tree; keep the rest of the block as stored.
				$shell['innerBlocks']  = array();
				$shell['innerContent'] = array();
			}
			$out[] = array(
				'id'        => $id,
				'label'     => $is_nav ? $label : '',
				'title'     => $is_nav ? $label : $name,
				'type'      => $is_nav ? ( 'post-type' === $kind ? 'post_type' : ( 'taxonomy' === $kind ? 'taxonomy' : 'custom' ) ) : 'block',
				'object'    => $is_nav ? (string) ( $attrs['type'] ?? 'custom' ) : $name,
				'object_id' => $is_nav ? (int) ( $attrs['id'] ?? 0 ) : 0,
				'url'       => $is_nav ? (string) ( $attrs['url'] ?? '' ) : '',
				'parent'    => $parent_id,
				'depth'     => $depth,
				'link'      => $is_nav ? (string) ( $attrs['url'] ?? '' ) : '',
				'extra'     => array( 'block' => $shell ),
			);
			if ( $is_nav && $inner ) {
				foreach ( $this->items_from_blocks( $inner, $id, $depth + 1, $counter ) as $child ) {
					$out[] = $child;
				}
			}
		}//end foreach
		return $out;
	}

	/**
	 * Tree nodes → blocks. Links with children become submenus and vice versa.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @return array<int,array<string,mixed>>
	 */
	private static function blocks( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			$block = (array) ( $node['extra']['block'] ?? array() );
			if ( 'block' === ( $node['type'] ?? '' ) ) {
				$out[] = $block;
				// Opaque: exactly as stored.
				continue;
			}
			$children                = self::blocks( (array) ( $node['children'] ?? array() ) );
			$block['attrs']          = (array) ( $block['attrs'] ?? array() );
			$block['attrs']['label'] = (string) $node['label'];
			$block['blockName']      = $children ? self::SUBMENU : self::LINK;
			$block['innerBlocks']    = $children;
			$block['innerHTML']      = '';
			$block['innerContent']   = array_fill( 0, count( $children ), null );
			$out[]                   = $block;
		}
		return $out;
	}
}
