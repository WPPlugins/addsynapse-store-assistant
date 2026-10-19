<?php
/**
 * Per-conversation run state: "an answer is being generated" and "the manager pressed Stop".
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the flags must be read fresh from the DB (another request sets them); the options cache would hide the change.

/**
 * A dropped browser connection must NOT cancel the answer: browsers abort long requests on
 * their own (e.g. Chrome's ERR_NETWORK_CHANGED whenever a network interface changes), and
 * the answer should still be completed and stored so the UI can reload it. So the loop is
 * cancelled only by an explicit Stop (POST …/stop), never by a disconnect.
 */
final class RunState {

	/**
	 * A run older than this is considered dead (PHP limit is 300 s).
	 */
	public const STALE_AFTER = 330;

	/**
	 * Option name.
	 *
	 * @param string $what run|stop.
	 * @param int    $id   Conversation id.
	 */
	private static function name( string $what, int $id ): string {
		return 'addsynapse_assistant_' . $what . '_' . $id;
	}

	/**
	 * Fresh read of an option (bypasses the object/option caches).
	 *
	 * @param string $name Option name.
	 */
	private static function read( string $name ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * Writes an option without autoload and without touching the caches of other requests.
	 *
	 * @param string $name  Option name.
	 * @param int    $value Value.
	 */
	private static function write( string $name, int $value ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $name, (string) $value ) );
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Removes an option.
	 *
	 * @param string $name Option name.
	 */
	private static function remove( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Marks a run as started (clears any previous Stop).
	 *
	 * @param int $id Conversation id.
	 */
	public static function start( int $id ): void {
		self::remove( self::name( 'stop', $id ) );
		self::write( self::name( 'run', $id ), time() );
	}

	/**
	 * Marks a run as finished.
	 *
	 * @param int $id Conversation id.
	 */
	public static function finish( int $id ): void {
		self::remove( self::name( 'run', $id ) );
		self::remove( self::name( 'stop', $id ) );
	}

	/**
	 * Whether an answer is being generated right now.
	 *
	 * @param int $id Conversation id.
	 */
	public static function is_running( int $id ): bool {
		$started = self::read( self::name( 'run', $id ) );
		return $started > 0 && time() - $started < self::STALE_AFTER;
	}

	/**
	 * Records the manager's Stop.
	 *
	 * @param int $id Conversation id.
	 */
	public static function request_stop( int $id ): void {
		if ( self::is_running( $id ) ) {
			self::write( self::name( 'stop', $id ), time() );
		}
	}

	/**
	 * Whether Stop was requested (polled by the running loop).
	 *
	 * @param int $id Conversation id.
	 */
	public static function stop_requested( int $id ): bool {
		return self::read( self::name( 'stop', $id ) ) > 0;
	}

	/**
	 * Abort poll for the AgentLoop: throttled DB check of the Stop flag, plus an optional
	 * tick (e.g. an SSE heartbeat) on every call.
	 *
	 * @param int           $id   Conversation id.
	 * @param callable|null $tick Called on every poll.
	 */
	public static function stop_poll( int $id, ?callable $tick = null ): callable {
		$last    = 0.0;
		$stopped = false;
		return static function () use ( $id, $tick, &$last, &$stopped ): bool {
			if ( $tick ) {
				$tick();
			}
			if ( ! $stopped && microtime( true ) - $last >= 1.0 ) {
				$last    = microtime( true );
				$stopped = self::stop_requested( $id );
			}
			return $stopped;
		};
	}
}
