<?php
/**
 * The first prompt to paste into Claude or ChatGPT, built in for languages translate.wordpress.org does not cover yet (1.3.6).
 *
 * It is text the manager SENDS to the AI, so it should be in the manager's language even before a language pack exists.
 * The UI itself stays English + __() (languages/*.po).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in translations of the first prompt, by language.
 */
final class FirstPrompt {

	/**
	 * Language → [store, site] prompt.
	 */
	private const BUILT_IN = array(
		'el' => array(
			'store' => 'Χρησιμοποίησε το Store Manager for WooCommerce και δείξε μου 3 προϊόντα από το κατάστημά μου.',
			'site'  => 'Χρησιμοποίησε το Site Manager for WordPress και δείξε μου 3 σελίδες από το site μου.',
		),
	);

	/**
	 * The built-in prompt for a locale ('' = none).
	 *
	 * @param string $locale WordPress locale (el, el_GR, …).
	 * @param bool   $site   Plain site (no WooCommerce).
	 */
	public static function built_in( string $locale, bool $site ): string {
		$lang = strtolower( (string) strtok( $locale, '_' ) );
		return self::BUILT_IN[ $lang ][ $site ? 'site' : 'store' ] ?? '';
	}
}
