<?php
/**
 * Measurement codes («tracking codes») the site can carry: the provider and its ID only. The code itself is always
 * built here, in the provider's official form — the AI never sends code for these.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Provider list, strict ID checks and the official snippets (built from a checked ID only).
 */
final class Providers {

	/**
	 * Provider key → {name, pattern (strict, anchored), example, group (google|other), consent (statistics|marketing), host}.
	 * The keys are stored in the option and travel as the unit key: never rename one.
	 */
	public const ALL = array(
		'ga4'        => array(
			'name'    => 'Google Analytics 4',
			'pattern' => '/^G-[A-Z0-9]{4,15}$/',
			'example' => 'G-ABC123XYZ9',
			'group'   => 'google',
			'consent' => 'statistics',
			'host'    => 'www.googletagmanager.com',
		),
		'gtm'        => array(
			'name'    => 'Google Tag Manager',
			'pattern' => '/^GTM-[A-Z0-9]{4,12}$/',
			'example' => 'GTM-AB12CDE3',
			'group'   => 'google',
			'consent' => 'statistics',
			'host'    => 'www.googletagmanager.com',
		),
		'google_ads' => array(
			'name'    => 'Google Ads',
			'pattern' => '/^AW-[0-9]{6,15}$/',
			'example' => 'AW-123456789',
			'group'   => 'google',
			'consent' => 'marketing',
			'host'    => 'www.googletagmanager.com',
		),
		'meta'       => array(
			'name'    => 'Meta Pixel',
			'pattern' => '/^[0-9]{8,20}$/',
			'example' => '1234567890123456',
			'group'   => 'other',
			'consent' => 'marketing',
			'host'    => 'connect.facebook.net',
		),
		'clarity'    => array(
			'name'    => 'Microsoft Clarity',
			'pattern' => '/^[a-z0-9]{6,20}$/',
			'example' => 'abcd123xyz',
			'group'   => 'other',
			'consent' => 'statistics',
			'host'    => 'www.clarity.ms',
		),
		'tiktok'     => array(
			'name'    => 'TikTok Pixel',
			'pattern' => '/^[A-Z0-9]{12,30}$/',
			'example' => 'C4ABCDEFGHIJ1234567K',
			'group'   => 'other',
			'consent' => 'marketing',
			'host'    => 'analytics.tiktok.com',
		),
	);

	/**
	 * Whether a provider key is known.
	 *
	 * @param string $provider Provider key.
	 */
	public static function known( string $provider ): bool {
		return isset( self::ALL[ $provider ] );
	}

	/**
	 * Display name of a provider.
	 *
	 * @param string $provider Provider key.
	 */
	public static function name( string $provider ): string {
		return (string) ( self::ALL[ $provider ]['name'] ?? $provider );
	}

	/**
	 * The ID as stored (trimmed; letters upper-case except Clarity's lower-case project ids), or '' when it is not valid
	 * for the provider. Nothing else than the exact pattern is ever accepted (no spaces, quotes, slashes or markup).
	 *
	 * @param string $provider Provider key.
	 * @param mixed  $id       ID as given.
	 */
	public static function normalize( string $provider, $id ): string {
		if ( ! self::known( $provider ) || ( ! is_string( $id ) && ! is_int( $id ) ) ) {
			return '';
		}
		$id = trim( (string) $id );
		$id = 'clarity' === $provider ? strtolower( $id ) : strtoupper( $id );
		return 1 === preg_match( self::ALL[ $provider ]['pattern'], $id ) ? $id : '';
	}

	/**
	 * Why an ID is not valid ('' = valid), in words the AI can repeat to the user.
	 *
	 * @param string $provider Provider key.
	 * @param mixed  $id       ID.
	 */
	public static function id_problem( string $provider, $id ): string {
		if ( ! self::known( $provider ) ) {
			return 'Unknown provider.';
		}
		if ( '' !== self::normalize( $provider, $id ) ) {
			return '';
		}
		return sprintf( 'This is not a valid %1$s ID (expected something like %2$s).', self::name( $provider ), self::ALL[ $provider ]['example'] );
	}

