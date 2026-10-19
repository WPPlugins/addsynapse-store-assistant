<?php
/**
 * Shapes stored rows for the admin UI.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

use Addsynapse\StoreAssistant\Abilities\CustomerReportAbility;
use Addsynapse\StoreAssistant\Agent\Selection;
use Addsynapse\StoreAssistant\Media\ImageUploads;
use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Data\MessageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Output shaping. Thinking blocks / signatures never leave the server.
 */
final class MessageFormatter {

	public const RESULT_PREVIEW_CHARS = 4000;

	/**
	 * UTC mysql datetime → ISO 8601.
	 *
	 * @param string $mysql Y-m-d H:i:s (UTC).
	 */
	public static function iso( string $mysql ): string {
		return '' === $mysql ? '' : str_replace( ' ', 'T', $mysql ) . 'Z';
	}

	/**
	 * Conversation for the UI.
	 *
	 * @param array<string,mixed> $c Conversation row.
	 * @return array<string,mixed>
	 */
	public static function conversation( array $c ): array {
		return array(
			'id'         => (int) $c['id'],
			'title'      => (string) $c['title'],
			'created_at' => self::iso( (string) $c['created_at'] ),
			'updated_at' => self::iso( (string) $c['updated_at'] ),
		);
	}

	/**
	 * Tool result block for the UI (stored history and live SSE event alike).
	 *
	 * Structured results (already capped by ToolRegistry) go out decoded as `data` so the UI
	 * can render tables/cards; they are rendered as text nodes, never as HTML.
	 *
	 * @param array<string,mixed> $b Stored tool_result block (tool_use_id, content, is_error).
	 * @return array<string,mixed>
	 */
	public static function tool_result( array $b ): array {
		$content = is_string( $b['content'] ?? null ) ? $b['content'] : (string) wp_json_encode( $b['content'] ?? '' );
		$block   = array(
			'type'        => 'tool_result',
			'tool_use_id' => (string) ( $b['tool_use_id'] ?? '' ),
			'is_error'    => ! empty( $b['is_error'] ),
		);
		$data    = empty( $b['is_error'] ) ? json_decode( $content, true ) : null;
		if ( is_array( $data ) ) {
			$block['data'] = $data;
			if ( CustomerReportAbility::REPORT === ( $data['report'] ?? '' ) ) {
				// Customer names ONLY for this browser (the stored result / the AI provider have references only).
				$block['data']['display'] = (object) CustomerReportAbility::display( $data );
			}
		} else {
			if ( mb_strlen( $content ) > self::RESULT_PREVIEW_CHARS ) {
				$content = mb_substr( $content, 0, self::RESULT_PREVIEW_CHARS ) . '…';
			}
			$block['content'] = $content;
		}
		return $block;
	}

	/**
	 * Agent-loop event for the browser (SSE). A `tool_result` event carries the same shaped
	 * block as the stored history, so the live turn renders result views / changeset cards
	 * immediately (not only after the thread is reloaded).
	 *
	 * @param array<string,mixed> $event Event from AgentLoop / CommandRunner.
	 * @return array<string,mixed>
	 */
	public static function event( array $event ): array {
		if ( 'tool_result' === ( $event['type'] ?? '' ) ) {
			return self::tool_result( $event );
		}
		return $event;
	}

	/**
	 * Message for the UI.
	 *
	 * @param array<string,mixed> $m     Message row.
	 * @param ToolRegistry|null   $tools For tool labels.
	 * @return array<string,mixed>
	 */
	public static function message( array $m, ?ToolRegistry $tools = null ): array {
		$blocks = array();
		foreach ( (array) $m['content'] as $b ) {
			$type = $b['type'] ?? '';
			if ( 'text' === $type ) {
				$blocks[] = array(
					'type' => 'text',
					'text' => (string) $b['text'],
				);
			} elseif ( 'tool_use' === $type && MessageRepository::ROLE_ASSISTANT === $m['role'] ) {
				$name     = (string) ( $b['name'] ?? '' );
				$blocks[] = array(
					'type'  => 'tool_use',
					'id'    => (string) ( $b['id'] ?? '' ),
					'name'  => $name,
					'label' => $tools ? $tools->label( $name ) : $name,
					'input' => empty( $b['input'] ) ? new \stdClass() : $b['input'],
				);
			} elseif ( 'tool_result' === $type && MessageRepository::ROLE_TOOL === $m['role'] ) {
				$blocks[] = self::tool_result( $b );
			}//end if
			// thinking / redacted_thinking are intentionally omitted.
		}//end foreach
		$out = array(
			'id'         => (int) $m['id'],
			'role'       => (string) $m['role'],
			'status'     => (string) $m['status'],
			'created_at' => self::iso( (string) $m['created_at'] ),
			'blocks'     => $blocks,
		);
		if ( MessageRepository::ROLE_USER === $m['role'] ) {
			$image = ImageUploads::from_blocks( (array) $m['content'] );
			if ( $image ) {
				// Thumbnail chip under the bubble (gone = deleted by the cleanup).
				$thumb        = 'attachment' === get_post_type( (int) $image['attachment_id'] ) ? wp_get_attachment_image_url( (int) $image['attachment_id'], 'thumbnail' ) : false;
				$out['image'] = array(
					'attachment_id' => (int) $image['attachment_id'],
					'name'          => (string) $image['name'],
					'width'         => (int) $image['width'],
					'height'        => (int) $image['height'],
					'thumb'         => $thumb ? (string) $thumb : '',
				);
			}
			$selection = Selection::from_blocks( (array) $m['content'] );
			if ( $selection ) {
				// «με N επιλεγμένα προϊόντα» under the bubble.
				$out['selection'] = array(
					'product_ids' => $selection,
					'count'       => count( $selection ),
				);
			}
		}//end if
		return $out;
	}
}
