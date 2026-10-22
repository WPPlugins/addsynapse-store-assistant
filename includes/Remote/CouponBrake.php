<?php
/**
 * Big coupons stay in wp-admin (owner decision 22/10/2026): a coupon that would give more than MAX_PERCENT % off — a new
 * one, an edit that raises the amount or switches the type to percent, or an Undo that would bring such a value back — is
 * never written from AddSynapse (the AI card or the AddSynapse Changes page). AddSynapse holds the draft here instead
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

	/**
	 * The coupon a write would leave behind: discount type + amount (current values overlaid with the new ones). Null = no
	 * coupon is left (Undo of a created coupon deletes it) or this is not a coupon unit.
	 *
	 * @param string                   $kind Unit kind.
	 * @param int                      $id   Coupon id (0 = new).
	 * @param array<string,mixed>|null $set  New values.
	 * @return array{discount_type:string,amount:float}|null
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
		return array(
			// A new WooCommerce coupon without a type is «fixed_cart».
			'discount_type' => (string) ( $set['discount_type'] ?? ( $now['discount_type'] ?? 'fixed_cart' ) ),
			'amount'        => (float) ( $set['amount'] ?? ( $now['amount'] ?? 0 ) ),
		);
	}

	/**
	 * Whether this write needs the wp-admin approval page.
	 *
	 * @param string                   $kind Unit kind.
	 * @param int                      $id   Coupon id (0 = new).
	 * @param array<string,mixed>|null $set  New values.
	 */
	public static function needs_admin( string $kind, int $id, ?array $set ): bool {
		$r = self::result( $kind, $id, $set );
		return null !== $r && 'percent' === $r['discount_type'] && $r['amount'] > self::MAX_PERCENT;
	}

	/**
	 * The refusal text (English: AddSynapse shows it as it is).
	 */
	public static function message(): string {
		return sprintf( 'A coupon above %d%% off is approved only in this site\'s wp-admin (AddSynapse → Approvals). Nothing was changed.', self::MAX_PERCENT );
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
