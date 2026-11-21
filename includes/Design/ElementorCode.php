<?php
/**
 * Elementor settings that hold code: custom CSS, HTML, custom attributes (1.3.14).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * What the assistant may write into code-like Elementor settings, with the same care as the rest of the design tools:
 *  - CSS (custom_css of an element, keys with «css»): CssText::problem, as Additional CSS. Elementor shows it only with
 *    Elementor Pro, so it is refused without Pro (a draft that would change nothing is worse than a clear «no»);
 *  - HTML (html widget, keys with «html»): page markup through kses (WordPress' «post» tags), no <script> / <style>,
 *    no event handlers (on…=), no javascript: / data: addresses, <iframe> only for https YouTube / Vimeo embeds, images only
 *    from this site, links checked like every other link (an outside link is flagged on the card);
 *  - custom attributes (`_attributes` of an element — Elementor Pro, one «name|value» per line — and `custom_attributes`
 *    of a link, «name|value» separated by commas): names [a-z0-9-], no on…, no href / src / srcdoc / formaction, values
 *    without javascript: / data: / quotes / angle brackets;
 *  - scripts, JavaScript and shortcodes stay closed.
 * Every code setting needs the «unfiltered_html» permission (as the Custom HTML block of plan_blocks_edit).
 */
final class ElementorCode {

	public const MAX = 20000;

	/**
	 * Keys that look code-like but are plain settings.
	 */
	public const SAFE_KEYS = array( '_css_classes', 'css_filters', 'css_filters_hover', 'html_tag' );

	/**
	 * Whether Elementor Pro is active (null = detect; tests set it).
	 *
	 * @var bool|null
	 */
	public static $pro = null;

	/**
	 * Iframe sources allowed in HTML.
	 */
	private const VIDEO_SRC = '#^https://(?:www\.)?(?:youtube\.com/embed/|youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)[A-Za-z0-9_\-/?=&;.%]*$#';

	/**
	 * Tags refused in HTML (besides script / style / iframe, checked on their own).
	 */
	private const BAD_TAGS = '/<\s*\/?\s*(object|embed|applet|frame|frameset|base|meta|link|form|input|textarea|select|option|button|svg|math|template|noscript)\b/i';

	/**
	 * Kind of a setting key: '' (plain), css, html, attributes or closed (scripts / shortcodes).
	 *
	 * @param string $key Key.
	 */
	public static function kind( string $key ): string {
		if ( in_array( $key, self::SAFE_KEYS, true ) ) {
			return '';
		}
		if ( preg_match( '/script|(^|_)js($|_)|shortcode|_code$|^code$/i', $key ) ) {
			return 'closed';
		}
		if ( preg_match( '/css/i', $key ) ) {
			return 'css';
		}
		if ( preg_match( '/html/i', $key ) ) {
			return 'html';
		}
		if ( preg_match( '/attributes/i', $key ) ) {
			return 'attributes';
		}
		return '';
	}

	/**
	 * Why a closed key is refused.
	 *
	 * @param string $key Key.
	 */
	public static function closed_message( string $key ): string {
		return sprintf( 'The setting "%s" holds a script or shortcode, which stays closed: scripts would run code in every visitor\'s browser and shortcodes run other plugins\' code. CSS (custom_css) and HTML (the html widget) are allowed.', $key );
	}

	/**
	 * Whether Elementor Pro is active.
	 */
	public static function pro(): bool {
		// Elementor Pro (a separate plugin by Elementor) announces itself with this action on every request.
		return null !== self::$pro ? (bool) self::$pro : did_action( 'elementor_pro/init' ) > 0;
	}

