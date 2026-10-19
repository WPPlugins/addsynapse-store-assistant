<?php
/**
 * AddSynapse → Store Assistant → Settings: the ONE settings screen of the plugin (your own AI key, images, voice,
 * pages that need a human, and an information card about the separate Pro add-on).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Account\OptIn;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Content\NeedsHuman;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\Media\ImageUploads;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered, admin-post + nonce + Config::settings_capability() (manage_woocommerce with WooCommerce,
 * manage_options on a plain site); the AI key additionally needs manage_options (OwnKeySection). The AI answers
 * only the chat requests of a logged-in user; nothing outside wp-admin can start anything on this site.
 */
final class SettingsPage {

	public const ACTION_CLEAR_NEEDS_HUMAN = 'addsynapse_assistant_clear_needs_human';

	public const ACTION_SAVE_IMAGES = 'addsynapse_assistant_save_image_settings';

	/** Saves «Voice input» (opt-in). */
	public const ACTION_SAVE_VOICE = 'addsynapse_assistant_save_voice';

	/** Option: voice input (microphone button) enabled — '1' only after a manager opted in. */
	public const VOICE_OPTION = 'addsynapse_assistant_voice';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_CLEAR_NEEDS_HUMAN, array( $this, 'handle_clear_needs_human' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_VOICE, array( $this, 'handle_save_voice' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_IMAGES, array( $this, 'handle_save_images' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ADDSYNAPSE_ASSISTANT_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * «Settings» link on the Plugins screen.
	 *
	 * @param array<int|string,string> $links Links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'addsynapse-store-assistant' ) . '</a>' );
		return $links;
	}

