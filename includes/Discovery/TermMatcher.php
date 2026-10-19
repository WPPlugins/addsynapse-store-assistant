<?php
/**
 * Exact + fuzzy matching of user words against term names (any script a locale pack knows).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Discovery;

use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\I18n\Locales;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions: normalization, transliteration to Latin through the locale packs
 * (`I18n\Locales`) and a similarity score in 0..1, so "Huadidas" → "Adidas" and a Greek
 * spelling of "adidas" → "Adidas".
 */
final class TermMatcher {

	/**
	 * Below this score a term is not a candidate.
	 */
	public const MIN_SCORE = 0.5;

	/**
	 * Lowercase, accent-free, punctuation-free text (keeps the script).
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( Shared::strip_accents( $text ), 'UTF-8' );
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Latin "sound" key used for scoring: normalized, Greek transliterated, spaces removed.
	 *
	 * @param string $text Text.
	 */
	public static function key( string $text ): string {
		$text = Locales::to_latin( self::normalize( $text ) );
		$text = str_replace( ' ', '', $text );
		// Common Latin spelling variants collapse too (ph/f, ck/k, w/v, double letters).
		$text = str_replace( array( 'ph', 'ck', 'w' ), array( 'f', 'k', 'v' ), $text );
		return (string) preg_replace( '/(.)\1+/', '$1', $text );
	}

	/**
	 * Similarity in 0..1 between a query and a candidate (name or slug).
	 *
	 * @param string $query     User text.
	 * @param string $candidate Term name/slug.
	 * @return array{score:float,match:string}
	 */
	public static function score( string $query, string $candidate ): array {
		$nq = self::normalize( $query );
		$nc = self::normalize( $candidate );
		if ( '' === $nq || '' === $nc ) {
			return array(
				'score' => 0.0,
				'match' => 'none',
			);
		}
		if ( $nq === $nc ) {
			return array(
				'score' => 1.0,
				'match' => 'exact',
			);
		}
		$kq = self::key( $query );
		$kc = self::key( $candidate );
		if ( '' === $kq || '' === $kc ) {
			return array(
				'score' => 0.0,
				'match' => 'none',
			);
		}
		if ( $kq === $kc ) {
			// Same letters once accents/script/spelling are ignored ("Αντιντας" = "Adidas").
			return array(
				'score' => 0.95,
				'match' => 'equivalent',
			);
		}
		$len_q   = strlen( $kq );
		$len_c   = strlen( $kc );
		$max     = max( $len_q, $len_c );
		$edit    = 1 - ( levenshtein( $kq, $kc ) / $max );
		$contain = 0.0;
		if ( min( $len_q, $len_c ) >= 3 && ( false !== strpos( $kc, $kq ) || false !== strpos( $kq, $kc ) ) ) {
			$contain = 0.6 + 0.35 * ( min( $len_q, $len_c ) / $max );
		}
		$score = max( $edit, $contain );
		return array(
			'score' => round( max( 0.0, min( 0.94, $score ) ), 2 ),
			'match' => $contain >= $edit && $contain > 0 ? 'partial' : 'fuzzy',
		);
	}

	/**
	 * Ranks terms for a query.
	 *
	 * @param string                                                     $query Query.
	 * @param array<int,array{id:int,name:string,slug:string,count:int}> $terms Terms.
	 * @param int                                                        $limit Max matches.
	 * @return array<int,array<string,mixed>> Sorted by score (desc), then usage count.
	 */
	public static function rank( string $query, array $terms, int $limit = 5 ): array {
		$out = array();
		foreach ( $terms as $term ) {
			$best = self::score( $query, $term['name'] );
			$slug = self::score( $query, rawurldecode( $term['slug'] ) );
			if ( $slug['score'] > $best['score'] ) {
				$best = $slug;
			}
			// Multi-word text: a single word of the query may match a term, or a word of
			// the term may match the query ("Κρεατίνη Μονοϋδρική" → "Κρεατίνες"). Weaker.
			$best = self::best_word_match( $query, $term['name'], $best );
			if ( $best['score'] < self::MIN_SCORE ) {
				continue;
			}
			$out[] = $term + $best;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $b['score'], $b['count'] ) <=> array( $a['score'], $a['count'] );
			}
		);
		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/**
	 * Word-level partial matching (each side's words of 3+ letters), capped below whole matches.
	 *
	 * @param string                          $query Query.
	 * @param string                          $name  Term name.
	 * @param array{score:float,match:string} $best  Best whole-text score so far.
	 * @return array{score:float,match:string}
	 */
	private static function best_word_match( string $query, string $name, array $best ): array {
		$query_words = array_filter( explode( ' ', self::normalize( $query ) ), static fn ( $w ) => mb_strlen( $w ) >= 3 );
		$name_words  = array_filter( explode( ' ', self::normalize( $name ) ), static fn ( $w ) => mb_strlen( $w ) >= 3 );
		if ( count( $query_words ) < 2 && count( $name_words ) < 2 ) {
			return $best;
		}
		foreach ( $query_words ? $query_words : array( $query ) as $qw ) {
			foreach ( $name_words ? $name_words : array( $name ) as $nw ) {
				$score = self::score( $qw, $nw )['score'] * 0.85;
				if ( $score > $best['score'] ) {
					$best = array(
						'score' => round( $score, 2 ),
						'match' => 'partial',
					);
				}
			}
		}
		return $best;
	}
}
