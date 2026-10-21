<?php
/**
 * AddSynapse → Store Assistant → Settings: the ONE settings screen of the plugin (your own AI key, images, voice,
 * pages that need a human, and an information card about the separate Pro add-on).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Account\Announcements;
use Addsynapse\StoreAssistant\Account\OptIn;
use Addsynapse\StoreAssistant\Account\Usage;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\Content\NeedsHuman;
use Addsynapse\StoreAssistant\Media\ImageUploads;
use Addsynapse\StoreAssistant\Remote\SetupStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered, admin-post + nonce + Config::settings_capability() (manage_woocommerce with WooCommerce,
 * manage_options on a plain site); the AI key additionally needs manage_options (OwnKeySection). The AI answers
 * only the chat requests of a logged-in user; nothing outside wp-admin can start anything on this site.
 */
final class SettingsPage {

	/** AddSynapse → Settings (1.3.0: its own menu item; was the «settings» tab of the Store Assistant page). */
	public const SLUG = 'addsynapse-store-assistant-settings';

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

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
		add_action( 'after_plugin_row_' . plugin_basename( ADDSYNAPSE_ASSISTANT_PLUGIN_FILE ), array( $this, 'setup_row' ), 10, 0 );
		add_action( 'admin_menu', array( $this, 'add_menu' ), AddsynapseMenu::PRIORITY_SUBMENU );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * AddSynapse → Settings.
	 */
	public function add_menu(): void {
		$this->hook = AddsynapseMenu::add_page(
			__( 'Store Assistant settings', 'addsynapse-store-assistant' ),
			__( 'Settings', 'addsynapse-store-assistant' ),
			Config::settings_capability(),
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * The small stylesheet + the «are you sure?» script (this screen only).
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}
		AdminPage::enqueue_admin_css();
		$script = ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'assets/settings.js';
		wp_enqueue_script( 'addsynapse-assistant-settings', ADDSYNAPSE_ASSISTANT_PLUGIN_URL . 'assets/settings.js', array(), ADDSYNAPSE_ASSISTANT_VERSION . '.' . ( is_readable( $script ) ? (int) filemtime( $script ) : 0 ), true );
	}

	/**
	 * The Settings screen (or its «Approval log» view).
	 */
	public function render_page(): void {
		if ( ! current_user_can( Config::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ) );
		}
		if ( 'log' === self::query_flag( 'view' ) ) {
			echo '<div class="wrap adsa-settings"><h1>' . esc_html__( 'Approval log', 'addsynapse-store-assistant' ) . '</h1>';
			echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← Settings', 'addsynapse-store-assistant' ) . '</a></p>';
			echo '<p>' . esc_html__( 'Every change AddSynapse wrote on this site after you approved it in Claude.', 'addsynapse-store-assistant' ) . '</p>';
			ConnectPage::render_log();
			echo '</div>';
			return;
		}
		echo '<div class="wrap adsa-settings"><h1 class="wp-heading-inline">' . esc_html__( 'Settings', 'addsynapse-store-assistant' ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( HomePage::setup_url( 'choose' ) ) . '">' . esc_html__( 'Setup guide', 'addsynapse-store-assistant' ) . '</a><hr class="wp-header-end">';
		Announcements::render( 'settings' );
		ReviewRequest::render( 'settings' );
		Usage::render( 'settings' );
		$this->render();
		echo '</div>';
	}

