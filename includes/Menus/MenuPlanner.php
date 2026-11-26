<?php
/**
 * Planner of addsynapse-assistant/plan-menu (ported from the Connector's menu planner): operations → target menu tree.
 * Writes nothing and stores nothing: AddSynapse keeps the draft (before/after snapshots) and, after the owner approved it,
 * sends it back through apply-changes (kind «menu»), which writes it only if the menu still matches «before».
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Menus;

use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapterInterface;
use Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapters;
use Addsynapse\StoreAssistant\Links\OwnLinks;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ONE menu per plan. The whole menu is ONE changeset line (before snapshot → after snapshot):
 * apply writes it only if the menu is still exactly as previewed (else conflict); undo
 * restores the previous tree (deleting what the plan created, recreating what it removed).
 * Referenced categories/products/pages must exist and be published; custom links must be
 * http(s). Nothing here touches users, SQL or code.
 */
class MenuPlanner {

	public const OPERATION = 'menu_update';

	public const OPS = array( 'create_menu', 'add_item', 'rename_item', 'move_item', 'remove_item', 'assign_location' );

	public const OBJECTS = array( 'product_cat', 'product_tag', 'category', 'product', 'page', 'post', 'custom' );

	/**
	 * Taxonomies a menu item can link to (1.3.20: product tags and post categories too).
	 */
	public const TAXONOMIES = array( 'product_cat', 'product_tag', 'category' );

	public const MAX_OPS   = 30;
	public const MAX_ITEMS = 500;

	public const NOTE = 'NOTHING has been changed yet. This is a DRAFT preview of the menu tree shown to the manager as a card with Approve / Reject buttons (shown in the manager\'s language). Only the manager can approve it; you cannot approve, apply, reject or undo it.';

	/**
	 * Adapter.
	 *
	 * @var MenuAdapterInterface
	 */
	private MenuAdapterInterface $adapter;

	/**
	 * New item counter.
	 *
	 * @var int
	 */
	private int $next = 0;

	/**
	 * Constructor.
	 *
	 * @param MenuAdapterInterface|null $adapter Adapter (default: the active theme's).
	 */
	public function __construct( ?MenuAdapterInterface $adapter = null ) {
		$this->adapter = $adapter ?? MenuAdapters::current();
	}

