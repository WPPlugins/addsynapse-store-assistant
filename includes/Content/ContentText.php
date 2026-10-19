<?php
/**
 * Validation / sanitisation of page texts, URLs and images proposed by the AI.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Content;

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
final class ContentText {

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
