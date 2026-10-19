<?php
/**
 * Canonical content blocks: keep only what providers understand.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Stored messages carry platform blocks (uploads, selections…) and extra keys; the API gets clean blocks only.
 */
final class Blocks {

	/**
	 * Largest image content block (base64 characters, ≈ 2 MB of image).
	 */
	public const MAX_IMAGE_BASE64 = 2800000;

	/** Largest encrypted OpenAI reasoning item kept for replay (bytes). */
	public const MAX_REASONING = 262144;

	/**
	 * Clean blocks of one message (unknown types dropped).
	 *
	 * @param mixed $content String or blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function clean( $content ): array {
		if ( is_string( $content ) ) {
			return '' === $content ? array() : array(
				array(
					'type' => 'text',
					'text' => $content,
				),
			);
		}
		$out = array();
		foreach ( (array) $content as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			switch ( (string) ( $block['type'] ?? '' ) ) {
				case 'text':
					if ( '' !== (string) ( $block['text'] ?? '' ) ) {
						$out[] = array(
							'type' => 'text',
							'text' => (string) $block['text'],
						);
					}
					break;
				case 'image':
					// An image the manager uploaded (base64, already reduced by the plugin): JPEG / PNG / WebP / GIF only.
					$source = is_array( $block['source'] ?? null ) ? $block['source'] : array();
					$media  = (string) ( $source['media_type'] ?? '' );
					$data   = (string) ( $source['data'] ?? '' );
					if ( 'base64' === ( $source['type'] ?? '' ) && in_array( $media, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) && '' !== $data && strlen( $data ) <= self::MAX_IMAGE_BASE64 ) {
						$out[] = array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $media,
								'data'       => $data,
							),
						);
					}
					break;
				case 'tool_use':
					$out[] = array(
						'type'  => 'tool_use',
						'id'    => (string) ( $block['id'] ?? '' ),
						'name'  => (string) ( $block['name'] ?? '' ),
						'input' => self::object( $block['input'] ?? array() ),
					);
					break;
				case 'tool_result':
					$result = array(
						'type'        => 'tool_result',
						'tool_use_id' => (string) ( $block['tool_use_id'] ?? '' ),
						'content'     => is_string( $block['content'] ?? null ) ? $block['content'] : \Addsynapse\StoreAssistant\Brain\Json::encode( $block['content'] ?? '' ),
					);
					if ( ! empty( $block['is_error'] ) ) {
						$result['is_error'] = true;
					}
					$out[] = $result;
					break;
				case 'thinking':
					if ( isset( $block['signature'] ) ) {
						$out[] = array(
							'type'      => 'thinking',
							'thinking'  => (string) ( $block['thinking'] ?? '' ),
							'signature' => (string) $block['signature'],
						);
					}
					break;
				case 'openai_reasoning':
					// OpenAI reasoning item (encrypted, opaque): replayed to OpenAI so a reasoning model keeps its reasoning across
					// tool rounds with store=false; never shown, never sent to another provider.
					if ( is_string( $block['encrypted_content'] ?? null ) && '' !== $block['encrypted_content'] && strlen( $block['encrypted_content'] ) <= self::MAX_REASONING ) {
						$out[] = array(
							'type'              => 'openai_reasoning',
							'id'                => (string) ( $block['id'] ?? '' ),
							'encrypted_content' => $block['encrypted_content'],
						);
					}
					break;
				case 'redacted_thinking':
					if ( isset( $block['data'] ) ) {
						$out[] = array(
							'type' => 'redacted_thinking',
							'data' => (string) $block['data'],
						);
					}
					break;
			}//end switch
		}//end foreach
		return $out;
	}

	/**
	 * A JSON object value (empty array → {}).
	 *
	 * @param mixed $value Decoded input.
	 * @return mixed
	 */
	public static function object( $value ) {
		if ( is_array( $value ) && ! $value ) {
			return new \stdClass();
		}
		return is_array( $value ) ? $value : new \stdClass();
	}
}
