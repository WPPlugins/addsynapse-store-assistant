<?php
/**
 * Database schema (dbDelta, versioned by an option). Schema 1 = 1.0.x; schema 2 = 1.1.0 (own AI key only: the
 * CSV upload tables and the AddSynapse connection settings of 1.0.x are removed on upgrade). Later versions add their
 * steps to migrate() and bump VERSION.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/updates the plugin tables.
 */
final class Schema {

	public const VERSION = '2';

	/**
	 * Options of 1.0.x that 1.1.0 no longer uses (the hosted AddSynapse connection, its trial and package status, the
	 * CSV-import mappings, the custom-field allow-list): deleted on upgrade and on uninstall.
	 */
	public const LEGACY_OPTIONS = array(
		'addsynapse_assistant_hub_url',
		'addsynapse_assistant_hub_site_key',
		'addsynapse_assistant_hub_signing_secret',
		'addsynapse_assistant_trial',
		'addsynapse_assistant_plan_status',
		'addsynapse_assistant_mappings',
		'addsynapse_assistant_meta_allowlist',
	);

	/**
	 * Tables of 1.0.x that 1.1.0 no longer uses (CSV uploads).
	 */
	public const LEGACY_TABLES  = array( 'uploads', 'upload_chunks' );
	public const VERSION_OPTION = 'addsynapse_assistant_schema_version';

	/**
	 * Table name helper.
	 *
	 * @param string $short conversations|messages|usage_log|changesets|changeset_items.
	 */
	public static function table( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . 'addsynapse_assistant_' . $short;
	}

	/**
	 * Activation hook.
	 */
	public static function install(): void {
		self::migrate();
	}

	/**
	 * Runs migrations when the stored schema version is behind.
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( self::VERSION_OPTION );
		if ( self::VERSION !== $stored ) {
			self::migrate();
			if ( '1' === $stored ) {
				self::drop_legacy();
			}
		}
	}

	/**
	 * Applies the full schema through dbDelta (idempotent).
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		$conversations   = self::table( 'conversations' );
		$messages        = self::table( 'messages' );
		$usage_log       = self::table( 'usage_log' );
		$changesets      = self::table( 'changesets' );
		$changeset_items = self::table( 'changeset_items' );

		// Note: dbDelta is picky — two spaces after PRIMARY KEY, one field per line.
		$sql = array(
			"CREATE TABLE {$conversations} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  title_auto tinyint(1) NOT NULL DEFAULT 1,
  archived tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_list (user_id,archived,updated_at)
) {$collate};",
			"CREATE TABLE {$messages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  role varchar(20) NOT NULL,
  content longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'complete',
  model varchar(100) NOT NULL DEFAULT '',
  tokens_in int(10) unsigned NOT NULL DEFAULT 0,
  tokens_out int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation (conversation_id,id)
) {$collate};",
			"CREATE TABLE {$usage_log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  purpose varchar(30) NOT NULL DEFAULT 'chat',
  model varchar(100) NOT NULL DEFAULT '',
  tokens_in int(10) unsigned NOT NULL DEFAULT 0,
  tokens_out int(10) unsigned NOT NULL DEFAULT 0,
  cache_read_tokens int(10) unsigned NOT NULL DEFAULT 0,
  cache_write_tokens int(10) unsigned NOT NULL DEFAULT 0,
  cost_usd decimal(12,6) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY created_at (created_at)
) {$collate};",
			"CREATE TABLE {$changesets} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL DEFAULT '',
  conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL,
  replanned_from bigint(20) unsigned NOT NULL DEFAULT 0,
  source longtext NULL,
  operation varchar(50) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  summary text NOT NULL,
  params longtext NULL,
  counts longtext NULL,
  log longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime DEFAULT NULL,
  expires_at datetime NOT NULL,
  approved_at datetime DEFAULT NULL,
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  applied_at datetime DEFAULT NULL,
  applied_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reverted_at datetime DEFAULT NULL,
  reverted_by bigint(20) unsigned NOT NULL DEFAULT 0,
  lock_until datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY conversation (conversation_id),
  KEY user_created (user_id,created_at),
  KEY status_expires (status,expires_at)
) {$collate};",
			"CREATE TABLE {$changeset_items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  changeset_id bigint(20) unsigned NOT NULL,
  object_type varchar(50) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  label varchar(255) NOT NULL DEFAULT '',
  sku varchar(100) NOT NULL DEFAULT '',
  operation varchar(50) NOT NULL DEFAULT 'set',
  field varchar(191) NOT NULL DEFAULT '',
  before_value longtext NULL,
  after_value longtext NULL,
  before_hash char(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  error varchar(255) NOT NULL DEFAULT '',
  applied_at datetime DEFAULT NULL,
  reverted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY changeset (changeset_id),
  KEY changeset_status (changeset_id,status),
  KEY changeset_object (changeset_id,object_id),
  KEY object (object_type,object_id)
) {$collate};",
		);

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Upgrade from 1.0.x: removes what only the hosted AddSynapse connection and the CSV import used (nothing of the
	 * shop's own content is touched).
	 */
	public static function drop_legacy(): void {
		global $wpdb;
		foreach ( self::LEGACY_TABLES as $short ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $short ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time removal of the 1.0.x upload tables.
		}
		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_transient( 'addsynapse_assistant_trial_pending' );
	}
}
