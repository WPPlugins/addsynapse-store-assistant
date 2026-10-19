<?php
/**
 * Conversations REST controller.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

use Addsynapse\StoreAssistant\Agent\RunState;
use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Data\ConversationRepository;
use Addsynapse\StoreAssistant\Data\MessageRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * List / create / rename / archive conversations and read their messages.
 * Every lookup is scoped to the current user (others' ids → 404).
 */
class ConversationsController {

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
	 * 404 error (also used for "not yours" so ids don't leak).
	 */
	public static function not_found(): WP_Error {
		return new WP_Error( 'addsynapse_assistant_not_found', __( 'Conversation not found.', 'addsynapse-store-assistant' ), array( 'status' => 404 ) );
	}

	/**
	 * GET /conversations.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$rows = $this->conversations->list_for_user( get_current_user_id(), sanitize_text_field( (string) $request['search'] ) );
		return new WP_REST_Response( array_map( array( MessageFormatter::class, 'conversation' ), $rows ) );
	}

	/**
	 * POST /conversations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$row = $this->conversations->create( get_current_user_id(), (string) $request['title'] );
		if ( ! $row ) {
			return new WP_Error( 'addsynapse_assistant_db', __( 'Could not create the conversation.', 'addsynapse-store-assistant' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( MessageFormatter::conversation( $row ), 201 );
	}

	/**
	 * GET /conversations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$row = $this->conversations->find_for_user( (int) $request['id'], get_current_user_id() );
		return $row ? new WP_REST_Response( MessageFormatter::conversation( $row ) ) : self::not_found();
	}

	/**
	 * PATCH /conversations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rename( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = (int) $request['id'];
		if ( ! $this->conversations->find_for_user( $id, $user_id ) ) {
			return self::not_found();
		}
		if ( '' === ConversationRepository::clean_title( (string) $request['title'] ) ) {
			return new WP_Error( 'addsynapse_assistant_invalid_title', __( 'The title cannot be empty.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		$this->conversations->rename( $id, $user_id, (string) $request['title'] );
		return new WP_REST_Response( MessageFormatter::conversation( (array) $this->conversations->find_for_user( $id, $user_id ) ) );
	}

	/**
	 * DELETE /conversations/{id} — archives (soft delete).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function archive( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = (int) $request['id'];
		if ( ! $this->conversations->find_for_user( $id, $user_id ) ) {
			return self::not_found();
		}
		$this->conversations->archive( $id, $user_id );
		return new WP_REST_Response(
			array(
				'id'       => $id,
				'archived' => true,
			)
		);
	}

	/**
	 * GET /conversations/{id}/messages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function messages( WP_REST_Request $request ) {
		$row = $this->conversations->find_for_user( (int) $request['id'], get_current_user_id() );
		if ( ! $row ) {
			return self::not_found();
		}
		$tools = new ToolRegistry();
		$out   = array();
		foreach ( $this->messages->list_for_conversation( (int) $row['id'] ) as $message ) {
			$out[] = MessageFormatter::message( $message, $tools );
		}
		return new WP_REST_Response(
			array(
				'conversation' => MessageFormatter::conversation( $row ),
				'messages'     => $out,
				// True while an answer is still being generated (e.g. after the browser's
				// stream dropped): the UI polls until it is false.
				'running'      => RunState::is_running( (int) $row['id'] ),
			)
		);
	}
}
