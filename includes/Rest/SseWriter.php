<?php
/**
 * Minimal Server-Sent Events writer for nginx/php-fpm.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Sends unbuffered SSE frames, keeps the connection alive with heartbeats and notices
 * client disconnects. A disconnect does NOT cancel the run (see Agent\RunState).
 */
class SseWriter {

	/**
	 * Seconds of silence after which a heartbeat comment is sent.
	 */
	public const HEARTBEAT_SECONDS = 10.0;

	/**
	 * Last write time (for keep-alive pings).
	 *
	 * @var float
	 */
	private float $last_write = 0.0;

	/**
	 * Whether the client disconnected.
	 *
	 * @var bool
	 */
	private bool $gone = false;

	/**
	 * Sends headers and disables buffering.
	 */
	public function start(): void {
		// Keep running to persist the partial reply when the user presses Stop.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running stream.
		}
		// Drop every removable output buffer (also zlib output compression, which would hold the stream back).
		while ( ob_get_level() > 0 ) {
			$buffer = ob_get_status();
			if ( empty( $buffer['flags'] ) || ! ( (int) $buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_clean() ) {
				break;
			}
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, no-transform' );
			// nginx: do not buffer this response.
			header( 'X-Accel-Buffering: no' );
			header( 'Connection: keep-alive' );
		}
		// Padding comment defeats small intermediary buffers.
		echo ':' . esc_html( str_repeat( ' ', 2048 ) ) . "\n\n";
		$this->flush();
	}

	/**
	 * Sends one event.
	 *
	 * @param string              $event Event name.
	 * @param array<string,mixed> $data  Payload (JSON encoded).
	 */
	public function send( string $event, array $data ): void {
		if ( $this->gone ) {
			return;
		}
		$event = preg_replace( '/[^a-z_]/', '', $event );
		// JSON on a single line; the browser parses it and never injects it as HTML.
		echo 'event: ' . esc_html( $event ) . "\n" . 'data: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) . "\n\n";
		$this->flush();
	}

	/**
	 * Sends an SSE comment when nothing was written for HEARTBEAT_SECONDS, so proxies and
	 * browsers do not treat a long model "think" as an idle connection.
	 */
	public function heartbeat(): void {
		if ( ! $this->gone && microtime( true ) - $this->last_write >= self::HEARTBEAT_SECONDS ) {
			echo ": ping\n\n";
			$this->flush();
		}
	}

	/**
	 * Whether the browser went away (only known after a write).
	 */
	public function client_gone(): bool {
		if ( ! $this->gone && connection_aborted() ) {
			$this->gone = true;
		}
		return $this->gone;
	}

	/**
	 * Flushes output to the client.
	 */
	private function flush(): void {
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
		$this->last_write = microtime( true );
	}

	/**
	 * Ends the request (bypasses the REST server's JSON output).
	 */
	public function finish(): void {
		exit;
	}
}
