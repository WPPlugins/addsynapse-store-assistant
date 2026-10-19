<?php
/**
 * Validation / sanitisation of AI-written product texts (SEO title, meta description, focus
 * keyword, short description, description) + the simple SEO checks.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\I18n\Locales;
use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\Adapters\Seo\AbstractSeoAdapter;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapterInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The texts come from the model, so the plugin trusts none of it:
 *  - SEO title / meta description / focus keyword: plain text only (tags stripped), one line,
 *    hard length caps, only the SEO plugin's known variables (%title%, %sep%…), letters required;
 *  - descriptions: HTML through wp_kses with the post tags MINUS scripts, iframes, styles,
 *    objects, forms (removed with their content, reported as `stripped`); a link or image
 *    pointing to another domain is REJECTED (error, never stored); caps; letters required.
 * The length guidance (title 50–60, description 120–160) only produces warnings.
 */
final class SeoText {

	public const TITLE_MAX       = 120;
	public const DESCRIPTION_MAX = 320;
	public const KEYWORD_MAX     = 100;
	public const SHORT_MAX       = 3000;
	public const LONG_MAX        = 20000;

	/**
	 * Largest stored SEO value accepted as a before/after (existing data may be long).
	 */
	public const STORED_MAX = 5000;

	/**
	 * Largest stored description accepted as a before/after.
	 */
	public const LONG_STORED_MAX = 500000;

	/**
	 * Recommended lengths (characters) of what search engines show.
	 */
	public const GUIDE = array(
		'title'       => array( 50, 60 ),
		'description' => array( 120, 160 ),
	);

