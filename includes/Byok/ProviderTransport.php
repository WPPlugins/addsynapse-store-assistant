<?php
/**
 * HTTPS calls to the AI provider the manager chose (own key) — allow-listed hosts only, no redirects.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Byok;

use Addsynapse\StoreAssistant\Brain\Provider\Adapters;
use Addsynapse\StoreAssistant\Brain\Provider\ProviderError;

defined( 'ABSPATH' ) || exit;

/**
 * Every call goes through wp_safe_remote_post() (the WP HTTP API: proxy settings, loggers and `pre_http_request` see it;
 * no raw cURL). Only https://api.anthropic.com and https://api.openai.com are accepted (Adapters::HOSTS), never a
 * redirect. Streaming uses the Requests progress hook (StreamState): the body is
 * handed over as it arrives when it is SSE; otherwise once, at the end. The PHPUnit suite replaces this class through
 * Seams (`provider_transport`).
 */
class ProviderTransport {

	/**
	 * Whether a URL is one of the provider API hosts over https (no port, no user info).
	 *
	 * @param string $provider Provider id.
	 * @param string $url      URL.
	 */
	public static function allowed( string $provider, string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && '' !== $host && ( Adapters::HOSTS[ $provider ] ?? '' ) === $host;
	}

	/**
	 * Posts JSON to the provider.
	 *
	 * @param string              $provider Provider id.
	 * @param array<string,mixed> $http     {url, headers, body} from the adapter.
	 * @param int                 $timeout  Seconds (total, also while streaming).
	 * @param callable|null       $on_chunk fn( string $chunk ): bool — false stops the transfer (streaming only).
	 * @param callable|null       $on_tick  fn(): bool — called whenever bytes arrive; false stops.
	 * @return array{status:int,body:string,aborted:bool}
	 * @throws ProviderError Network errors, timeouts and hosts that are not allowed.
	 */
	public function post( string $provider, array $http, int $timeout, ?callable $on_chunk = null, ?callable $on_tick = null ): array {
		$url = (string) ( $http['url'] ?? '' );
		if ( ! self::allowed( $provider, $url ) ) {
			throw new ProviderError( 'bad_request', 'This AI provider address is not allowed.' );
		}
		$headers = (array) ( $http['headers'] ?? array() );
		$chunk   = null === $on_chunk ? null : static function ( string $data ) use ( $on_chunk ): bool {
			return false !== $on_chunk( $data );
		};
		$stream  = new StreamState( null !== $chunk || null !== $on_tick, $chunk, $on_tick, $timeout );
		if ( $stream->enabled ) {
			$headers['Accept-Encoding'] = 'identity';
		}
		$stream->attach();
		try {
			$response = wp_safe_remote_post(
				$url,
				array(
					'headers'     => $headers,
					'body'        => (string) ( $http['body'] ?? '' ),
					'timeout'     => $timeout,
					'redirection' => 0,
					'decompress'  => ! $stream->enabled,
				)
			);
		} finally {
			$stream->detach();
		}
		if ( $stream->aborted ) {
			return array(
				'status'  => 200,
				'body'    => $stream->body,
				'aborted' => true,
			);
		}
		if ( $stream->timed_out || is_wp_error( $response ) ) {
			throw new ProviderError( 'network', 'Could not reach the AI provider (network error or timeout).' );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = (string) wp_remote_retrieve_body( $response );
		$aborted = false;
		// Not streamed as it arrived (buffered by the host, or answered at once): hand a successful SSE body over once.
		if ( $chunk && $status < 400 && '' !== $body && ! $stream->delivered && preg_match( '/^\s*(event:|data:)/', $body ) ) {
			$aborted = false === $chunk( $body );
		}
		return array(
			'status'  => $status,
			'body'    => $body,
			'aborted' => $aborted,
		);
	}
}
