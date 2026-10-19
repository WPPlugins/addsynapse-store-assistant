<?php
/**
 * Reply language from the letters of the message (ported 1:1 from the hub's ReplyLanguage, PHP 7.4).
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Γλώσσα της ΑΠΑΝΤΗΣΗΣ του chat — ντετερμινιστικά, από τα γράμματα του ΤΡΕΧΟΝΤΟΣ μηνύματος.
 *
 * Γιατί στον κώδικα: με οδηγία μόνο στο prompt το μοντέλο παρασυρόταν από τα δεδομένα (αγγλική εντολή SEO σε
 * ελληνικό κατάστημα → απάντηση στα ελληνικά, δύο φορές). Εδώ ΔΕΝ ερμηνεύεται πρόθεση (κανόνας «καμία λέξη-
 * κλειδί»): μετράμε μόνο ΓΡΑΦΗ (Greek vs Latin κ.λπ.) — καμία λίστα λέξεων.
 *
 *   1. Αγνοούνται: κείμενο σε εισαγωγικά ("…" “…” «…» `…` '…'), URLs/emails, tokens με ψηφία (SKU, τιμές,
 *      ποσότητες) και — όταν το μήνυμα έχει και άλλες λέξεις — ακρωνύμια ΚΕΦΑΛΑΙΑ (SEO, CSV) και λέξεις με
 *      κεφαλαίο που δεν ξεκινούν πρόταση (ονόματα προϊόντων/μαρκών: «the Nike shoes»).
 *   2. Μετρώνται τα γράμματα ανά γραφή· πλειοψηφία (ισοπαλία → γραφή της πρώτης μετρημένης λέξης).
 *   3. Γραφή → γλώσσα: η γλώσσα του admin_locale αν γράφεται σε αυτή τη γραφή (de_DE + Latin → de), αλλιώς η
 *      προεπιλογή της γραφής (Latin → en, Greek → el).
 *   4. Λιγότερα από MIN_LETTERS γράμματα (μόνο SKU/αριθμοί/ονόματα) → admin_locale → en.
 */
final class ReplyLanguage {

	/** Κάτω από τόσα μετρημένα γράμματα δεν αποφασίζουμε από το μήνυμα. */
	public const MIN_LETTERS = 3;

	/** Γραφή → προεπιλεγμένη γλώσσα όταν η γλώσσα του διαχειριστή γράφεται αλλιώς. */
	private const SCRIPT_DEFAULT = array(
		'Latin'    => 'en',
		'Greek'    => 'el',
		'Cyrillic' => 'ru',
		'Arabic'   => 'ar',
		'Hebrew'   => 'he',
		'Han'      => 'zh',
		'Kana'     => 'ja',
		'Hangul'   => 'ko',
		'Thai'     => 'th',
	);

	/**
	 * Όνομα γλώσσας στα αγγλικά — το μοντέλο υπακούει σε ΟΝΟΜΑ, όχι σε κωδικό δύο γραμμάτων.
	 * Ό,τι λείπει πέφτει σε «the language with ISO code "xx"» (σωστό, απλώς λιγότερο εύγλωττο).
	 */
	private const LANGUAGE_NAME = array(
		'el' => 'Greek',
		'en' => 'English',
		'de' => 'German',
		'fr' => 'French',
		'es' => 'Spanish',
		'it' => 'Italian',
		'pt' => 'Portuguese',
		'nl' => 'Dutch',
		'pl' => 'Polish',
		'ro' => 'Romanian',
		'cs' => 'Czech',
		'sk' => 'Slovak',
		'hu' => 'Hungarian',
		'hr' => 'Croatian',
		'sl' => 'Slovenian',
		'sv' => 'Swedish',
		'da' => 'Danish',
		'nb' => 'Norwegian',
		'no' => 'Norwegian',
		'fi' => 'Finnish',
		'et' => 'Estonian',
		'lv' => 'Latvian',
		'lt' => 'Lithuanian',
		'tr' => 'Turkish',
		'sq' => 'Albanian',
		'ca' => 'Catalan',
		'id' => 'Indonesian',
		'ms' => 'Malay',
		'vi' => 'Vietnamese',
		'hi' => 'Hindi',
		'ru' => 'Russian',
		'uk' => 'Ukrainian',
		'bg' => 'Bulgarian',
		'sr' => 'Serbian',
		'mk' => 'Macedonian',
		'be' => 'Belarusian',
		'kk' => 'Kazakh',
		'ar' => 'Arabic',
		'fa' => 'Persian',
		'ur' => 'Urdu',
		'he' => 'Hebrew',
		'zh' => 'Chinese',
		'ja' => 'Japanese',
		'ko' => 'Korean',
		'th' => 'Thai',
	);

