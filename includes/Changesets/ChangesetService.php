<?php
/**
 * Changeset engine: create (draft) → approve+apply → revert.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\ConnectorCore\Changeset\Selection;
use Addsynapse\ConnectorCore\Changeset\Status;
use Addsynapse\ConnectorCore\Changeset\UnitDecision;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rules (see CLAUDE.md → Security rules):
 *  - create() writes NOTHING to products: only the draft rows.
 *  - approve()/reject()/revert() are called ONLY by the REST controller behind UI buttons
 *    (nonce + manage_woocommerce + ownership). They are never abilities/agent tools.
 *  - Apply re-reads every value: if it differs from the recorded `before` the item is a
 *    `conflict` and is skipped (never overwritten). Revert restores `before` only where the
 *    value still equals `after` (else `revert_conflict`).
 *  - One product = one unit: its items are checked together and written with ONE save(), so a
 *    product is never left half-changed. Items move with compare-and-swap status updates and
 *    "already in the target state" counts as done → retries/restarts never double-apply.
 *  - Up to Config::changeset_sync_max() items run synchronously; bigger changesets run in
 *    Action Scheduler batches of Config::changeset_batch_size() products.
 *
 * Multi-platform (2026-09-26): the platform-neutral rules live in connector core
 * (Addsynapse\ConnectorCore\Changeset: Status lifecycle + final status, UnitDecision conflict detection,
 * Selection of a partial approval; ChangesetEngine = the reference engine for new platforms). This
 * WordPress engine uses those pieces; its WooCommerce specifics (product fields, Action Scheduler batches,
 * capability checks) stay here. Other line types come only from extensions (ChangesetHandler).
 */
class ChangesetService {

	public const HOOK  = 'addsynapse_assistant_changeset_process';
	public const GROUP = 'addsynapse-assistant';

	public const OBJECT_TYPES = array( 'product', 'variation' );

	/**
	 * Repository.
	 *
	 * @var ChangesetRepository
	 */
	private ChangesetRepository $repo;

	/**
	 * Constructor.
	 *
	 * @param ChangesetRepository|null $repo Repository.
	 */
	public function __construct( ?ChangesetRepository $repo = null ) {
		$this->repo = $repo ?? new ChangesetRepository();
	}

	/**
	 * Hooks the background worker.
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'run_job' ) );
	}

	/**
	 * Action Scheduler / cron entry point.
	 *
	 * @param mixed $changeset_id Changeset id.
	 */
	public static function run_job( $changeset_id ): void {
		( new self() )->process( (int) $changeset_id, false );
	}

	/**
	 * Repository accessor.
	 */
	public function repository(): ChangesetRepository {
		return $this->repo;
	}

