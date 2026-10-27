<?php
/**
 * Uninstall (Plugins → Delete): removes EVERYTHING the plugin stored (wp.org guide §2.6, docs/13 → Α6).
 *
 * Deleting the plugin means deleting its data: chat history, change history (Undo is no longer possible),
 * settings and the AI key (also what 1.0.x stored: CSV uploads, connection keys), and (1.3.0) every application password
 * WordPress issued to AddSynapse on the «Authorize Application» screen, plus the Connect AI state and approval log. Content the approved changes wrote
 * into products, pages and menus stays — it belongs to the site. Images uploaded in the chat that no approved change ever
 * used (still «pending») are deleted with their files; images in use stay in the media library.
 *
 * Standalone on purpose (no plugin class is loaded): explicit lists, then LIKE as a fallback for names with a
 * suffix (transients per user/token). Every site of a multisite network.
 *
 * Re-read line by line after every rename (guide §5): the lists come from `grep -rhoE "'_?addsynapse_assistant_[a-z0-9_]+'" includes`.
 *
 * @package Addsynapse\StoreAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Revokes every AddSynapse application password of the current site's users — matched by the fixed app_id that
 * AddSynapse passes to the «Authorize Application» screen (same value as RemoteAccess::APP_ID; a test keeps them equal),
 * never by name: passwords the owner created for other apps stay. Multisite: users of the site being cleaned.
 */
function addsynapse_assistant_uninstall_revoke_app_passwords() {
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return;
	}
	$app_id = '6f1c2a5e-8d3b-4c71-9e2a-ad5f0c3b7e41';
	$users  = get_users(
		array(
			'meta_key' => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only users who have application passwords; uninstall only.
			'fields'   => 'ID',
			'number'   => -1,
		)
	);
	foreach ( $users as $user_id ) {
		foreach ( WP_Application_Passwords::get_user_application_passwords( (int) $user_id ) as $item ) {
			if ( isset( $item['app_id'], $item['uuid'] ) && $app_id === $item['app_id'] ) {
				WP_Application_Passwords::delete_application_password( (int) $user_id, (string) $item['uuid'] );
			}
		}
	}
}

/**
 * Removes the plugin's data from the current site.
 */