	/**
	 * A checked code value.
	 *
	 * @param string $kind     css|html|attributes.
	 * @param string $key      Key.
	 * @param mixed  $value    Value.
	 * @param array  $external External links met (appended).
	 * @return string
	 * @throws \InvalidArgumentException When not allowed.
	 */
	public static function clean( string $kind, string $key, $value, array &$external ): string {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'The setting "%s" is text.', esc_html( $key ) ) );
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			throw new \InvalidArgumentException( 'Custom CSS, HTML and attributes need the «unfiltered_html» permission (an administrator).' );
		}
		if ( strlen( $value ) > self::MAX ) {
			throw new \InvalidArgumentException( sprintf( 'The setting "%1$s" is too long (max %2$d bytes): split it into several elements.', esc_html( $key ), (int) self::MAX ) );
		}
		switch ( $kind ) {
			case 'css':
				if ( ! self::pro() ) {
					throw new \InvalidArgumentException( 'Custom CSS of an Elementor element is shown only with Elementor Pro, which this site does not have: use plan_theme_settings (Additional CSS) instead, with a selector for this page or element.' );
				}
				$problem = '' === trim( $value ) ? '' : CssText::problem( $value );
				if ( '' !== $problem ) {
					throw new \InvalidArgumentException( esc_html( 'custom CSS: ' . $problem ) );
				}
				return $value;
			case 'html':
				return self::html( $value, $external );
			case 'attributes':
				if ( '_attributes' === $key && ! self::pro() ) {
					throw new \InvalidArgumentException( 'Custom attributes of an Elementor element are shown only with Elementor Pro, which this site does not have.' );
				}
				return self::attributes( $value, '_attributes' === $key ? "\n" : ',' );
		}
		throw new \InvalidArgumentException( esc_html( self::closed_message( $key ) ) );
	}

	/**
	 * Safe HTML for an html widget.
	 *
	 * @param string $html     Proposed HTML.
	 * @param array  $external External links met (appended).
	 * @throws \InvalidArgumentException When not allowed.
	 */
	public static function html( string $html, array &$external = array() ): string {
		$html = trim( str_replace( "\r\n", "\n", $html ) );
		if ( '' === $html ) {
			return '';
		}
		$decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( array( $html, $decoded ) as $candidate ) {
			if ( preg_match( '/<\s*\/?\s*script\b/i', $candidate ) ) {
				throw new \InvalidArgumentException( 'Scripts (<script>) stay closed: they would run code in every visitor\'s browser. Use HTML and CSS only.' );
			}
			if ( preg_match( '/<\s*\/?\s*style\b/i', $candidate ) ) {
				throw new \InvalidArgumentException( 'Put CSS in the element\'s custom_css setting (or Additional CSS), not in a <style> tag.' );
			}
			if ( preg_match( self::BAD_TAGS, $candidate, $m ) ) {
				throw new \InvalidArgumentException( sprintf( '<%s> is not allowed in HTML: only page content (text, links, lists, tables, images of this site, YouTube / Vimeo videos).', esc_html( strtolower( $m[1] ) ) ) );
			}
			if ( preg_match( '/[\s"\'\/]on[a-z]+\s*=|\bsrcdoc\s*=|\bformaction\s*=/i', $candidate ) ) {
				throw new \InvalidArgumentException( 'Event-handler attributes (on…=) are not allowed in HTML.' );
			}
			if ( preg_match( '/(?:java|vb)script\s*:|=\s*["\']?\s*data\s*:/i', $candidate ) ) {
				throw new \InvalidArgumentException( 'javascript: and data: addresses are not allowed in HTML.' );
			}
		}
		if ( preg_match_all( '/<\s*iframe\b([^>]*)>/i', $html, $frames ) ) {
			foreach ( $frames[1] as $attrs ) {
				$src = self::attr( $attrs, 'src' );
				if ( ! preg_match( self::VIDEO_SRC, html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) ) ) {
					throw new \InvalidArgumentException( 'Only YouTube or Vimeo videos (an https embed address: youtube.com/embed/…, player.vimeo.com/video/…) may be embedded with <iframe>.' );
				}
			}
		}
		if ( preg_match_all( '/<\s*img\b([^>]*)>/i', $html, $images ) ) {
			foreach ( $images[1] as $attrs ) {
				$urls = array( self::attr( $attrs, 'src' ) );
				foreach ( explode( ',', self::attr( $attrs, 'srcset' ) ) as $candidate ) {
					$urls[] = (string) strtok( trim( $candidate ), ' ' );
				}
				foreach ( $urls as $i => $url ) {
					if ( ( 0 === $i || '' !== $url ) && ! self::own_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) ) ) {
						throw new \InvalidArgumentException( 'Images in HTML must come from this site (the address of a media library image from find_media).' );
					}
				}
			}
		}
		if ( preg_match_all( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $links, PREG_SET_ORDER ) ) {
			foreach ( $links as $match ) {
				$href = html_entity_decode( (string) ( $match[1] ?? '' ) . (string) ( $match[2] ?? '' ) . (string) ( $match[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
				$url  = DesignText::url( $href );
				if ( is_wp_error( $url ) ) {
					throw new \InvalidArgumentException( esc_html( $url->get_error_message() ) );
				}
				if ( $url['external'] ) {
					$external[] = $url['url'];
				}
			}
		}
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( array( 'form', 'input', 'textarea', 'select', 'option', 'button' ) as $tag ) {
			unset( $allowed[ $tag ] );
		}
		$allowed['iframe'] = array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'title'           => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'frameborder'     => true,
			'loading'         => true,
			'referrerpolicy'  => true,
			'class'           => true,
			'id'              => true,
			'style'           => true,
		);
		return trim( wp_kses( $html, $allowed, array( 'http', 'https', 'mailto', 'tel' ) ) );
	}

	/**
	 * Checked custom attributes («name|value» pairs).
	 *
	 * @param string $value     Attributes.
	 * @param string $delimiter Between pairs ("\n" for an element, "," for a link).
	 * @throws \InvalidArgumentException When not allowed.
	 */
	public static function attributes( string $value, string $delimiter ): string {
		$pairs = array();
		foreach ( explode( $delimiter, str_replace( "\r\n", "\n", $value ) ) as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '|', $pair, 2 );
			$name  = strtolower( trim( $parts[0] ) );
			$attr  = trim( (string) ( $parts[1] ?? '' ) );
			if ( ! preg_match( '/^[a-z0-9-]{1,50}$/', $name ) ) {
				throw new \InvalidArgumentException( sprintf( 'Attribute name "%s" is not allowed: use letters, digits and hyphens (e.g. data-id|5, aria-label|Menu).', esc_html( $name ) ) );
			}
			if ( 0 === strpos( $name, 'on' ) || in_array( $name, array( 'href', 'src', 'srcdoc', 'formaction', 'action' ), true ) ) {
				throw new \InvalidArgumentException( sprintf( 'The attribute "%s" is not allowed (event handlers and addresses stay closed).', esc_html( $name ) ) );
			}
			$flat = html_entity_decode( $attr, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( mb_strlen( $attr ) > 500 || preg_match( '/[<>"`]|(?:java|vb)script\s*:|data\s*:/i', $flat ) ) {
				throw new \InvalidArgumentException( sprintf( 'The value of attribute "%s" is not allowed (no quotes, angle brackets, javascript: or data:; at most 500 characters).', esc_html( $name ) ) );
			}
			$pairs[] = '' === $attr ? $name : $name . '|' . $attr;
		}
		if ( count( $pairs ) > 20 ) {
			throw new \InvalidArgumentException( 'At most 20 custom attributes per element.' );
		}
		return implode( $delimiter, $pairs );
	}

	/**
	 * Why a stored Elementor document adds code that the planner would not have written ('' = fine). The remote Approve
	 * gets the new JSON back from the service: every code setting whose value is not already in $now (kept, moved or
	 * duplicated) must be exactly what clean() gives for it, and closed keys may not appear.
	 *
	 * @param string $now  JSON now.
	 * @param string $next JSON to write.
	 */
	public static function tree_problem( string $now, string $next ): string {
		$known = array();
		foreach ( self::code_values( (array) json_decode( $now, true ) ) as $entry ) {
			$known[ $entry['key'] . "\0" . wp_json_encode( $entry['value'] ) ] = true;
		}
		foreach ( self::code_values( (array) json_decode( $next, true ) ) as $entry ) {
			// Kept, moved or duplicated as it was: not new code.
			if ( isset( $known[ $entry['key'] . "\0" . wp_json_encode( $entry['value'] ) ] ) ) {
				continue;
			}
			if ( 'closed' === $entry['kind'] ) {
				return self::closed_message( $entry['key'] );
			}
			$external = array();
			try {
				$clean = self::clean( $entry['kind'], $entry['key'], $entry['value'], $external );
			} catch ( \InvalidArgumentException $e ) {
				return $e->getMessage();
			}
			if ( $clean !== $entry['value'] ) {
				return sprintf( 'The setting "%s" is not the one previewed.', $entry['key'] );
			}
		}
		return '';
	}

	/**
	 * Code settings of a document: "elementId/key/sub…" → {kind, key, value}.
	 *
	 * @param array<int|string,mixed> $items Elements.
	 * @return array<string,array{kind:string,key:string,value:mixed}>
	 */
	private static function code_values( array $items ): array {
		$out      = array();
		$walk     = static function ( $value, string $path ) use ( &$walk, &$out ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			foreach ( $value as $key => $item ) {
				$kind = is_string( $key ) ? self::kind( $key ) : '';
				if ( '' !== $kind && ! is_array( $item ) ) {
					$out[ $path . '/' . $key ] = array(
						'kind'  => $kind,
						'key'   => (string) $key,
						'value' => $item,
					);
					continue;
				}
				$walk( $item, $path . '/' . $key );
			}
		};
		$elements = static function ( array $items_list ) use ( &$elements, $walk ): void {
			foreach ( $items_list as $i => $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}
				$walk( $element['settings'] ?? array(), (string) ( $element['id'] ?? '#' . $i ) );
				$elements( (array) ( $element['elements'] ?? array() ) );
			}
		};
		$elements( $items );
		return $out;
	}

	/**
	 * An attribute of a tag ('' when missing).
	 *
	 * @param string $attrs Attributes text.
	 * @param string $name  Name.
	 */
	private static function attr( string $attrs, string $name ): string {
		if ( preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $m ) ) {
			return (string) ( $m[1] ?? '' ) . (string) ( $m[2] ?? '' ) . (string) ( $m[3] ?? '' );
		}
		return '';
	}

	/**
	 * Whether a URL is on this site (relative, or http(s) with this site's host).
	 *
	 * @param string $url URL.
	 */
	private static function own_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '#^/(?!/)#', $url ) ) {
			return true;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $host && preg_match( '#^https?://#i', $url ) && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}