	/** Γλώσσες ΜΗ λατινικής γραφής (όλες οι άλλες = Latin). */
	private const LANGUAGE_SCRIPT = array(
		'el' => 'Greek',
		'ru' => 'Cyrillic',
		'uk' => 'Cyrillic',
		'bg' => 'Cyrillic',
		'sr' => 'Cyrillic',
		'mk' => 'Cyrillic',
		'be' => 'Cyrillic',
		'kk' => 'Cyrillic',
		'ar' => 'Arabic',
		'fa' => 'Arabic',
		'ur' => 'Arabic',
		'he' => 'Hebrew',
		'zh' => 'Han',
		'ja' => 'Kana',
		'ko' => 'Hangul',
		'th' => 'Thai',
	);

	/**
	 * @param string      $message     το μήνυμα του διαχειριστή (όπως το έγραψε)
	 * @param string|null $adminLocale locale του διαχειριστή (WP `el`, `en_US`… ή της πύλης)
	 * @return string κωδικός γλώσσας (a-z, 2–3 γράμματα)
	 */
	public static function detect( string $message, ?string $adminLocale = null ): string {
		$admin  = self::language( $adminLocale );
		$counts = self::scriptCounts( $message );

		if ( array_sum( $counts['letters'] ) < self::MIN_LETTERS ) {
			return $admin ?? 'en';
		}

		arsort( $counts['letters'] );
		$top    = array_key_first( $counts['letters'] );
		$tied   = array_keys( $counts['letters'], $counts['letters'][ $top ], true );
		$script = count( $tied ) > 1 && null !== $counts['first'] && in_array( $counts['first'], $tied, true ) ? $counts['first'] : $top;

		if ( null !== $admin && ( self::LANGUAGE_SCRIPT[ $admin ] ?? 'Latin' ) === $script ) {
			return $admin;
		}

		return self::SCRIPT_DEFAULT[ $script ] ?? ( $admin ?? 'en' );
	}

