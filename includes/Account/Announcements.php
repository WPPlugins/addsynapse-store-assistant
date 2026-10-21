<?php
/**
 * Short announcements on the plugin's own screens: bundled with a release, or from AddSynapse for sites with an account.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Account;

use Addsynapse\StoreAssistant\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Two sources, one look (text + at most one link, dismissible per user, no images, no scripts, no tracking):
 *  - BUNDLED: shipped in the plugin with a date window (for example a seasonal notice in a future release). Nothing is
 *    fetched for them. The list is empty in this release.
 *  - From AddSynapse: ONLY when this site created an account (Connection). At most once a day, only while a user opens one
 *    of the plugin's own screens, the plugin sends the site key, its version and the site language to
 *    `POST /api/v1/assistant/announcements` and keeps the answer for a day. Nothing is sent without an account.
 * Shown only on the plugin's own screens (Guideline 11), at most MAX_SHOWN at a time.
 */
final class Announcements {

	/** Cached remote announcements: fetched_at + items. Not autoloaded. */
	public const OPTION = 'addsynapse_assistant_announcements';

	/** User meta: ids the user dismissed. */
	public const DISMISSED_META = 'addsynapse_assistant_dismissed_announcements';

	public const ACTION_DISMISS = 'addsynapse_assistant_dismiss_announcement';

	public const MAX_SHOWN = 2;

