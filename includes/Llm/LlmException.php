<?php
/**
 * LLM error.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Error with a stable machine code the UI can map to a friendly message.
 *
 * Codes: not_configured, nothing_to_answer, rate_limited, overloaded, server, network, invalid_response,
 * conversation_limit; own AI key: byok_invalid_key, byok_quota, bad_request.
 */
class LlmException extends \RuntimeException {

	/**
	 * Machine code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * HTTP status of the provider answer (0 = none).
	 *
	 * @var int
	 */
	private int $http_status;

	/**
	 * Constructor.
	 *
	 * @param string $code        Machine code.
	 * @param string $message     Plain text (escaped where printed).
	 * @param int    $http_status HTTP status (0 = none).
	 */
	public function __construct( string $code, string $message, int $http_status = 0 ) {
		parent::__construct( $message );
		$this->error_code  = $code;
		$this->http_status = $http_status;
	}

	/**
	 * An error of the manager's own AI provider (message already redacted by the brain's adapters; escaped where printed).
	 *
	 * @param string $code    Machine code.
	 * @param string $message Plain text.
	 */
	public static function from_provider( string $code, string $message ): self {
		return new self( $code, $message );
	}

	/**
	 * Machine code.
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * HTTP status.
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/**
	 * Whether retrying later may help.
	 */
	public function is_retryable(): bool {
		return in_array( $this->error_code, array( 'rate_limited', 'overloaded', 'server', 'network' ), true );
	}
}
