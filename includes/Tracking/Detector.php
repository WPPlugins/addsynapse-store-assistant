<?php
/**
 * What else on the site already measures visitors (other plugins, the theme, the rendered home page), and which cookie
 * banner (consent tool) is active — so a new code is never added twice without a clear warning.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Detection never fails loudly: what could not be checked says «could not check», never «none found».
 */
final class Detector {

	/** Marker around our own output in the page (so the home-page check skips it). */
	public const MARK_START = '<!-- AddSynapse measurement codes -->';
	public const MARK_END   = '<!-- /AddSynapse measurement codes -->';

	/**
	 * Plugins that add measurement codes: plugin file → {name, providers}.
	 */
	private const PLUGINS = array(
		'google-site-kit/google-site-kit.php'            => array( 'Site Kit by Google', array( 'ga4', 'gtm', 'google_ads' ) ),
		'google-analytics-for-wordpress/googleanalytics.php' => array( 'MonsterInsights', array( 'ga4' ) ),
		'google-analytics-premium/googleanalytics-premium.php' => array( 'MonsterInsights Pro', array( 'ga4' ) ),
		'google-analytics-dashboard-for-wp/gadwp.php'    => array( 'ExactMetrics', array( 'ga4' ) ),
		'ga-google-analytics/ga-google-analytics.php'    => array( 'GA Google Analytics', array( 'ga4' ) ),
		'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' => array( 'Google Analytics for WooCommerce', array( 'ga4' ) ),
		'google-listings-and-ads/google-listings-and-ads.php' => array( 'Google for WooCommerce', array( 'google_ads' ) ),
		'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' => array( 'GTM4WP', array( 'gtm' ) ),
		'pixelyoursite/pixelyoursite.php'                => array( 'PixelYourSite', array( 'meta', 'ga4', 'tiktok' ) ),
		'pixelyoursite-pro/pixelyoursite-pro.php'        => array( 'PixelYourSite Pro', array( 'meta', 'ga4', 'google_ads', 'tiktok' ) ),
		'official-facebook-pixel/facebook-for-wordpress.php' => array( 'Meta Pixel for WordPress', array( 'meta' ) ),
		'facebook-for-woocommerce/facebook-for-woocommerce.php' => array( 'Facebook for WooCommerce', array( 'meta' ) ),
		'microsoft-clarity/clarity.php'                  => array( 'Microsoft Clarity', array( 'clarity' ) ),
		'tiktok-for-business/tiktok-for-woocommerce.php' => array( 'TikTok for WooCommerce', array( 'tiktok' ) ),
		'insert-headers-and-footers/ihaf.php'            => array( 'WPCode (Insert Headers and Footers)', array() ),
		'header-footer-code-manager/99robots-header-footer-code-manager.php' => array( 'Header Footer Code Manager', array() ),
		'head-footer-code/head-footer-code.php'          => array( 'Head & Footer Code', array() ),
	);

	/**
	 * Cookie banners (consent tools) that update Google's consent themselves once their Google Consent Mode option is on:
	 * plugin file → name. Only with one of these does a Google tag start with «everything denied».
	 */
	private const CMPS = array(
		'complianz-gdpr/complianz-gpdr.php'     => 'Complianz',
		'complianz-gdpr-premium/complianz-gpdr-premium.php' => 'Complianz Premium',
		'cookie-law-info/cookie-law-info.php'   => 'CookieYes',
		'cookiebot/cookiebot.php'               => 'Cookiebot',
		'borlabs-cookie/borlabs-cookie.php'     => 'Borlabs Cookie',
		'real-cookie-banner/index.php'          => 'Real Cookie Banner',
		'real-cookie-banner-pro/index.php'      => 'Real Cookie Banner Pro',
		'iubenda-cookie-law-solution/iubenda_cookie_solution.php' => 'iubenda',
		'cookie-notice/cookie-notice.php'       => 'Cookie Notice & Compliance',
		'gdpr-cookie-compliance/moove-gdpr.php' => 'GDPR Cookie Compliance',
		'cookie-script-com/cookie-script.php'   => 'Cookie-Script',
	);

