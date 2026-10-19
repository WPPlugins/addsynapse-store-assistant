<?php
/**
 * Picks the brand adapter for this store from the store map (describe-store).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Brand;

use Addsynapse\StoreAssistant\Discovery\StoreMap;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * No guessing: the brand storage must be one the store map detected AND one we have an
 * adapter for (WooCommerce Brands, Perfect Brands, YITH Brands, or a global brand
 * attribute). Anything else (BeRocket, unknown taxonomies, local attributes, meta) →
 * "unsupported brand storage".
 */
final class BrandResolver {

	/**
	 * Taxonomy adapters by taxonomy.
	 *
	 * @return array<string,BrandAdapter>
	 */
	public static function taxonomy_adapters(): array {
		$out = array();
		foreach ( array( new WooBrandsAdapter(), new PerfectBrandsAdapter(), new YithBrandsAdapter() ) as $adapter ) {
			$out[ $adapter->taxonomy() ] = $adapter;
		}
		return $out;
	}

	/**
	 * Adapter for one detected storage (null = unsupported).
	 *
	 * @param array<string,mixed> $source Store-map brand source {storage, taxonomy, plugin, terms_count}.
	 */
	public static function for_source( array $source ): ?BrandAdapter {
		$taxonomy = (string) ( $source['taxonomy'] ?? '' );
		if ( 'attribute' === ( $source['storage'] ?? '' ) ) {
			$adapter = new AttributeBrandAdapter( $taxonomy );
			return $adapter->available() ? $adapter : null;
		}
		$adapters = self::taxonomy_adapters();
		if ( isset( $adapters[ $taxonomy ] ) && $adapters[ $taxonomy ]->available() ) {
			return $adapters[ $taxonomy ];
		}
		return null;
	}

	/**
	 * Resolves the brand adapter.
	 *
	 * @param string $preferred Optional taxonomy the caller wants (must be a detected source).
	 * @param bool   $refresh   Rebuild the store map first.
	 * @return array{adapter:BrandAdapter,warnings:array<int,string>}|WP_Error
	 */
	public static function resolve( string $preferred = '', bool $refresh = false ) {
		$map     = StoreMap::get( $refresh );
		$brand   = (array) ( $map['brand'] ?? array() );
		$sources = array_values( (array) ( $brand['sources'] ?? array() ) );
		if ( ! $sources ) {
			return new WP_Error( 'addsynapse_assistant_brand_unsupported', 'Unsupported brand storage: this store has no brand taxonomy or global brand attribute. A brand kept in a local attribute or custom meta cannot be changed from here.' );
		}
		$source = null;
		if ( '' !== $preferred ) {
			foreach ( $sources as $candidate ) {
				if ( ( $candidate['taxonomy'] ?? '' ) === $preferred ) {
					$source = $candidate;
				}
			}
			if ( ! $source ) {
				return new WP_Error( 'addsynapse_assistant_brand_unsupported', sprintf( 'Unsupported brand storage "%s". Detected: %s.', mb_substr( $preferred, 0, 60 ), implode( ', ', wp_list_pluck( $sources, 'taxonomy' ) ) ) );
			}
		} else {
			foreach ( $sources as $candidate ) {
				if ( ( $candidate['taxonomy'] ?? '' ) === ( $brand['primary'] ?? '' ) ) {
					$source = $candidate;
					break;
				}
			}
			$source = $source ? $source : $sources[0];
		}
		$adapter = self::for_source( $source );
		if ( ! $adapter ) {
			return new WP_Error( 'addsynapse_assistant_brand_unsupported', sprintf( 'Unsupported brand storage: brands are kept in "%1$s" (%2$s), which has no adapter. Nothing can be planned for it.', (string) $source['taxonomy'], (string) ( $source['plugin'] ?? 'unknown' ) ) );
		}
		$warnings = array();
		$others   = array();
		foreach ( $sources as $candidate ) {
			if ( ( $candidate['taxonomy'] ?? '' ) !== $adapter->taxonomy() ) {
				$others[] = sprintf( '%s (%d terms)', $candidate['taxonomy'], (int) ( $candidate['terms_count'] ?? 0 ) );
			}
		}
		if ( $others && '' === $preferred ) {
			$warnings[] = sprintf( 'The store has more than one brand storage; used %1$s (%2$d terms). Others: %3$s.', $adapter->taxonomy(), (int) ( $source['terms_count'] ?? 0 ), implode( ', ', $others ) );
		}
		return array(
			'adapter'  => $adapter,
			'warnings' => $warnings,
		);
	}
}
