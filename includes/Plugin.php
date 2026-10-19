<?php
/**
 * Plugin bootstrap.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Admin\AddsynapseMenu;
use Addsynapse\StoreAssistant\Admin\AdminPage;
use Addsynapse\StoreAssistant\Admin\SettingsPage;
use Addsynapse\StoreAssistant\Admin\OwnKeySection;
use Addsynapse\StoreAssistant\Admin\AdminBarLink;
use Addsynapse\StoreAssistant\Account\Announcements;
use Addsynapse\StoreAssistant\Account\OptIn;
use Addsynapse\StoreAssistant\Changesets\ChangesetService;
use Addsynapse\StoreAssistant\Discovery\StoreMap;
use Addsynapse\StoreAssistant\Discovery\StoreSummary;
use Addsynapse\StoreAssistant\Install\Schema;
use Addsynapse\StoreAssistant\Media\ImageUploads;
use Addsynapse\StoreAssistant\Rest\RestRoutes;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component into WordPress hooks.
 */
final class Plugin {

	/**
	 * Guards against double boot.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/** Text domain (= slug). */
	public const TEXT_DOMAIN = 'addsynapse-store-assistant';

	/**
	 * Boots the plugin (runs on plugins_loaded).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Run pending schema migrations (cheap option check on every load).
		Schema::maybe_upgrade();

		// Translations: WordPress loads the wordpress.org language packs just in time (no bundled files, no loader).

		foreach ( self::components( is_admin() ) as $component ) {
			if ( is_string( $component ) ) {
				$component::register_hooks();
			} else {
				$component->register_hooks();
			}
		}
	}

	/**
	 * What the plugin wires (strings = static register_hooks(), objects = instance).
	 *
	 * Engine: abilities, changesets (engine + background jobs), store map/summary, chat image uploads. Chat: REST (addsynapse-assistant/v1,
	 * logged-in users with the capability + REST nonce). wp-admin: the shared AddSynapse menu/Overview, AddSynapse → Store Assistant (chat + Settings).
	 *
	 * There is NO job channel: the AI answers with the manager's own key, every tool call
	 * is made by the wp-admin chat request of a logged-in user, as that user, and every change is applied by a
	 * logged-in user in wp-admin.
	 *
	 * @param bool $admin wp-admin request.
	 * @return array<int,object|string>
	 */
	public static function components( bool $admin ): array {
		$list = array(
			new AbilitiesRegistrar(),
			StoreMap::class,
			StoreSummary::class,
			ChangesetService::class,
			ImageUploads::class,
			new RestRoutes(),
			// Toolbar link to the chat (wp-admin and front end, users with the capability).
			AdminBarLink::class,
		);
		if ( $admin ) {
			// Shared «AddSynapse» top-level menu + Overview (docs/ADMIN-MENU.md).
			$list[] = AddsynapseMenu::class;
			$list[] = new AdminPage();
			$list[] = new SettingsPage();
			// Settings → «Your own AI key»: save + test, remove.
			$list[] = new OwnKeySection();
			// Optional AddSynapse account (opt-in card) and short announcements — the plugin's own screens only.
			$list[] = new OptIn();
			$list[] = new Announcements();
		}
		return $list;
	}
}
