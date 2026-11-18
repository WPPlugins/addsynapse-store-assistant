<?php
/**
 * Ability addsynapse-assistant/describe-store — the store map (discovery, read-only, cached), or the site map
 * on a WordPress site without WooCommerce.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Discovery\SiteMap;
use Addsynapse\StoreAssistant\Discovery\StoreMap;
use Addsynapse\StoreAssistant\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * How this shop stores product data: active plugins, product taxonomies, global and local
 * attributes, where the brand lives, product meta keys (frequency, type, safe sample) and
 * ACF field groups. See StoreMap.
 */
final class DescribeStoreAbility {

	public const NAME = 'addsynapse-assistant/describe-store';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => Environment::has_woocommerce() ? __( 'Store map', 'addsynapse-store-assistant' ) : __( 'Site map', 'addsynapse-store-assistant' ),
				'description'         => Environment::has_woocommerce()
					? 'Returns how this WooCommerce store structures product data (read-only, cached): active plugins, product taxonomies with top terms, global pa_* attributes with terms, local attribute names, where brands are stored, product meta keys with frequency/type/safe sample, and ACF field groups for products. Pass refresh=true to rebuild the cache.'
					: 'Returns how this WordPress site (no WooCommerce) is built (read-only): theme, pages and posts counts, front page, newest pages with their builder, SEO plugin, menus and locations, languages, active plugins.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'refresh' => array(
							'type'        => 'boolean',
							'description' => 'Rebuild the cached map.',
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'object',
					'required' => Environment::has_woocommerce() ? array( 'taxonomies', 'attributes', 'brand', 'meta_keys' ) : array( 'site_type', 'content', 'menus' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	public function execute( $input = null ): array {
		$input = is_array( $input ) ? $input : array();
		$map   = Environment::has_woocommerce() ? StoreMap::get( ! empty( $input['refresh'] ) ) : SiteMap::build();
		// 1.3.13: the content types list_content / get_content / plan_content_update work with (custom types included).
		$map['content_types'] = \Addsynapse\StoreAssistant\Remote\ListContentAbility::types();
		return $map;
	}
}
