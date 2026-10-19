<?php
/**
 * The "store map": how THIS shop stores its product data (plugins, taxonomies,
 * attributes, brand storage, meta keys, ACF fields). Read-only, cached.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Discovery;

use Addsynapse\StoreAssistant\I18n\Locales;

defined( 'ABSPATH' ) || exit;

/**
 * Every WooCommerce shop is different; the AI reads this map before it proposes anything
 * about a field, attribute or brand it has not seen. Built from WP/WC APIs plus ONE
 * bounded, aggregated, read-only SELECT on postmeta (product post types only). Cached in
 * a transient that is dropped when plugins or product taxonomies/attributes change.
 */
final class StoreMap {

	public const TRANSIENT = 'addsynapse_assistant_store_map_v1';
	public const TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * Products sampled for meta key statistics (newest first).
	 */
	public const META_SAMPLE = 2000;

	/**
	 * Section caps (keep the tool result well under ToolRegistry::MAX_RESULT_CHARS).
	 */
	public const MAX_META_KEYS       = 120;
	public const MAX_TERMS_PER_TAX   = 25;
	public const MAX_PLUGINS         = 60;
	public const MAX_LOCAL_ATTRS     = 30;
	public const MAX_ACF_GROUPS      = 15;
	public const MAX_ACF_FIELDS      = 40;
	public const MAX_CHOICES         = 20;
	public const LOCAL_ATTRS_SAMPLE  = 500;
	public const SAMPLE_VALUE_CHARS  = 60;
	public const PRODUCT_POST_TYPES  = array( 'product', 'product_variation' );
	public const INTERNAL_TAXONOMIES = array( 'product_type', 'product_visibility' );

	/**
	 * Known brand plugins → taxonomy they register.
	 */
	public const BRAND_TAXONOMIES = array(
		'product_brand'      => 'WooCommerce Brands',
		'pwb-brand'          => 'Perfect Brands for WooCommerce',
		'yith_product_brand' => 'YITH WooCommerce Brands',
		'berocket_brand'     => 'BeRocket Brands for WooCommerce',
	);

	/**
	 * Registers the cache invalidation hooks.
	 */
	public static function register_hooks(): void {
		$flush = array( self::class, 'flush' );
		add_action( 'activated_plugin', $flush );
		add_action( 'deactivated_plugin', $flush );
		add_action( 'upgrader_process_complete', $flush );
		add_action( 'woocommerce_attribute_added', $flush );
		add_action( 'woocommerce_attribute_updated', $flush );
		add_action( 'woocommerce_attribute_deleted', $flush );
		add_action( 'created_term', array( self::class, 'flush_for_term' ), 10, 3 );
		add_action( 'edited_term', array( self::class, 'flush_for_term' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'flush_for_term' ), 10, 3 );
		add_action( 'acf/update_field_group', $flush );
		add_action( 'acf/delete_field_group', $flush );
	}

