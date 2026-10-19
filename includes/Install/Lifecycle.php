<?php
/**
 * Background work follows the setup (wp.org Guidelines 7/8, guide §2.4/§3.4).
 *
 *  - Nothing recurring is scheduled before the manager saved a working AI key: no WP-Cron event, no Action Scheduler
 *    action. An image uploaded before that gets one local cleanup run when it would expire (ImageUploads::store()).
 *  - ready_now(): the recurring local cleanup of pending chat images is scheduled (ImageUploads::schedule()). It never
 *    calls anything outside the site.
 *  - deactivate(): everything is unscheduled (safe without WooCommerce / Action Scheduler).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Install;

use Addsynapse\StoreAssistant\Byok\KeyStore;
use Addsynapse\StoreAssistant\Changesets\ChangesetService;
use Addsynapse\StoreAssistant\Jobs;
use Addsynapse\StoreAssistant\Media\ImageUploads;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduling lifecycle.
 */
final class Lifecycle {

	/**
	 * Every cron / Action Scheduler hook the plugin may schedule (deactivation + uninstall unschedule them all).
	 *
	 * @return array<int,string>
	 */
	public static function hooks(): array {
		return array(
			ImageUploads::CLEANUP_HOOK,
			ChangesetService::HOOK,
		);
	}

	/**
	 * Whether the assistant is set up (a saved, tested AI key) — the only state in which anything recurring is scheduled.
	 */
	public static function ready_now(): bool {
		return KeyStore::active();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate(): void {
		self::unschedule_all();
	}

	/**
	 * Removes every scheduled event/action of the plugin.
	 */
	public static function unschedule_all(): void {
		foreach ( self::hooks() as $hook ) {
			Jobs::unschedule( $hook, ChangesetService::GROUP );
		}
	}
}
