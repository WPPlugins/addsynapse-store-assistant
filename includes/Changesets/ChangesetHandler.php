<?php
/**
 * Extension point of the changeset engine: lines of other object types.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The engine itself knows products, variations, product terms, pages/posts (SEO), menus and page content. Another
 * plugin can add its own line types by returning handlers from the `addsynapse_assistant_changeset_handlers` filter.
 * A handler validates its own lines, says who may approve them, and applies / undoes them; the engine keeps the
 * draft → approve → undo lifecycle, ownership, the audit log and the manager's buttons. Nothing in this plugin ships
 * a handler.
 */
interface ChangesetHandler {

	/**
	 * Object types of the lines this handler owns (never one of the engine's own types).
	 *
	 * @return array<int,string>
	 */
	public function object_types(): array;

	/**
	 * Why the plan items are invalid ('' = valid). Called with ALL items of a plan that has at least one line of
	 * this handler's types.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	public function invalid_items( array $items ): string;

	/**
	 * Extra permission check before approve / undo (null = allowed).
	 *
	 * @param array<string,mixed> $changeset Changeset.
	 * @param int                 $user_id   User.
	 */
	public function denied( array $changeset, int $user_id ): ?WP_Error;

	/**
	 * Whether these lines need WooCommerce (then they are neither applied nor undone while it is inactive).
	 */
	public function needs_woocommerce(): bool;

	/**
	 * Applies this handler's pending lines of a changeset (moves each line to applied / conflict / failed).
	 *
	 * @param int                 $changeset_id Changeset.
	 * @param ChangesetRepository $repo         Repository.
	 */
	public function apply( int $changeset_id, ChangesetRepository $repo ): void;

	/**
	 * Undoes this handler's applied lines of a changeset (moves each line to reverted / revert_conflict).
	 *
	 * @param int                 $changeset_id Changeset.
	 * @param ChangesetRepository $repo         Repository.
	 */
	public function revert( int $changeset_id, ChangesetRepository $repo ): void;
}
