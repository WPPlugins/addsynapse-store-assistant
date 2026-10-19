<?php
/**
 * Conversation titles.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

use Addsynapse\StoreAssistant\Data\ConversationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Instant truncated title. A better title may come from the AI with the first answer
 * (LlmResponse::$title, applied by the AgentLoop).
 */
final class TitleGenerator {

	/**
	 * Truncated fallback title from the first message.
	 *
	 * @param string $text First user message.
	 */
	public static function fallback( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		if ( mb_strlen( $text ) > 60 ) {
			$cut  = mb_substr( $text, 0, 60 );
			$last = mb_strrpos( $cut, ' ' );
			$text = rtrim( false !== $last && $last > 30 ? mb_substr( $cut, 0, $last ) : $cut, ' ,.;:' ) . '…';
		}
		return ConversationRepository::clean_title( $text );
	}
}
