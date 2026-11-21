<?php
/**
 * Remote change set units of measurement codes and AI scripts (read-state reads them, apply-changes writes them,
 * through DesignUnits — the same Approve / Undo / verify as every appearance change).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 *  - tracking  key = provider (ga4, gtm, google_ads, meta, clarity, tiktok): values {id: ID|null};
 *  - script    key = script key: values {script: JSON text of {label, about, pages, code} | null}.
 * Every write is checked again here (ID pattern, the JavaScript switch, JsGuard, protected pages, capabilities).
 */
final class TrackingUnits {

	public const KINDS = array( 'tracking', 'script' );

	/**
	 * Whether a kind is ours.
	 *
	 * @param string $kind Kind.
	 */
	public static function handles( string $kind ): bool {
		return in_array( $kind, self::KINDS, true );
	}

	/**
	 * Name, type and values of a unit.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 * @return array{name:string,type:string,values:array<string,mixed>}
	 * @throws \InvalidArgumentException When it cannot be read (or not by this user).
	 */
	public static function read_unit( string $kind, string $key ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \InvalidArgumentException( 'Measurement codes and scripts need an administrator (manage_options).' );
		}
		if ( 'tracking' === $kind ) {
			if ( ! Providers::known( $key ) ) {
				throw new \InvalidArgumentException( 'Unknown measurement provider.' );
			}
			$codes = TrackingStore::codes();
			return array(
				'name'   => Providers::name( $key ) . ' code',
				'type'   => 'tracking_code',
				'values' => array( 'id' => $codes[ $key ] ?? null ),
			);
		}
		if ( ! TrackingStore::valid_key( $key ) ) {
			throw new \InvalidArgumentException( 'Unknown script.' );
		}
		$scripts = TrackingStore::scripts();
		return array(
			'name'   => 'Script: ' . ( isset( $scripts[ $key ] ) ? (string) $scripts[ $key ]['label'] : $key ),
			'type'   => 'custom_script',
			'values' => array( 'script' => isset( $scripts[ $key ] ) ? (string) wp_json_encode( $scripts[ $key ] ) : null ),
		);
	}

	/**
	 * Why the values may not be written ('' = fine).
	 *
	 * @param string              $kind Kind.
	 * @param string              $key  Key.
	 * @param array<string,mixed> $set  Values.
	 */
	public static function invalid( string $kind, string $key, array $set ): string {
		$field = 'tracking' === $kind ? 'id' : 'script';
		if ( array_keys( $set ) !== array( $field ) ) {
			return 'Only ' . $field . ' can be set.';
		}
		if ( null === $set[ $field ] ) {
			return '';
			// Removing (or the Undo of a first add) is always allowed, even with the JavaScript switch off.
		}
		if ( 'tracking' === $kind ) {
			$id = Providers::normalize( $key, $set['id'] );
			return '' !== $id && $id === $set['id'] ? '' : Providers::id_problem( $key, $set['id'] );
		}
		if ( ! is_string( $set['script'] ) ) {
			return 'Invalid script.';
		}
		$script = json_decode( $set['script'], true );
		if ( ! is_array( $script ) || wp_json_encode( TrackingStore::shape( $script ) ) !== $set['script'] ) {
			return 'Invalid script.';
		}
		$scripts = TrackingStore::scripts();
		if ( ! isset( $scripts[ $key ] ) && count( $scripts ) >= TrackingStore::MAX_SCRIPTS ) {
			return sprintf( 'This site already has %d scripts added by the AI (the most).', TrackingStore::MAX_SCRIPTS );
		}
		return self::script_problem( $script );
	}

	/**
	 * Why a script may not be stored ('' = fine): the switch, capabilities, label, pages and JsGuard.
	 *
	 * @param array<string,mixed> $script Shaped script.
	 */
	public static function script_problem( array $script ): string {
		if ( ! TrackingStore::js_allowed() ) {
			return self::SWITCH_OFF;
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return 'Adding JavaScript needs an administrator who may add unfiltered HTML (unfiltered_html).';
		}
		if ( '' === trim( (string) $script['label'] ) || mb_strlen( (string) $script['label'] ) > 80 ) {
			return 'The script needs a short label (1–80 characters) saying what it does.';
		}
		if ( mb_strlen( (string) $script['about'] ) > 500 ) {
			return 'The description is too long (500 characters at most).';
		}
		$problem = self::pages_problem( $script['pages'] );
		if ( '' !== $problem ) {
			return $problem;
		}
		return JsGuard::check( $script['code'], TrackingStore::js_domains(), TrackingStore::own_host() );
	}

	/** The answer while «Allow the AI to add JavaScript» is off. */
	public const SWITCH_OFF = 'JavaScript from the AI is turned off on this site. The site owner can turn it on in WordPress → AddSynapse → Settings → «JavaScript added by the AI» → «Allow the AI to add JavaScript» (administrators only), then ask again. Measurement codes (Google Analytics, Tag Manager, Google Ads, Meta, Clarity, TikTok) do not need it.';

	/**
	 * Pages of a script: 'all', 'front_page' or 1–50 published pages / posts that are not checkout, cart or account pages.
	 *
	 * @param mixed $pages Pages.
	 */
	public static function pages_problem( $pages ): string {
		if ( in_array( $pages, array( 'all', 'front_page' ), true ) ) {
			return '';
		}
		if ( ! is_array( $pages ) || ! $pages || count( $pages ) > 50 ) {
			return 'pages must be "all", "front_page" or a list of 1–50 page / post ids.';
		}
		foreach ( $pages as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
				return sprintf( 'Page %d is not a published page or post.', (int) $id );
			}
			if ( Frontend::protected_post( $post ) ) {
				return sprintf( 'Page %d is a checkout, cart or account page: scripts from the AI never run there.', (int) $id );
			}
		}
		return '';
	}

	/**
	 * Writes one unit (already checked).
	 *
	 * @param string              $kind Kind.
	 * @param string              $key  Key.
	 * @param array<string,mixed> $set  Values.
	 * @throws \RuntimeException When the stored value differs.
	 */
	public static function write( string $kind, string $key, array $set ): void {
		if ( 'tracking' === $kind ) {
			TrackingStore::set_code( $key, null === $set['id'] ? null : (string) $set['id'] );
			$now = TrackingStore::codes()[ $key ] ?? null;
			if ( $now !== $set['id'] ) {
				throw new \RuntimeException( 'WordPress did not store the measurement code as planned.' );
			}
		} else {
			TrackingStore::set_script( $key, null === $set['script'] ? null : (array) json_decode( (string) $set['script'], true ) );
			$now = TrackingStore::scripts()[ $key ] ?? null;
			if ( ( null === $now ? null : wp_json_encode( $now ) ) !== $set['script'] ) {
				throw new \RuntimeException( 'WordPress did not store the script as planned.' );
			}
		}
		self::purge_page_caches();
	}

	/**
	 * Empties the page caches of the common caching plugins (the change must show in the HTML right away).
	 * Returns whether one was found.
	 */
	public static function purge_page_caches(): bool {
		$done = false;
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done = true;
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done = true;
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done = true;
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$done = true;
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own purge hook.
			$done = true;
		}
		if ( class_exists( '\WpeCommon' ) && method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
			\WpeCommon::purge_varnish_cache();
			$done = true;
		}
		return $done;
	}

	/**
	 * Card rows of a measurement code: [{field, before, after}].
	 *
	 * @param string      $provider Provider.
	 * @param string|null $before   ID now.
	 * @param string|null $after    ID after.
	 * @param string      $consent  Consent note.
	 * @return array<int,array<string,string>>
	 */
	public static function code_rows( string $provider, ?string $before, ?string $after, string $consent ): array {
		$rows = array(
			array(
				'field'  => Providers::name( $provider ),
				'before' => null === $before ? '(none)' : $before,
				'after'  => null === $after ? '(removed)' : $after,
			),
		);
		if ( null !== $after ) {
			$rows[] = array(
				'field'  => 'Code',
				'before' => '',
				'after'  => sprintf( 'The official %s code, built by the site from this ID, on every public page', Providers::name( $provider ) ),
			);
			$rows[] = array(
				'field'  => 'Cookie consent',
				'before' => '',
				'after'  => $consent,
			);
		}
		return $rows;
	}

	/**
	 * Card rows of a script: what it does, where it runs, what it talks to, and the whole code.
	 *
	 * @param array<string,mixed>|null $before Script now.
	 * @param array<string,mixed>|null $after  Script after.
	 * @return array<int,array<string,string>>
	 */
	public static function script_rows( ?array $before, ?array $after ): array {
		$own = TrackingStore::own_host();
		if ( null === $after ) {
			return array(
				array(
					'field'  => 'Script «' . (string) ( $before['label'] ?? '' ) . '»',
					'before' => 'Runs on ' . self::pages_text( $before['pages'] ?? 'all' ),
					'after'  => '(removed)',
				),
			);
		}
		$rows = array(
			array(
				'field'  => 'Script',
				'before' => null === $before ? '(none)' : (string) $before['label'],
				'after'  => (string) $after['label'],
			),
			array(
				'field'  => 'What it does (AI\'s description)',
				'before' => null === $before ? '' : (string) $before['about'],
				'after'  => (string) $after['about'],
			),
			array(
				'field'  => 'Runs on',
				'before' => null === $before ? '' : self::pages_text( $before['pages'] ),
				'after'  => self::pages_text( $after['pages'] ) . ' — never on checkout, cart, my account, login or wp-admin',
			),
			array(
				'field'  => 'Checked by the site',
				'before' => '',
				'after'  => implode( ' · ', JsGuard::facts( (string) $after['code'], $own ) ),
			),
			array(
				'field'  => 'Code',
				'before' => null === $before ? '' : (string) $before['code'],
				'after'  => (string) $after['code'],
			),
		);
		return $rows;
	}

	/**
	 * Pages in words.
	 *
	 * @param mixed $pages Pages.
	 */
	public static function pages_text( $pages ): string {
		if ( 'front_page' === $pages ) {
			return 'the home page';
		}
		if ( is_array( $pages ) ) {
			$names = array();
			foreach ( array_slice( $pages, 0, 10 ) as $id ) {
				$names[] = '«' . wp_strip_all_tags( get_the_title( (int) $id ) ) . '» (#' . (int) $id . ')';
			}
			return implode( ', ', $names ) . ( count( $pages ) > 10 ? sprintf( ' and %d more', count( $pages ) - 10 ) : '' );
		}
		return 'every public page';
	}
}
