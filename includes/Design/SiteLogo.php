<?php
/**
 * The site logo of any theme (1.3.7): block themes show the `site_logo` option (core/site-logo block), classic themes the
 * `custom_logo` theme mod — and WordPress keeps the two in sync with hooks that would undo half of an exact Undo. One unit
 * holds both, written together with those hooks held off.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Setting unit key `site_logo`: value {site_logo: id|MISSING, custom_logo: id|MISSING} (MISSING = not stored).
 */
final class SiteLogo {

	/** Setting key of the unit (also accepted as a `set` setting id, like custom_logo). */
	public const KEY = 'site_logo';

	/** Setting ids of plan_theme_settings `set` that mean the logo. */
	public const IDS = array( 'site_logo', 'custom_logo' );

	/**
	 * Whether this theme shows a logo at all.
	 */
	public static function supported(): bool {
		return wp_is_block_theme() || current_theme_supports( 'custom-logo' );
	}

	/**
	 * What is stored now.
	 *
	 * @return array{site_logo:mixed,custom_logo:mixed}
	 */
	public static function read(): array {
		$mods = get_option( 'theme_mods_' . get_stylesheet() );
		return array(
			'site_logo'   => get_option( 'site_logo', Customizer::MISSING ),
			'custom_logo' => is_array( $mods ) && array_key_exists( 'custom_logo', $mods ) ? $mods['custom_logo'] : Customizer::MISSING,
		);
	}

	/**
	 * The stored values after setting the logo to an image (0 = no logo).
	 *
	 * @param int $image_id Attachment id or 0.
	 * @return array{site_logo:mixed,custom_logo:mixed}
	 */
	public static function target( int $image_id ): array {
		$now = self::read();
		$out = array( 'site_logo' => $image_id > 0 ? $image_id : Customizer::MISSING ) + $now;
		if ( current_theme_supports( 'custom-logo' ) ) {
			$out['custom_logo'] = $image_id > 0 ? $image_id : Customizer::MISSING;
		}
		return $out;
	}

	/**
	 * The image id a value shows (0 = none).
	 *
	 * @param mixed $value Unit value.
	 */
	public static function image( $value ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}
		foreach ( array( 'site_logo', 'custom_logo' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) && (int) $value[ $key ] > 0 ) {
				return (int) $value[ $key ];
			}
		}
		return 0;
	}

	/**
	 * Why a value may not be written ('' = fine).
	 *
	 * @param mixed $value Decoded unit value.
	 */
	public static function problem( $value ): string {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return 'You are not allowed to change the logo.';
		}
		if ( ! is_array( $value ) || array( 'custom_logo', 'site_logo' ) !== self::sorted_keys( $value ) ) {
			return 'Invalid logo value.';
		}
		foreach ( $value as $part ) {
			if ( Customizer::MISSING === $part ) {
				continue;
			}
			if ( ! is_numeric( $part ) || (int) $part < 1 || ! wp_attachment_is_image( (int) $part ) ) {
				return 'The logo must be an image of the media library.';
			}
		}
		return '';
	}

	/**
	 * Writes both parts (no hooks of WordPress' logo sync in between).
	 *
	 * @param array<string,mixed> $value Value (checked by problem()).
	 * @throws \RuntimeException When WordPress did not store it as asked.
	 */
	public static function write( array $value ): void {
		global $_ignore_site_logo_changes; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's own global.
		$previous                  = $_ignore_site_logo_changes;
		$_ignore_site_logo_changes = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's own guard of its logo sync hooks, restored below.
		try {
			if ( Customizer::MISSING === $value['site_logo'] ) {
				delete_option( 'site_logo' );
			} else {
				update_option( 'site_logo', (int) $value['site_logo'] );
			}
			// Written after the option: deleting site_logo makes core remove the custom_logo theme mod.
			$name = 'theme_mods_' . get_stylesheet();
			$mods = get_option( $name );
			$mods = is_array( $mods ) ? $mods : array();
			if ( Customizer::MISSING === $value['custom_logo'] ) {
				unset( $mods['custom_logo'] );
			} else {
				$mods['custom_logo'] = (int) $value['custom_logo'];
			}
			update_option( $name, $mods );
		} finally {
			$_ignore_site_logo_changes = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restore.
		}
		$now = self::read();
		ksort( $now );
		ksort( $value );
		if ( ! Customizer::same( $now, $value ) ) {
			throw new \RuntimeException( 'WordPress did not store the logo as planned.' );
		}
	}

	/**
	 * Sorted keys of a value.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return array<int,string>
	 */
	private static function sorted_keys( array $value ): array {
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys );
		return $keys;
	}
}
