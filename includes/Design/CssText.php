<?php
/**
 * Checks of CSS the assistant writes (per-block custom CSS, site styles CSS, Additional CSS).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Plain CSS only: balanced braces / quotes / comments, no @import / @charset, no `</style`, no expression() / behavior /
 * -moz-binding, no javascript: and url() only to this site or https, at most MAX bytes.
 */
final class CssText {

	public const MAX = 20000;

	/**
	 * Why the CSS is not allowed ('' = fine).
	 *
	 * @param string $css CSS.
	 */
	public static function problem( string $css ): string {
		if ( strlen( $css ) > self::MAX ) {
			return sprintf( 'too long (max %d bytes).', self::MAX );
		}
		$lower = strtolower( $css );
		if ( preg_match( '/<\s*\/?\s*(style|script)|<!--|-->/', $lower ) ) {
			return 'HTML is not allowed in CSS.';
		}
		if ( preg_match( '/@import|@charset|expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:|vbscript\s*:/', $lower ) ) {
			return '@import, expression(), behavior and javascript: are not allowed.';
		}
		if ( preg_match_all( '/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is', $css, $m ) ) {
			$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			foreach ( $m[2] as $url ) {
				$url = trim( $url );
				if ( preg_match( '#^(/(?!/)|\.\./|\./)#', $url ) ) {
					continue;
				}
				$host = (string) wp_parse_url( $url, PHP_URL_HOST );
				if ( 0 === stripos( $url, 'https://' ) && '' !== $host ) {
					continue;
				}
				if ( '' !== $host && $host === $home ) {
					continue;
				}
				return 'url() may only point to this site or an https address.';
			}
		}
		// Balanced braces outside strings and comments.
		$depth  = 0;
		$quote  = '';
		$length = strlen( $css );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $css[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					return 'an unclosed comment.';
				}
				$i = $end + 1;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c ) {
				--$depth;
				if ( $depth < 0 ) {
					return 'unbalanced braces.';
				}
			}
		}//end for
		if ( '' !== $quote ) {
			return 'an unclosed string.';
		}
		if ( 0 !== $depth ) {
			return 'unbalanced braces.';
		}
		return '';
	}
}