	/**
	 * The gtag.js address for the first Google ID (gtag.js serves GA4 and Google Ads together).
	 *
	 * @param string $id Checked ID.
	 */
	public static function gtag_src( string $id ): string {
		return 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id );
	}

	/**
	 * The gtag() function and dataLayer, and — only when a cookie banner that updates Google's consent is active — Google
	 * Consent Mode v2 defaults (everything denied until the visitor chooses). Runs before any config command.
	 *
	 * @param bool $consent_default Add the consent defaults.
	 */
	public static function gtag_base( bool $consent_default ): string {
		$js = "window.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\n";
		if ( $consent_default ) {
			$js .= "gtag('consent', 'default', {'ad_storage': 'denied', 'ad_user_data': 'denied', 'ad_personalization': 'denied', 'analytics_storage': 'denied', 'wait_for_update': 500});\n";
		}
		return $js;
	}

	/**
	 * The gtag.js config lines of GA4 / Google Ads ids.
	 *
	 * @param array<int,string> $ids Checked ids.
	 */
	public static function gtag_config( array $ids ): string {
		$js = "gtag('js', new Date());\n";
		foreach ( $ids as $id ) {
			$js .= 'gtag(\'config\', ' . wp_json_encode( $id ) . ");\n";
		}
		return $js;
	}

	/**
	 * Google Tag Manager's official container loader.
	 *
	 * @param string $id Checked GTM id.
	 */
	public static function gtm( string $id ): string {
		return "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\nnew Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\nj=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n})(window,document,'script','dataLayer'," . wp_json_encode( $id ) . ');';
	}

	/**
	 * The <noscript> address of Google Tag Manager.
	 *
	 * @param string $id Checked GTM id.
	 */
	public static function gtm_noscript( string $id ): string {
		return 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $id );
	}

	/**
	 * Meta Pixel's official base code.
	 *
	 * @param string $id Checked pixel id.
	 */
	public static function meta( string $id ): string {
		return "!function(f,b,e,v,n,t,s)\n{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\nn.callMethod.apply(n,arguments):n.queue.push(arguments)};\nif(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\nn.queue=[];t=b.createElement(e);t.async=!0;\nt.src=v;s=b.getElementsByTagName(e)[0];\ns.parentNode.insertBefore(t,s)}(window, document,'script',\n'https://connect.facebook.net/en_US/fbevents.js');\nfbq('init', " . wp_json_encode( $id ) . ");\nfbq('track', 'PageView');";
	}

	/**
	 * The <noscript> image of the Meta Pixel.
	 *
	 * @param string $id Checked pixel id.
	 */
	public static function meta_noscript( string $id ): string {
		return 'https://www.facebook.com/tr?id=' . rawurlencode( $id ) . '&ev=PageView&noscript=1';
	}

	/**
	 * Microsoft Clarity's official tracking code.
	 *
	 * @param string $id Checked project id.
	 */
	public static function clarity( string $id ): string {
		return "(function(c,l,a,r,i,t,y){\nc[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};\nt=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;\ny=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);\n})(window, document, \"clarity\", \"script\", " . wp_json_encode( $id ) . ');';
	}

	/**
	 * TikTok Pixel's official base code.
	 *
	 * @param string $id Checked pixel id.
	 */
	public static function tiktok( string $id ): string {
		return "!function (w, d, t) {\n  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=[\"page\",\"track\",\"identify\",\"instances\",\"debug\",\"on\",\"off\",\"once\",\"ready\",\"alias\",\"group\",\"enableCookie\",\"disableCookie\",\"holdConsent\",\"revokeConsent\",\"grantConsent\"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r=\"https://analytics.tiktok.com/i18n/pixel/events.js\";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement(\"script\");o.type=\"text/javascript\",o.async=!0,o.src=r+\"?sdkid=\"+e+\"&lib=\"+t;var a=document.getElementsByTagName(\"script\")[0];a.parentNode.insertBefore(o,a)};\n  ttq.load(" . wp_json_encode( $id ) . ");\n  ttq.page();\n}(window, document, 'ttq');";
	}

	/**
	 * Loader of a non-Google provider.
	 *
	 * @param string $provider Provider key.
	 * @param string $id       Checked id.
	 */
	public static function loader( string $provider, string $id ): string {
		switch ( $provider ) {
			case 'meta':
				return self::meta( $id );
			case 'clarity':
				return self::clarity( $id );
			case 'tiktok':
				return self::tiktok( $id );
		}
		return '';
	}
}
