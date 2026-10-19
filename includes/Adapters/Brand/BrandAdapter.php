<?php
/**
 * Brand storage adapter contract.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * An adapter says WHERE a known brand plugin (or a global brand attribute) keeps the brand
 * of a product, and how it is written. Writing itself always goes through the changeset
 * engine (TermFields): plan → preview → Approve → apply → Undo.
 */
interface BrandAdapter {

	/**
	 * Stable key (woocommerce_brands, perfect_brands, yith_brands, attribute).
	 */
	public function key(): string;

	/**
	 * Human name of the storage (plugin name or "Global attribute").
	 */
	public function label(): string;

	/**
	 * The taxonomy holding the brand terms.
	 */
	public function taxonomy(): string;

	/**
	 * Storage kind: taxonomy (product terms) | attribute (global pa_* attribute).
	 */
	public function storage(): string;

	/**
	 * Whether the storage is available right now (plugin active / attribute registered).
	 */
	public function available(): bool;

	/**
	 * Changeset field used to write the brand (terms:{tax} or attribute:{pa_x}).
	 */
	public function field(): string;
}
