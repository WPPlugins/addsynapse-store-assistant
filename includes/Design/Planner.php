<?php
/**
 * Base of the design planners: they compute a change and return it, they never store or write anything.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Abilities\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * A planner returns {status draft|nothing_to_change, operation, summary, params, lines, …extra}. The lines (one per
 * unit: before / after) are turned into remote change set units by DesignUnits::from_lines(); AddSynapse keeps the
 * draft and, only after the owner approved it, sends the units to apply-changes.
 */
abstract class Planner {

	/**
	 * The plan result.
	 *
	 * @param array<int,array<string,mixed>> $items           Lines.
	 * @param string                         $operation       Operation.
	 * @param string                         $summary         Summary.
	 * @param array<string,mixed>            $params          Parameters.
	 * @param int                            $user_id         Unused (kept from the in-admin planners).
	 * @param int                            $conversation_id Unused.
	 * @param array<string,mixed>            $extra           Extra keys (changes, warnings, document…).
	 * @return array<string,mixed>
	 */
	protected function store( array $items, string $operation, string $summary, array $params, int $user_id, int $conversation_id, array $extra = array() ) {
		unset( $user_id, $conversation_id );
		return array_merge(
			array(
				'status'    => $items ? 'draft' : 'nothing_to_change',
				'operation' => $operation,
				'summary'   => $summary,
				'params'    => $params,
				'_data'     => Shared::DATA_NOTICE,
			),
			$extra,
			array(
				'warnings' => array_values( array_unique( (array) ( $extra['warnings'] ?? array() ) ) ),
				'lines'    => $items,
			)
		);
	}
}
