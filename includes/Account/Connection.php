<?php
/**
 * The AddSynapse account this site created from the opt-in card (none by default).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Account;

use Addsynapse\StoreAssistant\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Stored only after an administrator clicked «Create account» and AddSynapse answered: the site key (encrypted; used only
 * to fetch announcements), the account email, the offered Pro trial length and the links AddSynapse returned. Not autoloaded. Removed by
 * «Disconnect» on the Settings tab and by uninstall.php.
 */
final class Connection {

	public const OPTION = 'addsynapse_assistant_account';

	private const CRYPTO_CONTEXT = 'addsynapse-assistant-account-site-key';

	/**
	 * Stored connection, or null.
	 *
	 * @return array{site_key:string,email:string,created_at:int,trial_days:int,download_url:string,portal_url:string,marketing:string}|null
	 */
	public static function get(): ?array {
		$raw = get_option( self::OPTION, null );
		if ( ! is_array( $raw ) || empty( $raw['site_key'] ) ) {
			return null;
		}
		$key = Crypto::decrypt( (string) $raw['site_key'], self::CRYPTO_CONTEXT );
		if ( null === $key || '' === $key ) {
			return null;
		}
		return array(
			'site_key'     => $key,
			'email'        => (string) ( $raw['email'] ?? '' ),
			'created_at'   => (int) ( $raw['created_at'] ?? 0 ),
			'trial_days'   => (int) ( $raw['trial_days'] ?? 0 ),
			'download_url' => (string) ( $raw['download_url'] ?? '' ),
			'portal_url'   => (string) ( $raw['portal_url'] ?? '' ),
			'marketing'    => (string) ( $raw['marketing'] ?? 'none' ),
		);
	}

	/**
	 * Whether this site created (or linked) an account.
	 */
	public static function connected(): bool {
		return null !== self::get();
	}

	/**
	 * Stores the answer of a successful «Create account».
	 *
	 * @param array<string,mixed> $answer API answer (status created).
	 */
	public static function save( array $answer ): bool {
		$key = (string) ( $answer['site_key'] ?? '' );
		if ( ! preg_match( '/^ass_[A-Za-z0-9]{40}$/D', $key ) ) {
			return false;
		}
		$link = static function ( $url ): string {
			$url = is_string( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
			return '' !== $url && Api::link_allowed( $url ) ? $url : '';
		};
		update_option(
			self::OPTION,
			array(
				'site_key'     => Crypto::encrypt( $key, self::CRYPTO_CONTEXT ),
				'email'        => sanitize_email( (string) ( $answer['account_email'] ?? '' ) ),
				'created_at'   => time(),
				// The Pro trial is only OFFERED here: it starts when the add-on connects to this site (AddSynapse proves the domain).
				'trial_days'   => max( 0, min( 31, (int) ( $answer['trial_offer']['days'] ?? 0 ) ) ),
				'download_url' => $link( $answer['download_url'] ?? '' ),
				'portal_url'   => $link( $answer['portal_url'] ?? '' ),
				'marketing'    => 'pending_confirmation' === ( $answer['marketing'] ?? '' ) ? 'pending_confirmation' : 'none',
			),
			false
		);
		return true;
	}

	/**
	 * Forgets the connection (the account on addsynapse.com stays; announcements stop).
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		delete_option( Announcements::OPTION );
	}
}
