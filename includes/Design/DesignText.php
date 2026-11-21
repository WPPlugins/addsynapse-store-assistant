<?php
/**
 * Validation / sanitisation of page texts, URLs and images proposed by the AI.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Texts from the model are untrusted. Rules:
 *  - anything that looks like active content (script/iframe/style/object/embed/form/svg…,
 *    on* attributes, javascript:/data:/vbscript: URLs) is REJECTED, never silently fixed;
 *  - the rest goes through wp_kses with http/https only (headings/buttons: inline tags only);
 *  - URLs: relative (/path, #anchor, ?query) or absolute http(s); another domain is allowed but
 *    reported (`external`) so the card flags it;
 *  - images: only existing media-library image attachments (no uploads from URLs).
 */
final class DesignText {

	public const MAX = array(
		'heading' => 500,
		'text'    => 20000,
		'button'  => 200,
		'alt'     => 250,
		'url'     => 2000,
	);

	private const DANGEROUS_TAGS = '/<\s*\/?\s*(script|iframe|frame|frameset|style|object|embed|applet|form|input|textarea|select|button|link|meta|base|svg|math|template|noscript)\b/i';

	private const DANGEROUS_ATTR = '/[\s"\'\/]on[a-z]+\s*=|\bsrcdoc\s*=|\bformaction\s*=/i';

	private const DANGEROUS_URL = '/(?:java|vb)script\s*:|data\s*:|file\s*:/i';

	/**
	 * Inline tags allowed in headings.
	 */
	private const INLINE = array( 'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small', 'sub', 'sup', 'br', 'span', 'a' );

