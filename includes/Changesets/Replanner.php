<?php
/**
 * «Επανάληψη»: re-runs the plan of a finished changeset as a NEW draft (UI button only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Agent\RunState;
use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Data\ConversationRepository;
use Addsynapse\StoreAssistant\Data\MessageRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The old items are NEVER re-applied (their `before` values are stale). Instead the SAME plan
 * ability runs again with the stored input, through ToolRegistry::execute (same schema,
 * permission and draft-only invariant), so the new draft has fresh before-values, a fresh
 * expiry and new create_term / create_menu lines if they are needed again. It still needs
 * Έγκριση. Not an ability: the agent can never call it.
 */
final class Replanner {

	/**
	 * Statuses that may be repeated (the change is not in effect).
	 */
	public const STATUSES = array( ChangesetRepository::REVERTED, ChangesetRepository::REJECTED, ChangesetRepository::EXPIRED );

	/**
	 * `model` of the stored messages (like slash commands: no model call).
	 */
	public const MODEL = 'replan';

	/**
	 * Engine.
	 *
	 * @var ChangesetService
	 */
	private ChangesetService $service;

	/**
	 * Messages.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Conversations.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Constructor.
	 *
	 * @param ChangesetService|null $service Engine.
	 */
	public function __construct( ?ChangesetService $service = null ) {
		$this->service       = $service ?? new ChangesetService();
		$this->messages      = new MessageRepository();
		$this->conversations = new ConversationRepository();
	}

	/**
	 * Whether a changeset's status allows «Επανάληψη».
	 *
	 * @param array<string,mixed> $changeset Changeset.
	 */
	public static function can( array $changeset ): bool {
		return in_array( (string) $changeset['status'], self::STATUSES, true );
	}

	/**
	 * Re-plans a changeset for its owner.
	 *
	 * @param int $id      Changeset.
	 * @param int $user_id Current user.
	 * @return array{changeset:array<string,mixed>|null,result:mixed,is_error:bool,conversation_id:int,message_ids:array<int,int>}|WP_Error
	 */
	public function replan( int $id, int $user_id ) {
		$changeset = $this->service->get_for_user( $id, $user_id );
		if ( ! $changeset ) {
			return ChangesetService::not_found();
		}
		if ( ! self::can( $changeset ) ) {
			return new WP_Error( 'addsynapse_assistant_changeset_state', __( 'Only an undone, rejected or expired change can be repeated.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
		}
		$source = $changeset['source'] ? $changeset['source'] : $this->legacy_source( $changeset, $user_id );
		$name   = (string) ( $source['ability'] ?? '' );
		if ( ! $source || ! in_array( $name, ToolRegistry::plan_abilities(), true ) ) {
			return new WP_Error( 'addsynapse_assistant_replan_unavailable', __( 'This change cannot be repeated automatically. Ask for it again in the chat.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
		}

		$conversation_id = (int) $changeset['conversation_id'];
		if ( $conversation_id && ! $this->conversations->find_for_user( $conversation_id, $user_id ) ) {
			$conversation_id = 0;
		}
		if ( $conversation_id && RunState::is_running( $conversation_id ) ) {
			return new WP_Error( 'addsynapse_assistant_busy', __( 'The assistant is still answering in this conversation. Try again when it finishes.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
		}

		$tool  = ToolRegistry::tool_name( $name );
		$input = is_array( $source['input'] ?? null ) ? $source['input'] : array();
		$exec  = ( new ToolRegistry() )->execute( $tool, $input, $conversation_id, $id );

		// Stored like a slash command (tool_use → tool_result), so the new card is part of the
		// conversation after a reload and the transcript stays valid for the model.
		$ids = array();
		if ( $conversation_id ) {
			$use_id    = 'toolu_replan_' . wp_generate_password( 16, false );
			$assistant = $this->messages->add(
				$conversation_id,
				MessageRepository::ROLE_ASSISTANT,
				array(
					array(
						'type'  => 'tool_use',
						'id'    => $use_id,
						'name'  => $tool,
						'input' => $input ? $input : new \stdClass(),
					),
				),
				array( 'model' => self::MODEL )
			);
			$block     = array(
				'type'        => 'tool_result',
				'tool_use_id' => $use_id,
				'content'     => $exec['content'],
			);
			if ( $exec['is_error'] ) {
				$block['is_error'] = true;
			}
			$result = $this->messages->add( $conversation_id, MessageRepository::ROLE_TOOL, array( $block ) );
			foreach ( array( $assistant, $result ) as $row ) {
				if ( $row ) {
					$ids[] = (int) $row['id'];
				}
			}
			$this->conversations->touch( $conversation_id );
		}//end if

		$data = $exec['is_error'] ? null : json_decode( $exec['content'], true );
		$new  = is_array( $data ) && ! empty( $data['changeset_id'] ) ? $this->service->get_for_user( (int) $data['changeset_id'], $user_id ) : null;
		return array(
			'changeset'       => $new,
			'result'          => is_array( $data ) ? $data : $exec['content'],
			'is_error'        => $exec['is_error'],
			'conversation_id' => $conversation_id,
			'message_ids'     => $ids,
		);
	}

	/**
	 * Plan call of a changeset created before `source` was stored: the tool_use whose
	 * tool_result in the same conversation reported this changeset id.
	 *
	 * @param array<string,mixed> $changeset Changeset.
	 * @param int                 $user_id   Owner.
	 * @return array<string,mixed>|null {ability, input}
	 */
	private function legacy_source( array $changeset, int $user_id ): ?array {
		$conversation_id = (int) $changeset['conversation_id'];
		if ( ! $conversation_id || ! $this->conversations->find_for_user( $conversation_id, $user_id ) ) {
			return null;
		}
		$uses = array();
		foreach ( $this->messages->list_for_conversation( $conversation_id ) as $message ) {
			foreach ( (array) $message['content'] as $block ) {
				$type = $block['type'] ?? '';
				if ( 'tool_use' === $type && MessageRepository::ROLE_ASSISTANT === $message['role'] ) {
					$uses[ (string) ( $block['id'] ?? '' ) ] = $block;
				} elseif ( 'tool_result' === $type && MessageRepository::ROLE_TOOL === $message['role'] && empty( $block['is_error'] ) ) {
					$data = json_decode( is_string( $block['content'] ?? null ) ? $block['content'] : '', true );
					$use  = $uses[ (string) ( $block['tool_use_id'] ?? '' ) ] ?? null;
					if ( $use && is_array( $data ) && (int) ( $data['changeset_id'] ?? 0 ) === (int) $changeset['id'] ) {
						return array(
							'ability' => ToolRegistry::ability_name( (string) ( $use['name'] ?? '' ) ),
							'input'   => is_array( $use['input'] ?? null ) ? $use['input'] : array(),
						);
					}
				}
			}
		}//end foreach
		return null;
	}
}
