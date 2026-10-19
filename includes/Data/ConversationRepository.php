<?php
/**
 * Conversations persistence.
 *
 * All reads/writes are scoped by user_id: a user can only ever see their own
 * conversations (callers must pass the current user's id).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Data;

use Addsynapse\StoreAssistant\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, no WP API exists for them.

/**
 * CRUD for {prefix}addsynapse_assistant_conversations.
 */
class ConversationRepository {

	public const TITLE_MAX = 120;

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'conversations' );
	}

	/**
	 * Creates a conversation and returns it.
	 *
	 * @param int    $user_id Owner.
	 * @param string $title   Optional title (auto title when empty).
	 * @return array<string,mixed>|null
	 */
	public function create( int $user_id, string $title = '' ): ?array {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$title = self::clean_title( $title );
		$ok    = $wpdb->insert(
			$this->table(),
			array(
				'user_id'    => $user_id,
				'title'      => $title,
				'title_auto' => '' === $title ? 1 : 0,
				'archived'   => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			return null;
		}
		return $this->find_for_user( (int) $wpdb->insert_id, $user_id );
	}

	/**
	 * Finds a non-archived conversation owned by the user.
	 *
	 * @param int $id      Conversation id.
	 * @param int $user_id Owner.
	 * @return array<string,mixed>|null
	 */
	public function find_for_user( int $id, int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND user_id = %d AND archived = 0',
				$this->table(),
				$id,
				$user_id
			),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Lists a user's conversations, newest activity first.
	 *
	 * @param int    $user_id Owner.
	 * @param string $search  Optional title search.
	 * @param int    $limit   Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_for_user( int $user_id, string $search = '', int $limit = 200 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		if ( '' !== $search ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND archived = 0 AND title LIKE %s ORDER BY updated_at DESC, id DESC LIMIT %d',
					$this->table(),
					$user_id,
					'%' . $wpdb->esc_like( $search ) . '%',
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND archived = 0 ORDER BY updated_at DESC, id DESC LIMIT %d',
					$this->table(),
					$user_id,
					$limit
				),
				ARRAY_A
			);
		}//end if
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Renames (manual title — disables auto titling).
	 *
	 * @param int    $id      Conversation id.
	 * @param int    $user_id Owner.
	 * @param string $title   New title.
	 */
	public function rename( int $id, int $user_id, string $title ): bool {
		global $wpdb;
		$title = self::clean_title( $title );
		if ( '' === $title ) {
			return false;
		}
		return false !== $wpdb->update(
			$this->table(),
			array(
				'title'      => $title,
				'title_auto' => 0,
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s', '%d' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Sets an automatically generated title, only if the user has not renamed it.
	 *
	 * @param int    $id    Conversation id.
	 * @param string $title Title.
	 */
	public function set_auto_title( int $id, string $title ): bool {
		global $wpdb;
		$title = self::clean_title( $title );
		if ( '' === $title ) {
			return false;
		}
		return (bool) $wpdb->update(
			$this->table(),
			array( 'title' => $title ),
			array(
				'id'         => $id,
				'title_auto' => 1,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Soft-deletes (archives) a conversation. Data is kept for audit.
	 *
	 * @param int $id      Conversation id.
	 * @param int $user_id Owner.
	 */
	public function archive( int $id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table(),
			array(
				'archived'   => 1,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Bumps updated_at (activity).
	 *
	 * @param int $id Conversation id.
	 */
	public function touch( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Normalises a title (plain text, single line, bounded length).
	 *
	 * @param string $title Raw.
	 */
	public static function clean_title( string $title ): string {
		$title = sanitize_text_field( $title );
		$title = trim( $title, " \t\n\r\0\x0B\"'«»" );
		if ( mb_strlen( $title ) > self::TITLE_MAX ) {
			$title = rtrim( mb_substr( $title, 0, self::TITLE_MAX - 1 ) ) . '…';
		}
		return $title;
	}

	/**
	 * Casts a DB row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'title'      => (string) $row['title'],
			'title_auto' => (bool) $row['title_auto'],
			'archived'   => (bool) $row['archived'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}
}
