<?php
/**
 * Picks the menu adapter for the active theme.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Block theme → wp_navigation (BlockNavigationAdapter); classic theme → nav menus
 * (ClassicMenuAdapter). Filter `addsynapse_assistant_menu_adapter` may swap it (must implement the interface).
 */
final class MenuAdapters {

	/**
	 * Adapter for the active theme.
	 */
	public static function current(): MenuAdapterInterface {
		$adapter = self::is_block_theme() ? new BlockNavigationAdapter() : new ClassicMenuAdapter();
		$custom  = apply_filters( 'addsynapse_assistant_menu_adapter', $adapter );
		return $custom instanceof MenuAdapterInterface ? $custom : $adapter;
	}

	/**
	 * Adapter by kind (the one a changeset was planned with).
	 *
	 * @param string $kind classic|block.
	 */
	public static function for_kind( string $kind ): ?MenuAdapterInterface {
		if ( ClassicMenuAdapter::KIND === $kind ) {
			return new ClassicMenuAdapter();
		}
		if ( BlockNavigationAdapter::KIND === $kind ) {
			return new BlockNavigationAdapter();
		}
		return null;
	}

	/**
	 * Whether the active theme is a block theme.
	 */
	public static function is_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}
}
