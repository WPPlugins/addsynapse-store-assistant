<?php
/**
 * Changesets persistence ({prefix}addsynapse_assistant_changesets + {prefix}addsynapse_assistant_changeset_items).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Install\Schema;

use Addsynapse\ConnectorCore\Changeset\Selection;
use Addsynapse\ConnectorCore\Changeset\Status;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, no WP API exists for them.

/**
 * Plain data access. Status changes are compare-and-swap UPDATEs (`WHERE status IN (…)`)
 * so two requests / background runs can never both move the same changeset or item.
 */
class ChangesetRepository {

	// Changeset statuses. There is deliberately no "approved" status: Approve is one atomic
	// CAS draft → applying that also records approved_at/approved_by (+ an `approved` log
	// event) — the audit trail lives in those columns, not in a status (removed 2026-09-25).
	// The lifecycle is the commerce contract's (connector core Status — no `approved` status).
	public const DRAFT             = Status::DRAFT;
	public const APPLYING          = Status::APPLYING;
	public const APPLIED           = Status::APPLIED;
	public const PARTIALLY_APPLIED = Status::PARTIALLY_APPLIED;
	public const REJECTED          = Status::REJECTED;
	public const EXPIRED           = Status::EXPIRED;
	public const REVERTING         = Status::REVERTING;
	public const REVERTED          = Status::REVERTED;
	public const FAILED            = Status::FAILED;

	// Item statuses.
	public const ITEM_PENDING         = Status::ITEM_PENDING;
	public const ITEM_APPLIED         = Status::ITEM_APPLIED;
	public const ITEM_CONFLICT        = Status::ITEM_CONFLICT;
	public const ITEM_FAILED          = Status::ITEM_FAILED;
	public const ITEM_REVERTED        = Status::ITEM_REVERTED;
	public const ITEM_REVERT_CONFLICT = Status::ITEM_REVERT_CONFLICT;
	// Left out by a partial approval: never applied, never reverted.
	public const ITEM_EXCLUDED = Status::ITEM_EXCLUDED;

	public const STATUSES = Status::STATUSES;

	public const ITEM_STATUSES = Status::ITEM_STATUSES;

	/**
	 * Changesets table.
	 */
	private function table(): string {
		return Schema::table( 'changesets' );
	}

	/**
	 * Items table.
	 */
	private function items_table(): string {
		return Schema::table( 'changeset_items' );
	}

