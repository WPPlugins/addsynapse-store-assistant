<?php
/**
 * Test seams (no public filters).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the AI client / provider transport in the PHPUnit suite ONLY: the slots are read only while the constant
 * ADDSYNAPSE_ASSISTANT_TESTING is true, which only tests/bootstrap.php defines. Production code never sets a slot and
 * there is no filter a third party could use to swap the AI client.
 *
 * Slots: `llm_client` (Llm\LlmClient) · `no_intl` (any array: strip accents as on a host without the intl extension) ·
 * `provider_transport` (Byok\ProviderTransport: the own-key AI provider calls) · `no_router` (any array: the own-key
 * client skips its router call) · `any_upload` (any array: ImagesController accepts a non-uploaded temp file). A slot
 * holds the value, or a callable returning it.
 */
final class Seams {

	/**
	 * Overrides by slot.
	 *
	 * @var array<string,mixed>
	 */
	private static array $slots = array();

	/**
	 * Whether the seams are active (PHPUnit only).
	 */
	public static function enabled(): bool {
		return defined( 'ADDSYNAPSE_ASSISTANT_TESTING' ) && true === ADDSYNAPSE_ASSISTANT_TESTING;
	}

	/**
	 * Sets a slot (ignored outside the test suite).
	 *
	 * @param string $slot  Slot.
	 * @param mixed  $value Object, or a callable returning it; null clears.
	 */
	public static function set( string $slot, $value ): void {
		if ( ! self::enabled() ) {
			return;
		}
		if ( null === $value ) {
			unset( self::$slots[ $slot ] );
			return;
		}
		self::$slots[ $slot ] = $value;
	}

	/**
	 * The override of a slot that is an instance of $type (or an array for 'array'), else null (always null outside
	 * the test suite).
	 *
	 * @param string $slot Slot.
	 * @param string $type Expected class/interface, or 'array'.
	 * @return mixed
	 */
	public static function get( string $slot, string $type ) {
		if ( ! self::enabled() || ! isset( self::$slots[ $slot ] ) ) {
			return null;
		}
		$value = self::$slots[ $slot ];
		if ( $value instanceof \Closure || ( ! is_object( $value ) && is_callable( $value ) ) ) {
			$value = call_user_func( $value );
		}
		if ( 'array' === $type ) {
			return is_array( $value ) ? $value : null;
		}
		return $value instanceof $type ? $value : null;
	}

	/**
	 * Clears every slot.
	 */
	public static function reset(): void {
		self::$slots = array();
	}
}
