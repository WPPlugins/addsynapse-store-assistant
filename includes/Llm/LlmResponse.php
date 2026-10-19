<?php
/**
 * Normalised model response.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for one assistant message.
 */
final class LlmResponse {

	/**
	 * Content blocks (text / thinking / redacted_thinking / tool_use), decoded as arrays.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $content;

	/**
	 * Stop reason (end_turn, tool_use, max_tokens, refusal, ...). Empty when aborted.
	 *
	 * @var string
	 */
	public string $stop_reason;

	/**
	 * Usage object from the API.
	 *
	 * @var array<string,int>
	 */
	public array $usage;

	/**
	 * Model that produced the message.
	 *
	 * @var string
	 */
	public string $model;

	/**
	 * Whether the stream was cancelled by the user before completion.
	 *
	 * @var bool
	 */
	public bool $aborted;

	/**
	 * Conversation title suggested by the AI (first exchange only), '' otherwise.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $content     Blocks.
	 * @param string                         $stop_reason Stop reason.
	 * @param array<string,int>              $usage       Usage.
	 * @param string                         $model       Model.
	 * @param bool                           $aborted     Aborted flag.
	 */
	public function __construct( array $content, string $stop_reason, array $usage, string $model, bool $aborted = false ) {
		$this->content     = $content;
		$this->stop_reason = $stop_reason;
		$this->usage       = $usage;
		$this->model       = $model;
		$this->aborted     = $aborted;
	}

	/**
	 * Builds from a decoded API message object.
	 *
	 * @param array<string,mixed> $message Decoded message.
	 * @param bool                $aborted Aborted flag.
	 */
	public static function from_api( array $message, bool $aborted = false ): self {
		return new self(
			array_values( (array) ( $message['content'] ?? array() ) ),
			(string) ( $message['stop_reason'] ?? '' ),
			array_map( 'intval', array_filter( (array) ( $message['usage'] ?? array() ), 'is_numeric' ) ),
			(string) ( $message['model'] ?? '' ),
			$aborted
		);
	}

	/**
	 * Concatenated text blocks.
	 */
	public function text(): string {
		$out = '';
		foreach ( $this->content as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$out .= (string) ( $block['text'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * The tool_use blocks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function tool_uses(): array {
		return array_values(
			array_filter(
				$this->content,
				static function ( $block ) {
					return 'tool_use' === ( $block['type'] ?? '' );
				}
			)
		);
	}
}
