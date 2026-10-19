<?php
/**
 * Chat REST controller: send a message (JSON) or stream the reply (SSE).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

use Addsynapse\StoreAssistant\Agent\AgentLoop;
use Addsynapse\StoreAssistant\Byok\DirectClient;
use Addsynapse\StoreAssistant\Byok\KeyStore;
use Addsynapse\StoreAssistant\Agent\CommandParser;
use Addsynapse\StoreAssistant\Agent\ErrorMessages;
use Addsynapse\StoreAssistant\Agent\HistoryBuilder;
use Addsynapse\StoreAssistant\Agent\RunState;
use Addsynapse\StoreAssistant\Agent\Selection;
use Addsynapse\StoreAssistant\Agent\TitleGenerator;
use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Data\ConversationRepository;
use Addsynapse\StoreAssistant\Data\MessageRepository;
use Addsynapse\StoreAssistant\Media\ImageUploads;
use Addsynapse\StoreAssistant\Llm\ClientFactory;
use Addsynapse\StoreAssistant\Llm\LlmClient;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the agent for a conversation.
 */
class ChatController {

	public const MAX_MESSAGE_CHARS = 8000;

	/**
	 * Conversations repo.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Messages repo.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->conversations = new ConversationRepository();
		$this->messages      = new MessageRepository();
	}

	/**
	 * The LLM client (own AI key; tests inject a mock through Seams).
	 */
	protected function client(): LlmClient {
		return ClientFactory::make();
	}

	/**
	 * GET /status — configuration + usage for the UI.
	 */
	public function status(): WP_REST_Response {
		$client = $this->client();
		return new WP_REST_Response(
			array(
				'connection_configured' => $client->is_configured(),
				'connection'            => $client->is_configured() ? 'own_key' : 'none',
				// Own AI key: provider / model / last 4 characters only — never the key.
				'ai_key'                => AiKeyController::state(),
				'streaming'             => Config::streaming_enabled(),
				'tools'                 => array_map(
					static function ( $a ) {
						return ToolRegistry::tool_name( $a['name'] );
					},
					( new ToolRegistry() )->advertised()
				),
			)
		);
	}

	/**
	 * Validates the request, stores the user message (unless retry) and returns the conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{conversation:array<string,mixed>,user_message:?array<string,mixed>,first:bool}|WP_Error
	 */
	private function prepare( WP_REST_Request $request ) {
		$user_id      = get_current_user_id();
		$conversation = $this->conversations->find_for_user( (int) $request['id'], $user_id );
		if ( ! $conversation ) {
			return ConversationsController::not_found();
		}
		$retry   = (bool) $request['retry'];
		$content = trim( str_replace( "\r\n", "\n", (string) $request['content'] ) );
		// Keep the text as typed (it is rendered escaped, never as HTML); strip control chars.
		$content = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content );

