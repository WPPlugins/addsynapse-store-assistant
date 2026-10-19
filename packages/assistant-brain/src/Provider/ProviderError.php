<?php
/**
 * A provider error with a stable code and a key-free message.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: invalid_key | quota | rate_limited | overloaded | context_too_long | bad_request | server | network | bad_response.
 */
final class ProviderError extends \RuntimeException {

	public const RETRYABLE = array( 'rate_limited', 'overloaded', 'server', 'network' );

	/**
	 * Stable code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $code    Stable code.
	 * @param string $message Message (already redacted).
	 */
	public function __construct( string $code, string $message ) {
		parent::__construct( $message );
		$this->error_code = $code;
	}

	/**
	 * Stable code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Whether retrying later may help.
	 */
	public function retryable(): bool {
		return in_array( $this->error_code, self::RETRYABLE, true );
	}
}
