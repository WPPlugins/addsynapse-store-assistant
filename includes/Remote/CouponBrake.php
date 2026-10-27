<?php
/**
 * Big coupons stay in wp-admin (owner decision 22/10/2026; 1.3.7: audit 25/10 C1 reasons): a coupon that would give more
 * than MAX_PERCENT % off, a fixed discount above MAX_FIXED, free shipping without a usage limit, or a coupon with neither a usage
 * limit nor an expiry date — a new one, or an edit that leaves such a coupon; an Undo only when it would bring back a big
 * discount — is never written from AddSynapse (the AI card or the AddSynapse Changes page). AddSynapse holds the draft here instead
 * (AdminApprovals) and a site administrator approves it on this plugin's own wp-admin page. Enforced on the site, whatever
 * AddSynapse sends: the only check that still holds if the AddSynapse side were compromised.
 *
 * After a coupon is approved from AddSynapse, the site's administrator email gets a short notice (wp_mail, nothing else).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon ceiling for remote approvals + the admin notice.
 */
final class CouponBrake {

	/** Percent coupons above this need the wp-admin approval page. */
	public const MAX_PERCENT = 50;

	/** Typed refusal (same name the Connector uses for «approve this in the shop admin»). */
	public const ERROR = 'approve_in_admin';

	/** Fixed discounts (fixed_cart / fixed_product) above this amount (shop currency) need the wp-admin approval page too. */
	public const MAX_FIXED = 50;

	/** The reasons, in the order they are checked (the same names and rules as AddSynapse's coupon brake, audit 25/10 C1). */
	public const REASONS = array( 'percent', 'fixed', 'free_shipping', 'unlimited' );

	/**
	 * The coupon a write would leave behind: type, amount, usage limit, expiry, free shipping (current values overlaid with
	 * the new ones; an explicit null in the new values wins). Null = no coupon is left (Undo of a created coupon deletes
	 * it) or this is not a coupon unit.
	 *
	 * @param string                   $kind Unit kind.
	 * @param int                      $id   Coupon id (0 = new).
	 * @param array<string,mixed>|null $set  New values.
	 * @return array{discount_type:string,amount:float,usage_limit:int,date_expires:?string,free_shipping:bool}|null
	 */
	public static function result( string $kind, int $id, ?array $set ): ?array {
		if ( 'coupon' !== $kind || null === $set ) {
			return null;
		}
		$now = array();
		if ( $id > 0 ) {
			$read = StateUnits::read(
				array(
					'kind' => 'coupon',
					'id'   => $id,
				)
			);
			$now  = ! empty( $read['exists'] ) ? (array) $read['values'] : array();
		}
		$c       = $set + $now;
		$expires = $c['date_expires'] ?? null;
		return array(
			// A new WooCommerce coupon without a type is «fixed_cart».
			'discount_type' => (string) ( $c['discount_type'] ?? 'fixed_cart' ),
			'amount'        => (float) ( $c['amount'] ?? 0 ),
			'usage_limit'   => (int) ( $c['usage_limit'] ?? 0 ),
			'date_expires'  => null === $expires || '' === (string) $expires ? null : (string) $expires,
			'free_shipping' => filter_var( $c['free_shipping'] ?? false, FILTER_VALIDATE_BOOLEAN ),
		);
	}

	/**
	 * Why the coupon a write leaves behind is approved only in wp-admin, or null. percent above MAX_PERCENT · fixed amount above
	 * MAX_FIXED · free shipping without a total usage limit · neither a total usage limit nor an expiry date.
	 *
	 * @param string                   $kind Unit kind.
	 * @param int                      $id   Coupon id (0 = new).
	 * @param array<string,mixed>|null $set  New values.
	 */
	public static function reason( string $kind, int $id, ?array $set ): ?string {
		$r = self::result( $kind, $id, $set );
		if ( null === $r ) {
			return null;
		}
		$limited = $r['usage_limit'] > 0;
		if ( 'percent' === $r['discount_type'] && $r['amount'] > self::MAX_PERCENT ) {
			return 'percent';
		}
		if ( in_array( $r['discount_type'], array( 'fixed_cart', 'fixed_product' ), true ) && $r['amount'] > self::MAX_FIXED ) {
			return 'fixed';
		}
		if ( $r['free_shipping'] && ! $limited ) {
			return 'free_shipping';
		}
		if ( ! $limited && null === $r['date_expires'] ) {
			return 'unlimited';
		}
		return null;
	}

