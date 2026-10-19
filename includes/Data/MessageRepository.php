<?php
/**
 * Messages persistence.
 *
 * Each row is one API-level message. Roles:
 *  - user      : the human's text
 *  - assistant : model output blocks (text / thinking / tool_use)
 *  - tool      : tool_result blocks (sent to the API as a "user" message)
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Data;

use Addsynapse\StoreAssistant\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, no WP API exists for them.

/**
 * CRUD for {prefix}addsynapse_assistant_messages.
 */
class MessageRepository {

	public const ROLE_USER      = 'user';
	public const ROLE_ASSISTANT = 'assistant';
	public const ROLE_TOOL      = 'tool';

	public const STATUS_COMPLETE = 'complete';
	public const STATUS_STOPPED  = 'stopped';
	public const STATUS_ERROR    = 'error';

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'messages' );
	}

	/**
	 * Inserts a message.
	 *
	 * @param int                            $conversation_id Conversation.
	 * @param string                         $role            user|assistant|tool.
	 * @param array<int,array<string,mixed>> $blocks          Content blocks.
	 * @param array<string,mixed>            $meta            status, model, tokens_in, tokens_out.
	 * @return array<string,mixed>|null The stored message.
	 */
	public function add( int $conversation_id, string $role, array $blocks, array $meta = array() ): ?array {
		global $wpdb;
		$ok = $wpdb->insert(
			$this->table(),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => (string) wp_json_encode( array_values( $blocks ), JSON_UNESCAPED_UNICODE ),
				'status'          => (string) ( $meta['status'] ?? self::STATUS_COMPLETE ),
				'model'           => (string) ( $meta['model'] ?? '' ),
				'tokens_in'       => (int) ( $meta['tokens_in'] ?? 0 ),
				'tokens_out'      => (int) ( $meta['tokens_out'] ?? 0 ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( ! $ok ) {
			return null;
		}
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds one message.
	 *
	 * @param int $id Message id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * All messages of a conversation in order. Ownership must be checked by the caller.
	 *
	 * @param int $conversation_id Conversation.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_for_conversation( int $conversation_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE conversation_id = %d ORDER BY id ASC',
				$this->table(),
				$conversation_id
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Casts a DB row and decodes content.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		$content = json_decode( (string) $row['content'], true );
		return array(
			'id'              => (int) $row['id'],
			'conversation_id' => (int) $row['conversation_id'],
			'role'            => (string) $row['role'],
			'content'         => is_array( $content ) ? $content : array(),
			'status'          => (string) $row['status'],
			'model'           => (string) $row['model'],
			'tokens_in'       => (int) $row['tokens_in'],
			'tokens_out'      => (int) $row['tokens_out'],
			'created_at'      => (string) $row['created_at'],
		);
	}
}