		if ( ! $retry && '' === $content ) {
			return new WP_Error( 'addsynapse_assistant_empty', __( 'Please write a message.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $content ) > self::MAX_MESSAGE_CHARS ) {
			return new WP_Error( 'addsynapse_assistant_too_long', __( 'The message is too long.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}

		// No AI key: a question for the AI is refused before anything is stored. Slash commands run on the site
		// without the AI, so they work too — through the same ToolRegistry checks as always.
		$offline = $this->runs_offline( (int) $conversation['id'], $retry, $content );
		if ( ! $this->client()->is_configured() && ! $offline ) {
			return new WP_Error( 'addsynapse_assistant_not_configured', ErrorMessages::for_code( 'not_configured' ), array( 'status' => 409 ) );
		}

		if ( RunState::is_running( (int) $conversation['id'] ) ) {
			return new WP_Error( 'addsynapse_assistant_busy', __( 'An answer is still being prepared in this conversation. Please wait a moment.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
		}

		// An image uploaded in the chat: the user's own image, still pending.
		$image     = null;
		$image_ref = is_array( $request['image'] ) ? (int) ( $request['image']['attachment_id'] ?? 0 ) : 0;
		if ( ! $retry && $image_ref > 0 ) {
			$image = ImageUploads::find_for_user( $image_ref, $user_id );
			if ( ! $image ) {
				return ImagesController::not_found();
			}
		}

		$existing = $this->messages->list_for_conversation( (int) $conversation['id'] );
		$first    = true;
		foreach ( $existing as $row ) {
			if ( MessageRepository::ROLE_USER === $row['role'] ) {
				$first = false;
				break;
			}
		}

		if ( $retry ) {
			$history = HistoryBuilder::build( $existing );
			if ( ! $history || 'user' !== end( $history )['role'] ) {
				return new WP_Error( 'addsynapse_assistant_nothing_to_retry', __( 'There is nothing to retry.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
			}
			return array(
				'conversation' => $conversation,
				'user_message' => null,
				'first'        => false,
			);
		}

		// Checked products (structured context, never concatenated into the text). The text
		// block stays first: slash commands read content[0].
		$blocks    = array(
			array(
				'type' => 'text',
				'text' => $content,
			),
		);
		$selection = Selection::block( Selection::sanitize( $request['selection'] ) );
		if ( $selection ) {
			$blocks[] = $selection;
		}
		if ( $image ) {
			$blocks[] = ImageUploads::block( $image );
			ImageUploads::attach( (int) $image['attachment_id'], (int) $conversation['id'] );
		}
		$message = $this->messages->add( (int) $conversation['id'], MessageRepository::ROLE_USER, $blocks );
		if ( ! $message ) {
			return new WP_Error( 'addsynapse_assistant_db', __( 'Could not save the message.', 'addsynapse-store-assistant' ), array( 'status' => 500 ) );
		}
		if ( $first ) {
			$this->conversations->set_auto_title( (int) $conversation['id'], TitleGenerator::fallback( $content ) );
		}
		$this->conversations->touch( (int) $conversation['id'] );

		return array(
			'conversation' => (array) $this->conversations->find_for_user( (int) $conversation['id'], $user_id ),
			'user_message' => $message,
			'first'        => $first,
		);
	}

	/**
	 * Whether this message can be answered without the AI service: a slash command (typed now, or the last
	 * stored user message for a retry). Anything else needs the connection.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param bool   $retry           Retry of the last user message.
	 * @param string $content         Typed text (not a retry).
	 */
	private function runs_offline( int $conversation_id, bool $retry, string $content ): bool {
		if ( $retry ) {
			$content = '';
			foreach ( array_reverse( $this->messages->list_for_conversation( $conversation_id ) ) as $row ) {
				if ( MessageRepository::ROLE_USER === $row['role'] ) {
					$content = (string) ( $row['content'][0]['text'] ?? '' );
					break;
				}
			}
		}
		return null !== CommandParser::parse( $content );
	}

	/**
	 * Read abilities whose result tables can be paged from the UI (no model call).
	 */
	public const PAGEABLE = array( 'addsynapse-assistant/search-products' );

	/**
	 * POST /results/page — another page of a product table shown in the chat. Runs the same
	 * READ-ONLY ability through ToolRegistry::execute (schema + permission); nothing is stored
	 * in the conversation and nothing reaches the AI.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function result_page( WP_REST_Request $request ) {
		$ability = (string) $request['ability'];
		if ( ! in_array( $ability, self::PAGEABLE, true ) ) {
			return new WP_Error( 'addsynapse_assistant_not_pageable', __( 'This result cannot be paged.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		$input         = is_array( $request['input'] ) ? $request['input'] : array();
		$input['page'] = max( 1, min( 1000, (int) $request['page'] ) );
		$exec          = ( new ToolRegistry() )->execute( ToolRegistry::tool_name( $ability ), $input );
		$data          = $exec['is_error'] ? null : json_decode( $exec['content'], true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'addsynapse_assistant_page_failed', $exec['is_error'] ? $exec['content'] : __( 'Something went wrong. Please try again.', 'addsynapse-store-assistant' ), array( 'status' => 422 ) );
		}
		return new WP_REST_Response( array( 'data' => $data ) );
	}

	/**
	 * POST /conversations/{id}/stop — the manager's Stop button.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function stop( WP_REST_Request $request ) {
		$conversation = $this->conversations->find_for_user( (int) $request['id'], get_current_user_id() );
		if ( ! $conversation ) {
			return ConversationsController::not_found();
		}
		RunState::request_stop( (int) $conversation['id'] );
		return new WP_REST_Response( array( 'stopping' => true ) );
	}

	/**
	 * POST /conversations/{id}/messages — non-streaming.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( WP_REST_Request $request ) {
		$prepared = $this->prepare( $request );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$client       = $this->client();
		$conversation = $prepared['conversation'];
		$id           = (int) $conversation['id'];
		RunState::start( $id );
		try {
			$result = ( new AgentLoop( $client ) )->set_deadline( self::deadline( $client ) )->run( $conversation, null, RunState::stop_poll( $id ) );
		} finally {
			RunState::finish( $id );
		}
		// Reload: the AI may have set a better title with its first answer.
		$conversation = (array) $this->conversations->find_for_user( (int) $conversation['id'], get_current_user_id() );

		$tools    = new ToolRegistry();
		$messages = array();
		foreach ( $this->messages->list_for_conversation( (int) $conversation['id'] ) as $row ) {
			$messages[] = MessageFormatter::message( $row, $tools );
		}
		$body = array(
			'status'       => $result['status'],
			'conversation' => MessageFormatter::conversation( $conversation ),
			'messages'     => $messages,
		);
		if ( $result['error'] ) {
			$body['error'] = AgentLoop::error_event( $result['error'] );
		}
		return new WP_REST_Response( $body );
	}

	/**
	 * POST /conversations/{id}/stream — Server-Sent Events.
	 *
	 * Validation errors are returned as normal JSON errors (before any output).
	 * Once streaming starts, errors arrive as `error` events and the stream ends with `done`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public function stream( WP_REST_Request $request ) {
		$prepared = $this->prepare( $request );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$client       = $this->client();
		$conversation = $prepared['conversation'];
		$sse          = new SseWriter();
		$sse->start();

		$tools = new ToolRegistry();
		if ( $prepared['user_message'] ) {
			$sse->send( 'user_message', MessageFormatter::message( $prepared['user_message'], $tools ) );
		}
		$sse->send( 'conversation', MessageFormatter::conversation( $conversation ) );

		$emit = static function ( array $event ) use ( $sse ) {
			$sse->send( (string) $event['type'], MessageFormatter::event( $event ) );
		};

		// A dropped connection must not lose the answer: the run continues (ignore_user_abort)
		// and is stored; only an explicit Stop (POST …/stop) cancels it.
		$id = (int) $conversation['id'];
		RunState::start( $id );
		try {
			$result = ( new AgentLoop( $client ) )->set_deadline( self::deadline( $client ) )->run( $conversation, $emit, RunState::stop_poll( $id, array( $sse, 'heartbeat' ) ) );
		} finally {
			RunState::finish( $id );
		}

		$conversation = (array) $this->conversations->find_for_user( (int) $conversation['id'], get_current_user_id() );
		$sse->send(
			'done',
			array(
				'status'       => $result['status'],
				'conversation' => MessageFormatter::conversation( $conversation ),
			)
		);
		$sse->finish();
	}

	/**
	 * Time budget of one request for the own-key brain (the model is called from this server, so the host's PHP time
	 * limit applies). Compatibility mode = one model call per request. Another client (tests) gets none (null).
	 *
	 * @param LlmClient $client Client.
	 */
	public static function deadline( LlmClient $client ): ?float {
		if ( ! $client instanceof DirectClient ) {
			return null;
		}
		$now = microtime( true );
		if ( KeyStore::step_mode() ) {
			return $now;
		}
		$limit = (int) ini_get( 'max_execution_time' );
		$limit = $limit > 0 ? $limit : 300;
		return $now + max( 15, min( $limit, 300 ) - 25 );
	}
}
