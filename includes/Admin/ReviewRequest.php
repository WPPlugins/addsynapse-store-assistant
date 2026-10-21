<?php
/**
 * A one-time request for a review on wordpress.org, after the manager has approved enough changes.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Guideline 11 (no nagging): shown ONLY on the plugin's own screen (above the chat and in Settings), only to users who
 * may change the plugin's settings, only once the site has at least THRESHOLD approved changes, and gone forever for that
 * user after «Leave a review» or «Don't ask again» (user meta). No network call: the review link is a plain link.
 */
final class ReviewRequest {

	/** Approved changes before the request appears. */
	public const THRESHOLD = 10;

	/** User meta: the request was answered (review left or dismissed). */
	public const META = 'addsynapse_assistant_review_dismissed';

	public const ACTION = 'addsynapse_assistant_review';

	public const REVIEW_URL = 'https://wordpress.org/support/plugin/addsynapse-store-assistant/reviews/#new-post';

	/** The host «Leave a review» may redirect to. */
	private const HOST = 'wordpress.org';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Changes the manager approved on this site (applied, partly applied, or applied and undone later).
	 */
	public static function approved_count(): int {
		global $wpdb;
		$table = Schema::table( 'changesets' );
		// Small aggregate on our own table, read on our screen only.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN ( %s, %s, %s )', $table, 'applied', 'partially_applied', 'reverted' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Whether the request is shown to the current user now.
	 */
	public static function eligible(): bool {
		if ( ! current_user_can( Config::settings_capability() ) ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), self::META, true ) ) {
			return false;
		}
		return self::approved_count() >= self::THRESHOLD;
	}

	/**
	 * «Leave a review» (opens wordpress.org) or «Don't ask again»: either answers the request for good.
	 */
	public function handle(): void {
		if ( ! current_user_can( Config::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$choice = isset( $_POST['addsynapse_assistant_review'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_review'] ) ) : '';
		update_user_meta( get_current_user_id(), self::META, 'review' === $choice ? 'review' : 'dismissed' );
		if ( 'review' === $choice ) {
			add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_host' ) );
			wp_safe_redirect( self::REVIEW_URL );
			exit;
		}
		$tab = isset( $_POST['addsynapse_assistant_tab'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_tab'] ) ) : '';
		wp_safe_redirect( AdminPage::back_url( $tab ) );
		exit;
	}

	/**
	 * Allows wordpress.org for the «Leave a review» redirect.
	 *
	 * @param mixed $hosts Hosts.
	 * @return array<int,string>
	 */
	public static function allow_host( $hosts ): array {
		$hosts   = is_array( $hosts ) ? $hosts : array();
		$hosts[] = self::HOST;
		return $hosts;
	}

	/**
	 * The request (server-rendered, on the plugin's own screen).
	 *
	 * @param string $tab chat | settings.
	 */
	public static function render( string $tab ): void {
		if ( ! self::eligible() ) {
			return;
		}
		$count = self::approved_count();
		echo '<div class="aiops-connector__card aiops-review" id="addsynapse-assistant-review">';
		/* translators: %d: number of approved changes. */
		echo '<p><strong>' . esc_html( sprintf( _n( 'You have approved %d change with Store Assistant.', 'You have approved %d changes with Store Assistant.', $count, 'addsynapse-store-assistant' ), $count ) ) . '</strong> ';
		echo esc_html__( 'If it saves you time, a short review on WordPress.org helps other shop owners find it.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aiops-review__actions">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="addsynapse_assistant_tab" value="' . esc_attr( $tab ) . '" />';
		echo '<button type="submit" class="button button-primary" name="addsynapse_assistant_review" value="review" formtarget="_blank">' . esc_html__( 'Leave a review', 'addsynapse-store-assistant' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'addsynapse-store-assistant' ) . '</span></button> ';
		echo '<button type="submit" class="button button-link" name="addsynapse_assistant_review" value="dismiss">' . esc_html__( 'Don\'t ask again', 'addsynapse-store-assistant' ) . '</button>';
		echo '</form></div>';
	}
}
