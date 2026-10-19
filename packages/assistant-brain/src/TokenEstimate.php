<?php
/**
 * Rough token estimate without a tokenizer (same rule as the hub's OpsBrain::estimateTokens).
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Characters / 2.6 (JSON-heavy prompts tokenize densely); images ceil(w*h/750).
 */
final class TokenEstimate {

	/**
	 * Estimate for a canonical request (system + tools + messages).
	 *
	 * @param array<string,mixed> $request Canonical request.
	 */
	public static function request( array $request ): int {
		$chars = strlen( Json::encode( $request['system'] ?? '' ) ) + strlen( Json::encode( $request['tools'] ?? array() ) ) + strlen( Json::encode( $request['messages'] ?? array() ) );
		return (int) ceil( $chars / 2.6 );
	}

	/**
	 * Estimate for a string.
	 *
	 * @param string $text Text.
	 */
	public static function text( string $text ): int {
		return (int) ceil( strlen( $text ) / 2.6 );
	}
}