	/**
	 * Elements removed together with their content.
	 */
	private const DANGEROUS = array( 'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'noscript', 'svg', 'math', 'template', 'link', 'meta', 'base' );

	/**
	 * A plain SEO text (title / meta description).
	 *
	 * @param string              $text    Proposed text.
	 * @param int                 $max     Cap.
	 * @param SeoAdapterInterface $adapter Adapter (variables).
	 * @return string|WP_Error
	 */
	public static function plain( string $text, int $max, SeoAdapterInterface $adapter ) {
		if ( 1 === preg_match( '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{FFFD}]/u', $text ) || ! mb_check_encoding( $text, 'UTF-8' ) ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', 'contains control or invalid characters' );
		}
		$clean = AbstractSeoAdapter::clean( $text );
		if ( '' === $clean ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', 'is empty' );
		}
		if ( mb_strlen( $clean ) > $max ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', sprintf( 'is %1$d characters; the limit is %2$d', mb_strlen( $clean ), $max ) );
		}
		$unknown = $adapter->unknown_variables( $clean );
		if ( $unknown ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', sprintf( 'uses variables that %1$s does not know (%2$s)', $adapter->label(), implode( ', ', array_slice( $unknown, 0, 3 ) ) ) );
		}
		if ( ! preg_match( '/\p{L}/u', $clean ) ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', 'has no words' );
		}
		return $clean;
	}

	/**
	 * A focus keyword: one phrase, no commas (the plugin's separator).
	 *
	 * @param string              $text    Proposed keyword.
	 * @param SeoAdapterInterface $adapter Adapter.
	 * @return string|WP_Error
	 */
	public static function keyword( string $text, SeoAdapterInterface $adapter ) {
		$clean = self::plain( $text, self::KEYWORD_MAX, $adapter );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( false !== strpos( $clean, ',' ) || $adapter->has_variables( $clean ) ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', 'must be one keyword phrase (no commas, no variables)' );
		}
		return $clean;
	}

	/**
	 * A description (HTML allowed, sanitised).
	 *
	 * @param string $text Proposed HTML.
	 * @param int    $max  Cap (characters of the stored HTML).
	 * @return array{value:string,stripped:array<int,string>}|WP_Error
	 */
	public static function html( string $text, int $max ) {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) || 1 === preg_match( '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{FFFD}]/u', $text ) ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', 'contains control or invalid characters' );
		}
		$stripped = array();
		$tags     = implode( '|', self::DANGEROUS );
		$text     = (string) preg_replace_callback(
			'#<(' . $tags . ')\b[^>]*>.*?</\1\s*>#is',
			static function ( $m ) use ( &$stripped ) {
				$stripped[] = strtolower( (string) $m[1] );
				return '';
			},
			$text
		);
		$text     = (string) preg_replace_callback(
			'#</?(' . $tags . ')\b[^>]*>#i',
			static function ( $m ) use ( &$stripped ) {
				$stripped[] = strtolower( (string) $m[1] );
				return '';
			},
			$text
		);
		if ( preg_match( '/\son[a-z]+\s*=|\sstyle\s*=|javascript\s*:/i', $text ) ) {
			$stripped[] = 'attributes';
		}
		$text     = trim( wp_kses( $text, self::allowed_html() ) );
		$external = self::external_links( $text );
		if ( $external ) {
			return new WP_Error( 'addsynapse_assistant_seo_link', sprintf( 'links to another domain (%s); only links to this shop are allowed', implode( ', ', array_slice( $external, 0, 3 ) ) ) );
		}
		$plain = AbstractSeoAdapter::clean( $text );
		if ( '' === $plain || ! preg_match( '/\p{L}/u', $plain ) ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', 'has no words' );
		}
		if ( mb_strlen( $text ) > $max ) {
			return new WP_Error( 'addsynapse_assistant_seo_text', sprintf( 'is %1$d characters; the limit is %2$d', mb_strlen( $text ), $max ) );
		}
		return array(
			'value'    => $text,
			'stripped' => array_values( array_unique( $stripped ) ),
		);
	}

	/**
	 * Tags allowed in descriptions: the post tags without active/embedded content.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( self::DANGEROUS as $tag ) {
			unset( $allowed[ $tag ] );
		}
		foreach ( $allowed as $tag => $attributes ) {
			if ( is_array( $attributes ) ) {
				unset( $allowed[ $tag ]['style'], $allowed[ $tag ]['action'], $allowed[ $tag ]['formaction'] );
			}
		}
		return $allowed;
	}

	/**
	 * Hosts (or schemes) of URLs in the HTML that are not this shop.
	 *
	 * @param string $html HTML.
	 * @return array<int,string>
	 */
	public static function external_links( string $html ): array {
		if ( ! preg_match_all( '/\b(?:href|src|srcset|cite|poster|data|longdesc)\s*=\s*(["\']?)([^"\'>]*)\1/i', $html, $matches ) ) {
			return array();
		}
		$own = self::host( (string) home_url() );
		$out = array();
		foreach ( $matches[2] as $value ) {
			foreach ( preg_split( '/\s*,\s*/', html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) as $url ) {
				$url = trim( (string) preg_replace( '/\s+\d+[wx]$/', '', trim( (string) $url ) ) );
				if ( '' === $url || '#' === $url[0] || ( '/' === $url[0] && 0 !== strpos( $url, '//' ) ) || '?' === $url[0] ) {
					continue;
				}
				$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				if ( '' === $scheme && 0 !== strpos( $url, '//' ) ) {
					if ( ! preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ) {
						continue;
						// Relative path of this shop.
					}
				}
				$host = self::host( 0 === strpos( $url, '//' ) ? 'http:' . $url : $url );
				if ( ! in_array( $scheme, array( '', 'http', 'https' ), true ) || '' === $host || $host !== $own ) {
					$out[] = '' !== $host ? $host : $scheme . ':';
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Lower-case host without "www.".
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Status of a length against the guidance: ok | short | long | missing.
	 *
	 * @param string $part  title|description.
	 * @param int    $chars Length.
	 */
	public static function status( string $part, int $chars ): string {
		if ( 0 === $chars ) {
			return 'missing';
		}
		if ( ! isset( self::GUIDE[ $part ] ) ) {
			return 'ok';
		}
		list( $min, $max ) = self::GUIDE[ $part ];
		if ( $chars < $min ) {
			return 'short';
		}
		return $chars > $max ? 'long' : 'ok';
	}

	/**
	 * Whether a keyword appears in a text (case/accent-insensitive).
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Text.
	 */
	public static function contains( string $keyword, string $text ): bool {
		$keyword = self::fold( $keyword );
		return '' !== $keyword && false !== mb_strpos( self::fold( $text ), $keyword );
	}

	/**
	 * Lower case, no accents, single spaces.
	 *
	 * @param string $text Text.
	 */
	public static function fold( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( Shared::strip_accents( $text ) ) ) );
	}

	/**
	 * The script the store's texts should be written in ('' = Latin/unknown: not checked).
	 */
	public static function expected_script(): string {
		$locale = strtolower( (string) get_locale() );
		$lang   = Locales::language( $locale );
		foreach ( Locales::packs() as $pack ) {
			if ( $pack->code() === $lang ) {
				return 'Latin' === $pack->script() ? '' : $pack->script();
			}
		}
		foreach ( array( 'ru', 'bg', 'uk', 'sr', 'mk', 'be' ) as $prefix ) {
			if ( 0 === strpos( $locale, $prefix ) ) {
				return 'Cyrillic';
			}
		}
		return '';
	}

	/**
	 * Whether a text looks written in another language than the store's (letters of the
	 * expected script under 30% of all letters; short texts / brand-only texts are not judged).
	 *
	 * @param string $text   Plain text.
	 * @param string $script Expected script ('' = no check).
	 */
	public static function wrong_language( string $text, string $script ): bool {
		if ( '' === $script ) {
			return false;
		}
		$letters = (int) preg_match_all( '/\p{L}/u', $text );
		if ( $letters < 25 ) {
			return false;
		}
		$own = (int) preg_match_all( '/\p{' . $script . '}/u', $text );
		return $own / $letters < 0.3;
	}
}