	/**
	 * UTC now (mysql).
	 */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Inserts a draft changeset and its items. Returns the id, or 0 on failure (nothing left behind).
	 *
	 * @param array<string,mixed>            $row   Columns: user_id, conversation_id, operation, summary, params, expires_at.
	 * @param array<int,array<string,mixed>> $items Items: object_type, object_id, parent_id, label, sku, field, before, after.
	 */
	public function insert( array $row, array $items ): int {
		global $wpdb;
		$now = self::now();
		$ok  = $wpdb->insert(
			$this->table(),
			array(
				'uuid'            => wp_generate_uuid4(),
				'conversation_id' => (int) ( $row['conversation_id'] ?? 0 ),
				'user_id'         => (int) $row['user_id'],
				'operation'       => (string) $row['operation'],
				'status'          => self::DRAFT,
				'summary'         => (string) $row['summary'],
				'params'          => (string) wp_json_encode( $row['params'] ?? new \stdClass() ),
				'counts'          => '{}',
				'log'             => '[]',
				'created_at'      => $now,
				'updated_at'      => $now,
				'expires_at'      => (string) $row['expires_at'],
				'replanned_from'  => (int) ( $row['replanned_from'] ?? 0 ),
				'source'          => empty( $row['source'] ) ? null : (string) wp_json_encode( $row['source'] ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		foreach ( array_chunk( $items, 100 ) as $chunk ) {
			$params = array();
			foreach ( $chunk as $item ) {
				$before = (string) wp_json_encode( $item['before'] );
				array_push(
					$params,
					$id,
					(string) $item['object_type'],
					(int) $item['object_id'],
					(int) ( $item['parent_id'] ?? 0 ),
					mb_substr( (string) ( $item['label'] ?? '' ), 0, 255 ),
					mb_substr( (string) ( $item['sku'] ?? '' ), 0, 100 ),
					(string) $item['field'],
					$before,
					(string) wp_json_encode( $item['after'] ),
					hash( 'sha256', $before ),
					self::ITEM_PENDING,
					'set'
				);
			}
			$sql = 'INSERT INTO %i (changeset_id, object_type, object_id, parent_id, label, sku, field, before_value, after_value, before_hash, status, operation) VALUES ' . implode( ', ', array_fill( 0, count( $chunk ), '(%d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s)' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed placeholders only.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, array_merge( array( $this->items_table() ), $params ) ) ) ) {
				$this->delete( $id );
				return 0;
			}
		}//end foreach
		$this->recount( $id );
		return $id;
	}

	/**
	 * Hard delete (only used to undo a failed insert).
	 *
	 * @param int $id Changeset.
	 */
	private function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->items_table(), array( 'changeset_id' => $id ), array( '%d' ) );
		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Finds a changeset (no ownership check: background processing only).
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Finds a changeset owned by the user.
	 *
	 * @param int $id      Id.
	 * @param int $user_id Owner.
	 * @return array<string,mixed>|null
	 */
	public function find_for_user( int $id, int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND user_id = %d', $this->table(), $id, $user_id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Number of a user's drafts still awaiting approval (not expired) — the admin bar badge.
	 *
	 * @param int $user_id User.
	 */
	public function count_open_drafts( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND status = %s AND expires_at > %s', $this->table(), $user_id, self::DRAFT, self::now() ) );
	}

	/**
	 * Items of a changeset, grouped by product (stable order), paginated by row.
	 *
	 * @param int $changeset_id Changeset.
	 * @param int $page         Page.
	 * @param int $per_page     Rows per page (0 = all).
	 * @return array<int,array<string,mixed>>
	 */
	public function items( int $changeset_id, int $page = 1, int $per_page = 0 ): array {
		global $wpdb;
		if ( $per_page > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE changeset_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', $this->items_table(), $changeset_id, $per_page, ( max( 1, $page ) - 1 ) * $per_page ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE changeset_id = %d ORDER BY id ASC', $this->items_table(), $changeset_id ), ARRAY_A );
		}
		return array_map( array( self::class, 'hydrate_item' ), (array) $rows );
	}