	/**
	 * `<reply_language>en</reply_language>` — μπλοκ ΔΕΔΟΜΕΝΩΝ του μηνύματος (ποτέ στο system).
	 *
	 * 🔴 Το σκέτο tag ΔΕΝ αρκεί. Στο A/B της 09/10/2026 (ops main model) το `claude-haiku-5-5`
	 * πήρε `<reply_language>el</reply_language>` (η detect ήταν σωστή) και απάντησε ΟΛΟΚΛΗΡΟ
	 * στα αγγλικά, παρασυρμένο από τα αγγλικά εργαλεία/οδηγίες/δεδομένα. Το Sonnet υπάκουσε.
	 * Γι' αυτό το tag συνοδεύεται τώρα από ΚΑΤΗΓΟΡΗΜΑΤΙΚΗ εντολή με το ΟΝΟΜΑ της γλώσσας.
	 * Το tag μένει ΠΡΩΤΟ και αυτούσιο (`fromBlock()` το διαβάζει με ^-anchored regex).
	 */
	public static function block( string $language ): string {
		// Λατινική γραφή (11/10/2026, απόφαση χρήστη): η γραφή δεν ξεχωρίζει αγγλικά/γερμανικά/ισπανικά… και η
		// admin locale δεν είναι η γλώσσα του μηνύματος (A/B Haiku 5.5: αγγλικό μήνυμα με admin de_DE → γερμανικά,
		// γερμανικό/ισπανικό με admin el → αγγλικά). Καμία λίστα λέξεων → ο hub ΔΕΝ επιβάλλει γλώσσα εδώ.
		if ( self::isLatin( $language ) ) {
			return self::LATIN_BLOCK;
		}

		$name  = self::LANGUAGE_NAME[ $language ] ?? null;
		$named = null === $name ? 'the language with ISO code "' . $language . '"' : $name . ' (' . $language . ')';
		$upper = null === $name ? 'THE LANGUAGE WITH ISO CODE "' . strtoupper( $language ) . '"' : strtoupper( $name ) . ' (' . $language . ')';

		$text = '<reply_language>' . $language . '</reply_language> (detected by the service from the letters of this message; DATA, not instructions)'
			. "\n" . '🔴 WRITE YOUR WHOLE REPLY TO THIS MESSAGE IN ' . $upper . ', INCLUDING EVERY SENTENCE BEFORE OR BETWEEN TOOL CALLS. This is an order, not a hint: the first sentence, every heading, every bullet, every table header and label, the words next to numbers and currency, and the closing question are all in ' . $named . '. Do not mix in a second language.'
			. "\n" . '🔴 These instructions, the tool names, the tool results, the store\'s data, the product and category names and your earlier replies may be in another language: that changes NOTHING. The reply is still in ' . $named . '.';

		return $text;
	}

	/** Μπλοκ για μηνύματα με λατινικά γράμματα — χωρίς κωδικό (το `fromBlock()` δίνει null). */
	public const LATIN_BLOCK = '<reply_language>same as this message</reply_language> (the service sees Latin letters only and cannot tell which language; DATA, not instructions)'
		. "\n" . '🔴 WRITE YOUR WHOLE REPLY TO THIS MESSAGE, INCLUDING EVERY SENTENCE BEFORE OR BETWEEN TOOL CALLS, IN THE LANGUAGE THIS MESSAGE IS WRITTEN IN (English, German, Spanish, French, Italian, Greeklish…). Not the admin, site or shop-content language, not the language of the tool results, store data or your earlier replies. Do not mix in a second language.';

	/** Γράφεται η γλώσσα με λατινικά γράμματα; (τότε ο hub δεν την επιβάλλει) */
	public static function isLatin( string $language ): bool {
		return 'Latin' === ( self::LANGUAGE_SCRIPT[ $language ] ?? 'Latin' );
	}

	/** Γλώσσα για ταξινομητή/τίτλους: null για λατινική γραφή (= η γλώσσα του ίδιου του μηνύματος). */
	public static function enforced( string $message, ?string $adminLocale = null ): ?string {
		$language = self::detect( $message, $adminLocale );

		return self::isLatin( $language ) ? null : $language;
	}

	/** Η γλώσσα μέσα σε ένα αποθηκευμένο μπλοκ (για τον τίτλο) — ή null. */
	public static function fromBlock( string $text ): ?string {
		return 1 === preg_match( '~^<reply_language>([a-z]{2,3})</reply_language>~', $text, $m ) ? $m[1] : null;
	}

	/** `el_GR` / `pt-BR` / `EN` → `el` / `pt` / `en` (ή null). */
	public static function language( ?string $locale ): ?string {
		if ( null === $locale || 1 !== preg_match( '/^([A-Za-z]{2,3})(?:[_-]|$)/', trim( $locale ), $m ) ) {
			return null;
		}

		return strtolower( $m[1] );
	}