function addsynapse_assistant_uninstall_site() {
	global $wpdb;

	// The plugin's own tables (see Install/Schema.php; uploads / upload_chunks = 1.0.x).
	foreach ( array( 'conversations', 'messages', 'usage_log', 'changesets', 'changeset_items', 'uploads', 'upload_chunks' ) as $short ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'addsynapse_assistant_' . $short ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	// 1.3.0: AddSynapse application passwords of this site's users (Remote/RemoteAccess::revoke_all()).
	addsynapse_assistant_uninstall_revoke_app_passwords();

	// Options (explicit).
	$options = array(
		'addsynapse_assistant_schema_version',
		// 1.0.x (Install/Schema LEGACY_OPTIONS): the hosted connection, trial, package status, mappings, allow-list.
		'addsynapse_assistant_hub_url',
		'addsynapse_assistant_hub_site_key',
		'addsynapse_assistant_hub_signing_secret',
		'addsynapse_assistant_trial',
		'addsynapse_assistant_plan_status',
		'addsynapse_assistant_mappings',
		'addsynapse_assistant_meta_allowlist',
		'addsynapse_assistant_image_vision',
		'addsynapse_assistant_needs_human',
		'addsynapse_assistant_voice',
		// 1.1.0: the manager's own AI key (encrypted), its provider/model and the compatibility mode.
		'addsynapse_assistant_ai_provider',
		'addsynapse_assistant_ai_key',
		'addsynapse_assistant_ai_step_mode',
		// 1.1.0: the optional AddSynapse account (site key, encrypted), the opt-in card state, cached announcements.
		'addsynapse_assistant_account',
		'addsynapse_assistant_optin',
		'addsynapse_assistant_announcements',
		// 1.2.1: the optional anonymous usage info (choice + random id).
		'addsynapse_assistant_usage',
		// 1.3.0: Connect AI — the last setup check and the approval log (used ticket nonces go with the LIKE fallback).
		'addsynapse_assistant_connect_check',
		'addsynapse_assistant_remote_log',
		// 1.3.0: the welcome wizard (finished / skipped, chosen way).
		'addsynapse_assistant_onboarding',
		// 1.3.5: Home's «You're all set!» panel (first shown, hidden).
		'addsynapse_assistant_allset',
		// 1.3.0: the AddSynapse connection AddSynapse confirmed (uuid of the password, user, time — never the password).
		'addsynapse_assistant_relay_claimed',
		// 1.3.1: a claimed password AddSynapse has not used yet (uuid, user, time — never the password).
		'addsynapse_assistant_relay_pending',
		// 1.3.2: the plan and today's count AddSynapse reported (no personal data).
		'addsynapse_assistant_relay_plan',
		// 1.3.4: the AddSynapse connection keys (hashes only) and the per-site secret of those hashes.
		'addsynapse_assistant_relay_keys',
		'addsynapse_assistant_relay_key_pepper',
		// 1.3.4: drafts waiting on the wp-admin approval page (coupons above 50 % off), and when each key was last used.
		'addsynapse_assistant_admin_approvals',
		'addsynapse_assistant_relay_keys_used',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients (explicit) — the per-user/per-token ones go with the LIKE fallback below.
	foreach ( array( 'addsynapse_assistant_store_map_v1', 'addsynapse_assistant_store_summary_v1', 'addsynapse_assistant_trial_pending' ) as $transient ) {
		delete_transient( $transient );
	}

	// Fallback: anything left with our prefix (persistent object caches may hide some from delete_option()).
	$like = array(
		$wpdb->esc_like( 'addsynapse_assistant_' ) . '%',
		$wpdb->esc_like( '_transient_addsynapse_assistant_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_addsynapse_assistant_' ) . '%',
		$wpdb->esc_like( '_site_transient_addsynapse_assistant_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_addsynapse_assistant_' ) . '%',
	);
	foreach ( $like as $pattern ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// Announcements each user dismissed; the answer to the one-time review request (1.2.0).
	delete_metadata( 'user', 0, 'addsynapse_assistant_dismissed_announcements', '', true );
	delete_metadata( 'user', 0, 'addsynapse_assistant_review_dismissed', '', true );

	// Chat images never used by an approved change («pending»): the attachment and every size of its file.
	$pending = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_addsynapse_assistant_pending' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	foreach ( $pending as $attachment_id ) {
		wp_delete_attachment( (int) $attachment_id, true );
	}

	// Our post meta (images in use, coupons created by a change) — the posts themselves stay.
	foreach ( array( '_addsynapse_assistant_pending', '_addsynapse_assistant_uploaded', '_addsynapse_assistant_conversation', '_addsynapse_assistant_changeset', '_addsynapse_assistant_used_at', '_addsynapse_assistant_source_url' ) as $meta_key ) {
		delete_metadata( 'post', 0, $meta_key, '', true );
	}
	// Orders created by a change on HPOS stores keep their meta in the WooCommerce orders meta table.
	$orders_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) ) === $orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $orders_meta, '_addsynapse_assistant_changeset' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// Scheduled work: WP-Cron events and Action Scheduler actions (also without WooCommerce loaded).
	$hooks = array( 'addsynapse_assistant_imports_cleanup', 'addsynapse_assistant_images_cleanup', 'addsynapse_assistant_changeset_process', 'addsynapse_assistant_usage_ping', 'addsynapse_assistant_relay_pending_check' );
	foreach ( $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
	}
	$actions = $wpdb->prefix . 'actionscheduler_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) === $actions ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $hooks as $hook ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook = %s', $actions, $hook ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $addsynapse_assistant_site_id ) {
		switch_to_blog( (int) $addsynapse_assistant_site_id );
		addsynapse_assistant_uninstall_site();
		restore_current_blog();
	}
} else {
	addsynapse_assistant_uninstall_site();
}
