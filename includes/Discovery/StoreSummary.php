<?php
/**
 * Compact store summary + structure fingerprint, part of the AI system prompt.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Discovery;

use Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapters;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use Addsynapse\StoreAssistant\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * A ~0.5–1.5k token snapshot of THIS store's structure (currency, tz, theme, relevant plugins,
 * product types, brand storage, global attributes, top-level categories, taxonomies, custom
 * fields, menu locations) so the model can answer structure questions without a `describe_store`
 * round trip. Sent with the manager's own AI key only (Byok\DirectClient), as a data block.
 *
 * The fingerprint = hash of STRUCTURE only (active plugins + versions, theme, product taxonomies,
 * attribute definitions, ACF product group keys, currency/timezone, menu locations) — never
 * volatile counts, so it changes only when the structure does. Recomputed on every turn (cheap);
 * the summary itself is cached and dropped on the StoreMap invalidation hooks.
 *
 * Everything in it is store DATA (untrusted text): it is rendered as a DATA block, used only as a
 * data block of the first user message, never in the system prompt.
 */
final class StoreSummary {

	// v2 (2026-09-25): `site` block + `store.site_type`; «site summary» without WooCommerce.
	public const VERSION   = 2;
	public const TRANSIENT = 'addsynapse_assistant_store_summary_v1';
	public const TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * Hard cap of the JSON (chars). We aim for ~1.5k tokens.
	 */
	public const MAX_CHARS = 6000;

	/**
	 * Plugin families the model should know about (slug/name regex).
	 */
	private const FAMILIES = array(
		'seo'           => '/seo-by-rank-math|rank math|wordpress-seo|yoast|all-in-one-seo|aioseo|seopress|the seo framework/i',
		'page_builder'  => '/elementor|js_composer|wpbakery|divi|beaver-builder|oxygen|bricks|brizy|thrive-visual/i',
		'custom_fields' => '/advanced-custom-fields|\bacf\b|meta-box|metabox|pods|carbon-fields|jetengine|toolset/i',
		'brands'        => '/brand/i',
		'search_filter' => '/search|filter|fibosearch|woof|relevanssi|searchwp/i',
		'multilingual'  => '/sitepress|wpml|polylang|translatepress|weglot|gtranslate/i',
	);

