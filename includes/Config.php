<?php
/**
 * Configuration read from wp-config.php constants.
 *
 * The manager's own AI key is handled by Byok\KeyStore.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Static accessors for every tunable, with conservative defaults.
 */
final class Config {

	/**
	 * Returns a constant's value or a default.
	 *
	 * @param string $name          Constant name.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	private static function constant( string $name, $default_value ) {
		return defined( $name ) ? constant( $name ) : $default_value;
	}

	/**
	 * Max model round-trips per user message (tool loop guard).
	 */
	public static function max_iterations(): int {
		return max( 1, min( 20, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_MAX_ITERATIONS', 8 ) ) );
	}

	/**
	 * Whether the chat endpoint streams (SSE). Can be disabled if a proxy buffers.
	 */
	public static function streaming_enabled(): bool {
		return (bool) self::constant( 'ADDSYNAPSE_ASSISTANT_STREAMING', true );
	}

	/**
	 * Hours a draft changeset stays approvable (ADDSYNAPSE_ASSISTANT_CHANGESET_TTL_HOURS, default 24).
	 */
	public static function changeset_ttl_hours(): int {
		return max( 1, min( 24 * 30, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_CHANGESET_TTL_HOURS', 24 ) ) );
	}

	/**
	 * Max items (field changes) per changeset (ADDSYNAPSE_ASSISTANT_CHANGESET_MAX_ITEMS, default 500).
	 */
	public static function changeset_max_items(): int {
		return max( 1, min( 5000, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_CHANGESET_MAX_ITEMS', 500 ) ) );
	}

	/**
	 * Changesets with more items than this are applied/reverted in the background with
	 * Action Scheduler (ADDSYNAPSE_ASSISTANT_CHANGESET_SYNC_MAX, default 50); smaller ones synchronously.
	 */
	public static function changeset_sync_max(): int {
		return max( 0, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_CHANGESET_SYNC_MAX', 50 ) );
	}

	/**
	 * Products handled per background batch (ADDSYNAPSE_ASSISTANT_CHANGESET_BATCH, default 25).
	 */
	public static function changeset_batch_size(): int {
		return max( 1, min( 200, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_CHANGESET_BATCH', 25 ) ) );
	}

	/**
	 * Largest image upload in bytes (ADDSYNAPSE_ASSISTANT_IMAGE_MAX_BYTES, default 8 MB, hard cap 20 MB).
	 */
	public static function image_max_bytes(): int {
		return max( 1024, min( 20 * MB_IN_BYTES, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_IMAGE_MAX_BYTES', 8 * MB_IN_BYTES ) ) );
	}

	/**
	 * Longest side (px) an uploaded image may have (ADDSYNAPSE_ASSISTANT_IMAGE_MAX_SIDE, default 10000).
	 */
	public static function image_max_side(): int {
		return max( 100, min( 20000, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_IMAGE_MAX_SIDE', 10000 ) ) );
	}

	/**
	 * Most pixels (width × height) of an uploaded image (ADDSYNAPSE_ASSISTANT_IMAGE_MAX_PIXELS, default 30 MP):
	 * checked from the header BEFORE decoding (decompression bombs).
	 */
	public static function image_max_pixels(): int {
		return max( 10000, min( 100000000, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_IMAGE_MAX_PIXELS', 30000000 ) ) );
	}

	/**
	 * Hours an uploaded image may stay unused before the cleanup job deletes it
	 * (ADDSYNAPSE_ASSISTANT_IMAGE_PENDING_HOURS, default 24).
	 */
	public static function image_pending_hours(): int {
		return max( 1, min( 24 * 7, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_IMAGE_PENDING_HOURS', 24 ) ) );
	}

	/**
	 * Minutes an uploaded image is kept after its plan was rejected / undone, before the cleanup
	 * job deletes it (ADDSYNAPSE_ASSISTANT_IMAGE_RELEASE_MINUTES, default 60 — time for a «Επανάληψη»).
	 */
	public static function image_release_minutes(): int {
		return max( 0, min( 24 * 60, (int) self::constant( 'ADDSYNAPSE_ASSISTANT_IMAGE_RELEASE_MINUTES', 60 ) ) );
	}

	/**
	 * Capability required for everything in this plugin (see Capabilities: manage_woocommerce
	 * with WooCommerce, edit_theme_options on a plain WordPress site).
	 */
	public static function capability(): string {
		return Capabilities::resolve( Capabilities::BASE );
	}

	/**
	 * Capability of the Settings tab (AI key, images, voice): manage_woocommerce with
	 * WooCommerce, manage_options on a plain WordPress site.
	 */
	public static function settings_capability(): string {
		return Capabilities::resolve( Capabilities::SETTINGS );
	}
}
