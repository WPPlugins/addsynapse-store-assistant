<?php
/**
 * Providers and their default models (data; updated with releases).
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Price per million tokens in USD where known. An unknown model (custom id) has NO price: cost is shown as
 * unknown, never silently 0. `long_context` = prompt size above which the provider charges a higher rate.
 */
final class Models {

	public const PROVIDERS = array( 'anthropic', 'openai' );

	/**
	 * Catalog.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public static function catalog(): array {
		return array(
			'anthropic' => array(
				'claude-haiku-5-5'  => array(
					'label'        => 'Claude Haiku 5.5',
					'default'      => true,
					'input'        => 0.10,
					'output'       => 0.50,
					'cache_read'   => 0.01,
					'cache_write'  => 0.125,
					'long_context' => 100000,
				),
				'claude-sonnet-5-5' => array(
					'label'        => 'Claude Sonnet 5.5',
					'input'        => 2.0,
					'output'       => 10.0,
					'cache_read'   => 0.20,
					'cache_write'  => 2.5,
					'long_context' => 0,
				),
				'claude-opus-5-5'   => array(
					'label'        => 'Claude Opus 5.5',
					'input'        => 4.0,
					'output'       => 20.0,
					'cache_read'   => 0.20,
					'cache_write'  => 5.0,
					'long_context' => 0,
				),
			),
			// OpenAI: official prices (developers.openai.com/api/docs/pricing, 18/10/2026), standard tier. UNTESTED with a real
			// key (none available) — the adapter is covered by fixtures only. Cache writes are free on OpenAI.
			'openai'    => array(
				'gpt-5-mini'    => array(
					'label'        => 'GPT-5 mini',
					'default'      => true,
					'untested'     => true,
					'input'        => 0.25,
					'output'       => 2.0,
					'cache_read'   => 0.025,
					'cache_write'  => 0.0,
					'long_context' => 0,
				),
				'gpt-5-nano'    => array(
					'label'        => 'GPT-5 nano',
					'untested'     => true,
					'input'        => 0.05,
					'output'       => 0.4,
					'cache_read'   => 0.005,
					'cache_write'  => 0.0,
					'long_context' => 0,
				),
				'gpt-5.4-mini'  => array(
					'label'        => 'GPT-5.4 mini',
					'untested'     => true,
					'input'        => 0.75,
					'output'       => 4.5,
					'cache_read'   => 0.075,
					'cache_write'  => 0.0,
					'long_context' => 0,
				),
				'gpt-5.6-luna'  => array(
					'label'        => 'GPT-5.6 luna',
					'untested'     => true,
					'input'        => 0.2,
					'output'       => 1.2,
					'cache_read'   => 0.02,
					'cache_write'  => 0.0,
					'long_context' => 272000,
				),
				'gpt-5.6-terra' => array(
					'label'        => 'GPT-5.6 terra',
					'untested'     => true,
					'input'        => 2.0,
					'output'       => 12.0,
					'cache_read'   => 0.2,
					'cache_write'  => 0.0,
					'long_context' => 272000,
				),
				'gpt-5'         => array(
					'label'        => 'GPT-5',
					'untested'     => true,
					'input'        => 1.25,
					'output'       => 10.0,
					'cache_read'   => 0.125,
					'cache_write'  => 0.0,
					'long_context' => 0,
				),
				'gpt-4.1-mini'  => array(
					'label'        => 'GPT-4.1 mini',
					'untested'     => true,
					'input'        => 0.4,
					'output'       => 1.6,
					'cache_read'   => 0.1,
					'cache_write'  => 0.0,
					'long_context' => 0,
				),
			),
		);
	}

	/**
	 * Default model of a provider.
	 *
	 * @param string $provider Provider.
	 */
	public static function default_model( string $provider ): string {
		foreach ( self::catalog()[ $provider ] ?? array() as $id => $model ) {
			if ( ! empty( $model['default'] ) ) {
				return (string) $id;
			}
		}
		return '';
	}

	/**
	 * Whether a model id belongs to a provider ('' = the provider's default, always fine). Catalog models of the
	 * provider, or a newer model of the same family (Anthropic: claude-*; OpenAI: gpt-* / o<digit>*).
	 *
	 * @param string $provider Provider.
	 * @param string $model    Model id.
	 */
	public static function matches( string $provider, string $model ): bool {
		if ( '' === $model ) {
			return true;
		}
		if ( isset( self::catalog()[ $provider ][ $model ] ) ) {
			return true;
		}
		foreach ( self::catalog() as $other => $models ) {
			if ( $other !== $provider && isset( $models[ $model ] ) ) {
				return false;
			}
		}
		if ( 'anthropic' === $provider ) {
			return 1 === preg_match( '/^claude-/', $model );
		}
		return 'openai' === $provider && 1 === preg_match( '/^(gpt-|o\d)/', $model );
	}

	/**
	 * The models of a provider for a select: the recommended default first.
	 *
	 * @param string $provider Provider.
	 * @return array<string,string> id => label.
	 */
	public static function options( string $provider ): array {
		$out     = array();
		$default = self::default_model( $provider );
		$models  = self::catalog()[ $provider ] ?? array();
		if ( isset( $models[ $default ] ) ) {
			$out[ $default ] = (string) $models[ $default ]['label'];
		}
		foreach ( $models as $id => $model ) {
			if ( $id !== $default ) {
				$out[ (string) $id ] = (string) $model['label'];
			}
		}
		return $out;
	}

	/**
	 * Estimated cost in USD of one call, or null when the model has no known price.
	 *
	 * @param string            $provider Provider.
	 * @param string            $model    Model id.
	 * @param array<string,int> $usage    Canonical usage (input_tokens, output_tokens, cache_read_input_tokens, cache_creation_input_tokens).
	 */
	public static function cost( string $provider, string $model, array $usage ): ?float {
		$price = self::catalog()[ $provider ][ $model ] ?? null;
		if ( ! $price || ! isset( $price['input'], $price['output'] ) ) {
			return null;
		}
		$m = 1000000;
		return ( (int) ( $usage['input_tokens'] ?? 0 ) * $price['input']
			+ (int) ( $usage['output_tokens'] ?? 0 ) * $price['output']
			+ (int) ( $usage['cache_read_input_tokens'] ?? 0 ) * ( $price['cache_read'] ?? $price['input'] )
			+ (int) ( $usage['cache_creation_input_tokens'] ?? 0 ) * ( $price['cache_write'] ?? $price['input'] ) ) / $m;
	}

	/**
	 * Prompt size above which the model gets expensive (0 = none known).
	 *
	 * @param string $provider Provider.
	 * @param string $model    Model.
	 */
	public static function long_context( string $provider, string $model ): int {
		return (int) ( self::catalog()[ $provider ][ $model ]['long_context'] ?? 0 );
	}
}
