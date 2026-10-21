<?php
/**
 * Menu storage adapter (classic nav menus / block navigation).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Every adapter speaks the same normalized SNAPSHOT of one menu.
 *
 * Keys:
 *   adapter   'classic' | 'block'
 *   exists    bool (false = the menu does not exist: "before" of create_menu, or undo target)
 *   menu      {id, name, slug}
 *   items     flat depth-first list of {id, label, title, type, object, object_id, url, parent,
 *             order, link, extra} — id int (store) or "new:N" (planned); parent 0 = top level
 *   locations {slug: "self" | menu id | 0}  (classic only; "self" = this menu)
 *   raw       block adapter only: the exact stored post_content (exact undo)
 *
 * Adapters never decide WHAT changes: the planner builds the target snapshot, the changeset
 * engine checks conflicts with same() and calls write() only after the manager's approval.
 */
interface MenuAdapterInterface {

	/**
	 * 'classic' | 'block'.
	 */
	public function kind(): string;

	/**
	 * Whether theme locations can be assigned.
	 */
	public function supports_locations(): bool;

	/**
	 * Registered locations: [{slug, label, menu_id, menu_name}].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function locations(): array;

	/**
	 * Menus: [{id, name, slug, count}].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function menus(): array;

	/**
	 * Menu id by id / slug / name (accent- and case-insensitive), or null.
	 *
	 * @param string $ref Reference.
	 */
	public function find( string $ref ): ?int;

	/**
	 * Current snapshot of a menu (exists=false when it is gone).
	 *
	 * @param int $menu_id Menu id.
	 * @return array<string,mixed>
	 */
	public function read( int $menu_id ): array;

	/**
	 * Snapshot of a menu that does not exist yet.
	 *
	 * @param string $name Name.
	 * @return array<string,mixed>
	 */
	public function blank( string $name ): array;

	/**
	 * Whether two snapshots describe the same stored state (conflict detection).
	 *
	 * @param array<string,mixed> $a             Snapshot.
	 * @param array<string,mixed> $b             Snapshot.
	 * @param array<int,string>   $location_keys Theme locations to compare (the ones the change touches).
	 */
	public function same( array $a, array $b, array $location_keys = array() ): bool;

	/**
	 * Makes the store match $target (the current state is $current). Returns the fresh
	 * snapshot as stored. Throws \RuntimeException on failure.
	 *
	 * @param array<string,mixed> $current Current snapshot.
	 * @param array<string,mixed> $target  Target snapshot.
	 * @return array<string,mixed>
	 */
	public function write( array $current, array $target ): array;

	/**
	 * Default fields of a NEW item pointing at an object (type/object/object_id/url/extra).
	 *
	 * @param string $object_type product_cat|product|page|post|custom.
	 * @param int    $object_id   Object id (0 for custom).
	 * @param string $url         Custom URL.
	 * @return array<string,mixed>
	 */
	public function new_item( string $object_type, int $object_id, string $url ): array;
}
