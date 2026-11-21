<?php
/**
 * Prints the approved measurement codes and AI scripts on the public pages, through WordPress' script API
 * (wp_enqueue_script / wp_add_inline_script: CSP nonce filters and caching plugins see them like any other script).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Same output for every visitor of a page (no per-visitor state, no cookies of ours): page caches stay valid.
 */
final class Frontend {

	public const GTAG    = 'addsynapse-assistant-gtag';
	public const GTM     = 'addsynapse-assistant-gtm';
	public const PREFIX  = 'addsynapse-assistant-';
	public const SCRIPTS = 'addsynapse-assistant-js-';

	/**
	 * Handles whose tags are wrapped in our marker comments.
	 *
	 * @var array<string,bool>
	 */
	private static $handles = array();

	/**
	 * Hooks (front end only: wp_enqueue_scripts never runs in wp-admin, on wp-login.php or in the REST API).
	 */
	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 1 );
		add_action( 'wp_body_open', array( self::class, 'noscript' ), 1 );
		add_filter( 'script_loader_tag', array( self::class, 'mark' ), 10, 2 );
	}

	/**
	 * Enqueues the codes and the scripts of this page.
	 */
	public static function enqueue(): void {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return;
		}
		self::enqueue_codes( TrackingStore::codes() );
		if ( TrackingStore::js_allowed() && ! self::protected_page() ) {
			foreach ( TrackingStore::scripts() as $key => $script ) {
				if ( self::runs_here( $script['pages'] ) ) {
					self::enqueue_script( $key, $script );
				}
			}
		}
	}

	/**
	 * Measurement codes (also on checkout / cart: that is where conversions happen).
	 *
	 * @param array<string,string> $codes Codes.
	 */
	public static function enqueue_codes( array $codes ): void {
		if ( ! $codes ) {
			return;
		}
		$cmp         = Detector::cmp();
		$consent_api = Detector::consent_api();
		$deps        = $consent_api && wp_script_is( 'wp-consent-api', 'registered' ) ? array( 'wp-consent-api' ) : array();
		$google      = array_values( array_filter( array( $codes['ga4'] ?? '', $codes['google_ads'] ?? '' ) ) );
		$base        = Providers::gtag_base( null !== $cmp ) . ( $consent_api ? self::google_consent_bridge() : '' );
		if ( $google ) {
			wp_enqueue_script( self::GTAG, Providers::gtag_src( $google[0] ), $deps, null, array( 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the provider's own loader URL must stay exact.
			wp_add_inline_script( self::GTAG, $base . Providers::gtag_config( $google ), 'before' );
			self::$handles[ self::GTAG ] = true;
			$base                        = '';
		}
		if ( isset( $codes['gtm'] ) ) {
			wp_register_script( self::GTM, false, $deps, null, array( 'in_footer' => false ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline only.
			wp_enqueue_script( self::GTM );
			wp_add_inline_script( self::GTM, $base . Providers::gtm( $codes['gtm'] ) );
			self::$handles[ self::GTM ] = true;
		}
		foreach ( array( 'meta', 'clarity', 'tiktok' ) as $provider ) {
			if ( ! isset( $codes[ $provider ] ) ) {
				continue;
			}
			$handle = self::PREFIX . $provider;
			$loader = Providers::loader( $provider, $codes[ $provider ] );
			// With a banner that reports through the WP Consent API, the pixel waits for the visitor's «yes».
			if ( null !== $cmp && $consent_api ) {
				$loader = self::after_consent( (string) Providers::ALL[ $provider ]['consent'], $loader );
			}
			wp_register_script( $handle, false, $deps, null, array( 'in_footer' => false ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline only.
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $loader );
			self::$handles[ $handle ] = true;
		}
	}

	/**
	 * One approved AI script: its own handle, in the footer, run once the page is parsed (like defer).
	 *
	 * @param string              $key    Key.
	 * @param array<string,mixed> $script Script.
	 */
	private static function enqueue_script( string $key, array $script ): void {
		// Checked again at every page view: a script that no longer passes (domains removed in Settings) does not run.
		if ( '' !== JsGuard::check( $script['code'], TrackingStore::js_domains(), TrackingStore::own_host() ) ) {
			return;
		}
		$handle = self::SCRIPTS . $key;
		wp_register_script( $handle, false, array(), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline only.
		wp_enqueue_script( $handle );
		wp_add_inline_script( $handle, "(function(){\nvar run = function(){\n" . $script['code'] . "\n};\nif (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }\n})();" );
		self::$handles[ $handle ] = true;
	}

	/**
	 * <noscript> fallbacks of Google Tag Manager and the Meta Pixel (right after <body>).
	 */
	public static function noscript(): void {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}
		$codes = TrackingStore::codes();
		if ( isset( $codes['gtm'] ) && null === Detector::cmp() ) {
			echo '<noscript><iframe src="' . esc_url( Providers::gtm_noscript( $codes['gtm'] ) ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
		}
		if ( isset( $codes['meta'] ) && null === Detector::cmp() ) {
			echo '<noscript><img height="1" width="1" style="display:none" alt="" src="' . esc_url( Providers::meta_noscript( $codes['meta'] ) ) . '" /></noscript>' . "\n";
		}
	}

	/**
	 * Wraps our tags in marker comments (the home-page check skips our own codes).
	 *
	 * @param string $tag    Tag.
	 * @param string $handle Handle.
	 */
	public static function mark( $tag, $handle ) {
		if ( ! is_string( $tag ) || ! isset( self::$handles[ (string) $handle ] ) ) {
			return $tag;
		}
		return Detector::MARK_START . "\n" . $tag . Detector::MARK_END . "\n";
	}

	/**
	 * Pages where AI scripts never run: checkout, cart, my account (and their endpoints), pages holding the checkout /
	 * cart / account blocks or shortcodes. wp-admin, wp-login.php / registration never load wp_enqueue_scripts.
	 */
	public static function protected_page(): bool {
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() || is_account_page() || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) ) ) {
			return true;
		}
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && self::protected_post( $post ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a post is a checkout / cart / account page.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function protected_post( \WP_Post $post ): bool {
		if ( in_array( (int) $post->ID, self::protected_ids(), true ) ) {
			return true;
		}
		$content = (string) $post->post_content;
		foreach ( array( 'woocommerce/checkout', 'woocommerce/cart', 'woocommerce/customer-account' ) as $block ) {
			if ( has_block( $block, $content ) ) {
				return true;
			}
		}
		foreach ( array( 'woocommerce_checkout', 'woocommerce_cart', 'woocommerce_my_account', 'woocommerce_order_tracking' ) as $code ) {
			if ( has_shortcode( $content, $code ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ids of the WooCommerce checkout / cart / account pages.
	 *
	 * @return array<int,int>
	 */
	public static function protected_ids(): array {
		$ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'checkout', 'cart', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Whether a script's pages include this page.
	 *
	 * @param mixed $pages 'all' | 'front_page' | ids.
	 */
	public static function runs_here( $pages ): bool {
		if ( 'all' === $pages ) {
			return true;
		}
		if ( 'front_page' === $pages ) {
			return is_front_page();
		}
		if ( is_array( $pages ) && is_singular() ) {
			return in_array( (int) get_queried_object_id(), array_map( 'intval', $pages ), true );
		}
		return false;
	}

	/**
	 * Google consent updates from the WP Consent API (the banner's «statistics» / «marketing» choices).
	 */
	private static function google_consent_bridge(): string {
		return "(function(){\nvar map = function(){\nif (typeof wp_has_consent !== 'function') { return; }\nvar m = wp_has_consent('marketing') ? 'granted' : 'denied';\nvar s = wp_has_consent('statistics') ? 'granted' : 'denied';\ngtag('consent', 'update', {'ad_storage': m, 'ad_user_data': m, 'ad_personalization': m, 'analytics_storage': s});\n};\ndocument.addEventListener('wp_listen_for_consent_change', map);\nif (typeof wp_has_consent === 'function') { map(); }\n})();\n";
	}

	/**
	 * A loader that runs only once the visitor accepted a consent category (WP Consent API).
	 *
	 * @param string $category statistics | marketing.
	 * @param string $loader   Loader.
	 */
	private static function after_consent( string $category, string $loader ): string {
		$cat = wp_json_encode( $category );
		return "(function(){\nvar done = false;\nvar load = function(){\nif (done) { return; }\ndone = true;\n" . $loader . "\n};\nif (typeof wp_has_consent === 'function' && wp_has_consent(" . $cat . ")) { load(); }\ndocument.addEventListener('wp_listen_for_consent_change', function(e){\nif (e && e.detail && e.detail[" . $cat . "] === 'allow') { load(); }\n});\n})();";
	}
}