	/**
	 * Drops the cached map.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Flushes when a term of a product taxonomy changes.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function flush_for_term( $term_id, $tt_id, $taxonomy = '' ): void {
		unset( $term_id, $tt_id );
		if ( self::is_product_taxonomy( (string) $taxonomy ) ) {
			self::flush();
		}
	}

	/**
	 * Whether a taxonomy belongs to products (incl. pa_* attributes).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function is_product_taxonomy( string $taxonomy ): bool {
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0 === strpos( $taxonomy, 'pa_' );
		}
		$object = get_taxonomy( $taxonomy );
		return $object && array_intersect( (array) $object->object_type, self::PRODUCT_POST_TYPES );
	}

	/**
	 * The map (cached unless $refresh).
	 *
	 * @param bool $refresh Rebuild.
	 * @return array<string,mixed>
	 */
	public static function get( bool $refresh = false ): array {
		$cached = $refresh ? false : get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && ( $cached['_taxonomies_fingerprint'] ?? '' ) === self::taxonomies_fingerprint() ) {
			$cached['cached'] = true;
			unset( $cached['_taxonomies_fingerprint'] );
			return $cached;
		}
		$map                            = self::build();
		$map['_taxonomies_fingerprint'] = self::taxonomies_fingerprint();
		set_transient( self::TRANSIENT, $map, self::TTL );
		unset( $map['_taxonomies_fingerprint'] );
		$map['cached'] = false;
		return $map;
	}

	/**
	 * Fingerprint of registered product taxonomies: a taxonomy registered without a
	 * plugin (de)activation hook (e.g. theme code, WP-CLI) still invalidates the cache.
	 */
	private static function taxonomies_fingerprint(): string {
		$names = get_object_taxonomies( 'product' );
		sort( $names );
		return md5( implode( ',', $names ) );
	}

	/**
	 * Builds the map.
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		$attributes = self::global_attributes();
		return array(
			'generated_at'     => gmdate( 'c' ),
			'woocommerce'      => array(
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			),
			'product_types'    => self::product_types(),
			'plugins'          => self::plugins(),
			'taxonomies'       => self::taxonomies(),
			'attributes'       => $attributes,
			'local_attributes' => self::local_attributes(),
			'brand'            => self::brand( $attributes ),
			'meta_keys'        => self::meta_keys(),
			'acf'              => self::acf(),
			'notes'            => array(
				'Local (custom) attributes are stored per product and have no global terms.',
				'Meta key statistics come from a sample of the newest products; wc_internal keys are edited through WooCommerce fields, sensitive keys are never shown or editable.',
			),
			'_data'            => \Addsynapse\StoreAssistant\Abilities\Shared::DATA_NOTICE,
		);
	}

	/**
	 * Counts of products per type.
	 *
	 * @return array<string,int>
	 */
	private static function product_types(): array {
		$out   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_type',
				'hide_empty' => false,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ $term->slug ] = (int) $term->count;
			}
		}
		return $out;
	}

	/**
	 * Active plugins (name, version) flagged when they look product-related.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( array_unique( $active ) as $file ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$name  = (string) $all[ $file ]['Name'];
			$out[] = array(
				'plugin'          => dirname( $file ) === '.' ? $file : dirname( $file ),
				'name'            => $name,
				'version'         => (string) $all[ $file ]['Version'],
				'product_related' => 1 === preg_match( '/woo|product|brand|attribute|variation|swatch|filter|custom field|acf|meta|catalog|shop|seo|elementor|translat|wpml|polylang/i', $file . ' ' . $name ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( ! $a['product_related'], $a['name'] ) <=> array( ! $b['product_related'], $b['name'] );
			}
		);
		return array_slice( $out, 0, self::MAX_PLUGINS );
	}

	/**
	 * Product taxonomies (without pa_* attributes and WC internals) with sample terms.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function taxonomies(): array {
		$out = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $name => $taxonomy ) {
			if ( 0 === strpos( $name, 'pa_' ) || in_array( $name, self::INTERNAL_TAXONOMIES, true ) ) {
				continue;
			}
			$out[] = array(
				'taxonomy'     => (string) $name,
				'label'        => html_entity_decode( (string) $taxonomy->label, ENT_QUOTES, 'UTF-8' ),
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'brand_plugin' => self::BRAND_TAXONOMIES[ $name ] ?? null,
				'terms_count'  => (int) wp_count_terms(
					array(
						'taxonomy'   => $name,
						'hide_empty' => false,
					)
				),
				'terms'        => self::terms( (string) $name, self::MAX_TERMS_PER_TAX ),
			);
		}
		return $out;
	}

	/**
	 * Most used terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $max      Max.
	 * @return array<int,array{id:int,name:string,slug:string,count:int}>
	 */
	public static function terms( string $taxonomy, int $max ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => $max,
			)
		);
		$out   = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[] = array(
					'id'    => (int) $term->term_id,
					'name'  => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
					'slug'  => rawurldecode( (string) $term->slug ),
					'count' => (int) $term->count,
				);
			}
		}
		return $out;
	}

	/**
	 * Global (pa_*) attributes with their terms.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function global_attributes(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$exists   = taxonomy_exists( $taxonomy );
			$out[]    = array(
				'id'          => (int) $attribute->attribute_id,
				'taxonomy'    => $taxonomy,
				'label'       => html_entity_decode( (string) $attribute->attribute_label, ENT_QUOTES, 'UTF-8' ),
				'type'        => (string) $attribute->attribute_type,
				'order_by'    => (string) $attribute->attribute_orderby,
				'terms_count' => $exists ? (int) wp_count_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				) : 0,
				'terms'       => $exists ? self::terms( $taxonomy, self::MAX_TERMS_PER_TAX ) : array(),
			);
		}
		return $out;
	}

	/**
	 * Local (per-product) attribute names found in a sample of products.
	 *
	 * @return array<int,array{name:string,products:int}>
	 */
	private static function local_attributes(): array {
		global $wpdb;
		// Read-only, bounded: newest N product attribute blobs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only discovery, cached in a transient.
		$rows   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_product_attributes' AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')
				ORDER BY pm.post_id DESC LIMIT %d",
				self::LOCAL_ATTRS_SAMPLE
			)
		);
		$counts = array();
		foreach ( (array) $rows as $raw ) {
			$attributes = maybe_unserialize( $raw );
			if ( ! is_array( $attributes ) ) {
				continue;
			}
			foreach ( $attributes as $attribute ) {
				if ( is_array( $attribute ) && empty( $attribute['is_taxonomy'] ) && ! empty( $attribute['name'] ) ) {
					$name            = html_entity_decode( (string) $attribute['name'], ENT_QUOTES, 'UTF-8' );
					$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $counts );
		$out = array();
		foreach ( array_slice( $counts, 0, self::MAX_LOCAL_ATTRS, true ) as $name => $n ) {
			$out[] = array(
				'name'     => (string) $name,
				'products' => (int) $n,
			);
		}
		return $out;
	}

	/**
	 * How this shop stores the brand (taxonomy plugin, pa_* attribute, local attribute, meta).
	 *
	 * @param array<int,array<string,mixed>> $attributes Global attributes.
	 * @return array<string,mixed>
	 */
	private static function brand( array $attributes ): array {
		$sources = array();
		foreach ( self::BRAND_TAXONOMIES as $taxonomy => $plugin ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$sources[] = array(
					'storage'     => 'taxonomy',
					'taxonomy'    => $taxonomy,
					'plugin'      => $plugin,
					'terms_count' => (int) wp_count_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
						)
					),
					'products'    => self::products_with( $taxonomy ),
				);
			}
		}
		foreach ( $attributes as $attribute ) {
			if ( self::looks_like_brand( $attribute['taxonomy'] . ' ' . $attribute['label'] ) ) {
				$sources[] = array(
					'storage'     => 'attribute',
					'taxonomy'    => $attribute['taxonomy'],
					'plugin'      => null,
					'terms_count' => $attribute['terms_count'],
					'products'    => self::products_with( (string) $attribute['taxonomy'] ),
				);
			}
		}
		foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
			if ( ! isset( self::BRAND_TAXONOMIES[ $taxonomy ] ) && 0 !== strpos( $taxonomy, 'pa_' ) && self::looks_like_brand( $taxonomy ) ) {
				$sources[] = array(
					'storage'     => 'taxonomy',
					'taxonomy'    => $taxonomy,
					'plugin'      => 'unknown',
					'terms_count' => (int) wp_count_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
						)
					),
					'products'    => self::products_with( $taxonomy ),
				);
			}
		}
		// The storage used by the most products is primary (a shop may have both WC Brands and an
		// old pa_brand attribute); ties → the one with more terms (an empty one never wins).
		usort(
			$sources,
			static function ( $a, $b ) {
				return array( $b['products'], $b['terms_count'] ) <=> array( $a['products'], $a['terms_count'] );
			}
		);
		$primary = $sources[0] ?? null;
		return array(
			'primary' => $primary ? $primary['taxonomy'] : null,
			'sources' => $sources,
			'note'    => $primary
				? 'Brands are terms of ' . $primary['taxonomy'] . ' (the storage used by the most products). Use find-terms on it to match a brand name.'
				: 'No brand taxonomy or brand attribute was found. A brand may live in a local attribute or custom meta; check local_attributes and meta_keys.',
		);
	}

	/**
	 * Products (and variations) that have at least one term of a taxonomy. Read-only COUNT on
	 * the indexed term tables; volatile (never part of the store fingerprint).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function products_with( string $taxonomy ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only discovery, cached in a transient.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft')",
				$taxonomy
			)
		);
	}

	/**
	 * Whether a taxonomy/attribute name looks like "brand".
	 *
	 * @param string $text Name + label.
	 */
	public static function looks_like_brand( string $text ): bool {
		$key = TermMatcher::normalize( rawurldecode( $text ) );
		foreach ( Locales::words( 'brand_fragments' ) as $fragment ) {
			if ( '' !== $fragment && false !== mb_strpos( $key, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Aggregated meta key statistics over a sample of the newest products/variations.
	 *
	 * Read-only SELECT on postmeta limited to product post types (HPOS is irrelevant:
	 * products always live in posts/postmeta).
	 *
	 * @return array<string,mixed>
	 */
	private static function meta_keys(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only discovery, cached in a transient.
		$sampled = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID DESC LIMIT %d) s",
				self::META_SAMPLE
			)
		);
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_key AS meta_key, p.post_type AS post_type, COUNT(DISTINCT pm.post_id) AS posts,
					COUNT(DISTINCT CASE WHEN pm.meta_value <> '' THEN pm.post_id END) AS filled, LEFT(MAX(pm.meta_value), 200) AS sample
				FROM {$wpdb->postmeta} pm
				INNER JOIN (SELECT ID, post_type FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID DESC LIMIT %d) p ON p.ID = pm.post_id
				GROUP BY pm.meta_key, p.post_type
				ORDER BY posts DESC
				LIMIT 600",
				self::META_SAMPLE
			),
			ARRAY_A
		);
		// phpcs:enable
		$keys   = array();
		$hidden = 0;
		foreach ( (array) $rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( MetaPolicy::is_noise( $key ) ) {
				++$hidden;
				continue;
			}
			$display = 0 === strpos( $key, 'attribute_' ) ? 'attribute_*' : $key;
			if ( ! isset( $keys[ $display ] ) ) {
				$kind             = MetaPolicy::kind( $key );
				$sample           = (string) $row['sample'];
				$keys[ $display ] = array(
					'key'        => $display,
					'kind'       => 'attribute_*' === $display ? 'variation_attributes' : $kind,
					'products'   => 0,
					'variations' => 0,
					'filled'     => 0,
					'type'       => MetaPolicy::type_of( $sample ),
					'sample'     => self::sample( $key, $sample, $kind ),
				);
			}
			$field = 'product' === $row['post_type'] ? 'products' : 'variations';
			if ( 'attribute_*' === $display ) {
				// Several attribute_{name} keys per variation: report the most common one's reach.
				$keys[ $display ][ $field ]  = max( $keys[ $display ][ $field ], (int) $row['posts'] );
				$keys[ $display ]['filled']  = max( $keys[ $display ]['filled'], (int) $row['filled'] );
				$keys[ $display ]['names'][] = rawurldecode( substr( $key, 10 ) );
				continue;
			}
			$keys[ $display ][ $field ] += (int) $row['posts'];
			$keys[ $display ]['filled'] += (int) $row['filled'];
		}//end foreach
		foreach ( $keys as &$entry ) {
			if ( isset( $entry['names'] ) ) {
				$entry['names'] = array_slice( array_values( array_unique( $entry['names'] ) ), 0, 20 );
			}
			if ( 'acf_reference' === $entry['type'] ) {
				$entry['kind'] = 'acf_reference';
			}
			$entry['share'] = $sampled ? round( ( $entry['products'] + $entry['variations'] ) / $sampled, 3 ) : 0;
		}
		unset( $entry );
		$keys = array_values( $keys );
		usort(
			$keys,
			static function ( $a, $b ) {
				$rank = array(
					'custom'               => 0,
					'variation_attributes' => 1,
					'wc_internal'          => 2,
					'sensitive'            => 3,
					'acf_reference'        => 4,
				);
				return array( $rank[ $a['kind'] ] ?? 5, - ( $a['products'] + $a['variations'] ) ) <=> array( $rank[ $b['kind'] ] ?? 5, - ( $b['products'] + $b['variations'] ) );
			}
		);
		return array(
			'sampled_posts' => $sampled,
			'sample_limit'  => self::META_SAMPLE,
			'hidden_noise'  => $hidden,
			'total_keys'    => count( $keys ),
			'truncated'     => count( $keys ) > self::MAX_META_KEYS,
			'keys'          => array_slice( $keys, 0, self::MAX_META_KEYS ),
		);
	}

	/**
	 * Short, safe sample value of a meta key.
	 *
	 * @param string $key    Meta key.
	 * @param string $sample Raw value.
	 * @param string $kind   Key kind.
	 */
	private static function sample( string $key, string $sample, string $kind ): string {
		unset( $key );
		if ( 'sensitive' === $kind ) {
			return MetaPolicy::REDACTED;
		}
		if ( is_serialized( $sample ) ) {
			return '(serialized)';
		}
		$sample = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $sample ) ) );
		return mb_strlen( $sample ) > self::SAMPLE_VALUE_CHARS ? mb_substr( $sample, 0, self::SAMPLE_VALUE_CHARS ) . '…' : $sample;
	}

	/**
	 * ACF field groups that target products (name, label, type, choices).
	 *
	 * @return array<string,mixed>
	 */
	private static function acf(): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array( 'active' => false );
		}
		$groups = array();
		foreach ( (array) acf_get_field_groups() as $group ) {
			if ( ! self::acf_targets_products( (array) ( $group['location'] ?? array() ) ) ) {
				continue;
			}
			$fields = array();
			foreach ( array_slice( (array) acf_get_fields( $group ), 0, self::MAX_ACF_FIELDS ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				// name, key, label, type (read-only: fields are described, never written by this plugin).
				$row = array(
					'name'  => (string) ( $field['name'] ?? '' ),
					'key'   => (string) ( $field['key'] ?? '' ),
					'label' => (string) ( $field['label'] ?? '' ),
					'type'  => (string) ( $field['type'] ?? '' ),
				);
				if ( ! empty( $field['taxonomy'] ) ) {
					$row['taxonomy'] = (string) $field['taxonomy'];
				}
				$fields[] = $row;
			}
			$groups[] = array(
				'key'    => (string) ( $group['key'] ?? '' ),
				'title'  => (string) ( $group['title'] ?? '' ),
				'fields' => $fields,
			);
			if ( count( $groups ) >= self::MAX_ACF_GROUPS ) {
				break;
			}
		}//end foreach
		return array(
			'active' => true,
			'groups' => $groups,
		);
	}

	/**
	 * Whether an ACF location rule set includes products or variations.
	 *
	 * @param array<int,mixed> $location Location rule groups.
	 */
	public static function acf_targets_products( array $location ): bool {
		foreach ( $location as $and ) {
			foreach ( (array) $and as $rule ) {
				$param = (string) ( $rule['param'] ?? '' );
				$value = (string) ( $rule['value'] ?? '' );
				if ( ( 'post_type' === $param && in_array( $value, self::PRODUCT_POST_TYPES, true ) ) || 0 === strpos( $param, 'product' ) || ( 'post_taxonomy' === $param && 0 === strpos( $value, 'product_' ) ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
