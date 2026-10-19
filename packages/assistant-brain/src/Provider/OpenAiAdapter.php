<?php
/**
 * OpenAI Responses API.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

use Addsynapse\StoreAssistant\Brain\Json;

defined( 'ABSPATH' ) || exit;

/**
 * tool_use ↔ function_call (call_id = our id), tool_result ↔ function_call_output (no is_error → "ERROR: "
 * prefix), store:false (nothing kept at OpenAI), strict:false (our schemas have optional fields). Prompt
 * caching is automatic for a stable prefix; prompt_cache_key groups requests of one site.
 */
final class OpenAiAdapter implements ProviderAdapter, StreamingAdapter {

	public const URL = 'https://api.openai.com/v1/responses';

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $request Canonical request.
	 * @param string              $key     API key.
	 */
	public function build( array $request, string $key ): array {
		$body = array(
			'model'             => (string) $request['model'],
			'max_output_tokens' => (int) ( $request['max_tokens'] ?? 8000 ),
			'store'             => false,
		);
		// Reasoning effort only for reasoning models (gpt-5 family); others reject the field.
		if ( isset( $request['effort'] ) && 0 === strpos( (string) $request['model'], 'gpt-5' ) ) {
			$body['reasoning'] = array( 'effort' => (string) $request['effort'] );
		}
		// Reasoning models with store=false: ask for the encrypted reasoning items and replay them in the next call of the
		// turn (OpenAI's stateless pattern). Without them gpt-5 models may write their planning into the visible answer.
		if ( 0 === strpos( (string) $request['model'], 'gpt-5' ) || 1 === preg_match( '/^o[0-9]/', (string) $request['model'] ) ) {
			$body['include'] = array( 'reasoning.encrypted_content' );
		}
		if ( '' !== (string) ( $request['system'] ?? '' ) ) {
			$body['instructions'] = (string) $request['system'];
		}
		if ( '' !== (string) ( $request['cache_key'] ?? '' ) ) {
			$body['prompt_cache_key'] = substr( (string) $request['cache_key'], 0, 64 );
		}
		$tools = array();
		foreach ( (array) ( $request['tools'] ?? array() ) as $tool ) {
			$tools[] = array(
				'type'        => 'function',
				'name'        => (string) $tool['name'],
				'description' => (string) ( $tool['description'] ?? '' ),
				'parameters'  => Blocks::object( $tool['input_schema'] ?? array() ),
				'strict'      => false,
			);
		}
		if ( $tools ) {
			$body['tools']       = $tools;
			$body['tool_choice'] = 'none' === ( $request['tool_choice'] ?? 'auto' ) ? 'none' : 'auto';
		}
		$input = array();
		foreach ( (array) ( $request['messages'] ?? array() ) as $message ) {
			$role = 'assistant' === ( $message['role'] ?? '' ) ? 'assistant' : 'user';
			$text = array();
			foreach ( Blocks::clean( $message['content'] ?? array() ) as $block ) {
				switch ( $block['type'] ) {
					case 'text':
						$text[] = array(
							'type' => 'assistant' === $role ? 'output_text' : 'input_text',
							'text' => $block['text'],
						);
						break;
					case 'image':
						if ( 'user' === $role ) {
							$text[] = array(
								'type'      => 'input_image',
								'image_url' => 'data:' . $block['source']['media_type'] . ';base64,' . $block['source']['data'],
							);
						}
						break;
					case 'openai_reasoning':
						if ( 'assistant' === $role ) {
							$input   = self::flush( $input, $role, $text );
							$text    = array();
							$input[] = array_filter(
								array(
									'type'              => 'reasoning',
									'id'                => $block['id'],
									'summary'           => array(),
									'encrypted_content' => $block['encrypted_content'],
								),
								static function ( $v ) {
									return '' !== $v;
								}
							);
						}
						break;
					case 'tool_use':
						$input   = self::flush( $input, $role, $text );
						$text    = array();
						$input[] = array(
							'type'      => 'function_call',
							'call_id'   => $block['id'],
							'name'      => $block['name'],
							'arguments' => Json::encode( $block['input'] ),
						);
						break;
					case 'tool_result':
						$input   = self::flush( $input, $role, $text );
						$text    = array();
						$input[] = array(
							'type'    => 'function_call_output',
							'call_id' => $block['tool_use_id'],
							'output'  => ( empty( $block['is_error'] ) ? '' : 'ERROR: ' ) . $block['content'],
						);
						break;
				}//end switch
			}//end foreach
			$input = self::flush( $input, $role, $text );
		}//end foreach
		$body['input'] = $input;
		if ( ! empty( $request['stream'] ) ) {
			$body['stream'] = true;
		}
		return array(
			'url'     => self::URL,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => Json::encode( $body ),
		);
	}