	/**
	 * Distinct product ids of a changeset (in item order, create_term lines excluded), paginated.
	 *
	 * @param int $changeset_id Changeset.
	 * @param int $page         Page.
	 * @param int $per_page     Products per page.
	 * @return array{ids:array<int,int>,total:int}
	 */
	public function object_page( int $changeset_id, int $page, int $per_page ): array {
		global $wpdb;
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE changeset_id = %d AND object_type IN ( %s, %s ) GROUP BY object_id ORDER BY MIN(id) ASC LIMIT %d OFFSET %d', $this->items_table(), $changeset_id, 'product', 'variation', $per_page, ( max( 1, $page ) - 1 ) * $per_page ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT object_id) FROM %i WHERE changeset_id = %d AND object_type IN ( %s, %s )', $this->items_table(), $changeset_id, 'product', 'variation' ) );
		return array(
			'ids'   => array_map( 'intval', (array) $ids ),
			'total' => $total,
		);
	}

	/**
	 * All distinct product/variation ids of a changeset (item order), in one status or all ('').
	 *
	 * @param int    $changeset_id Changeset.
	 * @param string $status       Item status ('' = any).
	 * @return array<int,int>
	 */
	public function object_ids( int $changeset_id, string $status = '' ): array {
		global $wpdb;
		if ( '' === $status ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE changeset_id = %d AND object_type IN ( %s, %s ) GROUP BY object_id ORDER BY MIN(id) ASC', $this->items_table(), $changeset_id, 'product', 'variation' ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE changeset_id = %d AND status = %s AND object_type IN ( %s, %s ) GROUP BY object_id ORDER BY MIN(id) ASC', $this->items_table(), $changeset_id, $status, 'product', 'variation' ) );
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Typed unit keys "type:id" (product:12, variation:34, post:5) of a changeset (item order), in one status or all ('').
	 * Partial approval selects units by these keys (connector core Selection: ids of different types never match).
	 *
	 * @param int    $changeset_id Changeset.
	 * @param string $status       Item status ('' = any).
	 * @return array<int,string>
	 */
	public function unit_keys( int $changeset_id, string $status = '' ): array {
		global $wpdb;
		if ( '' === $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, object_id FROM %i WHERE changeset_id = %d AND object_type IN ( %s, %s ) GROUP BY object_type, object_id ORDER BY MIN(id) ASC', $this->items_table(), $changeset_id, 'product', 'variation' ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, object_id FROM %i WHERE changeset_id = %d AND status = %s AND object_type IN ( %s, %s ) GROUP BY object_type, object_id ORDER BY MIN(id) ASC', $this->items_table(), $changeset_id, $status, 'product', 'variation' ), ARRAY_A );
		}
		return array_map( static fn( array $r ): string => Selection::key( (string) $r['object_type'], (int) $r['object_id'] ), (array) $rows );
	}

	/**
	 * Product/variation ids of some items of a changeset (other items are ignored).
	 *
	 * @param int            $changeset_id Changeset.
	 * @param array<int,int> $item_ids     Item ids.
	 * @return array{objects:array<int,string>,found:int} Unit keys + how many item ids matched a product line.
	 */
	public function objects_of_items( int $changeset_id, array $item_ids ): array {
		global $wpdb;
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( ! $item_ids ) {
			return array(
				'objects' => array(),
				'found'   => 0,
			);
		}
		$in   = implode( ', ', array_fill( 0, count( $item_ids ), '%d' ) );
		$sql  = "SELECT id, object_type, object_id FROM %i WHERE changeset_id = %d AND object_type IN ( 'product', 'variation' ) AND id IN ( {$in} )";
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %d placeholders.
			$wpdb->prepare( $sql, array_merge( array( $this->items_table(), $changeset_id ), $item_ids ) ),
			ARRAY_A
		);
		return array(
			'objects' => array_values( array_unique( array_map( static fn( array $r ): string => Selection::key( (string) $r['object_type'], (int) $r['object_id'] ), (array) $rows ) ) ),
			'found'   => count( (array) $rows ),
		);
	}

	/**
	 * Partial approval: every pending product line whose unit is NOT in $keep becomes
	 * `excluded` (compare-and-swap on `pending`). Returns how many items moved.
	 *
	 * @param int               $changeset_id Changeset.
	 * @param array<int,string> $keep         Typed unit keys to apply (product:12, variation:34, post:5).
	 * @param string            $reason       Reason stored on the item.
	 */
	public function exclude_objects( int $changeset_id, array $keep, string $reason ): int {
		global $wpdb;
		$pairs = array();
		$args  = array();
		foreach ( $keep as $key ) {
			$unit = Selection::parse( (string) $key );
			if ( null === $unit ) {
				continue;
			}
			$args[] = $unit['type'];
			$args[] = $unit['id'];
		}
		if ( ! $args ) {
			return 0;
		}
		// One ( type, id ) placeholder pair per kept unit.
		$kept = implode( ' OR ', array_fill( 0, (int) ( count( $args ) / 2 ), '( object_type = %s AND object_id = %d )' ) );
		$sql  = "UPDATE %i SET status = %s, error = %s WHERE changeset_id = %d AND status = %s AND object_type IN ( 'product', 'variation' ) AND NOT ( {$kept} )";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $kept is a list of (%s, %d) placeholder pairs.
		return (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( array( $this->items_table(), self::ITEM_EXCLUDED, mb_substr( $reason, 0, 255 ), $changeset_id, self::ITEM_PENDING ), $args ) ) );
	}

	/**
	 * Items of some products of a changeset.
	 *
	 * @param int            $changeset_id Changeset.
	 * @param array<int,int> $object_ids   Product ids.
	 * @return array<int,array<string,mixed>>
	 */
	public function items_for_objects( int $changeset_id, array $object_ids ): array {
		global $wpdb;
		$object_ids = array_values( array_filter( array_map( 'intval', $object_ids ) ) );
		if ( ! $object_ids ) {
			return array();
		}
		$in   = implode( ', ', array_fill( 0, count( $object_ids ), '%d' ) );
		$sql  = "SELECT * FROM %i WHERE changeset_id = %d AND object_type IN ( 'product', 'variation' ) AND object_id IN ( {$in} ) ORDER BY id ASC";
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %d placeholders.
			$wpdb->prepare( $sql, array_merge( array( $this->items_table(), $changeset_id ), $object_ids ) ),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate_item' ), (array) $rows );
	}

	/**
	 * Next product ids that still have items in $status.
	 *
	 * @param int    $changeset_id  Changeset.
	 * @param string $status        Item status.
	 * @param int    $limit         Max products.
	 * @param bool   $products_only Leave out the create_term lines (object_type term).
	 * @return array<int,int>
	 */
	public function objects_with_status( int $changeset_id, string $status, int $limit, bool $products_only = false ): array {
		global $wpdb;
		if ( $products_only ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE changeset_id = %d AND status = %s AND object_type IN ( %s, %s ) GROUP BY object_id ORDER BY MIN(id) ASC LIMIT %d', $this->items_table(), $changeset_id, $status, 'product', 'variation', $limit ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE changeset_id = %d AND status = %s GROUP BY object_id ORDER BY MIN(id) ASC LIMIT %d', $this->items_table(), $changeset_id, $status, $limit ) );
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of items of the given object types (any status).
	 *
	 * @param int               $changeset_id Changeset.
	 * @param array<int,string> $types        Object types.
	 */
	public function count_types( int $changeset_id, array $types ): int {
		global $wpdb;
		$types = array_values( array_map( 'strval', $types ) );
		if ( ! $types ) {
			return 0;
		}
		$in  = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT COUNT(*) FROM %i WHERE changeset_id = %d AND object_type IN ( {$in} )";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $this->items_table(), $changeset_id ), $types ) ) );
	}

	/**
	 * Items of one special object type (term / menu), optionally with one status ('' = all).
	 *
	 * @param int    $changeset_id Changeset.
	 * @param string $type         Object type.
	 * @param string $status       Status or ''.
	 * @return array<int,array<string,mixed>>
	 */
	public function typed_items( int $changeset_id, string $type, string $status ): array {
		global $wpdb;
		if ( '' === $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE changeset_id = %d AND object_type = %s ORDER BY id ASC', $this->items_table(), $changeset_id, $type ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE changeset_id = %d AND object_type = %s AND status = %s ORDER BY id ASC', $this->items_table(), $changeset_id, $type, $status ), ARRAY_A );
		}
		return array_map( array( self::class, 'hydrate_item' ), (array) $rows );
	}

	/**
	 * Moves an item from one of $from to $to (compare-and-swap). Returns true when it moved.
	 *
	 * @param int               $item_id Item.
	 * @param array<int,string> $from    Allowed current statuses.
	 * @param string            $to      New status.
	 * @param string            $error   Error/reason text.
	 * @param string            $stamp   applied_at|reverted_at|'' column to stamp.
	 */
	public function move_item( int $item_id, array $from, string $to, string $error = '', string $stamp = '' ): bool {
		global $wpdb;
		$in     = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		$params = array( $this->items_table(), $to, mb_substr( $error, 0, 255 ) );
		$stamps = array();
		if ( in_array( $stamp, array( 'applied_at', 'reverted_at' ), true ) ) {
			$stamps   = array( $stamp );
			$params[] = $stamp;
			$params[] = self::now();
		}
		$params[] = $item_id;
		// Column names go through %i (from the allow-list above); every value is a placeholder.
		$sql = 'UPDATE %i SET status = %s, error = %s' . implode( '', array_fill( 0, count( $stamps ), ', %i = %s' ) ) . ' WHERE id = %d AND status IN ( ' . $in . ' )';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed fragments + placeholders.
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( $params, $from ) ) );
	}

	/**
	 * Records the value WooCommerce actually stored for an applied item.
	 *
	 * @param int    $item_id Item.
	 * @param mixed  $after   Stored value.
	 * @param string $note    Note.
	 */
	public function set_item_after( int $item_id, $after, string $note ): void {
		global $wpdb;
		$wpdb->update(
			$this->items_table(),
			array(
				'after_value' => (string) wp_json_encode( $after ),
				'error'       => mb_substr( $note, 0, 255 ),
			),
			array( 'id' => $item_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

		/**
		 * Moves a changeset from one of $from to $to (compare-and-swap) and sets extra columns.
		 *
		 * @param int                 $id    Changeset.
		 * @param array<int,string>   $from  Allowed current statuses.
		 * @param string              $to    New status.
		 * @param array<string,mixed> $extra Extra columns (approved_at, approved_by, applied_at, …).
		 */
	public function transition( int $id, array $from, string $to, array $extra = array() ): bool {
		global $wpdb;
		$allowed = array( 'approved_at', 'approved_by', 'applied_at', 'applied_by', 'reverted_at', 'reverted_by' );
		$ints    = array();
		$strings = array();
		foreach ( $extra as $column => $value ) {
			if ( in_array( $column, $allowed, true ) ) {
				if ( is_int( $value ) ) {
					array_push( $ints, $column, $value );
				} else {
					array_push( $strings, $column, $value );
				}
			}
		}
		$params = array_merge( array( $this->table(), $to, self::now() ), $ints, $strings, array( $id ) );
		$in     = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		// Column names go through %i (from the allow-list above); every value is a placeholder.
		$sql = 'UPDATE %i SET status = %s, updated_at = %s'
			. implode( '', array_fill( 0, (int) ( count( $ints ) / 2 ), ', %i = %d' ) )
			. implode( '', array_fill( 0, (int) ( count( $strings ) / 2 ), ', %i = %s' ) )
			. ' WHERE id = %d AND status IN ( ' . $in . ' )';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- column names from an allow-list; values are placeholders.
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( $params, $from ) ) );
	}

	/**
	 * Marks every draft past its expiry as expired.
	 *
	 * @param int $id Optional single changeset.
	 */
	public function expire_stale( int $id = 0 ): void {
		global $wpdb;
		if ( $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s AND expires_at <= %s', $this->table(), self::EXPIRED, self::now(), $id, self::DRAFT, self::now() ) );
			return;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE status = %s AND expires_at <= %s', $this->table(), self::EXPIRED, self::now(), self::DRAFT, self::now() ) );
	}

	/**
	 * Recomputes item counts (per status + distinct products) and stores them.
	 *
	 * @param int $id Changeset.
	 * @return array<string,int>
	 */
	public function recount( int $id ): array {
		global $wpdb;
		$counts = array_fill_keys( self::ITEM_STATUSES, 0 );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE changeset_id = %d GROUP BY status', $this->items_table(), $id ), ARRAY_A );
		$total  = 0;
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
			$total                   += (int) $row['n'];
		}
		$counts['items']    = $total;
		$counts['products'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT object_id) FROM %i WHERE changeset_id = %d AND object_type IN ( %s, %s )', $this->items_table(), $id, 'product', 'variation' ) );
		$wpdb->update( $this->table(), array( 'counts' => (string) wp_json_encode( $counts ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return $counts;
	}

	/**
	 * Appends an audit event.
	 *
	 * @param int                 $id    Changeset.
	 * @param array<string,mixed> $event Event (event, user_id, counts…).
	 */
	public function log( int $id, array $event ): void {
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT log FROM %i WHERE id = %d', $this->table(), $id ) );
		$log     = json_decode( (string) $current, true );
		$log     = is_array( $log ) ? $log : array();
		$log[]   = array( 'at' => self::now() ) + $event;
		$wpdb->update( $this->table(), array( 'log' => (string) wp_json_encode( array_slice( $log, -50 ) ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Takes the processing lock (one worker per changeset). Returns true when acquired.
	 *
	 * @param int $id      Changeset.
	 * @param int $seconds Lock lifetime.
	 */
	public function lock( int $id, int $seconds = 120 ): bool {
		global $wpdb;
		$now   = self::now();
		$until = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET lock_until = %s WHERE id = %d AND ( lock_until IS NULL OR lock_until < %s )', $this->table(), $until, $id, $now ) );
	}

	/**
	 * Releases the processing lock.
	 *
	 * @param int $id Changeset.
	 */
	public function unlock( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET lock_until = NULL WHERE id = %d', $this->table(), $id ) );
	}

	/**
	 * Decodes JSON columns.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( array( 'id', 'conversation_id', 'message_id', 'user_id', 'approved_by', 'applied_by', 'reverted_by', 'replanned_from' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}
		foreach ( array( 'params', 'counts', 'log', 'source' ) as $key ) {
			$decoded     = json_decode( (string) ( $row[ $key ] ?? '' ), true );
			$row[ $key ] = is_array( $decoded ) ? $decoded : array();
		}
		return $row;
	}

	/**
	 * Decodes an item row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public static function hydrate_item( array $row ): array {
		foreach ( array( 'id', 'changeset_id', 'object_id', 'parent_id' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['before'] = json_decode( (string) $row['before_value'], true );
		$row['after']  = json_decode( (string) $row['after_value'], true );
		unset( $row['before_value'], $row['after_value'] );
		return $row;
	}
}
