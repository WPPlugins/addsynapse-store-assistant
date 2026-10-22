<?php
/**
 * The AddSynapse plan and today's usage, as AddSynapse reports it on its own requests to this site.
 *
 * Every request AddSynapse makes with the connected application password may carry the header
 * `X-AddSynapse-Plan: {"plan":"free","label":"Free","used":12,"limit":100,"day":"2026-10-22"}` (used = AddSynapse's count
 * of the last 24 hours, limit 0 = unlimited, day = the UTC date it was sent; a later day shows 0). It is read ONLY on the AddSynapse remote routes, ONLY after WordPress authenticated the AddSynapse
 * application password (RemoteAccess::gate), checked strictly and kept in one option for Home. Nothing is sent anywhere.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Plan line on Home.
 */
final class PlanInfo {

	/** Option (not autoloaded): {plan, label, used, limit, day, at}. */
	public const OPTION = 'addsynapse_assistant_relay_plan';

	/** Header bigger than this is ignored. */
	public const MAX_BYTES = 300;

	/** Known plans. */
	public const PLANS = array( 'free', 'pro', 'business', 'agency' );

	/** Daily actions of the Free plan (shown before AddSynapse reported anything). */
	public const FREE_PER_DAY = 100;

	/**
	 * Parses the header value; null when it is not exactly what AddSynapse sends.
	 *
	 * @param mixed $raw Header value.
	 * @return array{plan:string,label:string,used:int,limit:int,day:string}|null
	 */
	public static function parse( $raw ): ?array {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_BYTES ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['plan'], $data['used'], $data['limit'], $data['day'] ) ) {
			return null;
		}
		if ( ! is_string( $data['plan'] ) || ! in_array( $data['plan'], self::PLANS, true ) ) {
			return null;
		}
		if ( ! is_int( $data['used'] ) || ! is_int( $data['limit'] ) || $data['used'] < 0 || $data['limit'] < 0 || $data['limit'] > 10000000 || $data['used'] > 10000000 ) {
			return null;
		}
		if ( ! is_string( $data['day'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $data['day'] ) ) {
			return null;
		}
		$label = isset( $data['label'] ) && is_string( $data['label'] ) ? sanitize_text_field( $data['label'] ) : '';
		return array(
			'plan'  => $data['plan'],
			'label' => mb_substr( $label, 0, 40 ),
			'used'  => $data['used'],
			'limit' => $data['limit'],
			'day'   => $data['day'],
		);
	}

	/**
	 * Keeps what an authenticated AddSynapse request reported (writes only when something changed, or once a minute).
	 *
	 * @param mixed $raw Header value.
	 */
	public static function capture( $raw ): void {
		$info = self::parse( $raw );
		if ( null === $info ) {
			return;
		}
		$old = get_option( self::OPTION, array() );
		if ( is_array( $old ) && isset( $old['at'] ) && time() - (int) $old['at'] < 60 ) {
			unset( $old['at'] );
			if ( $old === $info ) {
				return;
			}
		}
		update_option( self::OPTION, $info + array( 'at' => time() ), false );
	}

	/**
	 * What Home shows: the reported plan (today's count, 0 on a new UTC day), or null when nothing was reported.
	 *
	 * @return array{plan:string,label:string,used:int,limit:int}|null
	 */
	public static function current(): ?array {
		$info = get_option( self::OPTION, array() );
		if ( ! is_array( $info ) || ! isset( $info['plan'], $info['limit'] ) || ! in_array( $info['plan'], self::PLANS, true ) ) {
			return null;
		}
		return array(
			'plan'  => (string) $info['plan'],
			'label' => (string) ( $info['label'] ?? '' ),
			'used'  => gmdate( 'Y-m-d' ) === ( $info['day'] ?? '' ) ? (int) ( $info['used'] ?? 0 ) : 0,
			'limit' => (int) $info['limit'],
		);
	}

	/**
	 * Translated plan name.
	 *
	 * @param string $plan Plan key.
	 */
	public static function name( string $plan ): string {
		switch ( $plan ) {
			case 'pro':
				return __( 'Pro', 'addsynapse-store-assistant' );
			case 'business':
				return __( 'Business', 'addsynapse-store-assistant' );
			case 'agency':
				return __( 'Agency', 'addsynapse-store-assistant' );
			default:
				return __( 'Free', 'addsynapse-store-assistant' );
		}
	}

	/**
	 * The one line for Home.
	 */
	public static function line(): string {
		$info = self::current();
		if ( null === $info ) {
			/* translators: %s: number of actions per day, e.g. 100. */
			return sprintf( __( 'Free: %s actions a day', 'addsynapse-store-assistant' ), number_format_i18n( self::FREE_PER_DAY ) );
		}
		if ( 0 === $info['limit'] ) {
			/* translators: %s: plan name (e.g. Agency). */
			return sprintf( __( 'Plan: %s · unlimited actions', 'addsynapse-store-assistant' ), self::name( $info['plan'] ) );
		}
		return sprintf(
			/* translators: 1: plan name (Free, Pro…), 2: actions used in the last 24 hours, 3: daily actions of the plan. */
			__( 'Plan: %1$s · %2$s of %3$s actions today', 'addsynapse-store-assistant' ),
			self::name( $info['plan'] ),
			number_format_i18n( $info['used'] ),
			number_format_i18n( $info['limit'] )
		);
	}

	/**
	 * Forget it (disconnect).
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}
}
