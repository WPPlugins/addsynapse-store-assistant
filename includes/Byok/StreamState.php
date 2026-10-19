<?php
/**
 * One streamed AI provider request over the WP HTTP API (see ProviderTransport).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Byok;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the Requests library that ships with WordPress for exactly one request:
 *  - `requests-requests.before_request` (first request after attach() only): fsockopen transport + HTTP/1.0;
 *  - `requests-request.progress`: every body chunk → buffer, SSE sniffing, the chunk/tick listeners, Stop and the
 *    total timeout (a \WpOrg\Requests\Exception thrown here ends the transfer; WP_Http returns a WP_Error).
 */
final class StreamState {

	/**
	 * Whether this request streams.
	 *
	 * @var bool
	 */
	public bool $enabled;

	/**
	 * Body received so far.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * Stopped by a listener.
	 *
	 * @var bool
	 */
	public bool $aborted = false;

	/**
	 * Over the total timeout.
	 *
	 * @var bool
	 */
	public bool $timed_out = false;

	/**
	 * Chunks were handed to the chunk listener as they arrived (the body looked like SSE).
	 *
	 * @var bool
	 */
	public bool $delivered = false;

	/**
	 * Null until the first bytes: whether the body looks like SSE.
	 *
	 * @var bool|null
	 */
	private ?bool $sse = null;

	/**
	 * The next Requests call is ours (before_request fires once per request).
	 *
	 * @var bool
	 */
	private bool $armed = false;

	/**
	 * Re-entrancy guard (a listener must not see chunks of a request it makes itself).
	 *
	 * @var bool
	 */
	private bool $busy = false;

	/**
	 * Chunk listener.
	 *
	 * @var callable|null
	 */
	private $on_chunk;

	/**
	 * Tick listener.
	 *
	 * @var callable|null
	 */
	private $on_tick;

	/**
	 * Deadline (microtime).
	 *
	 * @var float
	 */
	private float $deadline;

	/**
	 * Constructor.
	 *
	 * @param bool          $enabled  Stream.
	 * @param callable|null $on_chunk fn( string $chunk, int $status, string $content_type ): bool.
	 * @param callable|null $on_tick  fn(): bool.
	 * @param int           $timeout  Total seconds.
	 */
	public function __construct( bool $enabled, ?callable $on_chunk, ?callable $on_tick, int $timeout ) {
		$this->enabled  = $enabled;
		$this->on_chunk = $on_chunk;
		$this->on_tick  = $on_tick;
		$this->deadline = microtime( true ) + max( 1, $timeout );
	}

	/**
	 * Hooks in (no-op when not streaming).
	 */
	public function attach(): void {
		if ( ! $this->enabled ) {
			return;
		}
		$this->armed = true;
		add_action( 'requests-requests.before_request', array( $this, 'before_request' ), 10, 5 );
		add_action( 'requests-request.progress', array( $this, 'progress' ), 10, 1 );
	}

	/**
	 * Unhooks.
	 */
	public function detach(): void {
		if ( ! $this->enabled ) {
			return;
		}
		remove_action( 'requests-requests.before_request', array( $this, 'before_request' ), 10 );
		remove_action( 'requests-request.progress', array( $this, 'progress' ), 10 );
		$this->armed = false;
	}

	/**
	 * Requests options of OUR request: fsockopen (when available) and HTTP/1.0.
	 *
	 * @param mixed $url     URL (by reference, unchanged).
	 * @param mixed $headers Headers (by reference, unchanged).
	 * @param mixed $data    Body (by reference, unchanged).
	 * @param mixed $type    Method (by reference, unchanged).
	 * @param mixed $options Requests options (by reference).
	 */
	public function before_request( &$url, &$headers, &$data, &$type, &$options ): void {
		if ( ! $this->armed || ! is_array( $options ) ) {
			return;
		}
		$this->armed = false;
		$ssl         = is_string( $url ) && 0 === stripos( $url, 'https://' );
		if ( \WpOrg\Requests\Transport\Fsockopen::test( array( 'ssl' => $ssl ) ) ) {
			$options['transport'] = \WpOrg\Requests\Transport\Fsockopen::class;
		}
		// HTTP/1.0: the server cannot answer with chunked framing, so the hook sees the plain SSE bytes.
		$options['protocol_version'] = 1.0;
	}

	/**
	 * One body chunk.
	 *
	 * @param mixed $data Chunk.
	 * @throws \WpOrg\Requests\Exception To stop the transfer (Stop / total timeout).
	 */
	public function progress( $data ): void {
		if ( $this->busy || ! is_string( $data ) ) {
			return;
		}
		$this->busy = true;
		try {
			$this->body .= $data;
			if ( null === $this->sse && '' !== ltrim( $this->body ) ) {
				$this->sse = (bool) preg_match( '/^(event:|data:|id:|retry:|:)/', ltrim( $this->body ) );
			}
			$continue = true;
			if ( $this->sse && $this->on_chunk && '' !== $data ) {
				$this->delivered = true;
				$continue        = false !== call_user_func( $this->on_chunk, $data, 200, 'text/event-stream' );
			}
			if ( $continue && $this->on_tick ) {
				$continue = false !== call_user_func( $this->on_tick );
			}
			if ( ! $continue ) {
				$this->aborted = true;
				throw new \WpOrg\Requests\Exception( 'Stopped.', 'addsynapse_assistant_abort' );
			}
			if ( microtime( true ) > $this->deadline ) {
				$this->timed_out = true;
				throw new \WpOrg\Requests\Exception( 'Timed out.', 'addsynapse_assistant_timeout' );
			}
		} finally {
			$this->busy = false;
		}//end try
	}
}
