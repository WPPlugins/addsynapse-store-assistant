<?php
/**
 * JSON scanner that keeps byte offsets (for surgical edits of stored JSON).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Parses JSON into a tree of value nodes with their byte ranges in the source:
 *   ['t' => object|array|string|number|literal, 's' => start, 'e' => end (exclusive),
 *    'k' => [key => node] (objects), 'i' => [node, …] (arrays), 'v' => decoded scalar].
 *
 * Why: decoding and re-encoding the whole document is NOT byte-exact (floats such as
 * 84.245999999999995, `{}` vs `[]`, escaping style), so a builder document is edited by replacing
 * only the byte range of the value that changes. Everything else stays byte-identical.
 */
final class JsonScanner {

	/**
	 * Source.
	 *
	 * @var string
	 */
	private string $src;

	/**
	 * Cursor.
	 *
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * Length.
	 *
	 * @var int
	 */
	private int $len;

	/**
	 * Parses a JSON document.
	 *
	 * @param string $json JSON.
	 * @return array<string,mixed> Root node.
	 * @throws \RuntimeException On invalid JSON.
	 */
	public static function parse( string $json ): array {
		$scanner = new self( $json );
		$scanner->ws();
		$root = $scanner->value( 0 );
		$scanner->ws();
		if ( $scanner->pos !== $scanner->len ) {
			throw new \RuntimeException( 'Trailing data in JSON.' );
		}
		return $root;
	}

	/**
	 * Constructor.
	 *
	 * @param string $json JSON.
	 */
	private function __construct( string $json ) {
		$this->src = $json;
		$this->len = strlen( $json );
	}

	/**
	 * Skips whitespace.
	 */
	private function ws(): void {
		$this->pos += strspn( $this->src, " \t\r\n", $this->pos );
	}

	/**
	 * One value.
	 *
	 * @param int $depth Depth.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On invalid JSON.
	 */
	private function value( int $depth ): array {
		if ( $depth > 256 || $this->pos >= $this->len ) {
			throw new \RuntimeException( 'Invalid JSON.' );
		}
		$c = $this->src[ $this->pos ];
		if ( '{' === $c ) {
			return $this->obj( $depth );
		}
		if ( '[' === $c ) {
			return $this->arr( $depth );
		}
		if ( '"' === $c ) {
			$start = $this->pos;
			$this->str();
			$raw = substr( $this->src, $start, $this->pos - $start );
			return array(
				't' => 'string',
				's' => $start,
				'e' => $this->pos,
				'v' => json_decode( $raw ),
			);
		}
		$start = $this->pos;
		$span  = strspn( $this->src, '0123456789+-.eEtruefalsn', $this->pos );
		if ( $span < 1 ) {
			throw new \RuntimeException( 'Invalid JSON.' );
		}
		$this->pos += $span;
		$raw        = substr( $this->src, $start, $span );
		$decoded    = json_decode( $raw );
		if ( null === $decoded && 'null' !== $raw ) {
			throw new \RuntimeException( 'Invalid JSON.' );
		}
		return array(
			't' => is_numeric( $raw ) ? 'number' : 'literal',
			's' => $start,
			'e' => $this->pos,
			'v' => $decoded,
		);
	}

	/**
	 * A string token (cursor on the opening quote → after the closing quote).
	 *
	 * @throws \RuntimeException On invalid JSON.
	 */
	private function str(): void {
		++$this->pos;
		while ( $this->pos < $this->len ) {
			$this->pos += strcspn( $this->src, '"\\', $this->pos );
			if ( $this->pos >= $this->len ) {
				break;
			}
			if ( '\\' === $this->src[ $this->pos ] ) {
				$this->pos += 2;
				continue;
			}
			++$this->pos;
			return;
		}
		throw new \RuntimeException( 'Unterminated JSON string.' );
	}

	/**
	 * An object.
	 *
	 * @param int $depth Depth.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On invalid JSON.
	 */
	private function obj( int $depth ): array {
		$start = $this->pos;
		++$this->pos;
		$keys = array();
		$this->ws();
		if ( $this->pos < $this->len && '}' === $this->src[ $this->pos ] ) {
			++$this->pos;
			return array(
				't' => 'object',
				's' => $start,
				'e' => $this->pos,
				'k' => $keys,
			);
		}
		while ( true ) {
			$this->ws();
			if ( $this->pos >= $this->len || '"' !== $this->src[ $this->pos ] ) {
				throw new \RuntimeException( 'Invalid JSON object key.' );
			}
			$key_start = $this->pos;
			$this->str();
			$key = (string) json_decode( substr( $this->src, $key_start, $this->pos - $key_start ) );
			$this->ws();
			if ( $this->pos >= $this->len || ':' !== $this->src[ $this->pos ] ) {
				throw new \RuntimeException( 'Invalid JSON object.' );
			}
			++$this->pos;
			$this->ws();
			$keys[ $key ] = $this->value( $depth + 1 );
			$this->ws();
			if ( $this->pos >= $this->len ) {
				throw new \RuntimeException( 'Invalid JSON object.' );
			}
			if ( ',' === $this->src[ $this->pos ] ) {
				++$this->pos;
				continue;
			}
			if ( '}' === $this->src[ $this->pos ] ) {
				++$this->pos;
				break;
			}
			throw new \RuntimeException( 'Invalid JSON object.' );
		}//end while
		return array(
			't' => 'object',
			's' => $start,
			'e' => $this->pos,
			'k' => $keys,
		);
	}

	/**
	 * An array.
	 *
	 * @param int $depth Depth.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On invalid JSON.
	 */
	private function arr( int $depth ): array {
		$start = $this->pos;
		++$this->pos;
		$items = array();
		$this->ws();
		if ( $this->pos < $this->len && ']' === $this->src[ $this->pos ] ) {
			++$this->pos;
			return array(
				't' => 'array',
				's' => $start,
				'e' => $this->pos,
				'i' => $items,
			);
		}
		while ( true ) {
			$this->ws();
			$items[] = $this->value( $depth + 1 );
			$this->ws();
			if ( $this->pos >= $this->len ) {
				throw new \RuntimeException( 'Invalid JSON array.' );
			}
			if ( ',' === $this->src[ $this->pos ] ) {
				++$this->pos;
				continue;
			}
			if ( ']' === $this->src[ $this->pos ] ) {
				++$this->pos;
				break;
			}
			throw new \RuntimeException( 'Invalid JSON array.' );
		}
		return array(
			't' => 'array',
			's' => $start,
			'e' => $this->pos,
			'i' => $items,
		);
	}
}
