<?php
/**
 * Picks the SEO adapter of this store (the active SEO plugin).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Detection uses the same source as the store map / summary (active plugins, plus the
 * plugins' own constants/classes). Rank Math and Yoast are supported; another active SEO
 * plugin → NoneAdapter naming it (SEO fields refused, descriptions still possible). When
 * both Rank Math and Yoast are active, Rank Math is used and `others` says so.
 * Filter `addsynapse_assistant_seo_adapter` (key or adapter) overrides it (tests, special setups).
 */
final class SeoAdapters {

	private const UNSUPPORTED = array(
		'all-in-one-seo-pack/'     => 'All in One SEO',
		'all-in-one-seo-pack-pro/' => 'All in One SEO',
		'wp-seopress/'             => 'SEOPress',
		'wp-seopress-pro/'         => 'SEOPress',
		'autodescription/'         => 'The SEO Framework',
		'slim-seo/'                => 'Slim SEO',
		'squirrly-seo/'            => 'Squirrly SEO',
	);

	/**
	 * Adapter by key (null = unknown key).
	 *
	 * @param string $key rank_math|yoast|none.
	 */
	public static function for_key( string $key ): ?SeoAdapterInterface {
		switch ( $key ) {
			case 'rank_math':
				return new RankMathAdapter();
			case 'yoast':
				return new YoastAdapter();
			case 'none':
				return new NoneAdapter();
		}
		return null;
	}

	/**
	 * The adapter to use now.
	 */
	public static function current(): SeoAdapterInterface {
		return self::detect()['adapter'];
	}

	/**
	 * Detection result.
	 *
	 * @return array{adapter:SeoAdapterInterface,others:array<int,string>}
	 */
	public static function detect(): array {
		/**
		 * Overrides the SEO adapter: an adapter object or a key (rank_math|yoast|none).
		 *
		 * @param SeoAdapterInterface|string|null $adapter Adapter.
		 */
		$forced = apply_filters( 'addsynapse_assistant_seo_adapter', null );
		if ( $forced instanceof SeoAdapterInterface ) {
			return array(
				'adapter' => $forced,
				'others'  => array(),
			);
		}
		if ( is_string( $forced ) && '' !== $forced ) {
			return array(
				'adapter' => self::for_key( $forced ) ?? new NoneAdapter(),
				'others'  => array(),
			);
		}
		$active = self::active_plugins();
		$rank   = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || self::has( $active, 'seo-by-rank-math/' );
		$yoast  = defined( 'WPSEO_VERSION' ) || self::has( $active, 'wordpress-seo/' ) || self::has( $active, 'wordpress-seo-premium/' );
		$others = array();
		foreach ( self::UNSUPPORTED as $prefix => $name ) {
			if ( self::has( $active, $prefix ) ) {
				$others[] = $name;
			}
		}
		if ( $rank ) {
			$adapter = new RankMathAdapter();
			if ( $yoast ) {
				$others[] = 'Yoast SEO';
			}
		} elseif ( $yoast ) {
			$adapter = new YoastAdapter();
		} else {
			$adapter = new NoneAdapter( $others ? $others[0] : '' );
			$others  = array_slice( $others, 1 );
		}
		return array(
			'adapter' => $adapter,
			'others'  => array_values( array_unique( $others ) ),
		);
	}

	/**
	 * Active plugin files (site + network).
	 *
	 * @return array<int,string>
	 */
	private static function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_map( 'strval', array_values( $active ) );
	}

	/**
	 * Whether a plugin folder is active.
	 *
	 * @param array<int,string> $active Active files.
	 * @param string            $prefix Folder prefix ("slug/").
	 */
	private static function has( array $active, string $prefix ): bool {
		foreach ( $active as $file ) {
			if ( 0 === strpos( $file, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
