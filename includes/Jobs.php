<?php
/**
 * Background jobs: Action Scheduler when available (it ships with WooCommerce), WP-Cron otherwise.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Decision (2026-09-25, plain WordPress sites): Action Scheduler is NOT bundled — WooCommerce
 * brings it; without WooCommerce our few jobs (daily CSV cleanup, hourly image cleanup, one-off
 * cleanup after a reject/undo, background changeset batches) fall back to WP-Cron. They are all
 * idempotent, so a job that runs twice (e.g. WooCommerce re-activated while a WP-Cron event is
 * still queued) is harmless; when Action Scheduler is back the WP-Cron copy is cleared.
 */
final class Jobs {

	/**
	 * Whether Action Scheduler functions are available.
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Schedules a recurring job once.
	 *
	 * @param string $hook     Hook.
	 * @param int    $interval Seconds (HOUR_IN_SECONDS → WP-Cron `hourly`, otherwise `daily`).
	 * @param string $group    Action Scheduler group.
	 */
	public static function recurring( string $hook, int $interval, string $group ): void {
		if ( self::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( $hook, array(), $group ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, $interval, $hook, array(), $group );
			}
			if ( wp_next_scheduled( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $interval <= HOUR_IN_SECONDS ? 'hourly' : 'daily', $hook );
		}
	}

	/**
	 * Schedules one run of a job at a time.
	 *
	 * @param int    $timestamp When.
	 * @param string $hook      Hook.
	 * @param string $group     Action Scheduler group.
	 */
	public static function single( int $timestamp, string $hook, string $group ): void {
		if ( self::has_action_scheduler() ) {
			as_schedule_single_action( $timestamp, $hook, array(), $group );
			return;
		}
		wp_schedule_single_event( $timestamp, $hook );
	}

	/**
	 * Removes every scheduled run of a hook (Action Scheduler + WP-Cron; safe without WooCommerce).
	 *
	 * @param string $hook  Hook.
	 * @param string $group Action Scheduler group.
	 */
	public static function unschedule( string $hook, string $group ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), $group );
		}
		wp_clear_scheduled_hook( $hook );
	}
}
