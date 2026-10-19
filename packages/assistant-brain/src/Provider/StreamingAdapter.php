<?php
/**
 * Streaming side of a provider adapter.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * build() with `stream: true`, then events → state; the final canonical response comes from the state.
 */
interface StreamingAdapter {

	/**
	 * Applies one SSE event to the state.
	 *
	 * @param array<string,mixed> $state State (starts as array()).
	 * @param string              $event Event name.
	 * @param mixed               $data  Decoded data.
	 * @return array{state:array<string,mixed>,text:string,tool:?array{id:string,name:string}}
	 * @throws ProviderError Error events.
	 */
	public function stream_event( array $state, string $event, $data ): array;

	/**
	 * Final canonical response from the state.
	 *
	 * @param array<string,mixed> $state State.
	 * @param string              $key   Key (redaction).
	 * @return array{content:array<int,array<string,mixed>>,stop_reason:string,usage:array<string,int>,model:string}
	 * @throws ProviderError Incomplete stream.
	 */
	public function stream_result( array $state, string $key ): array;
}