	/**
	 * Plans the operations.
	 *
	 * @param array<string,mixed> $input Input (menu, operations).
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input ) {
		$ops = array_values( (array) ( $input['operations'] ?? array() ) );
		if ( ! $ops ) {
			return self::error( 'Give at least one operation.' );
		}
		if ( count( $ops ) > self::MAX_OPS ) {
			return self::error( sprintf( 'Too many operations (max %d per plan).', self::MAX_OPS ) );
		}
		$menu_ref = trim( (string) ( $input['menu'] ?? '' ) );
		$creating = 'create_menu' === ( $ops[0]['op'] ?? '' );

		if ( $creating ) {
			$name = trim( (string) ( $ops[0]['name'] ?? '' ) );
			if ( '' !== $menu_ref ) {
				return self::error( 'Omit "menu" when the plan starts with create_menu (the new menu is the one being edited).' );
			}
			if ( '' === $name ) {
				return self::error( 'create_menu needs a name.' );
			}
			$existing = $this->adapter->find( $name );
			if ( null !== $existing ) {
				return self::error( sprintf( 'A menu named "%1$s" already exists (id %2$d). Edit it with menu="%2$d" instead.', $name, $existing ) );
			}
			$before = $this->adapter->blank( $name );
		} else {
			if ( '' === $menu_ref ) {
				return self::error( 'Say which menu to edit ("menu": id, slug or name — see describe_menus), or start with create_menu.' );
			}
			$menu_id = $this->adapter->find( $menu_ref );
			if ( null === $menu_id ) {
				return self::error( sprintf( 'Menu "%1$s" not found or ambiguous. Menus: %2$s.', $menu_ref, $this->menu_list() ) );
			}
			$before = $this->adapter->read( $menu_id );
		}//end if

		$target           = $before;
		$target['exists'] = true;
		unset( $target['raw'] );
		$tree     = MenuTree::to_tree( (array) $before['items'] );
		$refs     = array();
		$moved    = array();
		$warnings = array();
		$lines    = array();

		foreach ( $ops as $index => $op ) {
			$op   = is_array( $op ) ? $op : array();
			$kind = (string) ( $op['op'] ?? '' );
			$at   = sprintf( 'Operation %d (%s): ', $index + 1, $kind );
			if ( ! in_array( $kind, self::OPS, true ) ) {
				return self::error( $at . 'unknown operation.' );
			}
			switch ( $kind ) {
				case 'create_menu':
					if ( 0 !== $index ) {
						return self::error( $at . 'create_menu can only be the first operation.' );
					}
					$lines[] = 'create menu "' . $target['menu']['name'] . '"';
					break;

				case 'add_item':
					$node = $this->new_node( $op );
					if ( is_wp_error( $node ) ) {
						return self::error( $at . $node->get_error_message() );
					}
					$parent = $this->parent( $tree, $op, $refs, 0 );
					if ( is_wp_error( $parent ) ) {
						return self::error( $at . $parent->get_error_message() );
					}
					foreach ( self::children_of( $tree, $parent ) as $sibling ) {
						if ( $sibling['type'] === $node['type'] && $sibling['object'] === $node['object'] && (int) $sibling['object_id'] === (int) $node['object_id'] && ( 'custom' !== $node['type'] || $sibling['url'] === $node['url'] ) ) {
							$warnings[] = sprintf( '"%s" is already in the menu at the same level; it will appear twice.', $node['title'] );
							break;
						}
					}
					if ( ! self::insert( $tree, $parent, $node, (int) ( $op['position'] ?? 0 ) ) ) {
						return self::error( $at . 'the parent item was not found.' );
					}
					if ( ! empty( $op['ref'] ) ) {
						$refs[ (string) $op['ref'] ] = $node['id'];
					}
					$lines[] = 'add "' . $node['title'] . '"';
					break;

				case 'rename_item':
					$id = $this->item( $tree, $op, $refs );
					if ( is_wp_error( $id ) ) {
						return self::error( $at . $id->get_error_message() );
					}
					$title = self::clean_title( (string) ( $op['title'] ?? '' ) );
					if ( '' === $title ) {
						return self::error( $at . 'give the new title.' );
					}
					self::update(
						$tree,
						$id,
						array(
							'label' => $title,
							'title' => $title,
						)
					);
					$lines[] = 'rename item ' . $id . ' to "' . $title . '"';
					break;

				case 'move_item':
					$id = $this->item( $tree, $op, $refs );
					if ( is_wp_error( $id ) ) {
						return self::error( $at . $id->get_error_message() );
					}
					$has_parent = isset( $op['parent_id'] ) || isset( $op['parent_title'] ) || isset( $op['parent_ref'] );
					if ( ! $has_parent && empty( $op['position'] ) ) {
						return self::error( $at . 'say where to move it (parent_id / parent_title / parent_ref and/or position).' );
					}
					$current_parent = self::parent_of( $tree, $id );
					$parent         = $has_parent ? $this->parent( $tree, $op, $refs, 0 ) : $current_parent;
					if ( is_wp_error( $parent ) ) {
						return self::error( $at . $parent->get_error_message() );
					}
					if ( (string) $parent === (string) $id || in_array( (string) $parent, self::descendants( $tree, $id ), true ) ) {
						return self::error( $at . 'an item cannot be moved under itself.' );
					}
					$node = self::remove( $tree, $id );
					if ( ! $node || ! self::insert( $tree, $parent, $node, (int) ( $op['position'] ?? 0 ) ) ) {
						return self::error( $at . 'the item or its new parent was not found.' );
					}
					if ( is_int( $id ) ) {
						$moved[] = $id;
					}
					$lines[] = 'move item ' . $id;
					break;

				case 'remove_item':
					$id = $this->item( $tree, $op, $refs );
					if ( is_wp_error( $id ) ) {
						return self::error( $at . $id->get_error_message() );
					}
					$sub = count( self::descendants( $tree, $id ) );
					self::remove( $tree, $id );
					if ( $sub ) {
						$warnings[] = sprintf( 'Removing item %1$s also removes its %2$d sub-items.', $id, $sub );
					}
					$lines[] = 'remove item ' . $id;
					break;

				case 'assign_location':
					if ( ! $this->adapter->supports_locations() ) {
						return self::error( $at . 'this theme is a block theme: menus are placed in the Site Editor (Appearance → Editor), not in theme locations.' );
					}
					$slug = (string) ( $op['location'] ?? '' );
					if ( ! array_key_exists( $slug, (array) $target['locations'] ) ) {
						return self::error( $at . sprintf( 'unknown theme location "%1$s". Locations: %2$s.', $slug, implode( ', ', array_keys( (array) $target['locations'] ) ) ) );
					}
					if ( 'self' === $target['locations'][ $slug ] ) {
						$warnings[] = sprintf( 'The menu is already assigned to "%s".', $slug );
					} elseif ( (int) $target['locations'][ $slug ] > 0 ) {
						$warnings[] = sprintf( 'Location "%s" currently shows another menu; it will be replaced by this one.', $slug );
					}
					$target['locations'][ $slug ] = 'self';
					$lines[]                      = 'assign to location ' . $slug;
					break;
			}//end switch
		}//end foreach

		$target['items'] = MenuTree::flatten( $tree );
		if ( count( $target['items'] ) > self::MAX_ITEMS ) {
			return self::error( sprintf( 'The menu would have more than %d items.', self::MAX_ITEMS ) );
		}
		$keys    = MenuTree::location_keys( $before, $target );
		$changes = ! $this->adapter->same( self::without_raw( $before ), $target, $keys ) || empty( $before['exists'] );
		$preview = self::decorate( MenuTree::preview( $before, $target, $moved ) );

		$params  = array(
			'menu'       => (string) $target['menu']['name'],
			'menu_id'    => (int) $target['menu']['id'],
			'new_menu'   => empty( $before['exists'] ),
			'adapter'    => $this->adapter->kind(),
			'operations' => array_slice( $lines, 0, self::MAX_OPS ),
			'moved'      => $moved,
		);
		$summary = sprintf( 'Menu "%1$s": %2$s', $target['menu']['name'], implode( '; ', $lines ) );

		return array(
			'changes'  => $changes,
			'summary'  => mb_substr( $summary, 0, 500 ),
			'params'   => $params,
			'before'   => $before,
			'after'    => $target,
			'counts'   => array( 'menus' => 1 ) + $preview['counts'] + array( 'locations' => count( $preview['locations'] ) ),
			'preview'  => $preview,
			'warnings' => array_values( array_unique( $warnings ) ),
			'_data'    => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Adds names to the location changes of a preview ("self" → this menu).
	 *
	 * @param array<string,mixed> $preview Preview.
	 * @return array<string,mixed>
	 */
	public static function decorate( array $preview ): array {
		$labels = get_registered_nav_menus();
		foreach ( $preview['locations'] as $i => $change ) {
			foreach ( array( 'from', 'to' ) as $side ) {
				$value = $change[ $side ];
				if ( 'self' === $value ) {
					$name = (string) $preview['menu']['name'];
				} elseif ( (int) $value > 0 ) {
					$menu = wp_get_nav_menu_object( (int) $value );
					$name = $menu ? html_entity_decode( (string) $menu->name, ENT_QUOTES, 'UTF-8' ) : '#' . (int) $value;
				} else {
					$name = '';
				}
				$preview['locations'][ $i ][ $side . '_name' ] = $name;
			}
			$preview['locations'][ $i ]['label'] = html_entity_decode( (string) ( $labels[ $change['slug'] ] ?? $change['slug'] ), ENT_QUOTES, 'UTF-8' );
		}
		return $preview;
	}

