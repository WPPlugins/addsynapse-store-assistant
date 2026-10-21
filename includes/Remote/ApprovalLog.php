<?php
/**
 * Approval log: every change AddSynapse wrote to this site after the owner approved it (or undid it),
 * kept locally (last 200 entries, option not autoloaded).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Local log of remote writes.
 */
final class ApprovalLog {

	public const OPTION = 'addsynapse_assistant_remote_log';
	public const MAX    = 200;

	/**
	 * Adds an entry.
	 *
	 * @param string                         $changeset Change set reference (from AddSynapse).
	 * @param string                         $action    approve|undo.
	 * @param string                         $summary   Short summary.
	 * @param array<int,array<string,mixed>> $rows      Products written (id, name, from, to).
	 * @param string                         $result    Ok, or refused: reason.
	 */
	public static function add( string $changeset, string $action, string $summary, array $rows, string $result ): void {
		$log = self::all();
		array_unshift(
			$log,
			array(
				'time'      => time(),
				'changeset' => sanitize_text_field( $changeset ),
				'action'    => sanitize_key( $action ),
				'summary'   => sanitize_text_field( $summary ),
				'user'      => (string) wp_get_current_user()->user_login,
				'rows'      => $rows,
				'result'    => sanitize_text_field( $result ),
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/**
	 * All entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * One logged row as plain text: «Name (#id): field before → after; …». Prices (apply-prices rows) and every
	 * relay kind (product price/stock fields, variations, SEO title/description, coupons); rows written before
	 * 1.3.0 carry «kind #id» as the name and are resolved here.
	 *
	 * @param array<string,mixed> $row Row (id, [kind], name, from, to).
	 */
	public static function describe( array $row ): string {
		$id   = (int) ( $row['id'] ?? 0 );
		$kind = (string) ( $row['kind'] ?? 'product' );
		$name = (string) ( $row['name'] ?? '' );
		if ( preg_match( '/^(product|seo|coupon) #\d+$/', $name, $m ) ) {
			$kind = $m[1];
			$name = '';
		}
		$from = is_array( $row['from'] ?? null ) ? $row['from'] : null;
		$to   = is_array( $row['to'] ?? null ) ? $row['to'] : null;
		if ( '' === $name && 'coupon' === $kind ) {
			$name = (string) ( $to['code'] ?? ( $from['code'] ?? '' ) );
		}
		if ( '' === $name && $id ) {
			$name = (string) get_the_title( $id );
		}
		if ( 'coupon' === $kind ) {
			/* translators: %s: coupon code. */
			$name = sprintf( __( 'Coupon %s', 'addsynapse-store-assistant' ), '' !== $name ? $name : '?' );
		}
		$head = self::cut( $name, 80 ) . ( $id ? ' (#' . $id . ')' : '' );
		if ( 'coupon' === $kind && null === $to ) {
			return $head . ': ' . __( 'deleted', 'addsynapse-store-assistant' );
		}
		if ( 'coupon' === $kind && null === $from ) {
			$head .= ' — ' . _x( 'new', 'approval log: coupon created', 'addsynapse-store-assistant' );
		}
		$parts = array();
		foreach ( array_keys( (array) $to + (array) $from ) as $field ) {
			$before = self::value( (string) $field, $from[ $field ] ?? null );
			$after  = self::value( (string) $field, $to[ $field ] ?? null );
			if ( $before === $after ) {
				continue;
			}
			$parts[] = self::label( $kind, (string) $field ) . ' ' . $before . ' → ' . $after;
		}
		return $head . ': ' . ( $parts ? implode( '; ', $parts ) : __( 'no change', 'addsynapse-store-assistant' ) );
	}

	/**
	 * Field label.
	 *
	 * @param string $kind  Kind.
	 * @param string $field Field.
	 */
	private static function label( string $kind, string $field ): string {
		if ( 'seo' === $kind ) {
			return 'title' === $field ? __( 'SEO title', 'addsynapse-store-assistant' ) : __( 'SEO description', 'addsynapse-store-assistant' );
		}
		$labels = array(
			'regular_price'               => __( 'Regular price', 'addsynapse-store-assistant' ),
			'sale_price'                  => __( 'Sale price', 'addsynapse-store-assistant' ),
			'manage_stock'                => __( 'Manage stock', 'addsynapse-store-assistant' ),
			'stock_quantity'              => __( 'Stock quantity', 'addsynapse-store-assistant' ),
			'stock_status'                => __( 'Stock status', 'addsynapse-store-assistant' ),
			'code'                        => __( 'Code', 'addsynapse-store-assistant' ),
			'description'                 => __( 'Description', 'addsynapse-store-assistant' ),
			'discount_type'               => __( 'Discount type', 'addsynapse-store-assistant' ),
			'amount'                      => __( 'Amount', 'addsynapse-store-assistant' ),
			'date_expires'                => __( 'Expiry date', 'addsynapse-store-assistant' ),
			'usage_limit'                 => __( 'Usage limit', 'addsynapse-store-assistant' ),
			'usage_limit_per_user'        => __( 'Usage limit per user', 'addsynapse-store-assistant' ),
			'individual_use'              => __( 'Individual use only', 'addsynapse-store-assistant' ),
			'free_shipping'               => __( 'Free shipping', 'addsynapse-store-assistant' ),
			'minimum_amount'              => __( 'Minimum spend', 'addsynapse-store-assistant' ),
			'maximum_amount'              => __( 'Maximum spend', 'addsynapse-store-assistant' ),
			'product_ids'                 => __( 'Products', 'addsynapse-store-assistant' ),
			'excluded_product_ids'        => __( 'Excluded products', 'addsynapse-store-assistant' ),
			'product_categories'          => __( 'Categories', 'addsynapse-store-assistant' ),
			'excluded_product_categories' => __( 'Excluded categories', 'addsynapse-store-assistant' ),
		);
		return $labels[ $field ] ?? str_replace( '_', ' ', $field );
	}

	/**
	 * A value as short plain text («–» = empty).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	private static function value( string $field, $value ): string {
		if ( null === $value || '' === $value || array() === $value ) {
			return '–';
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'addsynapse-store-assistant' ) : __( 'No', 'addsynapse-store-assistant' );
		}
		if ( is_array( $value ) ) {
			return self::cut( implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ), 60 );
		}
		$value  = is_scalar( $value ) ? (string) $value : '';
		$labels = array(
			'instock'       => __( 'In stock', 'addsynapse-store-assistant' ),
			'outofstock'    => __( 'Out of stock', 'addsynapse-store-assistant' ),
			'onbackorder'   => __( 'On backorder', 'addsynapse-store-assistant' ),
			'percent'       => __( 'Percentage discount', 'addsynapse-store-assistant' ),
			'fixed_cart'    => __( 'Fixed cart discount', 'addsynapse-store-assistant' ),
			'fixed_product' => __( 'Fixed product discount', 'addsynapse-store-assistant' ),
		);
		if ( in_array( $field, array( 'stock_status', 'discount_type' ), true ) && isset( $labels[ $value ] ) ) {
			return $labels[ $value ];
		}
		$value = self::cut( $value, 60 );
		return '' === $value ? '–' : $value;
	}

	/**
	 * Plain text on one line, cut to $max characters («…» added).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 */
	private static function cut( string $text, int $max ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}
}
