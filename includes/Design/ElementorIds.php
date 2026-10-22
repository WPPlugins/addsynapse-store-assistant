<?php
/**
 * Element ids of Elementor documents.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Fresh, document-unique 7-character Elementor element ids (Elementor's own id format).
 */
final class ElementorIds {

	/**
	 * Ids already used in the document (+ the ones handed out).
	 *
	 * @var array<string,bool>
	 */
	private array $used;

	/**
	 * Id generator (tests may pass a deterministic one).
	 *
	 * @var callable
	 */
	private $random;

	/**
	 * Constructor.
	 *
	 * @param array<int,string> $used   Ids already in the page.
	 * @param callable|null     $random fn(): string (7 chars) — default random hex.
	 */
	public function __construct( array $used, ?callable $random = null ) {
		$this->used   = array_fill_keys( $used, true );
		$this->random = $random ?? static function (): string {
			return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		};
	}

	/**
	 * Every element id of an Elementor document.
	 *
	 * @param string $raw JSON.
	 * @return array<int,string>
	 */
	public static function ids_in( string $raw ): array {
		preg_match_all( '/"id"\s*:\s*"([^"\\\\]{1,40})"/', $raw, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * A fresh unique element id.
	 *
	 * @throws \RuntimeException When no unique id could be generated.
	 */
	public function id(): string {
		for ( $i = 0; $i < 1000; $i++ ) {
			$id = strtolower( (string) call_user_func( $this->random ) );
			if ( preg_match( '/^[a-z0-9]{7}$/', $id ) && ! isset( $this->used[ $id ] ) ) {
				$this->used[ $id ] = true;
				return $id;
			}
		}
		throw new \RuntimeException( 'Could not generate a unique element id.' );
	}
}
