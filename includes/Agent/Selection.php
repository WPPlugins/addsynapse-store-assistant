<?php
/**
 * Product selection sent with a chat message (checkboxes in the result tables).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * The selection is structured context, never text: it is stored on the user message as a
 * `selection` block (after the text block), shown under the bubble, sent to the AI as a DATA
 * block of that turn (Byok\DirectClient) and expands
 * `@selection` in slash commands (e.g. `/price ids=@selection +5%`).
 *
 * Only positive integer ids travel; nothing else from the browser.
 */
final class Selection {

	public const MAX = 500;

	public const BLOCK = 'selection';

	/**
	 * Unique positive ints, in order, at most MAX (anything else is dropped).
	 *
	 * @param mixed $raw `{product_ids: []}` or a plain list.
	 * @return array<int,int>
	 */
	public static function sanitize( $raw ): array {
		if ( is_array( $raw ) && array_key_exists( 'product_ids', $raw ) ) {
			$raw = $raw['product_ids'];
		}
		$ids = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			if ( ( is_int( $id ) || ( is_string( $id ) && preg_match( '/^\d{1,18}$/', $id ) ) ) && (int) $id > 0 ) {
				$ids[ (int) $id ] = true;
			}
			if ( count( $ids ) >= self::MAX ) {
				break;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Stored block for a user message ([] when there is no selection).
	 *
	 * @param array<int,int> $ids Ids.
	 * @return array<string,mixed>
	 */
	public static function block( array $ids ): array {
		$ids = self::sanitize( $ids );
		if ( ! $ids ) {
			return array();
		}
		return array(
			'type'        => self::BLOCK,
			'product_ids' => $ids,
		);
	}

	/**
	 * The selection stored on a message's blocks ([] = none).
	 *
	 * @param array<int,mixed> $blocks Content blocks.
	 * @return array<int,int>
	 */
	public static function from_blocks( array $blocks ): array {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && self::BLOCK === ( $block['type'] ?? '' ) ) {
				return self::sanitize( $block['product_ids'] ?? array() );
			}
		}
		return array();
	}
}