	/** Patterns of codes in a page / file: provider → pattern (first group = the ID). */
	private const IN_PAGE = array(
		'ga4'        => '/\b(G-[A-Z0-9]{4,15})\b/',
		'gtm'        => '/\b(GTM-[A-Z0-9]{4,12})\b/',
		'google_ads' => '/\b(AW-[0-9]{6,15})\b/',
		'meta'       => '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"]?([0-9]{8,20})/',
		'clarity'    => '/clarity\.ms\/tag\/([a-z0-9]{6,20})|[\'"]clarity[\'"]\s*,\s*[\'"]script[\'"]\s*,\s*[\'"]([a-z0-9]{6,20})/',
		'tiktok'     => '/ttq\.load\(\s*[\'"]([A-Z0-9]{12,30})/',
	);

	/**
	 * Active cookie banner (consent tool), or null.
	 *
	 * @return array{name:string,consent_api:bool}|null
	 */
	public static function cmp(): ?array {
		foreach ( self::CMPS as $file => $name ) {
			if ( self::active( $file ) ) {
				return array(
					'name'        => $name,
					'consent_api' => self::consent_api(),
				);
			}
		}
		return null;
	}

	/**
	 * Whether the WP Consent API is active (banners report the visitor's choice through it; our pixels wait for it).
	 */
	public static function consent_api(): bool {
		return function_exists( 'wp_has_consent' ) || self::active( 'wp-consent-api/wp-consent-api.php' );
	}

	/**
	 * Everything found: {plugins[{name, providers}], theme[{file, providers}], page{checked, found[{provider, id}], reason?}}.
	 *
	 * @param bool $look_at_page Also fetch the home page once (fail-open, 6 s).
	 * @return array<string,mixed>
	 */
	public static function scan( bool $look_at_page = true ): array {
		$plugins = array();
		foreach ( self::PLUGINS as $file => $info ) {
			if ( self::active( $file ) ) {
				$plugins[] = array(
					'name'      => $info[0],
					'providers' => $info[1],
				);
			}
		}
		$theme = array();
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			foreach ( array( 'header.php', 'functions.php', 'footer.php' ) as $file ) {
				$path = trailingslashit( $dir ) . $file;
				if ( ! is_readable( $path ) || filesize( $path ) > 512000 ) {
					continue;
				}
				$found = self::find( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local theme file.
				if ( $found ) {
					$theme[] = array(
						'file'  => basename( $dir ) . '/' . $file,
						'found' => $found,
					);
				}
			}
		}
		return array(
			'plugins' => $plugins,
			'theme'   => $theme,
			'page'    => $look_at_page ? self::page() : array(
				'checked' => false,
				'reason'  => 'not checked',
			),
		);
	}

