<?php
/**
 * "Needs a human" list: pages the assistant was asked about but cannot edit.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Content;

defined( 'ABSPATH' ) || exit;

/**
 * A small, bounded log (option, not autoloaded) shown in Settings so the developer sees which
 * pages use an unsupported builder. One row per page + builder (count + last time). Written only
 * by the plugin itself when a page is described/planned — never by the AI or page text.
 */
final class NeedsHuman {

	public const OPTION = 'addsynapse_assistant_needs_human';

	public const MAX = 50;

	/**
	 * Records (or bumps) a row.
	 *
	 * @param int    $page_id Page.
	 * @param string $builder Builder key/name.
	 * @param string $reason  Reason.
	 */
	public static function log( int $page_id, string $builder, string $reason ): void {
		$rows = self::all();
		$key  = $page_id . ':' . sanitize_key( $builder );
		$row  = $rows[ $key ] ?? array( 'count' => 0 );
		unset( $rows[ $key ] );
		$rows = array(
			$key => array(
				'page_id' => $page_id,
				'title'   => mb_substr( (string) get_the_title( $page_id ), 0, 150 ),
				'builder' => mb_substr( $builder, 0, 60 ),
				'reason'  => mb_substr( $reason, 0, 300 ),
				'count'   => (int) $row['count'] + 1,
				'user_id' => get_current_user_id(),
				'at'      => gmdate( 'Y-m-d H:i:s' ),
			),
		) + $rows;
		update_option( self::OPTION, array_slice( $rows, 0, self::MAX, true ), false );
	}

	/**
	 * Rows, newest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Clears the list.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
