<?php
/**
 * Locale packs registry: keyword lookups and script-aware normalization.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\I18n;

use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\I18n\Packs\English;
use Addsynapse\StoreAssistant\I18n\Packs\Greek;

defined( 'ABSPATH' ) || exit;

/**
 * All language-specific input handling goes through here (English is the source; Greek is the
 * first extra pack). UI strings are NOT here — they are gettext (`languages/`).
 *
 * Words are compared normalized (`Locales::normalize`: lower case, no accents, script folding,
 * punctuation → space), so "Ναι", "ναι" and "ΝΑΙ" are the same word.
 */
final class Locales {

	/**
	 * Cached packs.
	 *
	 * @var array<int,LocalePack>|null
	 */
	private static ?array $packs = null;

	/**
	 * Cached normalized lexicon per key.
	 *
	 * @var array<string,array<int|string,mixed>>
	 */
	private static array $cache = array();

	/**
	 * Active packs, English first. Filter `addsynapse_assistant_locale_packs` adds languages (LocalePack instances).
	 *
	 * @return array<int,LocalePack>
	 */
	public static function packs(): array {
		if ( null === self::$packs ) {
			$packs       = apply_filters( 'addsynapse_assistant_locale_packs', array( new English(), new Greek() ) );
			self::$packs = array_values(
				array_filter(
					(array) $packs,
					static function ( $pack ) {
						return $pack instanceof LocalePack;
					}
				)
			);
		}
		return self::$packs;
	}

	/**
	 * Forgets cached packs/lexicon (tests, filter changes).
	 */
	public static function reset(): void {
		self::$packs = null;
		self::$cache = array();
	}

	/**
	 * Lower case, accent-free, script-folded text; punctuation → single spaces.
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$text = self::fold( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Lower case + accents removed + each pack's folding (keeps spaces/punctuation).
	 *
	 * @param string $text Text.
	 */
	public static function fold( string $text ): string {
		$text = mb_strtolower( Shared::strip_accents( $text ), 'UTF-8' );
		foreach ( self::packs() as $pack ) {
			$text = $pack->fold( $text );
		}
		return $text;
	}

	/**
	 * Every non-Latin letter a pack knows → Latin "sound" letters (fuzzy matching keys).
	 *
	 * @param string $text Lower-case, accent-free text.
	 */
	public static function to_latin( string $text ): string {
		foreach ( self::packs() as $pack ) {
			$text = $pack->to_latin( $text );
		}
		return $text;
	}

	/**
	 * Slug text: the transliteration of the pack whose script the text is written in (Greek →
	 * ELOT 743); Latin (or an unknown script) → WordPress' sanitize_title. Lower case a-z0-9-.
	 *
	 * @param string $text Name.
	 */
	public static function slug( string $text ): string {
		$text = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		foreach ( self::packs() as $pack ) {
			if ( 'Latin' !== $pack->script() && 1 === preg_match( '/\p{' . $pack->script() . '}/u', $text ) ) {
				$latin = $pack->slug_latin( mb_strtolower( Shared::strip_accents( $text ), 'UTF-8' ) );
				if ( null !== $latin ) {
					$text = $latin;
				}
			}
		}
		$slug = sanitize_title( remove_accents( $text ) );
		// A script no pack transliterates stays percent-encoded: nothing usable.
		if ( false !== strpos( $slug, '%' ) ) {
			$slug = trim( (string) preg_replace( '/-+/', '-', (string) preg_replace( '/%[0-9a-f]{2}|[^a-z0-9-]/', '', $slug ) ), '-' );
		}
		return $slug;
	}

	/**
	 * Language code of a WordPress locale: "el_GR" → "el", "pt-BR" → "pt", "" → "en".
	 *
	 * @param string $locale Locale.
	 */
	public static function language( string $locale ): string {
		$code = strtolower( (string) preg_replace( '/[_-].*$/', '', trim( $locale ) ) );
		return preg_match( '/^[a-z]{2,3}$/', $code ) ? $code : 'en';
	}

