<?php
/**
 * Greek words people type + Greek script normalization (the ONLY place for Greek literals in
 * includes/ — see tests/fixtures/greek-allowlist.txt and tests/I18nTest.php).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\I18n\Packs;

use Addsynapse\StoreAssistant\I18n\LocalePack;

defined( 'ABSPATH' ) || exit;

/**
 * Greek: keywords, final-sigma folding, "sound" transliteration for fuzzy matching
 * ("αντιντας" ≈ "adidas") and ELOT 743 transliteration for slugs.
 */
final class Greek extends LocalePack {

	/**
	 * Digraphs (applied first) → Latin sound.
	 */
	private const DIGRAPHS = array(
		'ου' => 'ou',
		'μπ' => 'b',
		'ντ' => 'd',
		'γκ' => 'g',
		'γγ' => 'ng',
		'τσ' => 'ts',
		'τζ' => 'tz',
	);

	/**
	 * Letters → Latin.
	 */
	private const LETTERS = array(
		'α' => 'a',
		'β' => 'v',
		'γ' => 'g',
		'δ' => 'd',
		'ε' => 'e',
		'ζ' => 'z',
		'η' => 'i',
		'θ' => 'th',
		'ι' => 'i',
		'κ' => 'k',
		'λ' => 'l',
		'μ' => 'm',
		'ν' => 'n',
		'ξ' => 'x',
		'ο' => 'o',
		'π' => 'p',
		'ρ' => 'r',
		'σ' => 's',
		'ς' => 's',
		'τ' => 't',
		'υ' => 'y',
		'φ' => 'f',
		'χ' => 'ch',
		'ψ' => 'ps',
		'ω' => 'o',
	);

	/**
	 * {@inheritDoc}
	 */
	public function code(): string {
		return 'el';
	}

	/**
	 * {@inheritDoc}
	 */
	public function script(): string {
		return 'Greek';
	}

	/**
	 * {@inheritDoc}
	 */
	public function lexicon(): array {
		return array(
			'yes'             => array( 'ναι', 'nai' ),
			'no'              => array( 'όχι', 'oxi', 'ohi' ),
			'any'             => array( 'οποιοδήποτε', 'όλα' ),
			'refresh'         => array( 'ανανέωση' ),
			'page_front'      => array( 'αρχική' ),
			'page_shop'       => array( 'κατάστημα' ),
			'brand'           => array( 'μάρκα', 'μάρκες' ),
			'brand_fragments' => array( 'κατασκευαστ', 'μαρκα', 'εταιρια', 'εταιρεια' ),
			'periods'         => array(
				'σήμερα'   => 'today',
				'εβδομάδα' => '7d',
				'μήνας'    => 'month',
			),
			'taxonomies'      => array(
				'κατηγορία'  => 'product_cat',
				'κατηγορίες' => 'product_cat',
				'ετικέτα'    => 'product_tag',
				'ετικέτες'   => 'product_tag',
			),
			'import_targets'  => array(
				'κωδικός'  => 'sku',
				'τιμή'     => 'regular_price',
				'προσφορά' => 'sale_price',
				'απόθεμα'  => 'stock',
			),
			'csv_columns'     => array(
				'sale_price'    => array( 'τιμηπροσφορας', 'προσφορα', 'εκπτωτικητιμη', 'τιμηεκπτωσης', 'εκπτωση' ),
				'stock'         => array( 'αποθεμα', 'ποσοτητα', 'τεμαχια', 'διαθεσιμα' ),
				'sku'           => array( 'κωδικος', 'κωδικοςπροιοντος', 'κωδ' ),
				'regular_price' => array( 'τιμη', 'τιμηλιανικης', 'λιανικη', 'κανονικητιμη' ),
			),
			'csv_not'         => array( 'πωλησεις', 'κατασταση', 'διαθεσιμοτητα', 'κοστος', 'χονδρικη', 'αγορας', 'ημερομηνια' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $text Lower-case, accent-free text.
	 */
	public function fold( string $text ): string {
		return strtr( $text, array( 'ς' => 'σ' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $text Lower-case, accent-free text.
	 */
	public function to_latin( string $text ): string {
		return strtr( strtr( $text, self::DIGRAPHS ), self::LETTERS );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $text Lower-case, accent-free text.
	 */
	public function slug_latin( string $text ): ?string {
		$text = strtr(
			$text,
			array(
				'αυ' => 'av',
				'ευ' => 'ev',
				'ηυ' => 'iv',
			)
		);
		return $this->to_latin( $text );
	}
}
