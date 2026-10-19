<?php
/**
 * Ability addsynapse-assistant/find-media — images of the media library (read-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Content\MediaFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Candidates for a new banner / an image node: search by title / alt / file name, the newest
 * images, or the images of given products. Only existing library images (nothing is uploaded or
 * fetched from the internet); images whose file is missing on disk are left out and counted.
 */
final class FindMediaAbility {

	public const NAME = 'addsynapse-assistant/find-media';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Media library images', 'addsynapse-store-assistant' ),
				'description'         => 'Finds existing images of the media library (read-only): by words of their title / alt text / file name (query), the images of given products (product_ids) or the newest images (recent). Returns ids with thumbnail URLs (images the manager uploaded in the chat are included, flagged pending). Never uploads.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type' => 'object',
				),
				'execute_callback'    => array( $this, 'execute' ),
				// Standard: product images too (not only pages).
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
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
				'query'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'product_ids' => array(
					'type'     => 'array',
					'maxItems' => MediaFinder::MAX_PRODUCTS,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'recent'      => array(
					'type' => 'boolean',
				),
				'limit'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => MediaFinder::MAX_LIMIT,
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
		return MediaFinder::find( $input ) + array( '_data' => Shared::DATA_NOTICE );
	}
}
