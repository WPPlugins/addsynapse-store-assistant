<?php
/**
 * Ability check-links (1.3.20): whether addresses into this site lead to something that exists — for the drafts AddSynapse
 * builds itself (page and product texts, new posts and products) before it shows the card. Writes nothing.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Links\OwnLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only link check.
 */
final class CheckLinksAbility {

	public const NAME = 'addsynapse-assistant/check-links';

	public const MAX = 30;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Check links to this site', 'addsynapse-store-assistant' ),
				'description'         => 'Whether each address into this site leads to an existing page, post, product, category or file, with its real address or the closest real one. Read-only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'urls' ),
					'properties'           => array(
						'urls' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX,
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 2000,
							),
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
	 * Execute callback: {links: [{url, ok, own, real, problem, not_public, unverified}]}.
	 *
	 * @param array<string,mixed> $input {urls}.
	 * @return array<string,mixed>
	 */
	public function execute( $input ) {
		OwnLinks::begin();
		$out = array();
		foreach ( array_slice( array_values( (array) ( $input['urls'] ?? array() ) ), 0, self::MAX ) as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url || ! OwnLinks::is_own( $url ) ) {
				$out[] = array(
					'url' => $url,
					'ok'  => true,
					'own' => false,
				);
				continue;
			}
			$check = OwnLinks::check( $url );
			$out[] = array(
				'url'        => $url,
				'ok'         => '' === $check['problem'],
				'own'        => true,
				'real'       => (string) $check['url'],
				'problem'    => (string) $check['problem'],
				'not_public' => in_array( (string) $check['url'], OwnLinks::drafts(), true ),
				'unverified' => in_array( (string) $check['url'], OwnLinks::unverified(), true ),
			);
		}//end foreach
		return array( 'links' => $out );
	}
}
