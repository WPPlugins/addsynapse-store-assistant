<?php
/**
 * Incremental Server-Sent Events parser.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain\Provider;

use Addsynapse\StoreAssistant\Brain\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Feed raw chunks, get complete events {event, data(decoded JSON or null)}.
 */
final class Sse {

	/**
	 * Unparsed rest.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Feeds a chunk.
	 *
	 * @param string $chunk Raw bytes.
	 * @return array<int,array{event:string,data:mixed}>
	 */
	public function feed( string $chunk ): array {
		$this->buffer .= str_replace( "\r\n", "\n", $chunk );
		$events        = array();
		$pos           = strpos( $this->buffer, "\n\n" );
		for ( ; false !== $pos; $pos = strpos( $this->buffer, "\n\n" ) ) {
			$block        = substr( $this->buffer, 0, $pos );
			$this->buffer = (string) substr( $this->buffer, $pos + 2 );
			$name         = '';
			$data         = '';
			foreach ( explode( "\n", $block ) as $line ) {
				if ( 0 === strpos( $line, 'event:' ) ) {
					$name = trim( substr( $line, 6 ) );
				} elseif ( 0 === strpos( $line, 'data:' ) ) {
					$data .= ( '' === $data ? '' : "\n" ) . ltrim( substr( $line, 5 ) );
				}
			}
			if ( '' === $data || '[DONE]' === $data ) {
				continue;
			}
			$decoded = Json::decode( $data );
			if ( '' === $name && is_array( $decoded ) ) {
				$name = (string) ( $decoded['type'] ?? '' );
			}
			$events[] = array(
				'event' => $name,
				'data'  => $decoded,
			);
		}//end for
		return $events;
	}
}