	/**
	 * Rich text for a heading or text node → safe HTML.
	 *
	 * @param string $html    Proposed HTML.
	 * @param string $context heading|text.
	 * @return array{html:string,external:array<int,string>}|WP_Error
	 */
	public static function rich( string $html, string $context ) {
		$html = trim( str_replace( "\r\n", "\n", $html ) );
		if ( '' === $html ) {
			return new WP_Error( 'addsynapse_assistant_content_empty', 'The new text is empty. To remove an element, ask the manager to do it in the page editor.' );
		}
		$max = 'heading' === $context ? self::MAX['heading'] : self::MAX['text'];
		if ( mb_strlen( $html ) > $max ) {
			return new WP_Error( 'addsynapse_assistant_content_too_long', sprintf( 'The new text is too long (max %d characters): split it into several text blocks.', $max ) );
		}
		$active = self::active_content( $html );
		if ( '' !== $active ) {
			return new WP_Error( 'addsynapse_assistant_content_unsafe', $active );
		}
		$external = array();
		if ( preg_match_all( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$href = html_entity_decode( (string) ( $match[1] ?? '' ) . (string) ( $match[2] ?? '' ) . (string) ( $match[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
				$url  = self::url( $href );
				if ( is_wp_error( $url ) ) {
					return $url;
				}
				if ( $url['external'] ) {
					$external[] = $url['url'];
				}
			}
		}
		if ( preg_match( '/<\s*img\b/i', $html ) ) {
			return new WP_Error( 'addsynapse_assistant_content_unsafe', 'Images cannot be added inside text. Change an image node instead (image_id of an existing media library image).' );
		}
		$allowed = wp_kses_allowed_html( 'post' );
		if ( 'heading' === $context ) {
			$allowed = array_intersect_key( $allowed, array_flip( self::INLINE ) );
		} else {
			unset( $allowed['img'], $allowed['video'], $allowed['audio'], $allowed['source'], $allowed['track'], $allowed['area'], $allowed['map'], $allowed['form'], $allowed['button'], $allowed['input'], $allowed['textarea'], $allowed['select'], $allowed['option'] );
		}
		// 1.3.7: WordPress' CSS filter drops every value with «(», so Gutenberg's own text-colour format
		// <mark style="background-color:rgba(0, 0, 0, 0);color:#hex" class="has-inline-color"> lost its transparent background and
		// showed the browser's yellow <mark>. Plain rgb()/rgba() colours on color / background-color pass, only inside this call.
		add_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_rgb_color' ), 10, 2 );
		try {
			$clean = trim( wp_kses( $html, $allowed, array( 'http', 'https' ) ) );
		} finally {
			remove_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_rgb_color' ), 10 );
		}
		if ( '' === trim( wp_strip_all_tags( $clean ) ) ) {
			return new WP_Error( 'addsynapse_assistant_content_empty', 'The new text is empty after removing unsupported markup.' );
		}
		return array(
			'html'     => $clean,
			'external' => array_values( array_unique( $external ) ),
		);
	}

	/**
	 * Filter safecss_filter_attr_allow_css (only while rich() runs kses): a color / background-color declaration whose value is
	 * a plain rgb() / rgba() colour (numbers only) is allowed; everything else keeps WordPress' own verdict.
	 *
	 * @param bool   $allow       WordPress' verdict.
	 * @param string $declaration The declaration («property:value»).
	 */
	public static function allow_rgb_color( $allow, $declaration ): bool {
		if ( $allow ) {
			return true;
		}
		return (bool) preg_match( '/^\s*(?:background-color|color)\s*:\s*rgba?\(\s*\d{1,3}%?\s*,\s*\d{1,3}%?\s*,\s*\d{1,3}%?\s*(?:,\s*(?:0|1|0?\.\d{1,4}|\d{1,3}%)\s*)?\)\s*$/i', (string) $declaration );
	}

	/**
	 * Plain text (button label / alt text).
	 *
	 * @param string $text Proposed text.
	 * @param string $kind button|alt.
	 * @return string|WP_Error
	 */
	public static function plain( string $text, string $kind ) {
		if ( '' !== self::active_content( $text ) || preg_match( '/<[a-z\/!]/i', $text ) ) {
			return new WP_Error( 'addsynapse_assistant_content_unsafe', 'Button texts and alt texts are plain text: no HTML.' );
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		$max  = self::MAX[ $kind ] ?? 200;
		if ( 'button' === $kind && '' === $text ) {
			return new WP_Error( 'addsynapse_assistant_content_empty', 'The button text is empty.' );
		}
		if ( mb_strlen( $text ) > $max ) {
			return new WP_Error( 'addsynapse_assistant_content_too_long', sprintf( 'The text is too long (max %d characters).', $max ) );
		}
		return $text;
	}

	/**
	 * Why a text contains active content ('' = it does not).
	 *
	 * @param string $text Text.
	 */
	public static function active_content( string $text ): string {
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( array( $text, $decoded ) as $candidate ) {
			if ( preg_match( self::DANGEROUS_TAGS, $candidate ) ) {
				return 'Scripts, iframes, styles, forms and embeds are not allowed in page texts.';
			}
			if ( preg_match( self::DANGEROUS_ATTR, $candidate ) ) {
				return 'Event-handler attributes (on…=) are not allowed in page texts.';
			}
			if ( preg_match( self::DANGEROUS_URL, $candidate ) ) {
				return 'Only http(s) or relative links are allowed.';
			}
		}
		return '';
	}

	/**
	 * A link target: relative (/path, #anchor, ?query) or absolute http(s).
	 *
	 * @param string $url URL.
	 * @return array{url:string,external:bool}|WP_Error
	 */
	public static function url( string $url ) {
		$url = trim( $url );
		if ( '' === $url || mb_strlen( $url ) > self::MAX['url'] || preg_match( '/[\s<>"\'`\x00-\x1f\\\\]/', $url ) || preg_match( self::DANGEROUS_URL, $url ) ) {
			return new WP_Error( 'addsynapse_assistant_content_url', 'Invalid link: use a relative path (/shop/…) or a full http(s) address.' );
		}
		if ( preg_match( '#^(/(?!/)|\#|\?)#', $url ) ) {
			return array(
				'url'      => $url,
				'external' => false,
			);
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'addsynapse_assistant_content_url', 'Invalid link: use a relative path (/shop/…) or a full http(s) address.' );
		}
		$external = self::host( (string) $parts['host'] ) !== self::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $external && 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( 'addsynapse_assistant_content_url', 'Links to other sites must use https.' );
		}
		return array(
			'url'      => $url,
			'external' => $external,
		);
	}

	/**
	 * Host without www.
	 *
	 * @param string $host Host.
	 */
	private static function host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * An existing media-library image.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Image size for the stored URL.
	 * @return array{image_id:int,image_url:string,alt:string}|WP_Error
	 */
	public static function image( int $attachment_id, string $size = 'full' ) {
		$post = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $attachment_id ) || '' !== \Addsynapse\StoreAssistant\Media\ImageUploads::usable_problem( $attachment_id ) ) {
			return new WP_Error( 'addsynapse_assistant_content_image', sprintf( 'Image #%d is not an image of the media library. Only media library images can be used (the manager can upload one with the paperclip in the chat).', $attachment_id ) );
		}
		$url = wp_get_attachment_image_url( $attachment_id, '' !== $size ? $size : 'full' );
		if ( ! $url ) {
			$url = wp_get_attachment_url( $attachment_id );
		}
		return array(
			'image_id'  => $attachment_id,
			'image_url' => (string) $url,
			'alt'       => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Plain excerpt of HTML.
	 *
	 * @param string $html HTML.
	 * @param int    $max  Max chars.
	 */
	public static function excerpt( string $html, int $max = 160 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}
}
