<?php
/**
 * Settings → «Measurement codes and JavaScript added by the AI»: what the site carries, the JavaScript switch (OFF by
 * default, administrators with manage_options only), the other domains scripts may talk to, and the refused scripts.
 * The ONLY writer of the switch and of the domain list: neither can be changed from AddSynapse or by the AI.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Tracking\Providers;
use Addsynapse\StoreAssistant\Tracking\TrackingStore;
use Addsynapse\StoreAssistant\Tracking\TrackingUnits;

defined( 'ABSPATH' ) || exit;

/**
 * Settings section + its admin-post handler.
 */
final class TrackingSection {

	public const ACTION = 'addsynapse_assistant_save_js';

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'addsynapse_assistant_settings_sections', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Saves the switch and the domains (manage_options only).
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$on      = ! empty( $_POST['addsynapse_assistant_allow_js'] );
		$typed   = isset( $_POST['addsynapse_assistant_js_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['addsynapse_assistant_js_domains'] ) ) : '';
		$domains = array();
		foreach ( preg_split( '/[\s,]+/', $typed ) as $domain ) {
			$domain = TrackingStore::clean_domain( (string) $domain );
			if ( '' !== $domain ) {
				$domains[] = $domain;
			}
		}
		update_option( TrackingStore::ALLOW_JS, $on ? '1' : '0', false );
		update_option( TrackingStore::JS_DOMAINS, array_slice( array_values( array_unique( $domains ) ), 0, TrackingStore::MAX_DOMAINS ), false );
		TrackingUnits::purge_page_caches();
		wp_safe_redirect( SettingsPage::url( array( 'addsynapse_assistant_notice' => 'js_saved' ) ) . '#addsynapse-assistant-tracking' );
		exit;
	}

	/**
	 * The section.
	 */
	public function render(): void {
		echo '<h2 id="addsynapse-assistant-tracking">' . esc_html__( 'Measurement codes and JavaScript added by the AI', 'addsynapse-store-assistant' ) . '</h2>';
		if ( 'js_saved' === sanitize_key( (string) filter_input( INPUT_GET, 'addsynapse_assistant_notice', FILTER_UNSAFE_RAW ) ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'addsynapse-store-assistant' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Measurement codes (Google Analytics 4, Google Tag Manager, Google Ads, Meta Pixel, Microsoft Clarity, TikTok Pixel) you approved from Claude. The AI only gives the provider and the ID; the plugin writes the official code itself. Each one can be undone from the approval card.', 'addsynapse-store-assistant' ) . '</p>';
		$codes = TrackingStore::codes();
		if ( $codes ) {
			echo '<ul class="ul-disc">';
			foreach ( $codes as $provider => $id ) {
				echo '<li>' . esc_html( Providers::name( $provider ) . ': ' . $id ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="description">' . esc_html__( 'No measurement code added through AddSynapse.', 'addsynapse-store-assistant' ) . '</p>';
		}

		$can = current_user_can( 'manage_options' );
		echo '<h3>' . esc_html__( 'JavaScript added by the AI', 'addsynapse-store-assistant' ) . '</h3>';
		echo '<p>' . esc_html__( 'Off by default. When on, the AI may prepare small scripts as drafts: each one is checked by the plugin (no hidden or encoded code, no cookies, no form fields, no redirects, only the domains below), shown in full on the approval card, and runs only after you approve it. Scripts never run on checkout, cart, my account, login or wp-admin pages. Turning this off stops every script at once.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<p><label><input type="checkbox" name="addsynapse_assistant_allow_js" value="1"' . checked( TrackingStore::js_allowed(), true, false ) . disabled( $can, false, false ) . ' /> ' . esc_html__( 'Allow the AI to add JavaScript', 'addsynapse-store-assistant' ) . '</label></p>';
		echo '<p><label for="addsynapse-assistant-js-domains">' . esc_html__( 'Other websites scripts may talk to (one per line, e.g. widget.example.com). Your own site is always allowed.', 'addsynapse-store-assistant' ) . '</label><br />';
		echo '<textarea id="addsynapse-assistant-js-domains" name="addsynapse_assistant_js_domains" rows="3" cols="50" class="large-text code"' . disabled( $can, false, false ) . '>' . esc_textarea( implode( "\n", TrackingStore::js_domains() ) ) . '</textarea></p>';
		if ( $can ) {
			submit_button( __( 'Save', 'addsynapse-store-assistant' ), 'secondary' );
		} else {
			echo '<p class="description">' . esc_html__( 'Only an administrator can change this.', 'addsynapse-store-assistant' ) . '</p>';
		}
		echo '</form>';

		$scripts = TrackingStore::scripts();
		if ( $scripts ) {
			echo '<h4>' . esc_html__( 'Scripts on this site', 'addsynapse-store-assistant' ) . '</h4>';
			foreach ( $scripts as $script ) {
				echo '<details><summary>' . esc_html( $script['label'] . ' — ' . TrackingUnits::pages_text( $script['pages'] ) ) . '</summary>';
				if ( '' !== $script['about'] ) {
					echo '<p>' . esc_html( $script['about'] ) . '</p>';
				}
				echo '<pre class="code">' . esc_html( $script['code'] ) . '</pre></details>';
			}
		}
		$refused = array_slice( TrackingStore::refusals(), 0, 10 );
		if ( $refused ) {
			echo '<h4>' . esc_html__( 'Scripts the plugin refused (latest)', 'addsynapse-store-assistant' ) . '</h4><ul class="ul-disc">';
			foreach ( $refused as $row ) {
				echo '<li>' . esc_html( wp_date( 'Y-m-d H:i', (int) $row['time'] ) . ' · ' . (string) $row['label'] . ': ' . (string) $row['reason'] ) . '</li>';
			}
			echo '</ul>';
		}
	}
}
