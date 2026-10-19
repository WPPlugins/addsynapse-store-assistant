<?php
/**
 * Price plan: percent / fixed / set on the regular or sale price (+ sale dates).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Guards (a product that would break one is skipped with a reason, never "fixed" silently):
 *  - the new price must be > 0,
 *  - a sale price must be lower than the regular price,
 *  - a new regular price must stay above an existing sale price.
 * Variable products are expanded to their variations (prices live there); grouped products
 * have no own price and are skipped.
 */
final class PricePlanner extends Planner {

	public const OPERATION = 'price_update';
	public const MODES     = array( 'percent', 'fixed', 'set' );
	public const TARGETS   = array( 'regular', 'sale' );
	public const ROUNDING  = array( 'none', '2dp', 'x.90', 'x.99' );

	/**
	 * Builds the plan and stores a draft changeset.
	 *
	 * @param array<string,mixed> $input           Validated ability input.
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation (0 = none).
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$mode     = (string) ( $input['mode'] ?? '' );
		$value    = (float) ( $input['value'] ?? 0 );
		$target   = (string) ( $input['target'] ?? 'regular' );
		$rounding = (string) ( $input['rounding'] ?? '2dp' );
		if ( ! in_array( $mode, self::MODES, true ) || ! in_array( $target, self::TARGETS, true ) || ! in_array( $rounding, self::ROUNDING, true ) ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Invalid mode, target or rounding.' );
		}
		if ( 'percent' === $mode && ( $value <= -100 || $value > 1000 ) ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'A percent change must be greater than -100 and at most 1000.' );
		}
		if ( 'set' === $mode && $value <= 0 ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'A price must be greater than 0.' );
		}
		if ( 'set' !== $mode && 0.0 === $value ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'A change of 0 changes nothing.' );
		}

		$from = null;
		$to   = null;
		if ( ! empty( $input['sale_from'] ) || ! empty( $input['sale_to'] ) ) {
			if ( 'sale' !== $target ) {
				return new WP_Error( 'addsynapse_assistant_invalid_plan', 'sale_from/sale_to are only allowed with target=sale.' );
			}
			if ( ! empty( $input['sale_from'] ) ) {
				$from = self::date_timestamp( (string) $input['sale_from'], false );
			}
			if ( ! empty( $input['sale_to'] ) ) {
				$to = self::date_timestamp( (string) $input['sale_to'], true );
			}
			if ( ( ! empty( $input['sale_from'] ) && null === $from ) || ( ! empty( $input['sale_to'] ) && null === $to ) ) {
				return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Sale dates must be valid dates YYYY-MM-DD.' );
			}
			if ( null !== $from && null !== $to && $to < $from ) {
				return new WP_Error( 'addsynapse_assistant_invalid_plan', 'sale_to must be on or after sale_from.' );
			}
			if ( null !== $to && $to < time() ) {
				return new WP_Error( 'addsynapse_assistant_invalid_plan', 'sale_to is in the past.' );
			}
		}

		$max      = Config::changeset_max_items();
		$selected = ProductSelector::resolve( $input );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$skipped  = $selected['skipped'];
		$warnings = $selected['warnings'];
		if ( ( null !== $from || null !== $to ) && '' === (string) get_option( 'timezone_string' ) ) {
			// Sale days start/end at midnight of the STORE timezone; an offset-only setting is often a forgotten default.
			$warnings[] = sprintf( 'The store timezone is %s (no city is set in Settings → General): the sale starts and ends at midnight in that timezone.', 'UTC' . wp_timezone_string() );
		}
		$targets = array();
		foreach ( $selected['products'] as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( $child instanceof \WC_Product_Variation ) {
						$targets[ $child->get_id() ] = $child;
					}
				}
				continue;
			}
			if ( $product->is_type( 'grouped' ) ) {
				$skipped[] = ProductSelector::skip( $product, 'Grouped products have no price of their own.' );
				continue;
			}
			$targets[ $product->get_id() ] = $product;
		}
		if ( count( $targets ) > $max ) {
			return new WP_Error( 'addsynapse_assistant_changeset_too_large', sprintf( 'The selection has %1$d products/variations; the limit is %2$d per plan. Narrow it and make several plans.', count( $targets ), $max ) );
		}

		$rows      = array();
		$unchanged = 0;
		$sum_from  = 0.0;
		$sum_to    = 0.0;
		$expired   = 0;
		foreach ( $targets as $product ) {
			$regular = ProductFields::read( $product, 'regular_price' );
			$sale    = ProductFields::read( $product, 'sale_price' );
			$base    = 'regular' === $target ? $regular : $sale;

			if ( 'set' !== $mode && '' === $regular ) {
				$skipped[] = ProductSelector::skip( $product, 'It has no regular price to calculate from.' );
				continue;
			}
			if ( 'sale' === $target && '' === $regular ) {
				$skipped[] = ProductSelector::skip( $product, 'It has no regular price, so a sale price cannot be set.' );
				continue;
			}
			$new = 'set' === $mode ? round( $value, 4 ) : self::round_price( self::compute( (float) $regular, $mode, $value ), $rounding );
			if ( $new <= 0 ) {
				$skipped[] = ProductSelector::skip( $product, sprintf( 'The new price would be %s (zero or negative).', self::format( $new, 'none' ) ) );
				continue;
			}
			$new_text = self::format( $new, 'set' === $mode ? ( abs( round( $new, 2 ) - $new ) < 0.000001 ? '2dp' : 'none' ) : $rounding );
			if ( 'sale' === $target && $new >= (float) $regular ) {
				$skipped[] = ProductSelector::skip( $product, sprintf( 'The sale price %1$s would not be lower than the regular price %2$s.', $new_text, $regular ) );
				continue;
			}
			if ( 'regular' === $target && '' !== $sale && $new <= (float) $sale ) {
				$skipped[] = ProductSelector::skip( $product, sprintf( 'The new regular price %1$s would not be above the current sale price %2$s.', $new_text, $sale ) );
				continue;
			}

			$changes = array();
			$field   = 'regular' === $target ? 'regular_price' : 'sale_price';
			if ( ! ProductFields::same( $field, $base, $new_text ) ) {
				$changes[] = array(
					'field'  => $field,
					'before' => $base,
					'after'  => $new_text,
				);
				$sum_from += '' === $base ? 0.0 : (float) $base;
				$sum_to   += $new;
			}
			if ( 'sale' === $target ) {
				foreach ( array(
					'date_on_sale_from' => $from,
					'date_on_sale_to'   => $to,
				) as $date_field => $date_value ) {
					$current = ProductFields::read( $product, $date_field );
					if ( null !== $date_value && ! ProductFields::same( $date_field, $current, $date_value ) ) {
						$changes[] = array(
							'field'  => $date_field,
							'before' => $current,
							'after'  => $date_value,
						);
					}
				}
				$end = ProductFields::read( $product, 'date_on_sale_to' );
				if ( null === $to && null !== $end && $end < time() ) {
					++$expired;
				}
			}
			if ( ! $changes ) {
				++$unchanged;
				continue;
			}
			$rows[] = self::row( $product, $changes );
		}//end foreach

		if ( $expired ) {
			$warnings[] = sprintf( '%d products have a sale end date in the past: their sale price will not show until the sale dates change.', $expired );
		}
		if ( $skipped ) {
			$warnings[] = sprintf( '%d products/variations were skipped (see skipped).', count( $skipped ) );
		}

		$params = array(
			'mode'     => $mode,
			'value'    => $value,
			'target'   => $target,
			'rounding' => $rounding,
		);
		if ( null !== $from ) {
			$params['sale_from'] = (string) $input['sale_from'];
		}
		if ( null !== $to ) {
			$params['sale_to'] = (string) $input['sale_to'];
		}
		$params += self::selector_params( $input );

		return $this->finish(
			array(
				'operation'       => self::OPERATION,
				'params'          => $params,
				'summary'         => self::summary( $params, count( $rows ) ),
				'rows'            => $rows,
				'skipped'         => $skipped,
				'warnings'        => $warnings,
				'unchanged'       => $unchanged,
				'totals'          => array(
					'before_sum' => self::format( $sum_from, '2dp' ),
					'after_sum'  => self::format( $sum_to, '2dp' ),
					'change_pct' => $sum_from > 0 ? round( ( $sum_to - $sum_from ) / $sum_from * 100, 2 ) : null,
				),
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
			)
		);
	}

	/**
	 * New price from the regular price.
	 *
	 * @param float  $regular Regular price.
	 * @param string $mode    percent|fixed.
	 * @param float  $value   Value.
	 */
	public static function compute( float $regular, string $mode, float $value ): float {
		return 'percent' === $mode ? $regular * ( 1 + $value / 100 ) : $regular + $value;
	}

