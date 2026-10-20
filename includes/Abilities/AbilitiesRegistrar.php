<?php
/**
 * Registers the plugin's abilities (WordPress Abilities API, core 6.9+).
 *
 * Abilities are the ONLY way the agent touches the shop. Every ability:
 *  - has a JSON input schema,
 *  - checks current_user_can( Config::capability() ) (manage_woocommerce, or edit_theme_options
 *    on a plain WordPress site — see Capabilities),
 *  - is flagged with meta `addsynapse_assistant_agent => true` to be exposed as an agent tool.
 *
 * Write operations will never be abilities that apply changes directly: they will
 * create changesets that a human approves in the UI (apply/revert are REST-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks ability + category registration.
 */
final class AbilitiesRegistrar {

	public const CATEGORY = 'addsynapse-assistant';

	/** Namespace of our ability names (`addsynapse-assistant/<x>`). */
	public const ABILITY_PREFIX = 'addsynapse-assistant/';

	/**
	 * Names of the abilities that exist only with WooCommerce (docs/05 → «Χωρίς WooCommerce»).
	 */
	public const WOOCOMMERCE_ONLY = array(
		'addsynapse-assistant/search-products',
		'addsynapse-assistant/get-product',
		'addsynapse-assistant/product-report',
		'addsynapse-assistant/list-orders',
		'addsynapse-assistant/get-product-raw',
		'addsynapse-assistant/find-terms',
		'addsynapse-assistant/plan-price-update',
		'addsynapse-assistant/plan-stock-update',
		'addsynapse-assistant/plan-taxonomy-terms',
		'addsynapse-assistant/plan-brand-assign',
		'addsynapse-assistant/plan-product-update',
		'addsynapse-assistant/customer-report',
		'addsynapse-assistant/plan-seo-update',
	);

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Category.
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Store Assistant', 'addsynapse-store-assistant' ),
				'description' => __( 'Site and WooCommerce tools used by Store Assistant.', 'addsynapse-store-assistant' ),
			)
		);
	}

	/**
	 * Abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		// Read-only on every WordPress site (with or without WooCommerce): site map, menus, pages, media, SEO data.
		( new DescribeStoreAbility() )->register();
		( new DescribeMenusAbility() )->register();
		( new GetSeoAbility() )->register();
		( new DescribePageAbility() )->register();
		( new FindMediaAbility() )->register();
		// A change this plugin cannot make → a clear «part of Store Assistant Pro» answer with a «See Pro» link (changes nothing).
		( new ComingInProAbility() )->register();
		$has_woocommerce = Environment::has_woocommerce();
		if ( $has_woocommerce ) {
			// WooCommerce only: reports and look-ups.
			( new SearchProductsAbility() )->register();
			( new GetProductAbility() )->register();
			( new ProductReportAbility() )->register();
			( new ListOrdersAbility() )->register();
			( new GetProductRawAbility() )->register();
			( new FindTermsAbility() )->register();
			// Top customers: references only — names are shown only in the manager's browser.
			( new CustomerReportAbility() )->register();
			// Plans: record a DRAFT changeset of ONE product; applying needs the manager's Approve button.
			( new PlanPriceUpdateAbility() )->register();
			( new PlanStockUpdateAbility() )->register();
			( new PlanTaxonomyTermsAbility() )->register();
			( new PlanBrandAssignAbility() )->register();
			( new PlanProductUpdateAbility() )->register();
			( new PlanSeoUpdateAbility() )->register();
		}
		/**
		 * Extension point: other plugins register their own abilities here (Abilities API, inside
		 * `wp_abilities_api_init`). An ability becomes an assistant tool only with the meta
		 * `addsynapse_assistant_agent => true` and runs only if it is read-only or listed through the
		 * `addsynapse_assistant_plan_abilities` filter (draft changesets) — see Agent\ToolRegistry.
		 *
		 * @param bool $has_woocommerce WooCommerce is active.
		 */
		do_action( 'addsynapse_assistant_register_abilities', $has_woocommerce );
	}

	/**
	 * Shared permission callback.
	 */
	public static function can_manage(): bool {
		return current_user_can( Config::capability() );
	}
}