	/**
	 * Whether this write needs the wp-admin approval page. An Undo only for a big discount (percent / fixed): bringing back the
	 * merchant's own earlier limits is not new exposure (the same rule as AddSynapse).
	 *
	 * @param string                   $kind Unit kind.
	 * @param int                      $id   Coupon id (0 = new).
	 * @param array<string,mixed>|null $set  New values.
	 * @param bool                     $undo The write is an Undo.
	 */
	public static function needs_admin( string $kind, int $id, ?array $set, bool $undo = false ): bool {
		$reason = self::reason( $kind, $id, $set );
		return null !== $reason && ( ! $undo || in_array( $reason, array( 'percent', 'fixed' ), true ) );
	}

	/**
	 * The refusal text (English: AddSynapse shows it as it is).
	 */
	public static function message(): string {
		return sprintf( 'A coupon above %1$d%% off, a fixed discount above %2$d, free shipping without a usage limit, or a coupon with neither a usage limit nor an expiry date is approved only in this site\'s wp-admin (AddSynapse → Approvals). Nothing was changed.', self::MAX_PERCENT, self::MAX_FIXED );
	}

	/**
	 * After a coupon was approved from AddSynapse: a short email to the site's administrator address (site language).
	 *
	 * @param int                 $id  Coupon id.
	 * @param array<string,mixed> $set Values written.
	 * @param bool                $new A new coupon.
	 */
	public static function notify( int $id, array $set, bool $new ): void {
		$to = (string) get_option( 'admin_email' );
		if ( $id < 1 || ! is_email( $to ) || ! class_exists( '\WC_Coupon' ) ) {
			return;
		}
		$coupon   = new \WC_Coupon( $id );
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() );
		$type     = (string) $coupon->get_discount_type( 'edit' );
		$amount   = (float) $coupon->get_amount( 'edit' );
		$value    = 'percent' === $type ? wc_format_localized_decimal( $amount ) . '%'
			: html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		$code     = strtoupper( (string) $coupon->get_code( 'edit' ) );
		$site     = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
		$subject  = sprintf(
			/* translators: 1: site name, 2: coupon code. */
			$new ? __( '[%1$s] New coupon %2$s approved via AddSynapse', 'addsynapse-store-assistant' ) : __( '[%1$s] Coupon %2$s changed via AddSynapse', 'addsynapse-store-assistant' ),
			$site,
			$code
		);
		$lines    = array(
			sprintf(
				/* translators: %s: coupon code. */
				$new ? __( 'A new coupon was approved from AddSynapse (your AI chat): %s.', 'addsynapse-store-assistant' ) : __( 'A coupon was changed with an approval from AddSynapse (your AI chat): %s.', 'addsynapse-store-assistant' ),
				$code
			),
			/* translators: %s: discount, e.g. «15%» or «€10.00». */
			sprintf( __( 'Discount: %s', 'addsynapse-store-assistant' ), $value ),
			/* translators: %s: link to the coupon in wp-admin. */
			sprintf( __( 'Check it: %s', 'addsynapse-store-assistant' ), admin_url( 'post.php?post=' . $id . '&action=edit' ) ),
			'',
			__( 'If you did not expect this, set the coupon to Draft in WooCommerce → Coupons and disconnect AddSynapse in AddSynapse → Home.', 'addsynapse-store-assistant' ),
		);
		if ( $switched ) {
			restore_previous_locale();
		}
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