	/**
	 * «The AI sees the images you upload» (default ON): the ONLY writer of that option.
	 */
	public function handle_save_images(): void {
		if ( ! current_user_can( Config::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_SAVE_IMAGES );
		$on = ! empty( $_POST['addsynapse_assistant_image_vision'] );
		update_option( ImageUploads::VISION_OPTION, $on ? '1' : '0', false );
		wp_safe_redirect( self::url( array( 'addsynapse_assistant_notice' => 'images_saved' ) ) . '#addsynapse-assistant-images' );
		exit;
	}

	/**
	 * Whether voice input is on (default OFF: the browser sends the audio to its speech service — Guideline 7).
	 */
	public static function voice_enabled(): bool {
		return '1' === (string) get_option( self::VOICE_OPTION, '0' );
	}

	/**
	 * «Voice input» opt-in: the ONLY writer of that option.
	 */
	public function handle_save_voice(): void {
		if ( ! current_user_can( Config::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_SAVE_VOICE );
		$on = ! empty( $_POST['addsynapse_assistant_voice'] );
		update_option( self::VOICE_OPTION, $on ? '1' : '0', false );
		wp_safe_redirect( self::url( array( 'addsynapse_assistant_notice' => 'voice_saved' ) ) . '#addsynapse-assistant-voice' );
		exit;
	}

	/**
	 * Clears the «Pages that need a human» list (the pages themselves are not touched).
	 */
	public function handle_clear_needs_human(): void {
		if ( ! current_user_can( Config::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_CLEAR_NEEDS_HUMAN );
		NeedsHuman::clear();
		wp_safe_redirect( self::url() . '#addsynapse-assistant-needs-human' );
		exit;
	}

	/**
	 * URL of the settings tab.
	 *
	 * @param array<string,string> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => AdminPage::SLUG,
					'tab'  => 'settings',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A display-only value of our own redirects (notice code, count) — never an action: read through
	 * filter_input() and sanitized; callers match it against fixed lists.
	 *
	 * @param string $name Query arg.
	 */
	private static function query_flag( string $name ): string {
		return sanitize_key( (string) filter_input( INPUT_GET, $name, FILTER_UNSAFE_RAW ) );
	}

	/**
	 * Renders the tab body.
	 */
	public function render(): void {
		$notice = self::query_flag( 'addsynapse_assistant_notice' );
		if ( in_array( $notice, array( 'voice_saved', 'images_saved' ), true ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'addsynapse-store-assistant' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'AI connection', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<p>' . esc_html__( 'The assistant answers in the chat with your own AI key (Anthropic or OpenAI). Slash commands (type /help in the chat), previews, Approve and Undo work without it.', 'addsynapse-store-assistant' ) . '</p>';
		OwnKeySection::render( $notice );
		OptIn::render( 'settings' );
		OptIn::render_settings();

		/**
		 * Extra Settings sections of extensions (rendered after the AI connection).
		 */
		do_action( 'addsynapse_assistant_settings_sections' );

		self::render_images();
		self::render_voice();
		self::render_needs_human();
		self::render_pro();
	}

	/**
	 * Images uploaded in the chat: whether the AI may see them (default ON).
	 */
	private static function render_images(): void {
		echo '<h2 id="addsynapse-assistant-images">' . esc_html__( 'Images uploaded in the chat', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<p>' . esc_html__( 'Images you attach with the paperclip, by dragging them onto the chat or by pasting them go to the media library (without their location/camera data) and are deleted automatically if no approved change uses them within 24 hours. The assistant can use one as a product\'s main or gallery image, as a preview you approve.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE_IMAGES );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_IMAGES ) . '" />';
		echo '<p><label><input type="checkbox" name="addsynapse_assistant_image_vision" value="1"' . checked( ImageUploads::vision_enabled(), true, false ) . ' /> ' . esc_html__( 'The AI sees the images you upload', 'addsynapse-store-assistant' ) . '</label></p>';
		echo '<p class="description aiops-settings-note">' . esc_html__( 'On by default: when you attach an image, a reduced copy (max 1024 px) is sent with that message to your AI provider, so it can see it (it costs a little more per message). Nothing is sent for messages without an image. When off, the AI only knows the file name, size and dimensions.', 'addsynapse-store-assistant' ) . '</p>';
		submit_button( __( 'Save', 'addsynapse-store-assistant' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * «Coming soon: Store Assistant Pro»: information about the separate add-on (not released; nothing here checks for it).
	 */
	private static function render_pro(): void {
		$items = array();
		if ( Environment::has_woocommerce() ) {
			$items[] = __( 'Bulk changes: prices, stock and product details for a whole category, a brand or the whole catalogue', 'addsynapse-store-assistant' );
			$items[] = __( 'Price and stock import from a CSV file', 'addsynapse-store-assistant' );
			$items[] = __( 'Creating, renaming and merging categories, tags and brands', 'addsynapse-store-assistant' );
			$items[] = __( 'Product attributes and variations, custom fields, other product settings', 'addsynapse-store-assistant' );
			$items[] = __( 'Coupons and orders', 'addsynapse-store-assistant' );
		}
		$items[] = __( 'Site appearance: menus, page texts, sections and banners, posts and blog categories', 'addsynapse-store-assistant' );
		$items[] = __( 'SEO and image alt texts at scale, content writing', 'addsynapse-store-assistant' );
		$items[] = __( 'A full change history with undo of older changes', 'addsynapse-store-assistant' );
		$items[] = __( 'An AI you do not need a key for (the AddSynapse service)', 'addsynapse-store-assistant' );
		echo '<div class="aiops-connector__card aiops-pro-card" id="addsynapse-assistant-pro">';
		echo '<h2>' . esc_html__( 'Coming soon: Store Assistant Pro', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<p>' . esc_html__( 'This plugin is free and edits one product at a time. Store Assistant Pro, a separate add-on for work beyond single products, is coming soon:', 'addsynapse-store-assistant' ) . '</p>';
		echo '<ul class="aiops-pro-card__list">';
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Voice input (microphone button in the chat): opt-in, default OFF.
	 */
	private static function render_voice(): void {
		echo '<h2 id="addsynapse-assistant-voice">' . esc_html__( 'Voice input', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE_VOICE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_VOICE ) . '" />';
		echo '<p><label><input type="checkbox" name="addsynapse_assistant_voice" value="1"' . checked( self::voice_enabled(), true, false ) . ' /> ' . esc_html__( 'Show the microphone button in the chat', 'addsynapse-store-assistant' ) . '</label></p>';
		echo '<p class="description aiops-settings-note">' . esc_html__( 'Voice input uses your browser\'s speech recognition: while you dictate, the browser sends the audio to its own speech service (for example Google for Chrome, Apple for Safari) under that provider\'s terms. This plugin and AddSynapse never receive the audio — only the text you send. Off by default.', 'addsynapse-store-assistant' ) . '</p>';
		submit_button( __( 'Save', 'addsynapse-store-assistant' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * «Pages that need a human»: pages the assistant was asked about but cannot edit (unsupported
	 * builder / classic editor / unreadable content). For the developer; read-only list.
	 */
	private static function render_needs_human(): void {
		$rows = NeedsHuman::all();
		echo '<h2 id="addsynapse-assistant-needs-human">' . esc_html__( 'Pages that need a human', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pages the assistant was asked to show or change but cannot edit (a page builder it does not support yet, the classic editor, or content it cannot read safely). Nothing was changed on them; edit them in WordPress or ask your developer.', 'addsynapse-store-assistant' ) . '</p>';
		if ( ! $rows ) {
			echo '<p><em>' . esc_html__( 'Nothing yet.', 'addsynapse-store-assistant' ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped aiops-settings-table--wide"><thead><tr><th>' . esc_html__( 'Page', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Builder', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Reason', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Requests', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Last', 'addsynapse-store-assistant' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$link = get_edit_post_link( (int) $row['page_id'], 'raw' );
			echo '<tr><td>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( (string) $row['title'] ) . '</a>' : esc_html( (string) $row['title'] ) ) . ' <code>#' . (int) $row['page_id'] . '</code></td>';
			echo '<td>' . esc_html( (string) $row['builder'] ) . '</td><td>' . esc_html( (string) $row['reason'] ) . '</td><td>' . (int) $row['count'] . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( (string) $row['at'], 'Y-m-d H:i' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_CLEAR_NEEDS_HUMAN );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CLEAR_NEEDS_HUMAN ) . '" />';
		submit_button( __( 'Clear the list', 'addsynapse-store-assistant' ), 'secondary' );
		echo '</form>';
	}
}
