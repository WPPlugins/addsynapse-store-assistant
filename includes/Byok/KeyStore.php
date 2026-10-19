<?php
/**
 * The manager's own AI provider key (BYOK): stored encrypted, never sent to the browser.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Byok;

use Addsynapse\StoreAssistant\Brain\Models;
use Addsynapse\StoreAssistant\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Three options, all autoload=no (deleted by uninstall.php):
 *  - addsynapse_assistant_ai_provider: {provider, model, tested_at} (no secret);
 *  - addsynapse_assistant_ai_key:      Crypto::encrypt( key, 'addsynapse-assistant-ai-provider-key' ) (sodium secretbox,
 *                                      key derived from the salts);
 *  - addsynapse_assistant_ai_step_mode: '1' / '0' (compatibility mode).
 * A wp-config constant (ADDSYNAPSE_ASSISTANT_ANTHROPIC_KEY / ADDSYNAPSE_ASSISTANT_OPENAI_KEY) wins over the stored key
 * and hides the field. The key is only ever sent to the chosen provider's API — never to the browser, never to AddSynapse.
 * Honest limit: encryption protects database dumps and backups, not a fully compromised server.
 * Changing the WordPress salts makes the stored key unreadable → "enter the key again".
 */
final class KeyStore {

	public const OPTION_PROVIDER  = 'addsynapse_assistant_ai_provider';
	public const OPTION_KEY       = 'addsynapse_assistant_ai_key';
	public const OPTION_STEP_MODE = 'addsynapse_assistant_ai_step_mode';
	public const CONTEXT          = 'addsynapse-assistant-ai-provider-key';

	public const CONSTANTS = array(
		'anthropic' => 'ADDSYNAPSE_ASSISTANT_ANTHROPIC_KEY',
		'openai'    => 'ADDSYNAPSE_ASSISTANT_OPENAI_KEY',
	);

	/**
	 * Saved settings (no secret).
	 *
	 * @return array{provider:string,model:string,tested_at:int}
	 */
	public static function settings(): array {
		$raw      = get_option( self::OPTION_PROVIDER, array() );
		$raw      = is_array( $raw ) ? $raw : array();
		$provider = in_array( $raw['provider'] ?? '', Models::PROVIDERS, true ) ? (string) $raw['provider'] : '';
		$model    = self::clean_model( (string) ( $raw['model'] ?? '' ) );
		return array(
			'provider'  => $provider,
			'model'     => '' !== $model ? $model : ( '' !== $provider ? Models::default_model( $provider ) : '' ),
			'tested_at' => (int) ( $raw['tested_at'] ?? 0 ),
		);
	}

	/**
	 * Provider in use ('' = none).
	 */
	public static function provider(): string {
		return self::settings()['provider'];
	}

	/**
	 * Model in use.
	 */
	public static function model(): string {
		return self::settings()['model'];
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @param string $provider Provider.
	 */
	public static function from_constant( string $provider ): bool {
		$constant = self::CONSTANTS[ $provider ] ?? '';
		return '' !== $constant && defined( $constant ) && '' !== trim( (string) constant( $constant ) );
	}

	/**
	 * The key (server side only — never print, localize or return it over REST).
	 *
	 * @param string $provider Provider ('' = the saved one).
	 */
	public static function key( string $provider = '' ): string {
		$provider = '' !== $provider ? $provider : self::provider();
		if ( '' === $provider ) {
			return '';
		}
		if ( self::from_constant( $provider ) ) {
			return trim( (string) constant( self::CONSTANTS[ $provider ] ) );
		}
		$stored = get_option( self::OPTION_KEY, '' );
		if ( ! is_string( $stored ) || '' === $stored || self::provider() !== $provider ) {
			return '';
		}
		return (string) Crypto::decrypt( $stored, self::CONTEXT );
	}

	/**
	 * The stored key exists but can no longer be decrypted (salts changed).
	 */
	public static function unreadable(): bool {
		$provider = self::provider();
		if ( '' === $provider || self::from_constant( $provider ) ) {
			return false;
		}
		$stored = get_option( self::OPTION_KEY, '' );
		return is_string( $stored ) && '' !== $stored && null === Crypto::decrypt( $stored, self::CONTEXT );
	}

	/**
	 * Own key configured and tested once: the chat uses it.
	 */
	public static function active(): bool {
		$settings = self::settings();
		return '' !== $settings['provider'] && $settings['tested_at'] > 0 && '' !== self::key( $settings['provider'] );
	}

	/**
	 * Last 4 characters, for "…a1B2" in the settings (the only part ever shown).
	 *
	 * @param string $provider Provider ('' = the saved one).
	 */
	public static function hint( string $provider = '' ): string {
		$key = self::key( $provider );
		return strlen( $key ) >= 12 ? '…' . substr( $key, -4 ) : '';
	}

	/**
	 * Stores provider/model and (when given) a new key. Not marked tested.
	 *
	 * @param string      $provider Provider.
	 * @param string      $model    Model id.
	 * @param string|null $key      New key (null = keep the stored one).
	 */
	public static function save( string $provider, string $model, ?string $key ): bool {
		if ( ! in_array( $provider, Models::PROVIDERS, true ) || ! Models::matches( $provider, self::clean_model( $model ) ) ) {
			// A model of another provider is refused (never stored).
			return false;
		}
		$previous = self::provider();
		update_option(
			self::OPTION_PROVIDER,
			array(
				'provider'  => $provider,
				'model'     => self::clean_model( $model ),
				'tested_at' => 0,
			),
			false
		);
		if ( null !== $key && '' !== trim( $key ) ) {
			update_option( self::OPTION_KEY, Crypto::encrypt( trim( $key ), self::CONTEXT ), false );
		} elseif ( $previous !== $provider ) {
			// A key belongs to one provider: switching provider without a new key forgets the old one.
			delete_option( self::OPTION_KEY );
		}
		return true;
	}

	/**
	 * Marks the current key as tested (the chat may use it).
	 */
	public static function mark_tested(): void {
		$settings              = self::settings();
		$settings['tested_at'] = time();
		update_option( self::OPTION_PROVIDER, $settings, false );
	}

	/**
	 * Removes provider settings and key.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_PROVIDER );
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_STEP_MODE );
	}

	/**
	 * Whether a pasted API key has the shape of one: 8–400 characters, only letters, digits and `-_.:` (Anthropic
	 * `sk-ant-…`, OpenAI `sk-proj-…`, `sk-…`). Anything else (spaces, quotes, markup) is refused before it is stored.
	 *
	 * @param string $key Key.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_.:\-]{8,400}$/D', $key );
	}

	/**
	 * Model id allowed characters only.
	 *
	 * @param string $model Model.
	 */
	public static function clean_model( string $model ): string {
		$model = trim( $model );
		return 1 === preg_match( '/^[A-Za-z0-9._:\-\/]{1,100}$/', $model ) ? $model : '';
	}

	/**
	 * Compatibility mode: one model call per web request (hosts with short time limits or buffering proxies).
	 */
	public static function step_mode(): bool {
		return '1' === (string) get_option( self::OPTION_STEP_MODE, '0' );
	}

	/**
	 * Sets compatibility mode.
	 *
	 * @param bool $on On.
	 */
	public static function set_step_mode( bool $on ): void {
		update_option( self::OPTION_STEP_MODE, $on ? '1' : '0', false );
	}
}
