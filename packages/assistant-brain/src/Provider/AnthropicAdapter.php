<?php
/**
 * Anthropic Messages API.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

use Addsynapse\StoreAssistant\Brain\Json;
use Addsynapse\StoreAssistant\Brain\TokenEstimate;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical = Anthropic shape, so this adapter mostly cleans and adds prompt-cache breakpoints:
 * last tool, system, and the newest message (≤ 4 allowed). Small prompts get none (they would not cache).
 */
final class AnthropicAdapter implements ProviderAdapter, StreamingAdapter {

	public const URL     = 'https://api.anthropic.com/v1/messages';
	public const VERSION = '2023-06-01';

	/**
	 * Below this estimate no breakpoints are set (minimum cacheable prefix is model-dependent, up to 4096).
	 */
	public const MIN_CACHE_TOKENS = 4096;

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $request Canonical request.
	 * @param string              $key     API key.
	 */
	public function build( array $request, string $key ): array {
		$body = array(
			'model'      => (string) $request['model'],
			'max_tokens' => (int) ( $request['max_tokens'] ?? 8000 ),
		);
		if ( isset( $request['effort'] ) ) {
			$body['output_config'] = array( 'effort' => (string) $request['effort'] );
		}
		$cache = ! empty( $request['cache'] ) && TokenEstimate::request( $request ) >= self::MIN_CACHE_TOKENS;
		$mark  = array( 'type' => 'ephemeral' );

		$tools = array();
		foreach ( (array) ( $request['tools'] ?? array() ) as $tool ) {
			$tools[] = array(
				'name'         => (string) $tool['name'],
				'description'  => (string) ( $tool['description'] ?? '' ),
				'input_schema' => Blocks::object( $tool['input_schema'] ?? array() ),
			);
		}
		if ( $tools ) {
			if ( $cache ) {
				$tools[ count( $tools ) - 1 ]['cache_control'] = $mark;
			}
			$body['tools'] = $tools;
			if ( 'none' === ( $request['tool_choice'] ?? 'auto' ) ) {
				$body['tool_choice'] = array( 'type' => 'none' );
			}
		}
		$system = (string) ( $request['system'] ?? '' );
		if ( '' !== $system ) {
			$block = array(
				'type' => 'text',
				'text' => $system,
			);
			if ( $cache ) {
				$block['cache_control'] = $mark;
			}
			$body['system'] = array( $block );
		}
		$messages = array();
		foreach ( (array) ( $request['messages'] ?? array() ) as $message ) {
			// OpenAI reasoning items never go to Anthropic (a conversation may switch provider).
			$content = array_values(
				array_filter(
					Blocks::clean( $message['content'] ?? array() ),
					static function ( $block ) {
						return 'openai_reasoning' !== $block['type'];
					}
				)
			);
			if ( $content ) {
				$messages[] = array(
					'role'    => 'assistant' === ( $message['role'] ?? '' ) ? 'assistant' : 'user',
					'content' => $content,
				);
			}
		}
		if ( $cache && $messages ) {
			$last = count( $messages ) - 1;
			for ( $i = count( $messages[ $last ]['content'] ) - 1; $i >= 0; $i-- ) {
				if ( in_array( $messages[ $last ]['content'][ $i ]['type'], array( 'text', 'tool_result', 'tool_use' ), true ) ) {
					$messages[ $last ]['content'][ $i ]['cache_control'] = $mark;
					break;
				}
			}
		}
		$body['messages'] = $messages;
		if ( ! empty( $request['stream'] ) ) {
			$body['stream'] = true;
		}
		return array(
			'url'     => self::URL,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			),
			'body'    => Json::encode( $body ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 * @param string $key    Key.
	 * @throws ProviderError On errors.
	 */
	public function parse( int $status, string $body, string $key ): array {
		$data = Json::decode( $body );
		if ( $status < 200 || $status >= 300 ) {
			$error = self::error( $status, is_array( $data ) ? $data : array(), $key );
			throw $error;
		}
		if ( ! is_array( $data ) || ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			throw new ProviderError( 'bad_response', 'The AI provider sent an answer that could not be read.' );
		}
		$usage = array();
		foreach ( array( 'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens' ) as $field ) {
			$usage[ $field ] = (int) ( $data['usage'][ $field ] ?? 0 );
		}
		$stop = (string) ( $data['stop_reason'] ?? 'end_turn' );
		if ( 'pause_turn' === $stop || 'stop_sequence' === $stop || 'model_context_window_exceeded' === $stop ) {
			$stop = 'model_context_window_exceeded' === $stop ? 'max_tokens' : 'end_turn';
		}
		return array(
			'content'     => array_values( $data['content'] ),
			'stop_reason' => $stop,
			'usage'       => $usage,
			'model'       => (string) ( $data['model'] ?? '' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $model Model.
	 */
	public function test_request( string $model ): array {
		return array(
			'model'      => $model,
			'max_tokens' => 16,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'Reply with OK.',
				),
			),
		);
	}

	/**
	 * Error from status + body.
	 *
	 * @param int                 $status Status.
	 * @param array<string,mixed> $data   Decoded body.
	 * @param string              $key    Key.
	 */
	public static function error( int $status, array $data, string $key ): ProviderError {
		$type    = (string) ( $data['error']['type'] ?? '' );
		$message = Redactor::redact( (string) ( $data['error']['message'] ?? '' ), $key );
		$lower   = strtolower( $message );
		if ( 401 === $status || 403 === $status || 'authentication_error' === $type || 'permission_error' === $type ) {
			return new ProviderError( 'invalid_key', 'The AI provider rejected the API key.' );
		}
		if ( false !== strpos( $lower, 'credit balance' ) || false !== strpos( $lower, 'billing' ) ) {
			return new ProviderError( 'quota', 'Your AI provider account has no credit left.' );
		}
		if ( 429 === $status || 'rate_limit_error' === $type ) {
			return new ProviderError( 'rate_limited', 'Your AI provider account is at its rate limit; try again in a minute.' );
		}
		if ( 529 === $status || 'overloaded_error' === $type ) {
			return new ProviderError( 'overloaded', 'The AI provider is overloaded; try again in a minute.' );
		}
		if ( 413 === $status || false !== strpos( $lower, 'prompt is too long' ) || false !== strpos( $lower, 'too many tokens' ) ) {
			return new ProviderError( 'context_too_long', 'The conversation is too long for the model; start a new one.' );
		}
		if ( $status >= 500 ) {
			return new ProviderError( 'server', 'The AI provider had a temporary error.' );
		}
		if ( 404 === $status || 'not_found_error' === $type ) {
			return new ProviderError( 'bad_request', 'The AI provider does not know this model.' . ( '' !== $message ? ' ' . $message : '' ) );
		}
		return new ProviderError( 'bad_request', 'The AI provider refused the request.' . ( '' !== $message ? ' ' . $message : '' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $state State.
	 * @param string              $event Event.
	 * @param mixed               $data  Data.
	 * @throws ProviderError Error events.
	 */
	public function stream_event( array $state, string $event, $data ): array {
		$out  = array(
			'state' => $state,
			'text'  => '',
			'tool'  => null,
		);
		$data = is_array( $data ) ? $data : array();
		switch ( $event ) {
			case 'message_start':
				$message                 = (array) ( $data['message'] ?? array() );
				$out['state']['model']   = (string) ( $message['model'] ?? '' );
				$out['state']['usage']   = (array) ( $message['usage'] ?? array() );
				$out['state']['blocks']  = array();
				$out['state']['started'] = true;
				break;
			case 'content_block_start':
				$block = (array) ( $data['content_block'] ?? array() );
				if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
					$block['partial'] = '';
					$out['tool']      = array(
						'id'   => (string) ( $block['id'] ?? '' ),
						'name' => (string) ( $block['name'] ?? '' ),
					);
				}
				$out['state']['blocks'][ (int) ( $data['index'] ?? 0 ) ] = $block;
				break;
			case 'content_block_delta':
				$index = (int) ( $data['index'] ?? 0 );
				$delta = (array) ( $data['delta'] ?? array() );
				$block = (array) ( $out['state']['blocks'][ $index ] ?? array() );
				switch ( (string) ( $delta['type'] ?? '' ) ) {
					case 'text_delta':
						$block['text'] = (string) ( $block['text'] ?? '' ) . (string) ( $delta['text'] ?? '' );
						$out['text']   = (string) ( $delta['text'] ?? '' );
						break;
					case 'input_json_delta':
						$block['partial'] = (string) ( $block['partial'] ?? '' ) . (string) ( $delta['partial_json'] ?? '' );
						break;
					case 'thinking_delta':
						$block['thinking'] = (string) ( $block['thinking'] ?? '' ) . (string) ( $delta['thinking'] ?? '' );
						break;
					case 'signature_delta':
						$block['signature'] = (string) ( $block['signature'] ?? '' ) . (string) ( $delta['signature'] ?? '' );
						break;
				}
				$out['state']['blocks'][ $index ] = $block;
				break;
			case 'content_block_stop':
				$index = (int) ( $data['index'] ?? 0 );
				$block = (array) ( $out['state']['blocks'][ $index ] ?? array() );
				if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
					$input          = '' === (string) ( $block['partial'] ?? '' ) ? array() : Json::decode( (string) $block['partial'] );
					$block['input'] = is_array( $input ) ? $input : array( '__invalid_json' => true );
					unset( $block['partial'] );
				}
				$out['state']['blocks'][ $index ] = $block;
				break;
			case 'message_delta':
				$out['state']['stop_reason'] = (string) ( $data['delta']['stop_reason'] ?? '' );
				foreach ( (array) ( $data['usage'] ?? array() ) as $k => $v ) {
					if ( is_numeric( $v ) ) {
						$out['state']['usage'][ $k ] = (int) $v;
					}
				}
				break;
			case 'message_stop':
				$out['state']['done'] = true;
				break;
			case 'error':
				$error = self::error( 'overloaded_error' === ( $data['error']['type'] ?? '' ) ? 529 : 500, $data, '' );
				throw $error;
		}//end switch
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $state State.
	 * @param string              $key   Key.
	 * @throws ProviderError Incomplete stream.
	 */
	public function stream_result( array $state, string $key ): array {
		if ( empty( $state['started'] ) ) {
			throw new ProviderError( 'bad_response', 'The AI provider sent an answer that could not be read.' );
		}
		$blocks = (array) ( $state['blocks'] ?? array() );
		ksort( $blocks );
		$message = array(
			'model'       => (string) ( $state['model'] ?? '' ),
			'content'     => array_values( $blocks ),
			'stop_reason' => (string) ( $state['stop_reason'] ?? '' ),
			'usage'       => (array) ( $state['usage'] ?? array() ),
		);
		return $this->parse( 200, Json::encode( $message ), $key );
	}
}
