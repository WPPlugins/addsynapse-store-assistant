<?php
/**
 * Usage and estimated cost of the manager's own AI key (from the local usage log).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Byok;

use Addsynapse\StoreAssistant\Brain\Models;
use Addsynapse\StoreAssistant\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Estimates only: the provider's own dashboard is the bill. A model without a known price is reported as such,
 * never as $0. Only the calls made with the manager's own key count (usage_log purpose PURPOSE); the AddSynapse
 * service's calls are not part of it.
 */
final class Usage {

	/**
	 * The usage_log purpose of a call made with the manager's own key.
	 */
	public const PURPOSE = 'own_key';

	/**
	 * Provider of a model id.
	 *
	 * @param string $model Model.
	 */
	public static function provider_of( string $model ): string {
		if ( 0 === strpos( $model, 'claude' ) ) {
			return 'anthropic';
		}
		if ( 0 === strpos( $model, 'gpt' ) || 0 === strpos( $model, 'o' ) ) {
			return 'openai';
		}
		return '';
	}

	/**
	 * Totals since a UTC datetime (site-wide), per model.
	 *
	 * @param string $since_utc Y-m-d H:i:s.
	 * @return array{models:array<int,array<string,mixed>>,calls:int,tokens_in:int,tokens_out:int,cache_read:int,cache_write:int,cost:float,unknown:int}
	 */
	public static function since( string $since_utc ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table, a settings-page report.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT model, COUNT(*) AS calls, SUM(tokens_in) AS tokens_in, SUM(tokens_out) AS tokens_out, SUM(cache_read_tokens) AS cache_read, SUM(cache_write_tokens) AS cache_write FROM %i WHERE created_at >= %s AND purpose = %s GROUP BY model ORDER BY calls DESC',
				Schema::table( 'usage_log' ),
				$since_utc,
				self::PURPOSE
			),
			ARRAY_A
		);
		$out  = array(
			'models'      => array(),
			'calls'       => 0,
			'tokens_in'   => 0,
			'tokens_out'  => 0,
			'cache_read'  => 0,
			'cache_write' => 0,
			'cost'        => 0.0,
			'unknown'     => 0,
		);
		foreach ( $rows as $row ) {
			$model               = (string) $row['model'];
			$read                = (int) $row['cache_read'];
			$write               = (int) $row['cache_write'];
			$cost                = Models::cost(
				self::provider_of( $model ),
				$model,
				array(
					'input_tokens'                => max( 0, (int) $row['tokens_in'] - $read - $write ),
					'output_tokens'               => (int) $row['tokens_out'],
					'cache_read_input_tokens'     => $read,
					'cache_creation_input_tokens' => $write,
				)
			);
			$out['models'][]     = array(
				'model'      => $model,
				'calls'      => (int) $row['calls'],
				'tokens_in'  => (int) $row['tokens_in'],
				'tokens_out' => (int) $row['tokens_out'],
				'cache_read' => $read,
				'cost'       => $cost,
			);
			$out['calls']       += (int) $row['calls'];
			$out['tokens_in']   += (int) $row['tokens_in'];
			$out['tokens_out']  += (int) $row['tokens_out'];
			$out['cache_read']  += $read;
			$out['cache_write'] += $write;
			if ( null === $cost ) {
				$out['unknown'] += (int) $row['calls'];
			} else {
				$out['cost'] += $cost;
			}
		}//end foreach
		return $out;
	}

	/**
	 * This calendar month (site timezone) and the last 30 days.
	 *
	 * @return array{month:array<string,mixed>,days30:array<string,mixed>}
	 */
	public static function summary(): array {
		$month_start = ( new \DateTimeImmutable( 'first day of this month midnight', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		return array(
			'month'  => self::since( $month_start ),
			'days30' => self::since( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
		);
	}
}