	/**
	 * The codes in the rendered home page, outside our own block.
	 *
	 * @return array<string,mixed>
	 */
	public static function page(): array {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'                                 => 6,
				'redirection'                             => 3,
				'sslverify'                               => false,
				// Our own site: local / staging certificates must not hide the answer.
												'headers' => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'checked' => false,
				'reason'  => 'Could not check the home page (' . ( is_wp_error( $response ) ? 'no answer' : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) ) . ').',
			);
		}
		$html = (string) wp_remote_retrieve_body( $response );
		$html = (string) preg_replace( '/' . preg_quote( self::MARK_START, '/' ) . '.*?' . preg_quote( self::MARK_END, '/' ) . '/s', '', $html );
		// Our own codes are skipped too (inline-only tags may print without the marker comments).
		$ours  = TrackingStore::codes();
		$found = array_values(
			array_filter(
				self::find( $html ),
				static function ( array $hit ) use ( $ours ): bool {
					return ( $ours[ $hit['provider'] ] ?? null ) !== $hit['id'];
				}
			)
		);
		return array(
			'checked' => true,
			'found'   => $found,
		);
	}

	/**
	 * Codes in a text: [{provider, id}].
	 *
	 * @param string $text Text.
	 * @return array<int,array<string,string>>
	 */
	public static function find( string $text ): array {
		$out = array();
		foreach ( self::IN_PAGE as $provider => $pattern ) {
			if ( ! preg_match_all( $pattern, $text, $m, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $m as $hit ) {
				$id = '';
				foreach ( array_slice( $hit, 1 ) as $group ) {
					if ( '' !== (string) $group ) {
						$id = (string) $group;
						break;
					}
				}
				$key         = $provider . ':' . $id;
				$out[ $key ] = array(
					'provider' => $provider,
					'id'       => $id,
				);
			}
		}
		return array_values( $out );
	}

	/**
	 * Warnings for adding / keeping a provider: same provider elsewhere, and GTM next to a direct Google tag.
	 *
	 * @param string               $provider Provider.
	 * @param string               $id       ID.
	 * @param array<string,mixed>  $scan     scan().
	 * @param array<string,string> $ours     Our codes after the change.
	 * @return array<int,string>
	 */
	public static function warnings( string $provider, string $id, array $scan, array $ours ): array {
		$out  = array();
		$name = Providers::name( $provider );
		foreach ( (array) $scan['plugins'] as $plugin ) {
			if ( in_array( $provider, (array) $plugin['providers'], true ) ) {
				$out[] = sprintf( 'The plugin %1$s is active and can add %2$s too: if it does, visits are counted twice. Use one of the two.', $plugin['name'], $name );
			} elseif ( ! $plugin['providers'] ) {
				$out[] = sprintf( 'The plugin %s adds code to every page: check it does not already hold this %s code.', $plugin['name'], $name );
			}
		}
		foreach ( (array) $scan['theme'] as $file ) {
			foreach ( (array) $file['found'] as $hit ) {
				if ( $hit['provider'] === $provider ) {
					$out[] = sprintf( 'The theme file %1$s already holds a %2$s code (%3$s): visits would be counted twice.', $file['file'], $name, $hit['id'] );
				}
			}
		}
		$page = (array) $scan['page'];
		if ( empty( $page['checked'] ) ) {
			$out[] = (string) ( $page['reason'] ?? 'Could not check the home page.' ) . ' Other codes already on the site could not be ruled out.';
		} else {
			foreach ( (array) $page['found'] as $hit ) {
				if ( $hit['provider'] === $provider ) {
					$out[] = $hit['id'] === $id
						? sprintf( 'The home page already carries this exact %1$s code (%2$s) from another plugin or the theme: adding it here would count every visit twice.', $name, $id )
						: sprintf( 'The home page already carries another %1$s code (%2$s) from another plugin or the theme.', $name, $hit['id'] );
				}
			}
		}
		if ( 'gtm' === $provider && ( isset( $ours['ga4'] ) || isset( $ours['google_ads'] ) ) ) {
			$out[] = 'Google Tag Manager is added next to a direct Google Analytics / Google Ads tag: if the container also holds those tags, visits are counted twice.';
		}
		if ( in_array( $provider, array( 'ga4', 'google_ads' ), true ) && isset( $ours['gtm'] ) ) {
			$out[] = 'Google Tag Manager is already here: if its container also sends this tag, visits are counted twice.';
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The consent note of the draft.
	 *
	 * @param string $provider Provider.
	 */
	public static function consent_note( string $provider ): string {
		$cmp    = self::cmp();
		$google = 'google' === Providers::ALL[ $provider ]['group'];
		if ( null === $cmp ) {
			return 'No cookie banner (consent tool) was found on this site. In the EU (GDPR / ePrivacy) measurement and advertising codes need the visitor\'s consent first: add a cookie banner plugin (for example Complianz or CookieYes) — this code will then follow it.';
		}
		if ( $google ) {
			return sprintf( 'Cookie banner found (%s): the Google tag starts with Google Consent Mode v2 «denied» and measures fully only after the visitor accepts. Turn on Google Consent Mode in %s\'s settings, or nothing will ever be measured.', $cmp['name'], $cmp['name'] );
		}
		if ( $cmp['consent_api'] ) {
			return sprintf( 'Cookie banner found (%s, with the WP Consent API): this code loads only after the visitor accepts %s cookies.', $cmp['name'], Providers::ALL[ $provider ]['consent'] );
		}
		return sprintf( 'Cookie banner found (%1$s), but without the WP Consent API this code cannot wait for the visitor\'s choice and would load before consent. Install the free «WP Consent API» plugin (%1$s supports it) so it waits.', $cmp['name'] );
	}

	/**
	 * Whether a plugin is active.
	 *
	 * @param string $file Plugin file.
	 */
	private static function active( string $file ): bool {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $file, $active, true ) ) {
			return true;
		}
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			return isset( $network[ $file ] );
		}
		return false;
	}
}
