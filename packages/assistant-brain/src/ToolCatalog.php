<?php
/**
 * Tool names and descriptions shared with the hub.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical tool names (`plan_price_update`) ↔ ability names (`addsynapse-assistant/plan-price-update`), and the hub's
 * tuned descriptions (resources/tools.json, copied from the hub by bin/sync-from-hub.php).
 */
final class ToolCatalog {

	/**
	 * Canonical name of an ability (`addsynapse-assistant/plan-price-update` → `plan_price_update`).
	 *
	 * @param string $ability Ability name (`prefix/name`).
	 */
	public static function canonical( string $ability ): string {
		$pos  = strpos( $ability, '/' );
		$name = false === $pos ? $ability : substr( $ability, $pos + 1 );
		return str_replace( '-', '_', $name );
	}

	/**
	 * Hub description of a canonical tool, or '' when the hub does not know it (new local tools).
	 *
	 * @param string $name Canonical name.
	 */
	public static function description( string $name ): string {
		$tools = Json::resource( 'tools.json' );
		return isset( $tools[ $name ]['description'] ) ? (string) $tools[ $name ]['description'] : '';
	}

	/**
	 * Canonical names known to the hub catalog.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		return array_keys( Json::resource( 'tools.json' ) );
	}
}