	/**
	 * Registers cache invalidation (same events as the store map + theme/currency/tz).
	 */
	public static function register_hooks(): void {
		$flush = array( self::class, 'flush' );
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'woocommerce_attribute_added', 'woocommerce_attribute_updated', 'woocommerce_attribute_deleted', 'acf/update_field_group', 'acf/delete_field_group', 'switch_theme', 'update_option_woocommerce_currency', 'update_option_timezone_string', 'update_option_gmt_offset', 'update_option_WPLANG' ) as $hook ) {
			add_action( $hook, $flush );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_for_term' ), 10, 3 );
		}
	}

	/**
	 * Drops the cached summary.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Flushes when a product taxonomy term changes (counts/top terms).
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function flush_for_term( $term_id, $tt_id, $taxonomy = '' ): void {
		unset( $term_id, $tt_id );
		if ( StoreMap::is_product_taxonomy( (string) $taxonomy ) ) {
			self::flush();
		}
	}

	/**
	 * The current {fingerprint, summary} (cached; rebuilt when the fingerprint changes).
	 *
	 * @param bool $refresh Force a rebuild.
	 * @return array{fingerprint:string,summary:array<string,mixed>}
	 */
	public static function current( bool $refresh = false ): array {
		$fingerprint = self::fingerprint();
		$cached      = $refresh ? false : get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && ( $cached['fingerprint'] ?? '' ) === $fingerprint && is_array( $cached['summary'] ?? null ) ) {
			return $cached;
		}
		// Structure changed (maybe without a hook, e.g. files replaced): rebuild the map too.
		$stale_map = $refresh || ( is_array( $cached ) && ( $cached['fingerprint'] ?? '' ) !== $fingerprint );
		$state     = array(
			'fingerprint' => $fingerprint,
			// Plain WordPress site: no store map (no products); the summary is built from the site.
			'summary'     => self::build( $fingerprint, Environment::has_woocommerce() ? StoreMap::get( $stale_map ) : array() ),
		);
		set_transient( self::TRANSIENT, $state, self::TTL );
		return $state;
	}

	/**
	 * Structure fingerprint (16 hex). No counts, no term lists, no dates.
	 */
	public static function fingerprint(): string {
		return substr( hash( 'sha256', (string) wp_json_encode( self::fingerprint_parts() ) ), 0, 16 );
	}

	/**
	 * What the fingerprint is made of (public for tests/debugging).
	 *
	 * @return array<string,mixed>
	 */
	public static function fingerprint_parts(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$plugins = array();
		foreach ( array_unique( array_map( 'strval', $active ) ) as $file ) {
			$plugins[] = $file . '@' . ( isset( $all[ $file ] ) ? (string) $all[ $file ]['Version'] : '?' );
		}
		sort( $plugins );

		$taxonomies = get_object_taxonomies( 'product' );
		sort( $taxonomies );

		$attributes = array();
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				$attributes[] = implode( '|', array( (int) $attribute->attribute_id, (string) $attribute->attribute_name, (string) $attribute->attribute_label, (string) $attribute->attribute_type ) );
			}
			sort( $attributes );
		}

		$acf = array();
		if ( function_exists( 'acf_get_field_groups' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				if ( StoreMap::acf_targets_products( (array) ( $group['location'] ?? array() ) ) ) {
					$acf[] = (string) ( $group['key'] ?? '' ) . '@' . (string) ( $group['modified'] ?? '' );
				}
			}
			sort( $acf );
		}

		$locations = array_keys( (array) get_registered_nav_menus() );
		sort( $locations );
		$theme = wp_get_theme();

		return array(
			'v'           => self::VERSION,
			// Shop (WooCommerce active) or plain site: switching WooCommerce on/off → new summary.
			'woocommerce' => Environment::has_woocommerce(),
			'front'       => (string) get_option( 'show_on_front' ) . ':' . (int) get_option( 'page_on_front' ),
			'plugins'     => $plugins,
			'theme'       => get_stylesheet() . '@' . (string) $theme->get( 'Version' ) . '/' . get_template() . ( wp_is_block_theme() ? '/block' : '/classic' ),
			'taxonomies'  => $taxonomies,
			'attributes'  => $attributes,
			'acf'         => $acf,
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'timezone'    => wp_timezone_string(),
			// The shop content language: the model writes shop texts in it (a change → new summary).
			'locale'      => (string) get_locale(),
			'menus'       => $locations,
		);
	}

	/**
	 * Builds the compact summary from the (cached) store map.
	 *
	 * @param string              $fingerprint Fingerprint.
	 * @param array<string,mixed> $map         StoreMap::get().
	 * @return array<string,mixed>
	 */
	public static function build( string $fingerprint, array $map ): array {
		$limits = array(
			'top_terms'  => 5,
			'categories' => 20,
			'attributes' => 25,
			'lists'      => 10,
		);
		// Shrink until it fits (large catalogues with many attributes/categories).
		for ( $pass = 0; $pass < 4; $pass++ ) {
			$summary = self::compose( $fingerprint, $map, $limits );
			if ( strlen( (string) wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) <= self::MAX_CHARS ) {
				return $summary;
			}
			$limits = array(
				'top_terms'  => max( 0, $limits['top_terms'] - 2 ),
				'categories' => max( 5, (int) floor( $limits['categories'] / 2 ) ),
				'attributes' => max( 8, (int) floor( $limits['attributes'] / 2 ) ),
				'lists'      => max( 3, (int) floor( $limits['lists'] / 2 ) ),
			);
		}
		$summary['truncated'] = true;
		unset( $summary['local_attributes'], $summary['custom_fields'], $summary['menus'] );
		return $summary;
	}

	/**
	 * One composition pass.
	 *
	 * @param string              $fingerprint Fingerprint.
	 * @param array<string,mixed> $map         Store map.
	 * @param array<string,int>   $limits      Caps.
	 * @return array<string,mixed>
	 */
	private static function compose( string $fingerprint, array $map, array $limits ): array {
		if ( ! Environment::has_woocommerce() ) {
			return self::compose_site( $fingerprint, $limits );
		}
		$theme    = wp_get_theme();
		$families = self::plugin_families( (array) ( $map['plugins'] ?? array() ), $limits['lists'] );
		$summary  = array(
			'v'             => self::VERSION,
			'fingerprint'   => $fingerprint,
			'generated'     => gmdate( 'Y-m-d' ),
			'store'         => array_filter(
				array(
					'currency'       => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
					'timezone'       => wp_timezone_string(),
					'woocommerce'    => (string) ( $map['woocommerce']['version'] ?? '' ),
					'weight_unit'    => (string) get_option( 'woocommerce_weight_unit', '' ),
					'dimension_unit' => (string) get_option( 'woocommerce_dimension_unit', '' ),
					// Language of the shop's content (WordPress site language): texts the AI writes
					// into the shop use it; a multilingual plugin → the AI asks (not supported yet).
					'content_locale' => (string) get_locale(),
					'multilingual'   => array_values( (array) ( $families['multilingual'] ?? array() ) ),
					'site_type'      => Environment::SITE_SHOP,
				)
			),
			'site'          => self::site( $families ),
			'theme'         => array(
				'name' => html_entity_decode( (string) $theme->get( 'Name' ), ENT_QUOTES, 'UTF-8' ),
				'type' => MenuAdapters::is_block_theme() ? 'block' : 'classic',
			),
			'plugins'       => $families,
			'product_types' => array_filter( array_map( 'intval', (array) ( $map['product_types'] ?? array() ) ) ),
			'brand'         => self::brand( (array) ( $map['brand'] ?? array() ) ),
			'attributes'    => array(),
		);

		foreach ( array_slice( (array) ( $map['attributes'] ?? array() ), 0, $limits['attributes'] ) as $attribute ) {
			$row = array(
				'taxonomy' => (string) $attribute['taxonomy'],
				'label'    => (string) $attribute['label'],
				'terms'    => (int) $attribute['terms_count'],
			);
			if ( $limits['top_terms'] > 0 && ! empty( $attribute['terms'] ) ) {
				$row['top'] = array_slice( array_column( (array) $attribute['terms'], 'name' ), 0, $limits['top_terms'] );
			}
			$summary['attributes'][] = $row;
		}
		if ( count( (array) ( $map['attributes'] ?? array() ) ) > $limits['attributes'] ) {
			$summary['attributes_more'] = count( (array) $map['attributes'] ) - $limits['attributes'];
		}

		$local = array_slice( array_column( (array) ( $map['local_attributes'] ?? array() ), 'name' ), 0, $limits['lists'] );
		if ( $local ) {
			$summary['local_attributes'] = $local;
		}

		$summary['categories'] = self::categories( $limits['categories'] );

		$brand_taxonomies = wp_list_pluck( (array) ( $map['brand']['sources'] ?? array() ), 'taxonomy' );
		$taxonomies       = array();
		foreach ( (array) ( $map['taxonomies'] ?? array() ) as $taxonomy ) {
			if ( 'product_cat' === $taxonomy['taxonomy'] || in_array( $taxonomy['taxonomy'], $brand_taxonomies, true ) ) {
				continue;
			}
			$taxonomies[] = array(
				'taxonomy' => (string) $taxonomy['taxonomy'],
				'label'    => (string) $taxonomy['label'],
				'terms'    => (int) $taxonomy['terms_count'],
			);
		}
		if ( $taxonomies ) {
			$summary['taxonomies'] = array_slice( $taxonomies, 0, $limits['lists'] );
		}

		$custom = self::custom_fields( $map, $limits['lists'] );
		if ( $custom ) {
			$summary['custom_fields'] = $custom;
		}

		$locations = array();
		foreach ( (array) get_registered_nav_menus() as $slug => $label ) {
			$locations[] = $slug . ' (' . html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ) . ')';
		}
		if ( $locations ) {
			$summary['menus'] = array( 'locations' => array_slice( $locations, 0, $limits['lists'] ) );
		}

		return $summary;
	}

	/**
	 * «Site summary» of a WordPress site without WooCommerce: no products/orders/brands/
	 * attributes/categories/custom fields — theme, builders, pages, menus, SEO plugin, languages.
	 *
	 * @param string            $fingerprint Fingerprint.
	 * @param array<string,int> $limits      Caps.
	 * @return array<string,mixed>
	 */
	private static function compose_site( string $fingerprint, array $limits ): array {
		$theme     = wp_get_theme();
		$families  = self::plugin_families( StoreMap::plugins(), $limits['lists'] );
		$summary   = array(
			'v'           => self::VERSION,
			'fingerprint' => $fingerprint,
			'generated'   => gmdate( 'Y-m-d' ),
			'store'       => array_filter(
				array(
					'site_type'      => Environment::SITE,
					'timezone'       => wp_timezone_string(),
					'content_locale' => (string) get_locale(),
					'multilingual'   => array_values( (array) ( $families['multilingual'] ?? array() ) ),
				)
			),
			'site'        => self::site( $families ),
			'theme'       => array(
				'name' => html_entity_decode( (string) $theme->get( 'Name' ), ENT_QUOTES, 'UTF-8' ),
				'type' => MenuAdapters::is_block_theme() ? 'block' : 'classic',
			),
			'plugins'     => $families,
		);
		$locations = array();
		foreach ( (array) get_registered_nav_menus() as $slug => $label ) {
			$locations[] = $slug . ' (' . html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ) . ')';
		}
		$names = array_slice( array_column( MenuAdapters::current()->menus(), 'name' ), 0, $limits['lists'] );
		if ( $locations || $names ) {
			$summary['menus'] = array_filter(
				array(
					'locations' => array_slice( $locations, 0, $limits['lists'] ),
					'names'     => array_map( 'strval', $names ),
				)
			);
		}
		return $summary;
	}

	/**
	 * The `site` block (both modes): type, pages/posts, front page, builders, SEO plugin, languages.
	 *
	 * @param array<string,mixed> $families Plugin families.
	 * @return array<string,mixed>
	 */
	private static function site( array $families ): array {
		$pages = SiteMap::counts( 'page' );
		$posts = SiteMap::counts( 'post' );
		return array(
			'type'          => Environment::site_type(),
			'woocommerce'   => Environment::has_woocommerce(),
			'pages'         => $pages['publish'],
			'posts'         => $posts['publish'],
			'front_page'    => SiteMap::front_page(),
			'page_builders' => array_values( (array) ( $families['page_builder'] ?? array() ) ),
			'seo_plugin'    => SeoAdapters::detect()['adapter']->key(),
			'languages'     => SiteMap::languages(),
		);
	}

	/**
	 * Relevant plugins grouped by family (names only).
	 *
	 * @param array<int,array<string,mixed>> $plugins Map plugins.
	 * @param int                            $max     Cap of "other".
	 * @return array<string,array<int,string>>
	 */
	private static function plugin_families( array $plugins, int $max ): array {
		$out   = array();
		$other = array();
		foreach ( $plugins as $plugin ) {
			$slug = (string) ( $plugin['plugin'] ?? '' );
			$name = (string) ( $plugin['name'] ?? '' );
			if ( in_array( $slug, array( 'woocommerce', 'addsynapse-store-assistant' ), true ) ) {
				continue;
			}
			$family = '';
			foreach ( self::FAMILIES as $key => $regex ) {
				if ( preg_match( $regex, $slug . ' ' . $name ) ) {
					$family = $key;
					break;
				}
			}
			if ( '' !== $family ) {
				$out[ $family ][] = $name;
			} elseif ( ! empty( $plugin['product_related'] ) ) {
				$other[] = $name;
			}
		}
		if ( $other ) {
			$out['other_product_related'] = array_slice( $other, 0, $max );
		}
		$out['acf_active'] = function_exists( 'acf_get_field_groups' );
		return $out;
	}

	/**
	 * Brand storage: primary + sources with term/product counts.
	 *
	 * @param array<string,mixed> $brand Map brand section.
	 * @return array<string,mixed>
	 */
	private static function brand( array $brand ): array {
		$sources = array();
		foreach ( (array) ( $brand['sources'] ?? array() ) as $source ) {
			$sources[] = array_filter(
				array(
					'taxonomy' => (string) $source['taxonomy'],
					'storage'  => (string) $source['storage'],
					'plugin'   => (string) ( $source['plugin'] ?? '' ),
					'terms'    => (int) ( $source['terms_count'] ?? 0 ),
					'products' => (int) ( $source['products'] ?? 0 ),
				),
				static function ( $v ) {
					return '' !== $v;
				}
			);
		}
		return array(
			'primary' => $brand['primary'] ?? null,
			'sources' => $sources,
		);
	}

	/**
	 * Top-level product categories by product count.
	 *
	 * @param int $max Cap.
	 * @return array<string,mixed>
	 */
	private static function categories( int $max ): array {
		$top   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => 0,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		$rows  = array();
		$count = 0;
		if ( is_array( $top ) ) {
			$count = count( $top );
			foreach ( array_slice( $top, 0, $max ) as $term ) {
				$rows[] = array(
					'id'    => (int) $term->term_id,
					'name'  => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
					'count' => (int) $term->count,
				);
			}
		}
		return array(
			'total'          => (int) wp_count_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			),
			'top_level'      => $rows,
			'top_level_more' => max( 0, $count - $max ),
		);
	}

	/**
	 * ACF product fields and the most used custom meta keys (names only, never values).
	 *
	 * @param array<string,mixed> $map Store map.
	 * @param int                 $max Cap.
	 * @return array<string,mixed>
	 */
	private static function custom_fields( array $map, int $max ): array {
		$out    = array();
		$fields = array();
		foreach ( (array) ( $map['acf']['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$fields[] = $field['name'] . ( isset( $field['label'] ) && '' !== $field['label'] && $field['label'] !== $field['name'] ? ' «' . $field['label'] . '»' : '' )
					. ' (' . $field['type'] . ')';
			}
		}
		if ( $fields ) {
			$out['acf'] = array_slice( $fields, 0, $max * 2 );
		}
		$meta = array();
		foreach ( (array) ( $map['meta_keys']['keys'] ?? array() ) as $key ) {
			// Hidden (_*) and SEO/builder bookkeeping keys are not "fields" a manager talks about.
			if ( 'custom' === ( $key['kind'] ?? '' ) && ( (int) $key['products'] + (int) $key['variations'] ) > 0 && ! preg_match( '/^_|^(rank_math|yoast|elementor|woodmart)_/i', (string) $key['key'] ) ) {
				$meta[] = (string) $key['key'];
			}
		}
		if ( $meta ) {
			// Discovered custom meta keys (names only; this plugin reads them, never writes them).
			$out['meta_keys'] = array_slice( $meta, 0, $max );
		}
		return $out;
	}
}
