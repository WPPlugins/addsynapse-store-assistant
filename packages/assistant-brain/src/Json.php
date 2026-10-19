<?php
/**
 * JSON helpers without WordPress.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Encode/decode with fixed flags (deterministic bytes = stable prompt-cache prefix).
 */
final class Json {

	/**
	 * Encodes; an empty PHP array that must be a JSON object is passed as an stdClass by the caller.
	 *
	 * @param mixed $value Value.
	 */
	public static function encode( $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- framework-free package.
		return false === $json ? '{}' : $json;
	}

	/**
	 * Decodes to arrays (null on invalid JSON).
	 *
	 * @param string $json JSON.
	 * @return mixed
	 */
	public static function decode( string $json ) {
		$value = json_decode( $json, true );
		return JSON_ERROR_NONE === json_last_error() ? $value : null;
	}

	/**
	 * Reads a bundled resource file.
	 *
	 * @param string $name File in resources/.
	 * @return array<mixed>
	 */
	public static function resource( string $name ): array {
		static $cache = array();
		if ( ! isset( $cache[ $name ] ) ) {
			$raw            = (string) file_get_contents( dirname( __DIR__ ) . '/resources/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled local file.
			$data           = self::decode( $raw );
			$cache[ $name ] = is_array( $data ) ? $data : array();
		}
		return $cache[ $name ];
	}
}