	/**
	 * Snapshot without the raw content (comparison with a planned target).
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,mixed>
	 */
	private static function without_raw( array $snapshot ): array {
		unset( $snapshot['raw'] );
		return $snapshot;
	}

	/**
	 * "Name (#id), …" of the menus.
	 */
	private function menu_list(): string {
		$out = array();
		foreach ( array_slice( $this->adapter->menus(), 0, 20 ) as $menu ) {
			$out[] = $menu['name'] . ' (#' . $menu['id'] . ')';
		}
		return $out ? implode( ', ', $out ) : 'none';
	}

	/**
	 * A new node from an add_item operation (object resolved and validated).
	 *
	 * @param array<string,mixed> $op Operation.
	 * @return array<string,mixed>|WP_Error
	 */
	private function new_node( array $op ) {
		$object = (string) ( $op['object'] ?? '' );
		if ( ! in_array( $object, self::OBJECTS, true ) ) {
			return new WP_Error( 'addsynapse_assistant_menu', 'add_item needs object: product_cat, product_tag, category, product, page, post or custom (custom only for other websites).' );
		}
		$title = self::clean_title( (string) ( $op['title'] ?? '' ) );
		if ( 'custom' === $object ) {
			$url = self::safe_url( (string) ( $op['url'] ?? '' ) );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			if ( '' === $title ) {
				return new WP_Error( 'addsynapse_assistant_menu', 'A custom link needs a title.' );
			}
			$resolved = array(
				'id'   => 0,
				'name' => $title,
			);
			// 1.3.20 (25/11/2026): a typed address into the shop must exist; when it names a category / page / product the
			// item links to that item, so WordPress makes (and keeps) its address.
			if ( OwnLinks::is_own( $url ) ) {
				$own = OwnLinks::resolve( $url );
				if ( '' !== $own['problem'] ) {
					return new WP_Error( 'addsynapse_assistant_menu_url', $own['problem'] );
				}
				$target = is_array( $own['object'] ) ? (string) $own['object']['type'] : '';
				if ( '' !== $target && in_array( $target, self::OBJECTS, true ) ) {
					$object   = $target;
					$url      = '';
					$resolved = array(
						'id'   => (int) $own['object']['id'],
						'name' => $title,
					);
				}
			}
		} else {
			$url      = '';
			$resolved = self::resolve_object( $object, (int) ( $op['object_id'] ?? 0 ), (string) ( $op['object_name'] ?? '' ) );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
		}//end if
		++$this->next;
		$label = '' !== $title ? $title : $resolved['name'];
		$link  = 'custom' === $object ? $url : OwnLinks::object_link( in_array( $object, self::TAXONOMIES, true ) ? 'taxonomy' : 'post-type', $object, (int) $resolved['id'] );
		return array(
			'id'       => 'new:' . $this->next,
			'label'    => $label,
			'title'    => $label,
			'parent'   => 0,
			'link'     => $link,
			'children' => array(),
		) + $this->adapter->new_item( $object, (int) $resolved['id'], $url );
	}

