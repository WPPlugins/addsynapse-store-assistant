<?php
/**
 * One language's data for matching what people TYPE (not UI strings — those are gettext).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * A locale pack: keyword lists (yes/no, period words, CSV header synonyms…) plus the
 * script-specific steps of normalization (folding, transliteration to Latin for fuzzy
 * matching and slugs). English is the source language; other packs add their words.
 *
 * Every pack is active at the same time (a Greek manager on an English-language shop still
 * types Greek words). New languages: a subclass + the `addsynapse_assistant_locale_packs` filter
 * (see `Locales::packs()`); UI strings stay in `languages/` (gettext).
 */
abstract class LocalePack {

	/**
	 * Language code (ISO 639-1), e.g. "en", "el".
	 */
	abstract public function code(): string;

	/**
	 * Unicode script of the language's letters (a `\p{…}` name), e.g. "Latin", "Greek".
	 */
	public function script(): string {
		return 'Latin';
	}

	/**
	 * Keyword lists and maps, by key (see `Locales::words()` / `Locales::map()`):
	 *  - yes, no, any, refresh, page_front, page_shop, brand, brand_fragments: lists of words
	 *  - periods (word → today|7d|30d|month), taxonomies (word → product_cat|product_tag),
	 *    import_targets (word → sku|regular_price|sale_price|stock): maps
	 *  - csv_columns (target → header words), csv_not (header words that are something else)
	 *
	 * @return array<string,array<int|string,mixed>>
	 */
	abstract public function lexicon(): array;

	/**
	 * Script-specific folding AFTER lower-casing and accent removal (e.g. final sigma).
	 *
	 * @param string $text Lower-case, accent-free text.
	 */
	public function fold( string $text ): string {
		return $text;
	}

	/**
	 * Letters of this script → Latin "sound" letters (fuzzy matching keys). Identity for Latin.
	 *
	 * @param string $text Lower-case, accent-free text.
	 */
	public function to_latin( string $text ): string {
		return $text;
	}

	/**
	 * Transliteration for slugs (a standard such as ELOT 743), or null when this pack has none.
	 *
	 * @param string $text Lower-case, accent-free text.
	 */
	public function slug_latin( string $text ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- packs with a standard override it.
		return null;
	}
}
