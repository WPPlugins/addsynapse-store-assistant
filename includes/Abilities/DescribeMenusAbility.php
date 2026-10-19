<?php
/**
 * Ability addsynapse-assistant/describe-menus — menus, theme locations and item trees (read-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapterInterface;
use Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapters;
use Addsynapse\StoreAssistant\Menus\MenuTree;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery for menu plans: where this theme keeps menus (classic nav menus or block
 * navigation), the registered locations and what each shows, and every menu as a tree.
 */
final class DescribeMenusAbility {

	public const NAME = 'addsynapse-assistant/describe-menus';

	public const MAX_ITEMS = 300;

	/**
	 * Adapter override (tests).
	 *
	 * @var MenuAdapterInterface|null
	 */
	private ?MenuAdapterInterface $adapter;

	/**
	 * Constructor.
	 *
	 * @param MenuAdapterInterface|null $adapter Adapter.
	 */
	public function __construct( ?MenuAdapterInterface $adapter = null ) {
		$this->adapter = $adapter;
	}

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Menus', 'addsynapse-store-assistant' ),
				'description'         => 'Returns the navigation menus of the active theme (read-only): theme type and menu storage, registered theme locations with the menu each shows, and each menu as a tree of items (id, title, type, object, object_id, url, parent, position, depth). Pass menu (id, slug or name) for one menu only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type'     => 'object',
					'required' => array( 'theme', 'locations', 'menus' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Input schema (also the tool definition the AI provider receives).
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'menu' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
			),
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$adapter = $this->adapter ?? MenuAdapters::current();
		$theme   = wp_get_theme();
		$ids     = array_map(
			static function ( $menu ) {
				return (int) $menu['id'];
			},
			$adapter->menus()
		);
		if ( ! empty( $input['menu'] ) ) {
			$found = $adapter->find( (string) $input['menu'] );
			if ( null === $found ) {
				return new \WP_Error( 'addsynapse_assistant_menu', sprintf( 'Menu "%s" not found or ambiguous.', (string) $input['menu'] ) );
			}
			$ids = array( $found );
		}
		$locations = $adapter->locations();
		$menus     = array();
		foreach ( $ids as $id ) {
			$snapshot = $adapter->read( $id );
			if ( empty( $snapshot['exists'] ) ) {
				continue;
			}
			$menus[] = array(
				'id'        => (int) $snapshot['menu']['id'],
				'name'      => (string) $snapshot['menu']['name'],
				'slug'      => (string) $snapshot['menu']['slug'],
				'count'     => count( $snapshot['items'] ),
				'locations' => array_values(
					array_keys(
						array_filter(
							(array) $snapshot['locations'],
							static function ( $value ) {
								return 'self' === $value;
							}
						)
					)
				),
				'items'     => self::items( (array) $snapshot['items'] ),
				'truncated' => count( $snapshot['items'] ) > self::MAX_ITEMS,
			);
		}//end foreach
		$notes = array();
		if ( 'block' === $adapter->kind() ) {
			$notes[] = 'Block theme: menus are wp_navigation posts shown by Navigation blocks in the Site Editor; there are no theme locations. Item ids are positions in the current tree and change when the menu changes.';
		} elseif ( ! $locations ) {
			$notes[] = 'The theme registers no menu locations.';
		}
		if ( ! $menus ) {
			$notes[] = 'There are no menus yet.';
		}
		return array(
			'theme'     => array(
				'name'        => html_entity_decode( (string) $theme->get( 'Name' ), ENT_QUOTES, 'UTF-8' ),
				'block_theme' => MenuAdapters::is_block_theme(),
				'adapter'     => $adapter->kind(),
			),
			'locations' => $locations,
			'menus'     => $menus,
			'notes'     => $notes,
			'_data'     => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Items for the model/UI: tree order with parent, 1-based position among siblings, depth.
	 *
	 * @param array<int,array<string,mixed>> $items Snapshot items.
	 * @return array<int,array<string,mixed>>
	 */
	private static function items( array $items ): array {
		$out      = array();
		$position = array();
		foreach ( MenuTree::with_depth( $items ) as $item ) {
			$parent              = (string) $item['parent'];
			$position[ $parent ] = ( $position[ $parent ] ?? 0 ) + 1;
			if ( count( $out ) >= self::MAX_ITEMS ) {
				break;
			}
			$out[] = array(
				'id'        => $item['id'],
				'title'     => (string) $item['title'],
				'type'      => (string) $item['type'],
				'object'    => (string) $item['object'],
				'object_id' => (int) $item['object_id'],
				'url'       => (string) ( $item['link'] ?? $item['url'] ),
				'parent'    => $item['parent'],
				'position'  => $position[ $parent ],
				'depth'     => (int) $item['depth'],
			);
		}
		return $out;
	}
}
