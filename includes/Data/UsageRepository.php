<?php
/**
 * Token / cost usage log.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Data;

use Addsynapse\StoreAssistant\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables, no WP API exists for them.

/**
 * Writes one row per model call; aggregates for budgets.
 */
class UsageRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'usage_log' );
	}

	/**
	 * Logs one model call.
	 *
	 * @param array<string,mixed> $row user_id, conversation_id, message_id, purpose, model, usage (API usage object).
	 * @return float Cost in USD.
	 */
	public function log( array $row ): float {
		global $wpdb;
		$usage       = (array) ( $row['usage'] ?? array() );
		$model       = (string) ( $row['model'] ?? '' );
		$in          = (int) ( $usage['input_tokens'] ?? 0 );
		$out         = (int) ( $usage['output_tokens'] ?? 0 );
		$cache_read  = (int) ( $usage['cache_read_input_tokens'] ?? 0 );
		$cache_write = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
		// Stored cost only when the usage carries one; own-key usage is estimated from the tokens (Byok\Usage).
		$cost = round( (int) ( $usage['cost_micros'] ?? 0 ) / 1000000, 6 );

		$wpdb->insert(
			$this->table(),
			array(
				'user_id'            => (int) ( $row['user_id'] ?? 0 ),
				'conversation_id'    => (int) ( $row['conversation_id'] ?? 0 ),
				'message_id'         => (int) ( $row['message_id'] ?? 0 ),
				'purpose'            => (string) ( $row['purpose'] ?? 'chat' ),
				'model'              => $model,
				'tokens_in'          => $in + $cache_read + $cache_write,
				'tokens_out'         => $out,
				'cache_read_tokens'  => $cache_read,
				'cache_write_tokens' => $cache_write,
				'cost_usd'           => $cost,
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s' )
		);
		return $cost;
	}

	/**
	 * Cost (USD) since a UTC datetime, optionally for one user.
	 *
	 * @param string   $since_utc Y-m-d H:i:s.
	 * @param int|null $user_id   Optional user filter.
	 */
	public function cost_since( string $since_utc, ?int $user_id = null ): float {
		global $wpdb;
		if ( null === $user_id ) {
			return (float) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COALESCE(SUM(cost_usd), 0) FROM %i WHERE created_at >= %s', $this->table(), $since_utc )
			);
		}
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(cost_usd), 0) FROM %i WHERE created_at >= %s AND user_id = %d', $this->table(), $since_utc, $user_id )
		);
	}
}
