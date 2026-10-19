<?php
/**
 * Ability addsynapse-assistant/describe-page — the elements of one page (read-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Adapters\Content\ContentAdapterInterface;
use Addsynapse\StoreAssistant\Adapters\Content\ContentAdapters;
use Addsynapse\StoreAssistant\Content\NeedsHuman;
use Addsynapse\StoreAssistant\Content\PageFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the page (id, title/slug, front page, shop page) and returns its builder and normalized
 * nodes. A page built with an unsupported builder → `needs_human` (logged for the developer), no
 * guessing. Several title matches → `candidates` to ask about.
 */
final class DescribePageAbility {

	public const NAME = 'addsynapse-assistant/describe-page';

	public const MAX_NODES = 150;

	public const MAX_VALUE = 1500;

	public const MAX_SECTIONS = 60;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Page elements', 'addsynapse-store-assistant' ),
				'description'         => 'Returns one page (read-only): its builder (gutenberg, elementor, or needs_human for unsupported builders) and its elements as nodes {id, type heading|text|button|image|unsupported, label, value, text, path, editable fields, hidden_on = devices where the element is not shown} and its sections (the page structure). Find the page by page_id, query (title or slug), front_page or shop_page.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type' => 'object',
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( self::class, 'can_use' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Permission: the plugin's capability AND the core capability to edit pages.
	 */
	public static function can_use(): bool {
		return AbilitiesRegistrar::can_manage() && current_user_can( 'edit_pages' );
	}

	/**
	 * Input schema (also the tool definition the AI provider receives).
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'page_id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'query'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'front_page' => array(
					'type' => 'boolean',
				),
				'shop_page'  => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$found = PageFinder::find( $input );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( is_array( $found ) ) {
			return array(
				'ambiguous'  => true,
				'candidates' => $found,
				'note'       => 'Several pages match. Ask the manager which one (or suggest the "Show me" picker).',
				'_data'      => Shared::DATA_NOTICE,
			);
		}
		return self::describe( $found );
	}

	/**
	 * Description of one page.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public static function describe( \WP_Post $post ): array {
		$page     = PageFinder::summary( (int) $post->ID );
		$detected = ContentAdapters::detect( $post );
		$adapter  = $detected['adapter'];
		if ( ! $adapter instanceof ContentAdapterInterface ) {
			NeedsHuman::log( (int) $post->ID, $detected['builder'], $detected['reason'] );
			return array(
				'page'        => $page,
				'builder'     => $detected['builder'],
				'needs_human' => true,
				'reason'      => $detected['reason'],
				'nodes'       => array(),
				'note'        => 'needs_human: this page cannot be edited from the chat. Tell the manager; the request was listed for the developer in Settings.',
				'_data'       => Shared::DATA_NOTICE,
			);
		}
		try {
			$nodes = $adapter->read_nodes( (int) $post->ID );
		} catch ( \RuntimeException $e ) {
			NeedsHuman::log( (int) $post->ID, $detected['builder'], $e->getMessage() );
			return array(
				'page'        => $page,
				'builder'     => $detected['builder'],
				'needs_human' => true,
				'reason'      => $e->getMessage(),
				'nodes'       => array(),
				'_data'       => Shared::DATA_NOTICE,
			);
		}
		$counts = array(
			'nodes'    => count( $nodes ),
			'editable' => 0,
		);
		$out    = array();
		foreach ( $nodes as $node ) {
			if ( $node['editable'] ) {
				++$counts['editable'];
			}
			if ( count( $out ) >= self::MAX_NODES ) {
				continue;
			}
			$out[] = self::shape( $node );
		}
		// Sections / containers: the structure of the page.
		$sections = array();
		try {
			$sections = array_slice( $adapter->sections( (string) $adapter->raw( (int) $post->ID ), (int) $post->ID ), 0, self::MAX_SECTIONS );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		return array(
			'page'      => $page,
			'builder'   => $adapter->kind(),
			'nodes'     => $out,
			'sections'  => $sections,
			'counts'    => $counts,
			'truncated' => count( $nodes ) > count( $out ),
			'_data'     => Shared::DATA_NOTICE,
		);
	}

	/**
	 * A node for the model / UI (long values cut; internal keys dropped).
	 *
	 * @param array<string,mixed> $node Node.
	 * @return array<string,mixed>
	 */
	public static function shape( array $node ): array {
		$value = $node['value'];
		$cut   = false;
		if ( is_string( $value ) && mb_strlen( $value ) > self::MAX_VALUE ) {
			$value = mb_substr( $value, 0, self::MAX_VALUE );
			$cut   = true;
		}
		if ( is_array( $value ) ) {
			unset( $value['size'] );
			if ( ! empty( $value['image_id'] ) ) {
				$thumb          = wp_get_attachment_image_url( (int) $value['image_id'], 'thumbnail' );
				$value['thumb'] = $thumb ? (string) $thumb : '';
			}
		}
		$out = array(
			'id'       => (string) $node['id'],
			'type'     => (string) $node['type'],
			'label'    => (string) $node['label'],
			'value'    => $value,
			'text'     => (string) $node['text'],
			'path'     => (string) $node['path'],
			'editable' => array_values( (array) $node['editable'] ),
		);
		if ( $cut ) {
			$out['value_truncated'] = true;
		}
		if ( '' !== (string) ( $node['reason'] ?? '' ) ) {
			$out['reason'] = (string) $node['reason'];
		}
		if ( isset( $node['link'] ) ) {
			$out['link'] = (string) $node['link'];
		}
		if ( ! empty( $node['hidden_on'] ) ) {
			$out['hidden_on'] = array_values( (array) $node['hidden_on'] );
		}
		return $out;
	}
}