	/**
	 * Our links on the Plugins screen (<b>, not <strong>: core shows a <strong> of the title cell as a block): «Get started» (the setup wizard, until the manager finished or skipped it and no way
	 * works) or «Home», then «Settings» (also the wizard until then — the first thing to do is choose a way).
	 *
	 * @param array<int|string,string> $links Links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		if ( ! current_user_can( Config::capability() ) ) {
			return $links;
		}
		$setup = HomePage::needs_setup();
		$ours  = array(
			'addsynapse_assistant_start'    => $setup
				? '<a href="' . esc_url( HomePage::setup_url( 'choose' ) ) . '"><b>' . esc_html__( 'Get started', 'addsynapse-store-assistant' ) . '</b></a>'
				: '<a href="' . esc_url( HomePage::url() ) . '">' . esc_html__( 'Home', 'addsynapse-store-assistant' ) . '</a>',
			'addsynapse_assistant_settings' => '<a href="' . esc_url( $setup ? HomePage::setup_url( 'choose' ) : self::url() ) . '">' . esc_html__( 'Settings', 'addsynapse-store-assistant' ) . '</a>',
		);
		return array_merge( $ours, $links );
	}

	/**
	 * Under our own row of the Plugins list only, until the manager finished or skipped the wizard and no way works:
	 * one line with a «Start setup» button. No notice on any other screen; it goes away by itself.
	 */
	public function setup_row(): void {
		if ( ! current_user_can( Config::capability() ) || ! HomePage::needs_setup() ) {
			return;
		}
		$table   = $GLOBALS['wp_list_table'] ?? null;
		$columns = is_object( $table ) && method_exists( $table, 'get_column_count' ) ? (int) $table->get_column_count() : 3;
		echo '<tr class="plugin-update-tr active adsa-setup-row"><td colspan="' . (int) max( 1, $columns ) . '" class="plugin-update colspanchange">';
		echo '<div class="notice inline notice-info notice-alt"><p>' . esc_html__( 'Almost done: choose how you want to use Store Assistant — from Claude, or in a chat here in WordPress.', 'addsynapse-store-assistant' ) . ' ';
		echo '<a class="button button-primary button-small" href="' . esc_url( HomePage::setup_url( 'choose' ) ) . '">' . esc_html__( 'Start setup', 'addsynapse-store-assistant' ) . '</a></p></div>';
		echo '</td></tr>';
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
				array( 'page' => self::SLUG ),
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

		self::render_mcp();
		echo '<h2>' . esc_html__( 'Chat here in WordPress', 'addsynapse-store-assistant' ) . '</h2>';
		echo '<p>' . esc_html__( 'The assistant answers in the chat with your own AI key (Anthropic or OpenAI). Slash commands (type /help in the chat), previews, Approve and Undo work without it.', 'addsynapse-store-assistant' ) . '</p>';
		OwnKeySection::render( $notice );
		OptIn::render( 'settings' );
		OptIn::render_settings();
		Usage::render_settings();

		/**
		 * Extra Settings sections of extensions (rendered after the AI connection).
		 */
		do_action( 'addsynapse_assistant_settings_sections' );

		self::render_images();
		self::render_voice();
		self::render_needs_human();
	}

	/**
	 * «Claude»: the state of the AddSynapse connection, the setup steps and the approval log.
	 */
	private static function render_mcp(): void {
		$status = SetupStatus::connection();
		echo '<h2 id="addsynapse-assistant-mcp">' . esc_html__( 'Claude', 'addsynapse-store-assistant' ) . '</h2>';
		if ( $status['connected'] ) {
			/* translators: %s: user login */
			echo '<p>' . esc_html( sprintf( __( 'Connected. Approved by %s.', 'addsynapse-store-assistant' ), (string) $status['detail']['user'] ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( HomePage::setup_url( 'mcp' ) ) . '">' . esc_html__( 'Setup steps', 'addsynapse-store-assistant' ) . '</a> ';
		} else {
			echo '<p>' . esc_html( 'site' === Environment::site_type() ? __( 'Not connected yet. Use your site from claude.ai, without an API key.', 'addsynapse-store-assistant' ) : __( 'Not connected yet. Use your store from claude.ai, without an API key.', 'addsynapse-store-assistant' ) ) . '</p>';
			echo '<p class="adsa-settings__actions"><a class="button button-primary" href="' . esc_url( HomePage::setup_url( 'mcp' ) ) . '">' . esc_html__( 'Connect to Claude', 'addsynapse-store-assistant' ) . '</a> ';
		}
		/* translators: %d: number of entries */
		echo '<a class="button-link" href="' . esc_url( self::url( array( 'view' => 'log' ) ) ) . '">' . esc_html( sprintf( _n( 'Approval log (%d entry)', 'Approval log (%d entries)', (int) $status['log_count'], 'addsynapse-store-assistant' ), (int) $status['log_count'] ) ) . '</a></p>';
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
