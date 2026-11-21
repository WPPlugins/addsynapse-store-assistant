<?php
/**
 * Where measurement codes and AI scripts live: two plugin options (no files, no other storage).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Options:
 *  - codes   {provider: ID} — only checked IDs (Providers::normalize); the code is built at render time;
 *  - scripts {key: {label, about, pages, code}} — custom JavaScript, approved by the owner, checked by JsGuard;
 *  - allow_js '1' / '0' — «Allow the AI to add JavaScript» (wp-admin only, manage_options, OFF by default);
 *  - js_domains — the other web addresses scripts may talk to (wp-admin only);
 *  - refusals — the last refused scripts (why, when), shown in Settings.
 * None of them can be reached by the remote route's setting units (DesignUnits refuses our option names).
 */
final class TrackingStore {

	public const CODES      = 'addsynapse_assistant_tracking_codes';
	public const SCRIPTS    = 'addsynapse_assistant_custom_scripts';
	public const ALLOW_JS   = 'addsynapse_assistant_allow_js';
	public const JS_DOMAINS = 'addsynapse_assistant_js_domains';
	public const REFUSALS   = 'addsynapse_assistant_js_refusals';

	/** Every option of this feature (uninstall, and the guard against writing them through other units). */
	public const OPTIONS = array( self::CODES, self::SCRIPTS, self::ALLOW_JS, self::JS_DOMAINS, self::REFUSALS );

	/** Most scripts at once. */
	public const MAX_SCRIPTS = 20;

	/** Most allowed other domains. */
	public const MAX_DOMAINS = 20;

	/**
	 * Stored measurement codes: provider → ID (only known providers with a valid ID).
	 *
	 * @return array<string,string>
	 */
	public static function codes(): array {
		$out = array();
		foreach ( (array) get_option( self::CODES, array() ) as $provider => $id ) {
			$id = Providers::normalize( (string) $provider, $id );
			if ( '' !== $id ) {
				$out[ (string) $provider ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Sets or removes (null) one code.
	 *
	 * @param string      $provider Provider.
	 * @param string|null $id       Checked id or null.
	 */
	public static function set_code( string $provider, ?string $id ): void {
		$codes = self::codes();
		if ( null === $id ) {
			unset( $codes[ $provider ] );
		} else {
			$codes[ $provider ] = $id;
		}
		ksort( $codes );
		if ( $codes ) {
			update_option( self::CODES, $codes, true );
		} else {
			delete_option( self::CODES );
		}
	}

	/**
	 * Stored scripts: key → {label, about, pages, code}.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function scripts(): array {
		$out = array();
		foreach ( (array) get_option( self::SCRIPTS, array() ) as $key => $script ) {
			if ( is_array( $script ) && self::valid_key( (string) $key ) && is_string( $script['code'] ?? null ) ) {
				$out[ (string) $key ] = self::shape( $script );
			}
		}
		return $out;
	}

	/**
	 * One script in its stored shape (fixed key order, so its JSON text compares exactly).
	 *
	 * @param array<string,mixed> $script Script.
	 * @return array<string,mixed>
	 */
	public static function shape( array $script ): array {
		$pages = $script['pages'] ?? 'all';
		if ( is_array( $pages ) ) {
			$pages = array_values( array_unique( array_map( 'intval', $pages ) ) );
			sort( $pages );
		} elseif ( ! in_array( $pages, array( 'all', 'front_page' ), true ) ) {
			$pages = 'all';
		}
		return array(
			'label' => (string) ( $script['label'] ?? '' ),
			'about' => (string) ( $script['about'] ?? '' ),
			'pages' => $pages,
			'code'  => str_replace( "\r\n", "\n", (string) ( $script['code'] ?? '' ) ),
		);
	}

	/**
	 * Sets or removes (null) one script.
	 *
	 * @param string                   $key    Key.
	 * @param array<string,mixed>|null $script Script.
	 */
	public static function set_script( string $key, ?array $script ): void {
		$scripts = self::scripts();
		if ( null === $script ) {
			unset( $scripts[ $key ] );
		} else {
			$scripts[ $key ] = self::shape( $script );
		}
		ksort( $scripts );
		if ( $scripts ) {
			update_option( self::SCRIPTS, $scripts, true );
		} else {
			delete_option( self::SCRIPTS );
		}
	}

	/**
	 * A script key: lower-case letters, digits and dashes.
	 *
	 * @param string $key Key.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $key );
	}

	/**
	 * Key of a label («Hide chat on mobile» → hide-chat-on-mobile).
	 *
	 * @param string $label Label.
	 */
	public static function key_of( string $label ): string {
		$key = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $label ) ) ), '-' );
		return substr( '' === $key ? 'script' : $key, 0, 40 );
	}

	/**
	 * Whether «Allow the AI to add JavaScript» is on (OFF by default).
	 */
	public static function js_allowed(): bool {
		return '1' === (string) get_option( self::ALLOW_JS, '0' );
	}

	/**
	 * The other domains scripts may talk to (set in wp-admin only).
	 *
	 * @return array<int,string>
	 */
	public static function js_domains(): array {
		$out = array();
		foreach ( (array) get_option( self::JS_DOMAINS, array() ) as $domain ) {
			$domain = self::clean_domain( (string) $domain );
			if ( '' !== $domain ) {
				$out[] = $domain;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_DOMAINS );
	}

	/**
	 * A host name as typed («https://Widget.Example.com/x» → widget.example.com), '' when it is not one.
	 *
	 * @param string $text Text.
	 */
	public static function clean_domain( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $text );
		$text = (string) preg_replace( '#[/?\#:].*$#', '', $text );
		$text = ltrim( $text, '.' );
		if ( strlen( $text ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $text ) ) {
			return '';
		}
		return $text;
	}

	/**
	 * This site's own host.
	 */
	public static function own_host(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Keeps a refused script's reason (last 30), for Settings.
	 *
	 * @param string $label  Label.
	 * @param string $reason Why.
	 */
	public static function log_refusal( string $label, string $reason ): void {
		$log   = (array) get_option( self::REFUSALS, array() );
		$log[] = array(
			'time'   => time(),
			'label'  => mb_substr( $label, 0, 80 ),
			'reason' => mb_substr( $reason, 0, 300 ),
		);
		update_option( self::REFUSALS, array_slice( $log, -30 ), false );
	}

	/**
	 * The refused scripts, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function refusals(): array {
		return array_reverse( array_values( array_filter( (array) get_option( self::REFUSALS, array() ), 'is_array' ) ) );
	}
}