	/**
	 * Validates a custom link: absolute http(s) only (no javascript:, data:, relative…).
	 *
	 * @param string $url URL.
	 * @return string|WP_Error
	 */
	public static function safe_url( string $url ) {
		$url = trim( $url );
		$bad = new WP_Error( 'addsynapse_assistant_menu_url', 'Custom links must be absolute http:// or https:// URLs.' );
		if ( '' === $url || strlen( $url ) > 2000 || preg_match( '/[\x00-\x20\x7f"<>\\\\`{}|^]/', $url ) ) {
			return $bad;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $bad;
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean ) {
			return $bad;
		}
		return $clean;
	}

	/**
	 * Resolves a category/product/page/post by id or exact name (must be published).
	 *
	 * @param string $object_type Object type.
	 * @param int    $id     Id.
	 * @param string $name   Name/title.
	 * @return array{id:int,name:string}|WP_Error
	 */
	public static function resolve_object( string $object_type, int $id, string $name ) {
		$name = trim( $name );
		if ( $id < 1 && '' === $name ) {
			return new WP_Error( 'addsynapse_assistant_menu', 'Give object_id or object_name.' );
		}
		if ( in_array( $object_type, self::TAXONOMIES, true ) ) {
			if ( ! taxonomy_exists( $object_type ) ) {
				return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'The taxonomy "%s" is not available on this site.', $object_type ) );
			}
			if ( $id > 0 ) {
				$term = get_term( $id, $object_type );
				return $term instanceof \WP_Term ? array(
					'id'   => (int) $term->term_id,
					'name' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
				) : new WP_Error( 'addsynapse_assistant_menu', sprintf( 'Term %1$d of %2$s does not exist.', $id, $object_type ) );
			}
			$key   = MenuTree::key( $name );
			$found = array();
			$terms = get_terms(
				array(
					'taxonomy'   => $object_type,
					'hide_empty' => false,
					'number'     => 2000,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( MenuTree::key( (string) $term->name ) === $key || $term->slug === $name ) {
					$found[] = $term;
				}
			}
			if ( 1 === count( $found ) ) {
				return array(
					'id'   => (int) $found[0]->term_id,
					'name' => html_entity_decode( (string) $found[0]->name, ENT_QUOTES, 'UTF-8' ),
				);
			}
			if ( $found ) {
				return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'Several %1$s terms are named "%2$s" (ids %3$s): use object_id.', $object_type, $name, implode( ', ', wp_list_pluck( $found, 'term_id' ) ) ) );
			}
			return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'No %1$s term is named "%2$s". Use find_terms (taxonomy %1$s) to find the closest one and confirm it with the manager.', $object_type, $name ) );
		}//end if
		if ( ! post_type_exists( $object_type ) ) {
			// E.g. `product` on a site without WooCommerce.
			return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'The content type "%s" is not available on this site.', $object_type ) );
		}

		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== $object_type ) {
				return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'There is no %1$s with id %2$d.', $object_type, $id ) );
			}
			if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
				return new WP_Error( 'addsynapse_assistant_menu', sprintf( '"%s" is not published (or is password protected): only published content can go in a menu.', html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ) ) );
			}
			return array(
				'id'   => (int) $post->ID,
				'name' => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
			);
		}
		$key   = MenuTree::key( $name );
		$found = array();
		$posts = get_posts(
			array(
				'post_type'        => $object_type,
				'post_status'      => 'publish',
				's'                => $name,
				'numberposts'      => 50,
				'suppress_filters' => false,
				'has_password'     => false,
			)
		);
		foreach ( $posts as $post ) {
			if ( MenuTree::key( (string) $post->post_title ) === $key ) {
				$found[] = $post;
			}
		}
		if ( 1 === count( $found ) ) {
			return array(
				'id'   => (int) $found[0]->ID,
				'name' => html_entity_decode( (string) $found[0]->post_title, ENT_QUOTES, 'UTF-8' ),
			);
		}
		if ( $found ) {
			return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'Several published %1$s items are titled "%2$s" (ids %3$s): use object_id.', $object_type, $name, implode( ', ', wp_list_pluck( $found, 'ID' ) ) ) );
		}
		return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'No published %1$s is titled exactly "%2$s". Search first (search_products for products) and confirm with the manager.', $object_type, $name ) );
	}

	/**
	 * Existing item id from item_id / item_title / ref.
	 *
	 * @param array<int,array<string,mixed>> $tree Tree.
	 * @param array<string,mixed>            $op   Operation.
	 * @param array<string,string>           $refs Refs of this plan.
	 * @return int|string|WP_Error
	 */
	private function item( array $tree, array $op, array $refs ) {
		if ( ! empty( $op['item_id'] ) ) {
			$node = self::find( $tree, (int) $op['item_id'] );
			if ( ! $node ) {
				return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'Menu item %d is not in this menu (call describe_menus).', (int) $op['item_id'] ) );
			}
		} elseif ( ! empty( $op['ref'] ) && isset( $refs[ (string) $op['ref'] ] ) ) {
			$node = self::find( $tree, $refs[ (string) $op['ref'] ] );
		} elseif ( ! empty( $op['item_title'] ) ) {
			$matches = self::by_title( $tree, (string) $op['item_title'] );
			if ( 1 !== count( $matches ) ) {
				return new WP_Error(
					'addsynapse_assistant_menu',
					$matches ? sprintf( 'Several items are titled "%1$s" (ids %2$s): use item_id.', $op['item_title'], implode( ', ', $matches ) ) : sprintf( 'No item titled "%s" in this menu.', $op['item_title'] )
				);
			}
			$node = self::find( $tree, $matches[0] );
		} else {
			return new WP_Error( 'addsynapse_assistant_menu', 'Say which item (item_id or item_title).' );
		}
		if ( ! $node ) {
			return new WP_Error( 'addsynapse_assistant_menu', 'Menu item not found.' );
		}
		if ( 'block' === $node['type'] ) {
			return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'Item %s is a "%s" block, not a link: it can only be changed in the Site Editor.', $node['id'], $node['object'] ) );
		}
		return $node['id'];
	}

	/**
	 * Parent id from parent_ref / parent_id / parent_title (default when none given).
	 *
	 * @param array<int,array<string,mixed>> $tree     Tree.
	 * @param array<string,mixed>            $op       Operation.
	 * @param array<string,string>           $refs     Refs.
	 * @param int|string                     $fallback Default parent.
	 * @return int|string|WP_Error
	 */
	private function parent( array $tree, array $op, array $refs, $fallback ) {
		if ( ! empty( $op['parent_ref'] ) ) {
			if ( ! isset( $refs[ (string) $op['parent_ref'] ] ) ) {
				return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'parent_ref "%s" was not defined by an earlier add_item (ref).', $op['parent_ref'] ) );
			}
			$parent = $refs[ (string) $op['parent_ref'] ];
		} elseif ( isset( $op['parent_id'] ) ) {
			$parent = (int) $op['parent_id'];
			if ( $parent > 0 && ! self::find( $tree, $parent ) ) {
				return new WP_Error( 'addsynapse_assistant_menu', sprintf( 'Parent item %d is not in this menu.', $parent ) );
			}
		} elseif ( ! empty( $op['parent_title'] ) ) {
			$matches = self::by_title( $tree, (string) $op['parent_title'] );
			if ( 1 !== count( $matches ) ) {
				return new WP_Error(
					'addsynapse_assistant_menu',
					$matches ? sprintf( 'Several items are titled "%1$s" (ids %2$s): use parent_id.', $op['parent_title'], implode( ', ', $matches ) ) : sprintf( 'No item titled "%s" in this menu to put it under.', $op['parent_title'] )
				);
			}
			$parent = $matches[0];
		} else {
			return $fallback;
		}//end if
		$node = 0 === $parent ? null : self::find( $tree, $parent );
		if ( $node && 'block' === $node['type'] ) {
			return new WP_Error( 'addsynapse_assistant_menu', 'Items can only be placed under links, not under other blocks.' );
		}
		return $parent;
	}

	/**
	 * Strips tags/control characters from a title.
	 *
	 * @param string $title Title.
	 */
	private static function clean_title( string $title ): string {
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $title ) ) ), 0, 200 );
	}

	// ── Nested tree helpers ────────────────────────────────────────────────────────────────

	/**
	 * Node by id.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @param int|string                     $id    Id.
	 * @return array<string,mixed>|null
	 */
	private static function find( array $nodes, $id ): ?array {
		foreach ( $nodes as $node ) {
			if ( (string) $node['id'] === (string) $id ) {
				return $node;
			}
			$found = self::find( (array) $node['children'], $id );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Ids of the nodes whose title matches.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @param string                         $title Title.
	 * @return array<int,int|string>
	 */
	private static function by_title( array $nodes, string $title ): array {
		$key = MenuTree::key( $title );
		$out = array();
		foreach ( MenuTree::flatten( $nodes ) as $node ) {
			if ( 'block' !== $node['type'] && MenuTree::key( (string) $node['title'] ) === $key ) {
				$out[] = $node['id'];
			}
		}
		return $out;
	}

	/**
	 * Children of a parent (0 = top level).
	 *
	 * @param array<int,array<string,mixed>> $nodes  Nodes.
	 * @param int|string                     $parent_id Parent.
	 * @return array<int,array<string,mixed>>
	 */
	private static function children_of( array $nodes, $parent_id ): array {
		if ( 0 === $parent_id || '0' === (string) $parent_id ) {
			return $nodes;
		}
		$node = self::find( $nodes, $parent_id );
		return $node ? (array) $node['children'] : array();
	}

	/**
	 * Parent id of a node (0 = top level).
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @param int|string                     $id    Id.
	 * @return int|string
	 */
	private static function parent_of( array $nodes, $id ) {
		foreach ( MenuTree::flatten( $nodes ) as $node ) {
			if ( (string) $node['id'] === (string) $id ) {
				return $node['parent'];
			}
		}
		return 0;
	}

	/**
	 * Ids under a node.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes.
	 * @param int|string                     $id    Id.
	 * @return array<int,string>
	 */
	private static function descendants( array $nodes, $id ): array {
		$node = self::find( $nodes, $id );
		if ( ! $node ) {
			return array();
		}
		return array_map(
			static function ( $n ) {
				return (string) $n['id'];
			},
			MenuTree::flatten( (array) $node['children'] )
		);
	}

	/**
	 * Removes a node (with its subtree) and returns it.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes (by ref).
	 * @param int|string                     $id    Id.
	 * @return array<string,mixed>|null
	 */
	private static function remove( array &$nodes, $id ): ?array {
		foreach ( $nodes as $i => $node ) {
			if ( (string) $node['id'] === (string) $id ) {
				array_splice( $nodes, $i, 1 );
				return $node;
			}
			$found = self::remove( $nodes[ $i ]['children'], $id );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Inserts a node under $parent_id at a 1-based position (0 / too big = last).
	 *
	 * @param array<int,array<string,mixed>> $nodes    Nodes (by ref).
	 * @param int|string                     $parent_id   Parent (0 = top level).
	 * @param array<string,mixed>            $node     Node.
	 * @param int                            $position Position.
	 */
	private static function insert( array &$nodes, $parent_id, array $node, int $position ): bool {
		if ( 0 === $parent_id || '0' === (string) $parent_id ) {
			$index = $position > 0 ? min( $position - 1, count( $nodes ) ) : count( $nodes );
			array_splice( $nodes, $index, 0, array( $node ) );
			return true;
		}
		foreach ( $nodes as $i => $candidate ) {
			if ( (string) $candidate['id'] === (string) $parent_id ) {
				return self::insert( $nodes[ $i ]['children'], 0, $node, $position );
			}
			if ( self::insert( $nodes[ $i ]['children'], $parent_id, $node, $position ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Updates fields of a node.
	 *
	 * @param array<int,array<string,mixed>> $nodes  Nodes (by ref).
	 * @param int|string                     $id     Id.
	 * @param array<string,mixed>            $fields Fields.
	 */
	private static function update( array &$nodes, $id, array $fields ): bool {
		foreach ( $nodes as $i => $node ) {
			if ( (string) $node['id'] === (string) $id ) {
				$nodes[ $i ] = array_merge( $node, $fields );
				return true;
			}
			if ( self::update( $nodes[ $i ]['children'], $id, $fields ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Error.
	 *
	 * @param string $message Message.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( 'addsynapse_assistant_menu', $message );
	}
}
