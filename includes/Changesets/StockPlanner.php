<?php
/**
 * Stock plan: set / adjust stock quantities.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rules:
 *  - Products that do not track stock are skipped unless enable_manage_stock is true
 *    (then tracking is switched on as explicit changeset items, including the stock status
 *    WooCommerce will derive, so undo restores it exactly).
 *  - Stock never goes below 0 unless the product allows backorders (flagged in warnings).
 *  - Variable products: the parent when it manages stock itself, otherwise its variations.
 */
final class StockPlanner extends Planner {

	public const OPERATION = 'stock_update';
	public const MODES     = array( 'set', 'adjust' );
	public const MAX_QTY   = 1000000;

	/**
	 * Builds the plan and stores a draft changeset.
	 *
	 * @param array<string,mixed> $input           Validated ability input.
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation.
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$mode   = (string) ( $input['mode'] ?? '' );
		$enable = ! empty( $input['enable_manage_stock'] );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'mode must be set or adjust.' );
		}
		$lines        = array_values( (array) ( $input['items'] ?? array() ) );
		$has_selector = ! empty( $input['ids'] ) || ! empty( $input['skus'] );
		if ( ! $lines && ! $has_selector ) {
			return new WP_Error( 'addsynapse_assistant_no_selection', 'Say which product: items[] (one id or sku + qty), or ids/skus with value.' );
		}
		// One product per change.
		if ( count( $lines ) + ( $has_selector ? count( (array) ( $input['ids'] ?? array() ) ) + count( (array) ( $input['skus'] ?? array() ) ) : 0 ) > 1 ) {
			return ProductSelector::one_item_error();
		}
		if ( $has_selector && ! isset( $input['value'] ) ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Give `value` (the quantity) with ids/skus.' );
		}
		$value = (int) ( $input['value'] ?? 0 );
		$max   = Config::changeset_max_items();

		// Root product → requested quantity.
		$roots    = array();
		$skipped  = array();
		$warnings = array();
		foreach ( $lines as $line ) {
			$line = (array) $line;
			$id   = (int) ( $line['id'] ?? 0 );
			if ( ! $id && ! empty( $line['sku'] ) ) {
				$id = (int) wc_get_product_id_by_sku( (string) $line['sku'] );
				if ( ! $id ) {
					$skipped[] = array(
						'id'     => 0,
						'name'   => (string) $line['sku'],
						'reason' => 'No product or variation has this SKU.',
					);
					continue;
				}
			}
			if ( ! $id ) {
				return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Every item needs an id or a sku.' );
			}
			$product = wc_get_product( $id );
			if ( ! $product || 'trash' === $product->get_status() ) {
				$skipped[] = array(
					'id'     => $id,
					'name'   => '',
					'reason' => 'Product not found.',
				);
				continue;
			}
			$roots[] = array( $product, (int) $line['qty'] );
		}//end foreach
		if ( $has_selector ) {
			$selected = ProductSelector::resolve( $input );
			if ( is_wp_error( $selected ) ) {
				return $selected;
			}
			$skipped  = array_merge( $skipped, $selected['skipped'] );
			$warnings = $selected['warnings'];
			foreach ( $selected['products'] as $product ) {
				$roots[] = array( $product, $value );
			}
		}

		// Expand to the objects that actually hold the stock.
		$targets = array();
		foreach ( $roots as list( $product, $qty ) ) {
			foreach ( $this->stock_holders( $product, $skipped ) as $holder ) {
				if ( isset( $targets[ $holder->get_id() ] ) ) {
					if ( $targets[ $holder->get_id() ][1] !== $qty ) {
						$skipped[] = ProductSelector::skip( $holder, 'Listed more than once with different quantities; kept the first.' );
					}
					continue;
				}
				$targets[ $holder->get_id() ] = array( $holder, $qty );
			}
		}
		if ( count( $targets ) > $max ) {
			return new WP_Error( 'addsynapse_assistant_changeset_too_large', sprintf( 'The selection has %1$d products/variations; the limit is %2$d per plan. Narrow it.', count( $targets ), $max ) );
		}

		$rows       = array();
		$unchanged  = 0;
		$sum_before = 0;
		$sum_after  = 0;
		$negative   = 0;
		foreach ( $targets as list( $product, $qty ) ) {
			$manage  = ProductFields::read( $product, 'manage_stock' );
			$current = ProductFields::read( $product, 'stock_quantity' );
			if ( ! $manage && ! $enable ) {
				$skipped[] = ProductSelector::skip( $product, 'Stock is not tracked for it (manage stock is off). Tracking is enabled only when explicitly requested.' );
				continue;
			}
			$base = $manage ? (int) $current : 0;
			$new  = 'set' === $mode ? $qty : $base + $qty;
			if ( abs( $new ) > self::MAX_QTY ) {
				$skipped[] = ProductSelector::skip( $product, 'The quantity is out of range.' );
				continue;
			}
			$backorders = $manage ? (string) $product->get_backorders( 'edit' ) : 'no';
			if ( $new < 0 ) {
				if ( 'no' === $backorders ) {
					$skipped[] = ProductSelector::skip( $product, sprintf( 'Stock would become %d and this product does not allow backorders.', $new ) );
					continue;
				}
				++$negative;
			}
			$changes = array();
			if ( ! $manage ) {
				$changes[]  = array(
					'field'  => 'manage_stock',
					'before' => false,
					'after'  => true,
				);
				$changes[]  = array(
					'field'  => 'stock_quantity',
					'before' => $current,
					'after'  => $new,
				);
				$status_now = ProductFields::read( $product, 'stock_status' );
				$status_new = self::derived_status( $new, $backorders );
				if ( $status_now !== $status_new ) {
					$changes[] = array(
						'field'  => 'stock_status',
						'before' => $status_now,
						'after'  => $status_new,
					);
				}
			} elseif ( ! ProductFields::same( 'stock_quantity', $current, $new ) ) {
				$changes[] = array(
					'field'  => 'stock_quantity',
					'before' => $current,
					'after'  => $new,
				);
			}//end if
			if ( ! $changes ) {
				++$unchanged;
				continue;
			}
			$sum_before += $base;
			$sum_after  += $new;
			$rows[]      = self::row( $product, $changes );
		}//end foreach

		if ( $negative ) {
			$warnings[] = sprintf( '%d products will have negative stock (they allow backorders).', $negative );
		}
		if ( $skipped ) {
			$warnings[] = sprintf( '%d products/variations were skipped (see skipped).', count( $skipped ) );
		}

		$params = array(
			'mode'                => $mode,
			'enable_manage_stock' => $enable,
		);
		if ( $has_selector ) {
			$params['value'] = $value;
		}
		if ( $lines ) {
			$params['items'] = count( $lines );
		}
		$params += self::selector_params( $input );

		return $this->finish(
			array(
				'operation'       => self::OPERATION,
				'params'          => $params,
				'summary'         => sprintf( 'Stock update: %s%s — %d products/variations.', 'set' === $mode ? 'set quantity' : 'adjust quantity', $has_selector ? ' ' . ( 'adjust' === $mode ? sprintf( '%+d', $value ) : (string) $value ) : ' (per item)', count( $rows ) ),
				'rows'            => $rows,
				'skipped'         => $skipped,
				'warnings'        => $warnings,
				'unchanged'       => $unchanged,
				'totals'          => array(
					'before_sum' => $sum_before,
					'after_sum'  => $sum_after,
				),
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
			)
		);
	}

	/**
	 * Objects holding the stock of a selected product.
	 *
	 * @param \WC_Product                    $product Selected product.
	 * @param array<int,array<string,mixed>> $skipped Skipped rows (by ref).
	 * @return array<int,\WC_Product>
	 */
	private function stock_holders( \WC_Product $product, array &$skipped ): array {
		if ( $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			$skipped[] = ProductSelector::skip( $product, 'This product type does not hold stock.' );
			return array();
		}
		if ( $product->is_type( 'variable' ) ) {
			if ( $product->get_manage_stock( 'edit' ) ) {
				return array( $product );
			}
			$out = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof \WC_Product_Variation ) {
					$out[] = $child;
				}
			}
			return $out;
		}
		if ( $product instanceof \WC_Product_Variation && ! $product->get_manage_stock( 'edit' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent && $parent->get_manage_stock( 'edit' ) ) {
				return array( $parent );
				// Stock is managed at parent level.
			}
		}
		return array( $product );
	}

	/**
	 * Stock status WooCommerce derives for a managed quantity (see WC_Product::validate_props).
	 *
	 * @param int    $qty        Quantity.
	 * @param string $backorders Backorders setting.
	 */
	public static function derived_status( int $qty, string $backorders ): string {
		if ( $qty > abs( (float) get_option( 'woocommerce_notify_no_stock_amount', 0 ) ) ) {
			return 'instock';
		}
		return 'no' !== $backorders ? 'onbackorder' : 'outofstock';
	}
}