	/**
	 * A WordPress locale safe to send (e.g. "el", "en_US", "de_DE_formal"), or ''.
	 *
	 * @param string $locale Locale.
	 */
	public static function clean_locale( string $locale ): string {
		$locale = trim( $locale );
		return 1 === preg_match( '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,2}$/', $locale ) ? $locale : '';
	}

	/**
	 * Normalized union of a word list over all packs.
	 *
	 * @param string $key Lexicon key (yes, no, any, refresh, page_front, page_shop, brand, brand_fragments).
	 * @return array<int,string>
	 */
	public static function words( string $key ): array {
		if ( ! isset( self::$cache[ 'w:' . $key ] ) ) {
			$out = array();
			foreach ( self::packs() as $pack ) {
				foreach ( (array) ( $pack->lexicon()[ $key ] ?? array() ) as $word ) {
					$out[] = self::normalize( (string) $word );
				}
			}
			self::$cache[ 'w:' . $key ] = array_values( array_unique( $out ) );
		}
		return self::$cache[ 'w:' . $key ];
	}

	/**
	 * Normalized union of a word → value map over all packs (earlier packs win).
	 *
	 * @param string $key Lexicon key (periods, taxonomies, import_targets).
	 * @return array<string,string>
	 */
	public static function map( string $key ): array {
		if ( ! isset( self::$cache[ 'm:' . $key ] ) ) {
			$out = array();
			foreach ( self::packs() as $pack ) {
				foreach ( (array) ( $pack->lexicon()[ $key ] ?? array() ) as $word => $value ) {
					$word = self::normalize( (string) $word );
					if ( ! isset( $out[ $word ] ) ) {
						$out[ $word ] = (string) $value;
					}
				}
			}
			self::$cache[ 'm:' . $key ] = $out;
		}
		return self::$cache[ 'm:' . $key ];
	}

	/**
	 * Raw lists grouped by target over all packs, target order from the first pack that has it.
	 *
	 * @param string $key Lexicon key (csv_columns).
	 * @return array<string,array<int,string>>
	 */
	public static function grouped( string $key ): array {
		$out = array();
		foreach ( self::packs() as $pack ) {
			foreach ( (array) ( $pack->lexicon()[ $key ] ?? array() ) as $target => $words ) {
				$out[ (string) $target ] = array_merge( $out[ (string) $target ] ?? array(), array_map( 'strval', (array) $words ) );
			}
		}
		return $out;
	}

	/**
	 * Raw union of a word list over all packs (for callers with their own normalization).
	 *
	 * @param string $key Lexicon key.
	 * @return array<int,string>
	 */
	public static function raw( string $key ): array {
		$out = array();
		foreach ( self::packs() as $pack ) {
			$out = array_merge( $out, array_map( 'strval', (array) ( $pack->lexicon()[ $key ] ?? array() ) ) );
		}
		return $out;
	}

	/**
	 * Whether a text is one of the words of a list (any language).
	 *
	 * @param string $key  Lexicon key.
	 * @param string $text Text.
	 */
	public static function is( string $key, string $text ): bool {
		return in_array( self::normalize( $text ), self::words( $key ), true );
	}

	/**
	 * Yes/no word → bool (null = neither), any language.
	 *
	 * @param string $text Text.
	 */
	public static function yes_no( string $text ): ?bool {
		if ( '' === trim( $text ) ) {
			return null;
		}
		if ( self::is( 'yes', $text ) ) {
			return true;
		}
		return self::is( 'no', $text ) ? false : null;
	}

	/**
	 * Value of a word in a map (any language), or null.
	 *
	 * @param string $key  Lexicon key.
	 * @param string $text Text.
	 */
	public static function lookup( string $key, string $text ): ?string {
		return self::map( $key )[ self::normalize( $text ) ] ?? null;
	}
}
