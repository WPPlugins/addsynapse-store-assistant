<?php
/**
 * 1.3.10: every text the remote route writes (Approve AND Undo) goes through the same cleaning.
 *  - <script> and <style> elements are removed together with their content (wp_kses_post alone leaves the code as visible text);
 *  - HTML fields then pass wp_kses_post, plain fields (product name, alt text, media title) sanitize_text_field.
 * Undo stays exact: on Approve the plugin keeps a hash of each previous value; on Undo a value that matches that hash is the
 * site's own previous text and is written back as it was (an embed the owner had keeps working). Anything else AddSynapse
 * sends on Undo is cleaned like a new text, so a compromised AddSynapse can never inject code through Undo.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaning of remote text writes.
 */
final class TextGuard {

	/** Post meta with the hashes of the previous values, per change set. */
	public const META = '_addsynapse_assistant_undo_hashes';

	/** Change sets remembered per post (the oldest are dropped). */
	private const KEEP = 20;

	/**
	 * Removes <script> / <style> elements with their content (also an unclosed one, up to the end).
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function strip_code( string $value ): string {
		$value = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value );
		return (string) preg_replace( '#<(script|style)\b.*$#is', '', $value );
	}

	/**
	 * A new HTML text.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function html( string $value ): string {
		return wp_kses_post( self::strip_code( $value ) );
	}

	/**
	 * A new plain text.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function text( string $value ): string {
		return sanitize_text_field( self::strip_code( $value ) );
	}

	/**
	 * Approve: remembers the previous values of the fields about to be written.
	 *
	 * @param int                  $post_id   Post (product, page, attachment).
	 * @param string               $changeset Change set id.
	 * @param string               $kind      Unit kind.
	 * @param array<string,string> $previous  field => previous value.
	 */
	public static function remember( int $post_id, string $changeset, string $kind, array $previous ): void {
		if ( $post_id <= 0 || '' === $changeset ) {
			return;
		}
		$all = get_post_meta( $post_id, self::META, true );
		$all = is_array( $all ) ? $all : array();
		unset( $all[ $changeset ] );
		foreach ( $previous as $field => $value ) {
			$all[ $changeset ][ $kind . ':' . $field ] = hash( 'sha256', (string) $value );
		}
		if ( count( $all ) > self::KEEP ) {
			$all = array_slice( $all, -self::KEEP, null, true );
		}
		update_post_meta( $post_id, self::META, $all );
	}

	/**
	 * The value to write: on Undo the site's own previous value is kept as it was; everything else is cleaned.
	 *
	 * @param string $value     Value sent by AddSynapse.
	 * @param bool   $html      HTML field (else plain text).
	 * @param bool   $undo      Undo.
	 * @param int    $post_id   Post.
	 * @param string $changeset Change set id.
	 * @param string $key       kind:field.
	 * @return string
	 */
	public static function value( string $value, bool $html, bool $undo, int $post_id, string $changeset, string $key ): string {
		if ( $undo && $post_id > 0 && '' !== $changeset ) {
			$all = get_post_meta( $post_id, self::META, true );
			$own = is_array( $all ) ? ( $all[ $changeset ][ $key ] ?? null ) : null;
			if ( is_string( $own ) && hash_equals( $own, hash( 'sha256', $value ) ) ) {
				return $value;
			}
		}
		return $html ? self::html( $value ) : self::text( $value );
	}
}
