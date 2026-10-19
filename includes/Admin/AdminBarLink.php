<?php
/**
 * «Store Assistant» link in the WordPress toolbar (admin bar).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Config;

defined( 'ABSPATH' ) || exit;

/**
 * One plain toolbar link (icon + label) to the chat, in wp-admin and on the front end, for users who can use the assistant.
 * No counter, badge, notice or promotion (Guideline 11); nothing is sent anywhere.
 */
final class AdminBarLink {

	public const NODE = 'addsynapse-store-assistant';

	/**
	 * Registers hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_bar_menu', array( self::class, 'add_node' ), 80 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'style' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'style' ) );
	}

	/**
	 * Whether the current user sees the link.
	 */
	private static function visible(): bool {
		return is_user_logged_in() && current_user_can( Config::capability() );
	}

	/**
	 * Adds the node.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 */
	public static function add_node( $bar ): void {
		if ( ! self::visible() || ! is_object( $bar ) || ! method_exists( $bar, 'add_node' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Store Assistant', 'addsynapse-store-assistant' ) . '</span>',
				'href'  => esc_url( AdminPage::url() ),
				'meta'  => array(
					'title' => esc_attr__( 'Open the AI chat of Store Assistant', 'addsynapse-store-assistant' ),
				),
			)
		);
	}

	/**
	 * The icon (a Dashicons glyph) — a few lines added to the toolbar's own stylesheet, only when the toolbar shows.
	 */
	public static function style(): void {
		if ( ! self::visible() || ! is_admin_bar_showing() ) {
			return;
		}
		wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-' . self::NODE . ' .ab-icon:before{content:"\f125";top:2px}' );
	}
}