	/**
	 * Rounds a computed price: none (4 decimals), 2dp, or UP to the next x.90 / x.99.
	 *
	 * @param float  $price    Price.
	 * @param string $rounding Mode.
	 */
	public static function round_price( float $price, string $rounding ): float {
		switch ( $rounding ) {
			case 'none':
				return round( $price, 4 );
			case 'x.90':
			case 'x.99':
				$ending = 'x.90' === $rounding ? 0.90 : 0.99;
				$price  = round( $price, 4 );
				$result = floor( $price ) + $ending;
				if ( $result + 0.00001 < $price ) {
					++$result;
				}
				return round( $result, 2 );
		}
		return round( $price, 2 );
	}

	/**
	 * Price → stored decimal string.
	 *
	 * @param float  $price    Price.
	 * @param string $rounding Rounding mode (none keeps up to 4 decimals).
	 */
	public static function format( float $price, string $rounding ): string {
		if ( 'none' === $rounding ) {
			return (string) wc_format_decimal( round( $price, 4 ), 4, true );
		}
		return (string) wc_format_decimal( round( $price, 2 ), 2 );
	}

	/**
	 * English summary (audit/model; the UI builds its own translated description).
	 *
	 * @param array<string,mixed> $params Params.
	 * @param int                 $count  Products.
	 */
	private static function summary( array $params, int $count ): string {
		$field = 'sale' === $params['target'] ? 'sale price' : 'regular price';
		switch ( $params['mode'] ) {
			case 'percent':
				$what = sprintf( '%s %+g%%%s', $field, $params['value'], 'sale' === $params['target'] ? ' of the regular price' : '' );
				break;
			case 'fixed':
				$what = sprintf( '%s %+g%s', $field, $params['value'], 'sale' === $params['target'] ? ' from the regular price' : '' );
				break;
			default:
				$what = sprintf( '%s set to %s', $field, $params['value'] );
		}
		$dates = '';
		if ( isset( $params['sale_from'] ) || isset( $params['sale_to'] ) ) {
			$dates = sprintf( ' (sale %s → %s)', $params['sale_from'] ?? '…', $params['sale_to'] ?? '…' );
		}
		return sprintf( 'Price update: %s%s, rounding %s — %d products/variations.', $what, $dates, $params['rounding'], $count );
	}
}
