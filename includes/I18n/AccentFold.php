<?php
/**
 * Accent folding WITHOUT the intl extension (shared hosting often has no `Normalizer`).
 *
 * With intl, Abilities\Shared::strip_accents() decomposes (NFD) and drops the combining marks. Without
 * it, WordPress' remove_accents() only knows Latin — Greek tonos/dialytika stayed and «χρωμα» no longer
 * matched «Χρώμα» (and slugs lost letters: «chra»). This is the same result for Greek in pure PHP: every
 * precomposed Greek letter (monotonic + polytonic, U+0370–03FF, U+1F00–1FFF) → its base letter.
 * The table is generated from the Unicode decompositions (letters written as escapes: no Greek literal in
 * the code, tests/I18nTest.php).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Pure-PHP accent stripping (Greek + Latin).
 */
final class AccentFold {

	/**
	 * Base letter → every precomposed variant of it (tonos, dialytika, polytonic breathings/accents/iota subscript).
	 */
	private const GREEK = array(
		// Capital letter alpha.
		"\u{0391}" => "\u{0386}\u{1F08}\u{1F09}\u{1F0A}\u{1F0B}\u{1F0C}\u{1F0D}\u{1F0E}\u{1F0F}\u{1F88}\u{1F89}\u{1F8A}\u{1F8B}\u{1F8C}\u{1F8D}\u{1F8E}\u{1F8F}\u{1FB8}\u{1FB9}\u{1FBA}\u{1FBB}\u{1FBC}",
		// Capital letter epsilon.
		"\u{0395}" => "\u{0388}\u{1F18}\u{1F19}\u{1F1A}\u{1F1B}\u{1F1C}\u{1F1D}\u{1FC8}\u{1FC9}",
		// Capital letter eta.
		"\u{0397}" => "\u{0389}\u{1F28}\u{1F29}\u{1F2A}\u{1F2B}\u{1F2C}\u{1F2D}\u{1F2E}\u{1F2F}\u{1F98}\u{1F99}\u{1F9A}\u{1F9B}\u{1F9C}\u{1F9D}\u{1F9E}\u{1F9F}\u{1FCA}\u{1FCB}\u{1FCC}",
		// Capital letter iota.
		"\u{0399}" => "\u{038A}\u{03AA}\u{1F38}\u{1F39}\u{1F3A}\u{1F3B}\u{1F3C}\u{1F3D}\u{1F3E}\u{1F3F}\u{1FD8}\u{1FD9}\u{1FDA}\u{1FDB}",
		// Capital letter omicron.
		"\u{039F}" => "\u{038C}\u{1F48}\u{1F49}\u{1F4A}\u{1F4B}\u{1F4C}\u{1F4D}\u{1FF8}\u{1FF9}",
		// Capital letter rho.
		"\u{03A1}" => "\u{1FEC}",
		// Capital letter upsilon.
		"\u{03A5}" => "\u{038E}\u{03AB}\u{1F59}\u{1F5B}\u{1F5D}\u{1F5F}\u{1FE8}\u{1FE9}\u{1FEA}\u{1FEB}",
		// Capital letter omega.
		"\u{03A9}" => "\u{038F}\u{1F68}\u{1F69}\u{1F6A}\u{1F6B}\u{1F6C}\u{1F6D}\u{1F6E}\u{1F6F}\u{1FA8}\u{1FA9}\u{1FAA}\u{1FAB}\u{1FAC}\u{1FAD}\u{1FAE}\u{1FAF}\u{1FFA}\u{1FFB}\u{1FFC}",
		// Small letter alpha.
		"\u{03B1}" => "\u{03AC}\u{1F00}\u{1F01}\u{1F02}\u{1F03}\u{1F04}\u{1F05}\u{1F06}\u{1F07}\u{1F70}\u{1F71}\u{1F80}\u{1F81}\u{1F82}\u{1F83}\u{1F84}\u{1F85}\u{1F86}\u{1F87}\u{1FB0}\u{1FB1}\u{1FB2}\u{1FB3}\u{1FB4}\u{1FB6}\u{1FB7}",
		// Small letter epsilon.
		"\u{03B5}" => "\u{03AD}\u{1F10}\u{1F11}\u{1F12}\u{1F13}\u{1F14}\u{1F15}\u{1F72}\u{1F73}",
		// Small letter eta.
		"\u{03B7}" => "\u{03AE}\u{1F20}\u{1F21}\u{1F22}\u{1F23}\u{1F24}\u{1F25}\u{1F26}\u{1F27}\u{1F74}\u{1F75}\u{1F90}\u{1F91}\u{1F92}\u{1F93}\u{1F94}\u{1F95}\u{1F96}\u{1F97}\u{1FC2}\u{1FC3}\u{1FC4}\u{1FC6}\u{1FC7}",
		// Small letter iota.
		"\u{03B9}" => "\u{0390}\u{03AF}\u{03CA}\u{1F30}\u{1F31}\u{1F32}\u{1F33}\u{1F34}\u{1F35}\u{1F36}\u{1F37}\u{1F76}\u{1F77}\u{1FD0}\u{1FD1}\u{1FD2}\u{1FD3}\u{1FD6}\u{1FD7}",
		// Small letter omicron.
		"\u{03BF}" => "\u{03CC}\u{1F40}\u{1F41}\u{1F42}\u{1F43}\u{1F44}\u{1F45}\u{1F78}\u{1F79}",
		// Small letter rho.
		"\u{03C1}" => "\u{1FE4}\u{1FE5}",
		// Small letter upsilon.
		"\u{03C5}" => "\u{03B0}\u{03CB}\u{03CD}\u{1F50}\u{1F51}\u{1F52}\u{1F53}\u{1F54}\u{1F55}\u{1F56}\u{1F57}\u{1F7A}\u{1F7B}\u{1FE0}\u{1FE1}\u{1FE2}\u{1FE3}\u{1FE6}\u{1FE7}",
		// Small letter omega.
		"\u{03C9}" => "\u{03CE}\u{1F60}\u{1F61}\u{1F62}\u{1F63}\u{1F64}\u{1F65}\u{1F66}\u{1F67}\u{1F7C}\u{1F7D}\u{1FA0}\u{1FA1}\u{1FA2}\u{1FA3}\u{1FA4}\u{1FA5}\u{1FA6}\u{1FA7}\u{1FF2}\u{1FF3}\u{1FF4}\u{1FF6}\u{1FF7}",
		// Upsilon with hook symbol.
		"\u{03D2}" => "\u{03D3}\u{03D4}",
		// Psili.
		"\u{1FBF}" => "\u{1FCD}\u{1FCE}\u{1FCF}",
		// Dasia.
		"\u{1FFE}" => "\u{1FDD}\u{1FDE}\u{1FDF}",
	);

	/**
	 * Variant → base (built once).
	 *
	 * @var array<string,string>|null
	 */
	private static $map = null;

	/**
	 * Text without accents: combining marks of already decomposed input dropped first, then Greek variants →
	 * base letters and Latin through remove_accents() (which recomposes to NFC when intl exists).
	 *
	 * @param string $text Text.
	 */
	public static function strip( string $text ): string {
		if ( null === self::$map ) {
			self::$map = array();
			foreach ( self::GREEK as $base => $variants ) {
				foreach ( (array) preg_split( '//u', $variants, -1, PREG_SPLIT_NO_EMPTY ) as $variant ) {
					self::$map[ (string) $variant ] = $base;
				}
			}
		}
		$text = (string) preg_replace( '/\p{Mn}+/u', '', $text );
		return remove_accents( strtr( $text, self::$map ) );
	}
}