	/**
	 * Creates a DRAFT changeset. Writes nothing to products.
	 *
	 * @param array<string,mixed> $plan user_id, conversation_id, operation, summary, params, items[].
	 * @return int|WP_Error Changeset id.
	 */
	public function create( array $plan ) {
		$items = array_values( (array) ( $plan['items'] ?? array() ) );
		if ( ! $items ) {
			return new WP_Error( 'addsynapse_assistant_changeset_empty', 'Nothing to change.' );
		}
		if ( count( $items ) > Config::changeset_max_items() ) {
			return new WP_Error( 'addsynapse_assistant_changeset_too_large', sprintf( 'Too many changes (%1$d). The limit is %2$d per plan: narrow the selection.', count( $items ), Config::changeset_max_items() ) );
		}
		$invalid = self::invalid_items( $items );
		if ( $invalid ) {
			return new WP_Error( 'addsynapse_assistant_changeset_invalid', $invalid );
		}
		$user_id = (int) ( $plan['user_id'] ?? 0 );
		if ( $user_id < 1 ) {
			return new WP_Error( 'addsynapse_assistant_changeset_invalid', 'A changeset needs an owner.' );
		}
		$call   = ToolRegistry::current_call();
		$params = is_array( $plan['params'] ?? null ) ? $plan['params'] : array();
		$from   = (int) ( $plan['replanned_from'] ?? $call['replanned_from'] );
		if ( $from > 0 ) {
			// «Επανάληψη» of a partial approval: the products the manager left out start unticked
			// in the new card (still their choice — nothing is excluded silently).
			$left_out = $this->repo->unit_keys( $from, ChangesetRepository::ITEM_EXCLUDED );
			if ( $left_out ) {
				$params['deselected'] = array_slice( $left_out, 0, Config::changeset_max_items() );
			}
		}
		$id = $this->repo->insert(
			array(
				'user_id'         => $user_id,
				'source'          => $plan['source'] ?? $call['source'],
				'replanned_from'  => (int) ( $plan['replanned_from'] ?? $call['replanned_from'] ),
				'conversation_id' => (int) ( $plan['conversation_id'] ?? 0 ),
				'operation'       => (string) $plan['operation'],
				'summary'         => (string) ( $plan['summary'] ?? '' ),
				'params'          => $params,
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + Config::changeset_ttl_hours() * HOUR_IN_SECONDS ),
			),
			$items
		);
		if ( ! $id ) {
			return new WP_Error( 'addsynapse_assistant_db', 'Could not store the plan.' );
		}
		$this->repo->log(
			$id,
			array(
				'event'   => 'created',
				'user_id' => $user_id,
				'items'   => count( $items ),
			) + ( $call['replanned_from'] || ! empty( $plan['replanned_from'] ) ? array( 'replanned_from' => (int) ( $plan['replanned_from'] ?? $call['replanned_from'] ) ) : array() )
		);
		/**
		 * Fires after a draft changeset was stored (e.g. links images uploaded in the chat).
		 *
		 * @param int                            $id     Changeset id.
		 * @param array<string,mixed>            $params Plan params.
		 * @param array<int,array<string,mixed>> $items  Items as planned.
		 */
		do_action( 'addsynapse_assistant_changeset_created', (int) $id, $params, $items );
		return $id;
	}

	/**
	 * Loads a changeset for its owner (expiring it lazily). Null = not found / not theirs.
	 *
	 * @param int $id      Changeset.
	 * @param int $user_id Current user.
	 * @return array<string,mixed>|null
	 */
	public function get_for_user( int $id, int $user_id ): ?array {
		$this->repo->expire_stale( $id );
		return $this->repo->find_for_user( $id, $user_id );
	}

	/**
	 * Approves a draft and applies it (synchronously, or queues the background job).
	 *
	 * Partial approval: with $object_ids (typed unit keys of this changeset: product:12, variation:34, post:5 — an
	 * untyped id = the product with that id only, connector core Selection) or $item_ids
	 * (its product lines → their whole products: one product stays one unit) only those
	 * products are applied; every other product line becomes `excluded` (never applied, never
	 * reverted), and a create_term / create_attribute line that no applied line needs any more
	 * is excluded too. Ids that are not part of the changeset → 422, nothing happens.
	 *
	 * @param int                        $id         Changeset.
	 * @param int                        $user_id    The approving user (must own it and have manage_woocommerce).
	 * @param array<int,int|string>|null $object_ids Units to apply (null = all).
	 * @param array<int,int>|null        $item_ids   Items whose products to apply (null = all).
	 * @param array<string,scalar>       $log_extra  Extra keys of the `approved` log event.
	 * @return array<string,mixed>|WP_Error Fresh changeset row.
	 */
	public function approve( int $id, int $user_id, ?array $object_ids = null, ?array $item_ids = null, array $log_extra = array() ) {
		$changeset = $this->owned( $id, $user_id );
		if ( is_wp_error( $changeset ) ) {
			return $changeset;
		}
		$denied = $this->handler_denied( $changeset, $user_id ) ?? $this->woocommerce_denied( $changeset );
		if ( $denied ) {
			return $denied;
		}
		if ( ChangesetRepository::EXPIRED === $changeset['status'] ) {
			return new WP_Error( 'addsynapse_assistant_changeset_expired', __( 'This preview has expired. Ask again to create a new one.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
		}
		if ( ChangesetRepository::DRAFT !== $changeset['status'] ) {
			return self::state_error();
		}
		$keep = $this->selected_objects( $changeset, $object_ids, $item_ids );
		if ( is_wp_error( $keep ) ) {
			return $keep;
		}
		// The processing lock keeps a polling worker away until the exclusions are recorded.
		if ( null !== $keep && ! $this->repo->lock( $id, 60 ) ) {
			return self::state_error();
		}
		$excluded = 0;
		try {
			$ok = $this->repo->transition(
				$id,
				array( ChangesetRepository::DRAFT ),
				ChangesetRepository::APPLYING,
				array(
					'approved_at' => ChangesetRepository::now(),
					'approved_by' => $user_id,
				)
			);
			if ( ! $ok ) {
				return self::state_error();
			}
			if ( null !== $keep ) {
				$excluded = $this->repo->exclude_objects( $id, $keep, 'Not approved: left out by the manager.' );
			}
		} finally {
			if ( null !== $keep ) {
				$this->repo->unlock( $id );
			}
		}//end try
		$counts = $this->repo->recount( $id );
		$this->repo->log(
			$id,
			array(
				'event'   => 'approved',
				'user_id' => $user_id,
				'items'   => (int) $counts['items'] - $excluded,
			) + ( $excluded ? array( 'excluded' => $excluded ) : array() ) + self::log_extra( $log_extra )
		);
		$this->start( $id, (int) $counts[ ChangesetRepository::ITEM_PENDING ] );
		return $this->repo->find( $id );
	}

	/**
	 * The products a (partial) approval applies: null = all of them.
	 *
	 * @param array<string,mixed>        $changeset  Changeset.
	 * @param array<int,int|string>|null $object_ids Typed unit keys (untyped id = product only).
	 * @param array<int,int>|null        $item_ids   Item ids.
	 * @return array<int,string>|null|WP_Error Unit keys.
	 */
	private function selected_objects( array $changeset, ?array $object_ids, ?array $item_ids ) {
		if ( null === $object_ids && null === $item_ids ) {
			return null;
		}
		$id      = (int) $changeset['id'];
		$handled = self::handled_types();
		if ( $handled && $this->repo->count_types( $id, $handled ) > 0 ) {
			// Lines of an extension's own types depend on each other: approved as a whole.
			return new WP_Error( 'addsynapse_assistant_changeset_partial', __( 'This change is approved as a whole.', 'addsynapse-store-assistant' ), array( 'status' => 422 ) );
		}
		$units    = null;
		$items_ok = true;
		if ( null !== $item_ids ) {
			$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
			$found    = $this->repo->objects_of_items( $id, $item_ids );
			$items_ok = count( $item_ids ) === $found['found'];
			$units    = $found['objects'];
		}
		// Connector core: every id must belong to the changeset, none → 400, all → a normal approval.
		$resolved = Selection::resolve( $this->repo->unit_keys( $id ), $object_ids, $units, $items_ok );
		if ( Selection::NOTHING_SELECTED === $resolved['error'] ) {
			return new WP_Error( 'addsynapse_assistant_changeset_nothing_selected', __( 'Select at least one product to approve.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		if ( '' !== $resolved['error'] ) {
			return self::foreign_selection();
		}
		return $resolved['keep'];
	}

	/**
	 * 422: ids that do not belong to the changeset.
	 */
	private static function foreign_selection(): WP_Error {
		return new WP_Error( 'addsynapse_assistant_changeset_selection', __( 'Some selected products are not part of this change set. Reload and try again.', 'addsynapse-store-assistant' ), array( 'status' => 422 ) );
	}

	/**
	 * Rejects a draft (nothing was ever written).
	 *
	 * @param int $id      Changeset.
	 * @param int $user_id Owner.
	 * @return array<string,mixed>|WP_Error
	 */
	public function reject( int $id, int $user_id ) {
		$changeset = $this->owned( $id, $user_id );
		if ( is_wp_error( $changeset ) ) {
			return $changeset;
		}
		if ( ! $this->repo->transition( $id, array( ChangesetRepository::DRAFT ), ChangesetRepository::REJECTED ) ) {
			return self::state_error();
		}
		$this->repo->log(
			$id,
			array(
				'event'   => 'rejected',
				'user_id' => $user_id,
			)
		);
		/**
		 * Fires after a draft was rejected (nothing was written).
		 *
		 * @param int $id Changeset id.
		 */
		do_action( 'addsynapse_assistant_changeset_rejected', $id );
		return $this->repo->find( $id );
	}

	/**
	 * Reverts an applied changeset (restores `before` where nothing changed since).
	 *
	 * @param int                  $id        Changeset.
	 * @param int                  $user_id   Owner.
	 * @param array<string,scalar> $log_extra Extra keys of the `revert_requested` log event.
	 * @return array<string,mixed>|WP_Error
	 */
	public function revert( int $id, int $user_id, array $log_extra = array() ) {
		$changeset = $this->owned( $id, $user_id );
		if ( is_wp_error( $changeset ) ) {
			return $changeset;
		}
		$denied = $this->handler_denied( $changeset, $user_id ) ?? $this->woocommerce_denied( $changeset );
		if ( $denied ) {
			return $denied;
		}
		$ok = $this->repo->transition(
			$id,
			array( ChangesetRepository::APPLIED, ChangesetRepository::PARTIALLY_APPLIED ),
			ChangesetRepository::REVERTING,
			array( 'reverted_by' => $user_id )
		);
		if ( ! $ok ) {
			return self::state_error();
		}
		$this->repo->log(
			$id,
			array(
				'event'   => 'revert_requested',
				'user_id' => $user_id,
			) + self::log_extra( $log_extra )
		);
		$this->start( $id, (int) ( $changeset['counts'][ ChangesetRepository::ITEM_APPLIED ] ?? 0 ) );
		return $this->repo->find( $id );
	}

	/**
	 * Extra log keys (scalars, lowercase keys, never overriding the event's own keys).
	 *
	 * @param array<string,mixed> $extra Extra.
	 * @return array<string,scalar>
	 */
	private static function log_extra( array $extra ): array {
		$out = array();
		foreach ( $extra as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^[a-z_]{1,32}$/D', $key ) && is_scalar( $value ) && ! in_array( $key, array( 'event', 'user_id', 'items', 'excluded', 'at' ), true ) ) {
				$out[ $key ] = is_string( $value ) ? mb_substr( $value, 0, 200 ) : $value;
			}
		}
		return $out;
	}

	/**
	 * Runs synchronously (small) or queues the first background batch (large).
	 *
	 * @param int $id    Changeset.
	 * @param int $items Number of items involved.
	 */
	private function start( int $id, int $items ): void {
		if ( $items > Config::changeset_sync_max() ) {
			self::enqueue( $id );
			return;
		}
		$this->process( $id, true );
	}

	/**
	 * Called when the owner's UI polls an in-progress changeset: runs one batch right away
	 * when no worker holds the lock (so progress does not wait for WP-Cron to spawn Action
	 * Scheduler), and re-queues the background job if nothing is scheduled (worker died).
	 * Safe to overlap with Action Scheduler: one lock per changeset + per-item CAS.
	 *
	 * @param array<string,mixed> $changeset Changeset row.
	 * @return array<string,mixed> Fresh row.
	 */
	public function ensure_progress( array $changeset ): array {
		if ( ! in_array( $changeset['status'], array( ChangesetRepository::APPLYING, ChangesetRepository::REVERTING ), true ) ) {
			return $changeset;
		}
		$id = (int) $changeset['id'];
		$this->process( $id, false, false );
		$fresh = $this->repo->find( $id );
		if ( $fresh && in_array( $fresh['status'], array( ChangesetRepository::APPLYING, ChangesetRepository::REVERTING ), true ) && ! self::is_scheduled( $id ) ) {
			self::enqueue( $id );
		}
		return $fresh ? $fresh : $changeset;
	}

	/**
	 * Processes an applying/reverting changeset: everything ($all) or one batch.
	 *
	 * @param int  $id         Changeset.
	 * @param bool $all        Process every batch now (synchronous path).
	 * @param bool $reschedule Queue the next background batch when more remain (jobs do; the
	 *                         UI-poll nudge does not, so jobs are never duplicated).
	 */
	public function process( int $id, bool $all, bool $reschedule = true ): void {
		if ( ! $this->repo->lock( $id, 300 ) ) {
			return;
			// Another worker is on it.
		}
		try {
			do {
				$changeset = $this->repo->find( $id );
				if ( ! $changeset ) {
					return;
				}
				$reverting = ChangesetRepository::REVERTING === $changeset['status'];
				if ( ! $reverting && ChangesetRepository::APPLYING !== $changeset['status'] ) {
					return;
				}
				if ( null !== $this->woocommerce_denied( $changeset ) ) {
					// WooCommerce was deactivated mid-run: pause (resumes when it is back).
					return;
				}
				$source = $reverting ? ChangesetRepository::ITEM_APPLIED : ChangesetRepository::ITEM_PENDING;
				if ( ! $reverting ) {
					$this->apply_handled( $id );
				}
				$objects = $this->repo->objects_with_status( $id, $source, Config::changeset_batch_size(), true );
				if ( ! $objects ) {
					if ( $reverting ) {
						$this->revert_after_products( $id );
					}
					$this->finish( $changeset, $reverting );
					return;
				}
				$items_by_object = array();
				foreach ( $this->repo->items_for_objects( $id, $objects ) as $item ) {
					if ( $source === $item['status'] ) {
						$items_by_object[ $item['object_id'] ][] = $item;
					}
				}
				foreach ( $items_by_object as $object_id => $items ) {
					if ( $reverting ) {
						$this->revert_object( (int) $object_id, $items );
					} else {
						$this->apply_object( (int) $object_id, $items );
					}
				}
				$this->repo->recount( $id );
			} while ( $all );
			// Background: finish now, or queue the next batch as a new job.
			if ( $this->repo->objects_with_status( $id, $source, 1, true ) ) {
				if ( $reschedule ) {
					self::enqueue( $id );
				}
			} else {
				if ( $reverting ) {
					$this->revert_after_products( $id );
				}
				$this->finish( $this->repo->find( $id ), $reverting );
			}
		} finally {
			$this->repo->unlock( $id );
		}//end try
	}

	/**
	 * Applies the pending items of one product (all-or-nothing per product).
	 *
	 * @param int                            $object_id Product/variation id.
	 * @param array<int,array<string,mixed>> $items     Pending items.
	 */
	private function apply_object( int $object_id, array $items ): void {
		$pending = array( ChangesetRepository::ITEM_PENDING );
		$product = self::load_object( $object_id, $items );
		if ( ! $product ) {
			foreach ( $items as $item ) {
				$this->repo->move_item( $item['id'], $pending, ChangesetRepository::ITEM_FAILED, self::not_found_reason( $items ) );
			}
			return;
		}
		foreach ( $items as $item ) {
			// Custom fields: the key must STILL be allowed now (hard deny, the manager's allow-list,
			// the ACF field) — a key disabled after the preview is never written. SEO fields: the
			// same SEO plugin must still be active.
			$denied = Fields::apply_denial( (string) $item['field'], $item['after'], $object_id );
			if ( '' !== $denied ) {
				foreach ( $items as $failed ) {
					$this->repo->move_item( $failed['id'], $pending, ChangesetRepository::ITEM_FAILED, $denied );
				}
				return;
			}
		}
		// One product = one unit (connector core): current == after → already done (e.g. a retried batch),
		// == before → write, else conflict and the WHOLE product is skipped.
		$decision  = self::decide( $product, $items, UnitDecision::APPLY );
		$write     = $decision['write'];
		$done      = $decision['done'];
		$conflicts = $decision['conflicts'];
		if ( $conflicts ) {
			// Someone changed this product since the preview: skip the whole product.
			foreach ( $items as $item ) {
				$reason = isset( $conflicts[ $item['id'] ] )
					? 'Changed since the preview (now: ' . Fields::show( $item['field'], $conflicts[ $item['id'] ] ) . ').'
					: 'Skipped: another field of this product changed since the preview.';
				$this->repo->move_item( $item['id'], $pending, ChangesetRepository::ITEM_CONFLICT, $reason );
			}
			return;
		}
		if ( $write && ! $this->save( $product, $write, 'after' ) ) {
			return;
		}
		foreach ( array_merge( $done, $write ) as $item ) {
			$this->repo->move_item( $item['id'], $pending, ChangesetRepository::ITEM_APPLIED, '', 'applied_at' );
		}
		if ( $write ) {
			$this->verify( $object_id, $write, 'after' );
		}
	}

	/**
	 * Restores the applied items of one product (all-or-nothing per product).
	 *
	 * @param int                            $object_id Product/variation id.
	 * @param array<int,array<string,mixed>> $items     Applied items.
	 */
	private function revert_object( int $object_id, array $items ): void {
		$applied = array( ChangesetRepository::ITEM_APPLIED );
		$product = self::load_object( $object_id, $items );
		if ( ! $product ) {
			foreach ( $items as $item ) {
				$this->repo->move_item( $item['id'], $applied, ChangesetRepository::ITEM_REVERT_CONFLICT, self::not_found_reason( $items ) );
			}
			return;
		}
		$decision  = self::decide( $product, $items, UnitDecision::REVERT );
		$write     = $decision['write'];
		$done      = $decision['done'];
		$conflicts = $decision['conflicts'];
		if ( $conflicts ) {
			foreach ( $items as $item ) {
				$reason = isset( $conflicts[ $item['id'] ] )
					? 'Changed after the update (now: ' . Fields::show( $item['field'], $conflicts[ $item['id'] ] ) . '); not restored.'
					: 'Not restored: another field of this product changed after the update.';
				$this->repo->move_item( $item['id'], $applied, ChangesetRepository::ITEM_REVERT_CONFLICT, $reason );
			}
			return;
		}
		if ( $write && ! $this->save( $product, $write, 'before' ) ) {
			return;
		}
		foreach ( array_merge( $done, $write ) as $item ) {
			$this->repo->move_item( $item['id'], $applied, ChangesetRepository::ITEM_REVERTED, '', 'reverted_at' );
		}
	}

	/**
	 * Conflict detection of one product (connector core UnitDecision) with the plugin's field reader/comparison.
	 *
	 * @param \WC_Product                    $product Product.
	 * @param array<int,array<string,mixed>> $items   Items of that product.
	 * @param string                         $mode    UnitDecision::APPLY|REVERT.
	 * @return array{write:array<int,array<string,mixed>>,done:array<int,array<string,mixed>>,conflicts:array<int|string,mixed>}
	 */
	private static function decide( $product, array $items, string $mode ): array {
		return UnitDecision::decide(
			$items,
			$mode,
			static function ( string $field ) use ( $product ) {
				return Fields::read( $product, $field );
			},
			static function ( string $field, $a, $b ): bool {
				return Fields::same( $field, $a, $b );
			}
		);
	}

	/**
	 * Writes the given side (before|after) of items into the product with ONE save.
	 * On failure the items are marked failed (apply) / revert_conflict (revert).
	 *
	 * @param \WC_Product                    $product Product.
	 * @param array<int,array<string,mixed>> $items   Items.
	 * @param string                         $side    before|after.
	 */
	private function save( $product, array $items, string $side ): bool {
		usort(
			$items,
			static function ( $a, $b ) {
				return Fields::order( $a['field'] ) <=> Fields::order( $b['field'] );
			}
		);
		try {
			foreach ( $items as $item ) {
				Fields::write( $product, $item['field'], $item[ $side ] );
			}
			$product->save();
			foreach ( $items as $item ) {
				// A gallery written as "absent" leaves no empty meta row behind (exact undo).
				CoreFields::purge_absent( $product, (string) $item['field'], $item[ $side ] );
			}
			return true;
		} catch ( \Throwable $e ) {
			$from = 'after' === $side ? array( ChangesetRepository::ITEM_PENDING ) : array( ChangesetRepository::ITEM_APPLIED );
			$to   = 'after' === $side ? ChangesetRepository::ITEM_FAILED : ChangesetRepository::ITEM_REVERT_CONFLICT;
			foreach ( $items as $item ) {
				$this->repo->move_item( $item['id'], $from, $to, 'WooCommerce refused the change: ' . $e->getMessage() );
			}
			return false;
		}//end try
	}

	/**
	 * After a save, records what WooCommerce actually stored when it adjusted a value
	 * itself (e.g. the stock status it derives), so a later revert compares correctly.
	 *
	 * @param int                            $object_id Product id.
	 * @param array<int,array<string,mixed>> $items     Written items.
	 * @param string                         $side      after.
	 */
	private function verify( int $object_id, array $items, string $side ): void {
		$fresh = self::load_object( $object_id, $items );
		if ( ! $fresh ) {
			return;
		}
		foreach ( $items as $item ) {
			$actual = Fields::read( $fresh, $item['field'] );
			if ( ! Fields::same( $item['field'], $actual, $item[ $side ] ) ) {
				$this->repo->set_item_after( $item['id'], $actual, 'WooCommerce stored ' . Fields::show( $item['field'], $actual ) . '.' );
			}
		}
	}

	/**
	 * Final status + audit entry.
	 *
	 * @param array<string,mixed> $changeset Changeset.
	 * @param bool                $reverting Revert run.
	 */
	private function finish( array $changeset, bool $reverting ): void {
		$id     = (int) $changeset['id'];
		$counts = $this->repo->recount( $id );
		if ( $reverting ) {
			$this->repo->transition(
				$id,
				array( ChangesetRepository::REVERTING ),
				ChangesetRepository::REVERTED,
				array( 'reverted_at' => ChangesetRepository::now() )
			);
			$this->repo->log(
				$id,
				array(
					'event'           => 'reverted',
					'user_id'         => (int) $changeset['reverted_by'],
					'reverted'        => $counts[ ChangesetRepository::ITEM_REVERTED ],
					'revert_conflict' => $counts[ ChangesetRepository::ITEM_REVERT_CONFLICT ],
				)
			);
			/**
			 * Fires after a changeset was undone.
			 *
			 * @param int               $id     Changeset id.
			 * @param array<string,int> $counts Item counts.
			 */
			do_action( 'addsynapse_assistant_changeset_reverted', $id, $counts );
			return;
		}//end if
		// Nothing applied → failed; some conflict/failed → partially_applied; else applied (excluded ≠ problem).
		$status = Status::finalApplyStatus( $counts );
		$this->repo->transition(
			$id,
			array( ChangesetRepository::APPLYING ),
			$status,
			array(
				'applied_at' => ChangesetRepository::now(),
				'applied_by' => (int) $changeset['approved_by'],
			)
		);
		$this->repo->log(
			$id,
			array(
				'event'    => 'applied',
				'user_id'  => (int) $changeset['approved_by'],
				'status'   => $status,
				'applied'  => $counts[ ChangesetRepository::ITEM_APPLIED ],
				'conflict' => $counts[ ChangesetRepository::ITEM_CONFLICT ],
				'failed'   => $counts[ ChangesetRepository::ITEM_FAILED ],
			) + ( $counts[ ChangesetRepository::ITEM_EXCLUDED ] ? array( 'excluded' => $counts[ ChangesetRepository::ITEM_EXCLUDED ] ) : array() )
		);
		/**
		 * Fires after a changeset was applied (audit hook).
		 *
		 * @param int                $id     Changeset id.
		 * @param string             $status Final status.
		 * @param array<string,int>  $counts Item counts.
		 */
		do_action( 'addsynapse_assistant_changeset_applied', $id, $status, $counts );
	}

	/**
	 * Owner + capability check. Not found / not theirs → 404.
	 *
	 * @param int $id      Changeset.
	 * @param int $user_id User.
	 * @return array<string,mixed>|WP_Error
	 */
	private function owned( int $id, int $user_id ) {
		if ( $user_id < 1 || ! user_can( $user_id, Config::capability() ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to use Store Assistant.', 'addsynapse-store-assistant' ), array( 'status' => 403 ) );
		}
		$changeset = $this->get_for_user( $id, $user_id );
		if ( ! $changeset ) {
			return self::not_found();
		}
		return $changeset;
	}

	/**
	 * 404 error.
	 */
	public static function not_found(): WP_Error {
		return new WP_Error( 'addsynapse_assistant_changeset_not_found', __( 'Change set not found.', 'addsynapse-store-assistant' ), array( 'status' => 404 ) );
	}

	/**
	 * 409 wrong-state error.
	 */
	private static function state_error(): WP_Error {
		return new WP_Error( 'addsynapse_assistant_changeset_state', __( 'This change set cannot do that in its current state. Reload to see its status.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
	}

	/**
	 * Queues one background batch.
	 *
	 * @param int $id Changeset.
	 */
	public static function enqueue( int $id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $id ), self::GROUP );
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, array( $id ) );
	}

	/**
	 * Whether a batch is already queued.
	 *
	 * @param int $id Changeset.
	 */
	public static function is_scheduled( int $id ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( self::HOOK, array( $id ), self::GROUP );
		}
		return (bool) wp_next_scheduled( self::HOOK, array( $id ) );
	}

	/**
	 * Why a list of plan items is invalid ('' = valid). Items are product lines (product/variation id ≥ 1, allowed
	 * field; term fields only on parent products), or lines of an extension's own types (ChangesetHandler).
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	public static function invalid_items( array $items ): string {
		foreach ( self::handlers() as $handler ) {
			$types = $handler->object_types();
			foreach ( $items as $item ) {
				if ( in_array( (string) ( $item['object_type'] ?? '' ), $types, true ) ) {
					return $handler->invalid_items( $items );
				}
			}
		}
		foreach ( $items as $item ) {
			$field = (string) ( $item['field'] ?? '' );
			$kind  = TermFields::kind( $field );
			if ( ! Fields::allowed( $field ) || ! in_array( $item['object_type'] ?? '', self::OBJECT_TYPES, true ) || (int) ( $item['object_id'] ?? 0 ) < 1 ) {
				return 'Invalid changeset item.';
			}
			if ( SeoFields::is_field( $field ) ) {
				// SEO texts live on the product itself; a variation has only its own description.
				$on_variation = 'variation' === $item['object_type'] && 'description' === $field;
				if ( ( 'product' !== $item['object_type'] && ! $on_variation ) || ! SeoFields::valid_value( $field, $item['after'] ?? null ) || ! SeoFields::valid_value( $field, $item['before'] ?? null ) ) {
					return 'Invalid SEO change.';
				}
			}
			if ( CoreFields::is_field( $field ) ) {
				if ( ! CoreFields::on( $field, (string) $item['object_type'] ) || ! CoreFields::valid_value( $field, $item['after'] ?? null ) || ! CoreFields::valid_value( $field, $item['before'] ?? null ) ) {
					return 'Invalid product field change.';
				}
			}
			if ( '' !== $kind ) {
				if ( 'product' !== $item['object_type'] || ! TermFields::valid_value( $field, $item['after'] ?? null, array() ) || ! TermFields::valid_value( $field, $item['before'] ?? null, array() ) ) {
					return 'Invalid term change.';
				}
			}
			if ( Fields::same( $field, $item['before'] ?? null, $item['after'] ?? null ) ) {
				return 'A changeset item does not change anything.';
			}
		}//end foreach
		return '';
	}

	/**
	 * The product or variation of a group of product lines.
	 *
	 * @param int                            $object_id Object id.
	 * @param array<int,array<string,mixed>> $items     Its items (same object_type).
	 * @return \WC_Product|null
	 */
	private static function load_object( int $object_id, array $items ) {
		unset( $items );
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $object_id );
		return $product ? $product : null;
	}

	/**
	 * Reason when the object of a group is gone.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	private static function not_found_reason( array $items ): string {
		unset( $items );
		return function_exists( 'wc_get_product' ) ? 'Product not found (deleted?).' : 'WooCommerce is not active: product changes cannot be applied or undone now.';
	}

	/**
	 * Product lines (products, variations) need WooCommerce: with
	 * WooCommerce deactivated they are neither applied nor undone (nothing half-written) — the
	 * card says why, and the same buttons work again once WooCommerce is back.
	 *
	 * @param array<string,mixed> $changeset Changeset.
	 */
	private function woocommerce_denied( array $changeset ): ?WP_Error {
		if ( Environment::has_woocommerce() ) {
			return null;
		}
		$types = self::OBJECT_TYPES;
		foreach ( self::handlers() as $handler ) {
			if ( $handler->needs_woocommerce() ) {
				$types = array_merge( $types, $handler->object_types() );
			}
		}
		if ( $this->repo->count_types( (int) $changeset['id'], $types ) > 0 ) {
			return new WP_Error( 'addsynapse_assistant_woocommerce_inactive', __( 'WooCommerce is not active: product changes cannot be applied or undone now. Activate WooCommerce and try again.', 'addsynapse-store-assistant' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/**
	 * Undo after the product lines: the extensions' own lines.
	 *
	 * @param int $id Changeset.
	 */
	private function revert_after_products( int $id ): void {
		foreach ( self::handlers() as $handler ) {
			$handler->revert( $id, $this->repo );
		}
	}

	/**
	 * Applies the extensions' own pending lines (before the product lines).
	 *
	 * @param int $id Changeset.
	 */
	private function apply_handled( int $id ): void {
		foreach ( self::handlers() as $handler ) {
			$handler->apply( $id, $this->repo );
		}
	}

	/**
	 * The extensions' permission checks.
	 *
	 * @param array<string,mixed> $changeset Changeset.
	 * @param int                 $user_id   User.
	 */
	private function handler_denied( array $changeset, int $user_id ): ?WP_Error {
		foreach ( self::handlers() as $handler ) {
			if ( $this->repo->count_types( (int) $changeset['id'], $handler->object_types() ) > 0 ) {
				$denied = $handler->denied( $changeset, $user_id );
				if ( $denied ) {
					return $denied;
				}
			}
		}
		return null;
	}

	/**
	 * Object types the extensions own.
	 *
	 * @return array<int,string>
	 */
	private static function handled_types(): array {
		$types = array();
		foreach ( self::handlers() as $handler ) {
			$types = array_merge( $types, $handler->object_types() );
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Extension point: handlers of other line types (see ChangesetHandler). The engine's own object types can never
	 * be taken over.
	 *
	 * @return array<int,ChangesetHandler>
	 */
	public static function handlers(): array {
		/**
		 * Handlers of extra changeset line types.
		 *
		 * @param array<int,ChangesetHandler> $handlers Handlers.
		 */
		$handlers = apply_filters( 'addsynapse_assistant_changeset_handlers', array() );
		$own      = self::OBJECT_TYPES;
		$out      = array();
		foreach ( is_array( $handlers ) ? $handlers : array() as $handler ) {
			if ( $handler instanceof ChangesetHandler && ! array_intersect( $handler->object_types(), $own ) ) {
				$out[] = $handler;
			}
		}
		return $out;
	}
}
