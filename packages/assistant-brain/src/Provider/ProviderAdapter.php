<?php
/**
 * One AI provider: canonical (Anthropic-shaped) request/response ↔ the provider's HTTP API.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical request: model, max_tokens, system (string), messages (role user|assistant, content blocks
 * text / tool_use / tool_result / thinking), tools [{name, description, input_schema}], tool_choice auto|none,
 * cache (bool). Canonical response: content blocks, stop_reason end_turn|tool_use|max_tokens|refusal, usage
 * (input_tokens, output_tokens, cache_read_input_tokens, cache_creation_input_tokens), model.
 * Transport (HTTP) lives in the host platform; adapters are pure.
 */
interface ProviderAdapter {

	/**
	 * HTTP request for a canonical request.
	 *
	 * @param array<string,mixed> $request Canonical request.
	 * @param string              $key     API key.
	 * @return array{url:string,headers:array<string,string>,body:string}
	 */
	public function build( array $request, string $key ): array;

	/**
	 * Canonical response from an HTTP answer (throws ProviderError for errors).
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 * @param string $key    Key (for redaction).
	 * @return array{content:array<int,array<string,mixed>>,stop_reason:string,usage:array<string,int>,model:string}
	 * @throws ProviderError On provider errors.
	 */
	public function parse( int $status, string $body, string $key ): array;

	/**
	 * Cheapest possible request that proves the key works (Save & test).
	 *
	 * @param string $model Model.
	 * @return array<string,mixed> Canonical request.
	 */
	public function test_request( string $model ): array;
}
