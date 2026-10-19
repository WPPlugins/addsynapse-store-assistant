<?php
/**
 * LLM client contract.
 *
 * Implementation: Byok\DirectClient (the manager's own AI provider key). The only other implementation is the
 * PHPUnit mock (tests, via Seams).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * A client that runs one model call.
 *
 * Request shape (internal, provider-neutral enough to adapt):
 *  - model      string
 *  - max_tokens int
 *  - system     string|array of {type:text,text}
 *  - messages   array of {role:user|assistant, content: blocks[]}
 *                blocks: {type:text,text} | {type:tool_use,id,name,input} |
 *                        {type:tool_result,tool_use_id,content,is_error?}
 *
 * The response carries assistant content blocks of the same shape and a stop_reason
 * (end_turn | tool_use | max_tokens | refusal).
 */
interface LlmClient {

	/**
	 * Runs one model call.
	 *
	 * @param array<string,mixed> $request      Request (see interface doc).
	 * @param callable|null       $on_event     When given, the client may stream and call it with
	 *                                          events: {type:text_delta,text} | {type:thinking} |
	 *                                          {type:tool_use_start,id,name}.
	 * @param callable|null       $should_abort Polled while streaming; return true to cancel.
	 *                                          On cancel return a response with aborted=true.
	 * @return LlmResponse
	 * @throws LlmException On transport / provider errors.
	 */
	public function create_message( array $request, ?callable $on_event = null, ?callable $should_abort = null ): LlmResponse;

	/**
	 * Whether the AI connection is configured (false = not connected: nothing is sent).
	 */
	public function is_configured(): bool;
}