	/**
	 * Appends a pending text message.
	 *
	 * @param array<int,mixed>                $input Input items.
	 * @param string                          $role  Role.
	 * @param array<int,array<string,string>> $text  Text parts.
	 * @return array<int,mixed>
	 */
	private static function flush( array $input, string $role, array $text ): array {
		if ( $text ) {
			$input[] = array(
				'role'    => $role,
				'content' => $text,
			);
		}
		return $input;
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
		if ( ! is_array( $data ) || ! isset( $data['output'] ) || ! is_array( $data['output'] ) ) {
			throw new ProviderError( 'bad_response', 'The AI provider sent an answer that could not be read.' );
		}
		if ( 'failed' === ( $data['status'] ?? '' ) ) {
			$error = self::error( 500, array( 'error' => $data['error'] ?? array() ), $key );
			throw $error;
		}
		$content = array();
		$stop    = 'end_turn';
		foreach ( $data['output'] as $item ) {
			$type = (string) ( $item['type'] ?? '' );
			if ( 'reasoning' === $type && is_string( $item['encrypted_content'] ?? null ) && '' !== $item['encrypted_content'] ) {
				$content[] = array(
					'type'              => 'openai_reasoning',
					'id'                => (string) ( $item['id'] ?? '' ),
					'encrypted_content' => $item['encrypted_content'],
				);
			} elseif ( 'message' === $type ) {
				foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
					if ( 'output_text' === ( $part['type'] ?? '' ) && '' !== (string) ( $part['text'] ?? '' ) ) {
						$content[] = array(
							'type' => 'text',
							'text' => (string) $part['text'],
						);
					} elseif ( 'refusal' === ( $part['type'] ?? '' ) ) {
						$content[] = array(
							'type' => 'text',
							'text' => (string) ( $part['refusal'] ?? '' ),
						);
						$stop      = 'refusal';
					}
				}
			} elseif ( 'function_call' === $type ) {
				$args      = Json::decode( (string) ( $item['arguments'] ?? '' ) );
				$content[] = array(
					'type'  => 'tool_use',
					'id'    => (string) ( $item['call_id'] ?? $item['id'] ?? '' ),
					'name'  => (string) ( $item['name'] ?? '' ),
					'input' => is_array( $args ) ? $args : array( '__invalid_json' => true ),
				);
				$stop      = 'tool_use';
			}//end if
		}//end foreach
		if ( 'incomplete' === ( $data['status'] ?? '' ) && 'tool_use' !== $stop ) {
			$stop = 'content_filter' === ( $data['incomplete_details']['reason'] ?? '' ) ? 'refusal' : 'max_tokens';
		}
		$cached = (int) ( $data['usage']['input_tokens_details']['cached_tokens'] ?? 0 );
		return array(
			'content'     => $content,
			'stop_reason' => $stop,
			'usage'       => array(
				'input_tokens'                => max( 0, (int) ( $data['usage']['input_tokens'] ?? 0 ) - $cached ),
				'output_tokens'               => (int) ( $data['usage']['output_tokens'] ?? 0 ),
				'cache_read_input_tokens'     => $cached,
				'cache_creation_input_tokens' => 0,
			),
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
		$code    = (string) ( $data['error']['code'] ?? '' );
		$type    = (string) ( $data['error']['type'] ?? '' );
		$message = Redactor::redact( (string) ( $data['error']['message'] ?? '' ), $key );
		if ( 401 === $status || 'invalid_api_key' === $code ) {
			return new ProviderError( 'invalid_key', 'The AI provider rejected the API key.' );
		}
		if ( 'insufficient_quota' === $code || 'insufficient_quota' === $type ) {
			return new ProviderError( 'quota', 'Your AI provider account has no credit left.' );
		}
		if ( 429 === $status ) {
			return new ProviderError( 'rate_limited', 'Your AI provider account is at its rate limit; try again in a minute.' );
		}
		if ( 'context_length_exceeded' === $code ) {
			return new ProviderError( 'context_too_long', 'The conversation is too long for the model; start a new one.' );
		}
		if ( 503 === $status ) {
			return new ProviderError( 'overloaded', 'The AI provider is overloaded; try again in a minute.' );
		}
		if ( $status >= 500 ) {
			return new ProviderError( 'server', 'The AI provider had a temporary error.' );
		}
		if ( 403 === $status ) {
			return new ProviderError( 'invalid_key', 'The API key may not use this model.' . ( '' !== $message ? ' ' . $message : '' ) );
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
			case 'response.output_text.delta':
				$out['text'] = (string) ( $data['delta'] ?? '' );
				break;
			case 'response.output_item.added':
				if ( 'function_call' === ( $data['item']['type'] ?? '' ) ) {
					$out['tool'] = array(
						'id'   => (string) ( $data['item']['call_id'] ?? '' ),
						'name' => (string) ( $data['item']['name'] ?? '' ),
					);
				}
				break;
			case 'response.completed':
			case 'response.incomplete':
				$out['state']['response'] = (array) ( $data['response'] ?? array() );
				break;
			case 'response.failed':
			case 'error':
				$error = self::error( 500, array( 'error' => (array) ( $data['response']['error'] ?? $data['error'] ?? $data ) ), '' );
				throw $error;
		}
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
		if ( empty( $state['response'] ) ) {
			throw new ProviderError( 'bad_response', 'The AI provider sent an answer that could not be read.' );
		}
		return $this->parse( 200, Json::encode( $state['response'] ), $key );
	}
}
