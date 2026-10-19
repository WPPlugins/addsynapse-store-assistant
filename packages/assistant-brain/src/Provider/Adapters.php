<?php
/**
 * Adapter by provider id.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Factory + the hosts each provider may be called on (the transport refuses anything else).
 */
final class Adapters {

	public const HOSTS = array(
		'anthropic' => 'api.anthropic.com',
		'openai'    => 'api.openai.com',
	);

	/**
	 * Adapter for a provider (null when unknown).
	 *
	 * @param string $provider Provider id.
	 */
	public static function make( string $provider ): ?ProviderAdapter {
		if ( 'anthropic' === $provider ) {
			return new AnthropicAdapter();
		}
		if ( 'openai' === $provider ) {
			return new OpenAiAdapter();
		}
		return null;
	}
}
