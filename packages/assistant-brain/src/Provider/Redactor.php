<?php
/**
 * Removes API keys from any text that may be shown or logged.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Providers echo keys back in errors ("Incorrect API key provided: sk-…abcd").
 */
final class Redactor {

	/**
	 * Redacts the given key and anything shaped like a provider key.
	 *
	 * @param string $text Text.
	 * @param string $key  The key in use ('' = none).
	 */
	public static function redact( string $text, string $key = '' ): string {
		if ( '' !== $key ) {
			$text = str_replace( $key, '[redacted]', $text );
		}
		$text = (string) preg_replace( '/\b(sk-ant-[A-Za-z0-9_\-]{4,}|sk-(?:proj-|svcacct-)?[A-Za-z0-9_\-*.…]{6,}|AIza[0-9A-Za-z_\-]{10,})/u', '[redacted]', $text );
		return 1 === preg_match( '/^.{0,300}/us', $text, $m ) ? $m[0] : substr( $text, 0, 300 );
	}
}