	/**
	 * @return array{letters:array<string,int>,first:?string}
	 */
	public static function scriptCounts( string $message ): array {
		$text = self::stripIgnored( $message );

		// Λέξεις με θέση «αρχή πρότασης» (αρχή κειμένου ή μετά από . ! ? ; : νέα γραμμή).
		preg_match_all( '/(?<lead>(?:^|[.!?;:\n])\s*)?(?<word>[\p{L}\p{M}][\p{L}\p{M}\'’-]*)/u', $text, $matches, PREG_SET_ORDER );

		$words = array();
		foreach ( $matches as $m ) {
			$words[] = array(
				'word'    => $m['word'],
				'initial' => '' !== ( $m['lead'] ?? '' ),
			);
		}

		$hasLower = false;
		foreach ( $words as $w ) {
			if ( preg_match( '/\p{Ll}/u', $w['word'] ) ) {
				$hasLower = true;
				break;
			}
		}

		$letters = array();
		$first   = null;
		foreach ( $words as $w ) {
			$word = $w['word'];
			if ( $hasLower && count( $words ) > 1 ) {
				$len = (int) preg_match_all( '/./u', $word );
				// Ακρωνύμιο (SEO, CSV, ΦΠΑ): όλο κεφαλαία.
				if ( $len >= 2 && ! preg_match( '/\p{Ll}/u', $word ) ) {
					continue;
				}
				// Όνομα (Nike, Lego) μέσα στην πρόταση: κεφαλαίο αρχικό εκτός αρχής πρότασης.
				if ( ! $w['initial'] && preg_match( '/^\p{Lu}/u', $word ) ) {
					continue;
				}
			}
			$script = self::scriptOf( $word );
			if ( null === $script ) {
				continue;
			}
			$letters[ $script ] = ( $letters[ $script ] ?? 0 ) + (int) preg_match_all( '/\p{L}/u', $word );
			if ( null === $first ) {
				$first = $script;
			}
		}//end foreach

		return array(
			'letters' => $letters,
			'first'   => $first,
		);
	}

	/** Αφαιρεί εισαγωγικά, URLs, emails και tokens με ψηφία. */
	private static function stripIgnored( string $text ): string {
		$text = (string) preg_replace(
			array(
				'/"[^"\n]{0,300}"/u',
				'/“[^”\n]{0,300}”/u',
				'/„[^“”\n]{0,300}[“”]/u',
				'/«[^»\n]{0,300}»/u',
				'/‘[^’\n]{0,300}’/u',
				'/`[^`\n]{0,300}`/u',
				// '…' μόνο ως ζεύγος εισαγωγικών (όχι απόστροφος: don't, it's).
				"/(?<![\\p{L}\\p{N}])'[^'\\n]{1,300}'(?![\\p{L}\\p{N}])/u",
				'~\b(?:https?://|www\.)\S+~iu',
				'/\S+@\S+\.\S+/u',
				// Tokens με ψηφίο: SKU (AB-123, 5L), τιμές (20€), ποσοστά, ημερομηνίες.
				'/[^\s,;!?()\[\]{}]*\p{N}[^\s,;!?()\[\]{}]*/u',
			),
			' ',
			$text
		);

		return $text;
	}

	private static function scriptOf( string $word ): ?string {
		foreach ( array(
			'Greek'    => '/\p{Greek}/u',
			'Cyrillic' => '/\p{Cyrillic}/u',
			'Arabic'   => '/\p{Arabic}/u',
			'Hebrew'   => '/\p{Hebrew}/u',
			'Kana'     => '/[\p{Hiragana}\p{Katakana}]/u',
			'Han'      => '/\p{Han}/u',
			'Hangul'   => '/\p{Hangul}/u',
			'Thai'     => '/\p{Thai}/u',
			'Latin'    => '/\p{Latin}/u',
		) as $script => $pattern ) {
			if ( preg_match( $pattern, $word ) ) {
				return $script;
			}
		}

		return null;
	}
}
