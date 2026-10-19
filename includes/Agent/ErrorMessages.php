<?php
/**
 * Friendly, translatable error messages for LLM error codes.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Maps codes to user-facing text.
 */
final class ErrorMessages {

	/**
	 * Message for a code.
	 *
	 * @param string $code   Code.
	 * @param string $detail Technical detail (shown for bad_request only).
	 */
	public static function for_code( string $code, string $detail = '' ): string {
		switch ( $code ) {
			case 'not_configured':
				return __( 'The AI is not set up on this site yet. An administrator can add an Anthropic or OpenAI API key in AddSynapse → Store Assistant → Settings. Slash commands (type /help) work without it.', 'addsynapse-store-assistant' );
			case 'byok_invalid_key':
				return __( 'Your AI provider rejected the API key. Check the key in AddSynapse → Store Assistant → Settings.', 'addsynapse-store-assistant' );
			case 'byok_quota':
				return __( 'Your AI provider account has no credit or reached its spending limit. Add credit in your provider\'s dashboard, then retry.', 'addsynapse-store-assistant' );
			case 'conversation_limit':
				return __( 'This conversation is too long. Please start a new conversation.', 'addsynapse-store-assistant' );
			case 'invalid_response':
				return __( 'The AI service and this plugin could not agree on the conversation state. Please retry or start a new conversation.', 'addsynapse-store-assistant' );
			case 'rate_limited':
				return __( 'The AI service is receiving too many requests right now. Please wait a moment and retry.', 'addsynapse-store-assistant' );
			case 'overloaded':
				return __( 'The AI service is temporarily overloaded. Please retry in a minute.', 'addsynapse-store-assistant' );
			case 'server':
				return __( 'The AI service had a temporary error. Please retry.', 'addsynapse-store-assistant' );
			case 'network':
				return __( 'Could not reach the AI service (network error or timeout). Please retry.', 'addsynapse-store-assistant' );
			case 'nothing_to_answer':
				return __( 'There is no message to answer.', 'addsynapse-store-assistant' );
			case 'bad_request':
				/* translators: %s: technical error detail from the API. */
				return sprintf( __( 'The AI service rejected the request: %s', 'addsynapse-store-assistant' ), $detail );
			default:
				return __( 'Something went wrong while contacting the AI service.', 'addsynapse-store-assistant' );
		}//end switch
	}
}
