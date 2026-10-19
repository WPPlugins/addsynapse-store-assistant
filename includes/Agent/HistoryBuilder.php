<?php
/**
 * Converts stored messages into a valid Messages API `messages` array.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

use Addsynapse\StoreAssistant\Data\MessageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Repairs history so it is always a valid request:
 *  - `tool` rows become user messages of tool_result blocks,
 *  - tool_use blocks without a matching tool_result (e.g. stopped mid-loop) are dropped,
 *  - orphan tool_results are dropped,
 *  - thinking blocks without a signature (partial/stopped) are dropped,
 *  - consecutive same-role messages are merged, leading assistant messages dropped.
 */
final class HistoryBuilder {

	/**
	 * Builds the API messages.
	 *
	 * @param array<int,array<string,mixed>> $rows Stored messages (ordered).
	 * @return array<int,array{role:string,content:array<int,array<string,mixed>>}>
	 */
	public static function build( array $rows ): array {
		$rows = array_values( $rows );
		$out  = array();
		$n    = count( $rows );

		for ( $i = 0; $i < $n; $i++ ) {
			$row    = $rows[ $i ];
			$role   = $row['role'];
			$blocks = (array) $row['content'];

			if ( MessageRepository::ROLE_USER === $role ) {
				$blocks   = array_values(
					array_filter(
						$blocks,
						static function ( $b ) {
							// The facts of an image uploaded with the message stay with it (the AI client turns them into a
							// data block; providers never get the raw platform block).
							return ( 'text' === ( $b['type'] ?? '' ) && '' !== trim( (string) ( $b['text'] ?? '' ) ) ) || \Addsynapse\StoreAssistant\Media\ImageUploads::BLOCK === ( $b['type'] ?? '' );
						}
					)
				);
				$has_text = array_filter(
					$blocks,
					static function ( $b ) {
						return 'text' === ( $b['type'] ?? '' );
					}
				);
				if ( $has_text ) {
					$out[] = array(
						'role'    => 'user',
						'content' => $blocks,
					);
				}
				continue;
			}//end if

			if ( MessageRepository::ROLE_ASSISTANT === $role ) {
				$next        = $rows[ $i + 1 ] ?? null;
				$result_ids  = array();
				$next_blocks = array();
				if ( $next && MessageRepository::ROLE_TOOL === $next['role'] ) {
					foreach ( (array) $next['content'] as $b ) {
						if ( 'tool_result' === ( $b['type'] ?? '' ) ) {
							$result_ids[ (string) $b['tool_use_id'] ] = true;
						}
					}
				}
				$kept_ids = array();
				$clean    = array();
				foreach ( $blocks as $b ) {
					$type = $b['type'] ?? '';
					if ( 'text' === $type ) {
						if ( '' !== trim( (string) ( $b['text'] ?? '' ) ) ) {
							$clean[] = array(
								'type' => 'text',
								'text' => (string) $b['text'],
							);
						}
					} elseif ( 'thinking' === $type ) {
						if ( ! empty( $b['signature'] ) ) {
							$clean[] = $b;
						}
					} elseif ( 'redacted_thinking' === $type ) {
						$clean[] = $b;
					} elseif ( 'tool_use' === $type ) {
						if ( isset( $result_ids[ (string) $b['id'] ] ) ) {
							$clean[]                       = $b;
							$kept_ids[ (string) $b['id'] ] = true;
						}
					}
				}//end foreach
				// Thinking alone is not a useful assistant turn.
				$has_visible = false;
				foreach ( $clean as $b ) {
					if ( in_array( $b['type'], array( 'text', 'tool_use' ), true ) ) {
						$has_visible = true;
					}
				}
				if ( ! $has_visible ) {
					continue;
				}
				// If no tool_use survived, drop thinking blocks too (keep it simple and valid).
				if ( ! $kept_ids ) {
					$clean = array_values(
						array_filter(
							$clean,
							static function ( $b ) {
								return 'text' === $b['type'];
							}
						)
					);
				}
				$out[] = array(
					'role'    => 'assistant',
					'content' => $clean,
				);
				if ( $kept_ids && $next ) {
					foreach ( (array) $next['content'] as $b ) {
						if ( 'tool_result' === ( $b['type'] ?? '' ) && isset( $kept_ids[ (string) $b['tool_use_id'] ] ) ) {
							$next_blocks[] = $b;
						}
					}
					$out[] = array(
						'role'    => 'user',
						'content' => $next_blocks,
					);
					++$i;
					// Consumed the tool row.
				}
				continue;
			}//end if
			// Orphan tool rows (not directly after their assistant message) are skipped.
		}//end for

		return self::merge( $out );
	}

	/**
	 * Merges consecutive same-role messages and drops leading assistant turns.
	 *
	 * @param array<int,array{role:string,content:array<int,array<string,mixed>>}> $messages Messages.
	 * @return array<int,array{role:string,content:array<int,array<string,mixed>>}>
	 */
	private static function merge( array $messages ): array {
		$out = array();
		foreach ( $messages as $m ) {
			if ( ! $out && 'assistant' === $m['role'] ) {
				continue;
			}
			$last = count( $out ) - 1;
			if ( $last >= 0 && $out[ $last ]['role'] === $m['role'] ) {
				// tool_result blocks must come first in a user message.
				$out[ $last ]['content'] = array_merge( $out[ $last ]['content'], $m['content'] );
				continue;
			}
			$out[] = $m;
		}
		return $out;
	}
}
