<?php
/**
 * Ability check-media-url (1.3.7, plan_media_upload): whether an image address may be downloaded into the media library
 * once the owner approves — https, a public host (looked up, never fetched here) and an image file name. Downloads nothing.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Media\MediaSideload;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only check of an image address.
 */
final class CheckMediaUrlAbility {

	public const NAME = 'addsynapse-assistant/check-media-url';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Check an image address', 'addsynapse-store-assistant' ),
				'description'         => 'Whether an image address (https, public host) can be added to the media library after an approval, and the limits (JPG, PNG, WebP or GIF, at most 10 MB). Looks the host up but downloads nothing. Read-only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'url' ),
					'properties'           => array(
						'url' => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Execute callback: {ok, url, reason?, can_upload, max_bytes, types}.
	 *
	 * @param array<string,mixed> $input {url}.
	 * @return array<string,mixed>
	 */
	public function execute( $input ) {
		$url     = trim( (string) ( $input['url'] ?? '' ) );
		$problem = MediaSideload::url_problem( $url );
		$upload  = current_user_can( 'upload_files' );
		if ( '' === $problem && ! $upload ) {
			$problem = 'The WordPress user AddSynapse is connected as may not upload files.';
		}
		return array(
			'ok'         => '' === $problem,
			'url'        => $url,
			'reason'     => $problem,
			'can_upload' => $upload,
			'max_bytes'  => MediaSideload::MAX_BYTES,
			'types'      => array_keys( MediaSideload::TYPES ),
		);
	}
}