	/**
	 * Announcements shipped with this release: id (unique, `b-…`), title, body, link_url (https, optional), link_label,
	 * starts/ends (Y-m-d, site time zone, inclusive; optional). Empty in this release.
	 *
	 * @var array<int,array<string,string>>
	 */
	public const BUNDLED = array();

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Bundled announcements whose window includes $now.
	 *
	 * @param array<int,array<string,string>> $items List (default BUNDLED).
	 * @param int|null                        $now  Unix time.
	 * @return array<int,array{id:string,title:string,body:string,link_url:string,link_label:string}>
	 */
	public static function bundled( array $items = self::BUNDLED, ?int $now = null ): array {
		$now   = $now ?? time();
		$today = wp_date( 'Y-m-d', $now );
		$out   = array();
		foreach ( $items as $item ) {
			$starts = (string) ( $item['starts'] ?? '' );
			$ends   = (string) ( $item['ends'] ?? '' );
			if ( ( '' !== $starts && $today < $starts ) || ( '' !== $ends && $today > $ends ) ) {
				continue;
			}
			$clean = self::clean( $item, 'b-' );
			if ( null !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Remote announcements (cached for a day; fetched only with an account).
	 *
	 * @param int|null $now Unix time.
	 * @return array<int,array{id:string,title:string,body:string,link_url:string,link_label:string}>
	 */
	public static function remote( ?int $now = null ): array {
		$connection = Connection::get();
		if ( null === $connection ) {
			return array();
		}
		$now    = $now ?? time();
		$cached = get_option( self::OPTION, array() );
		$cached = is_array( $cached ) ? $cached : array();
		if ( $now - (int) ( $cached['fetched_at'] ?? 0 ) >= DAY_IN_SECONDS ) {
			$cached = array(
				'fetched_at' => $now,
				'items'      => self::fetch( $connection['site_key'] ),
			);
			update_option( self::OPTION, $cached, false );
		}
		$out = array();
		foreach ( (array) ( $cached['items'] ?? array() ) as $item ) {
			$ends = strtotime( (string) ( $item['ends_at'] ?? '' ) );
			if ( false !== $ends && $ends < $now ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * One call to AddSynapse (failures = nothing to show until tomorrow).
	 *
	 * @param string $site_key Site key.
	 * @return array<int,array<string,string>>
	 */
	private static function fetch( string $site_key ): array {
		$answer = Api::post(
			'/api/v1/assistant/announcements',
			array(
				'site_key'       => $site_key,
				'plugin_version' => ADDSYNAPSE_ASSISTANT_VERSION,
				'locale'         => (string) get_locale(),
			),
			5
		);
		if ( is_wp_error( $answer ) || 200 !== $answer['status'] ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( (array) ( $answer['body']['announcements'] ?? array() ), 0, 5 ) as $item ) {
			$clean = is_array( $item ) ? self::clean( $item, 'r-' ) : null;
			if ( null !== $clean ) {
				$clean['ends_at'] = is_string( $item['ends_at'] ?? null ) ? sanitize_text_field( $item['ends_at'] ) : '';
				$out[]            = $clean;
			}
		}
		return $out;
	}

	/**
	 * Plain text + one checked link.
	 *
	 * @param array<string,mixed> $item   Item.
	 * @param string              $prefix Id prefix (b- bundled, r- remote).
	 * @return array{id:string,title:string,body:string,link_url:string,link_label:string}|null
	 */
	private static function clean( array $item, string $prefix ): ?array {
		$id    = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $item['id'] ?? '' ) );
		$title = sanitize_text_field( mb_substr( wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ), 0, 120 ) );
		$body  = sanitize_textarea_field( mb_substr( wp_strip_all_tags( (string) ( $item['body'] ?? '' ) ), 0, 500 ) );
		if ( '' === $id || ( '' === $title && '' === $body ) ) {
			return null;
		}
		// One link, and only to an AddSynapse website (Api::link_allowed: https on addsynapse.com hosts).
		$link = esc_url_raw( (string) ( $item['link_url'] ?? '' ), array( 'https', 'http' ) );
		if ( '' !== $link && ! Api::link_allowed( $link ) ) {
			$link = '';
		}
		$label = sanitize_text_field( mb_substr( (string) ( $item['link_label'] ?? '' ), 0, 40 ) );
		return array(
			'id'         => 0 === strpos( $id, $prefix ) ? $id : $prefix . $id,
			'title'      => $title,
			'body'       => $body,
			'link_url'   => $link,
			'link_label' => '' !== $link && '' === $label ? __( 'Learn more', 'addsynapse-store-assistant' ) : $label,
		);
	}

	/**
	 * Ids the current user dismissed.
	 *
	 * @return array<int,string>
	 */
	private static function dismissed(): array {
		$ids = get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * What the current user sees now.
	 *
	 * @param int|null $now Unix time.
	 * @return array<int,array{id:string,title:string,body:string,link_url:string,link_label:string}>
	 */
	public static function visible( ?int $now = null ): array {
		$hidden = array_flip( self::dismissed() );
		$out    = array();
		foreach ( array_merge( self::bundled( self::BUNDLED, $now ), self::remote( $now ) ) as $item ) {
			if ( ! isset( $hidden[ $item['id'] ] ) ) {
				$out[] = $item;
			}
		}
		return array_slice( $out, 0, self::MAX_SHOWN );
	}

	/**
	 * «Dismiss» (per user).
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( Config::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_DISMISS );
		$id  = isset( $_POST['addsynapse_assistant_announcement'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( wp_unslash( $_POST['addsynapse_assistant_announcement'] ) ) ) : '';
		$tab = isset( $_POST['addsynapse_assistant_tab'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_tab'] ) ) : '';
		if ( '' !== $id ) {
			$ids   = self::dismissed();
			$ids[] = $id;
			update_user_meta( get_current_user_id(), self::DISMISSED_META, array_slice( array_values( array_unique( $ids ) ), -100 ) );
		}
		wp_safe_redirect( \Addsynapse\StoreAssistant\Admin\AdminPage::back_url( $tab ) );
		exit;
	}

	/**
	 * Renders the visible announcements (plugin screens only).
	 *
	 * @param string $tab chat | settings.
	 */
	public static function render( string $tab ): void {
		foreach ( self::visible() as $item ) {
			echo '<div class="aiops-announcement" role="status"><p>';
			if ( '' !== $item['title'] ) {
				echo '<strong>' . esc_html( $item['title'] ) . '</strong> ';
			}
			echo esc_html( $item['body'] );
			if ( '' !== $item['link_url'] ) {
				echo ' <a href="' . esc_url( $item['link_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['link_label'] ) . '</a>';
			}
			echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aiops-announcement__dismiss">';
			wp_nonce_field( self::ACTION_DISMISS );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISMISS ) . '" />';
			echo '<input type="hidden" name="addsynapse_assistant_announcement" value="' . esc_attr( $item['id'] ) . '" />';
			echo '<input type="hidden" name="addsynapse_assistant_tab" value="' . esc_attr( $tab ) . '" />';
			submit_button( __( 'Dismiss', 'addsynapse-store-assistant' ), 'secondary small', 'submit', false );
			echo '</form></div>';
		}
	}
}
