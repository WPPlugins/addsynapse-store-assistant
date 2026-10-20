<?php
/**
 * System prompt for the local (own AI key) brain, built from the hub's sections.
 *
 * @package Addsynapse\StoreAssistant\Brain
 */

namespace Addsynapse\StoreAssistant\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * resources/prompt.json = the hub's assistant prompt split at its "# " headings, rewritten for this free edition (one
 * item per change, no package block; `md5` = md5 of the joined texts). Lean by design (1.2.0): every request gets a
 * small core (who you are, language, how you work, data safety, users, answer format) and only the sections whose
 * tools this request offers or whose facts this conversation has (ticked products, an uploaded image) — a smaller,
 * stable prefix is what the manager pays for on every question with their own key. Within a conversation the
 * sections only grow (the router's bundles and the facts are sticky), so the cached prefix stays valid.
 */
final class Prompt {

	/**
	 * Section title → canonical tools; the section is sent when ANY of them is offered. Sections not listed here or in
	 * SECTION_FACTS are core (always sent).
	 */
	private const SECTION_TOOLS = array(
		'Filtered reports'                               => array( 'product_report', 'search_products' ),
		'Limited results are a sample, not the whole catalogue' => array( 'product_report', 'search_products', 'list_orders', 'customer_report', 'find_media' ),
		'Learn the store before you talk about its structure' => array( 'get_product_raw', 'describe_store' ),
		'Names of brands, categories, tags and attribute values' => array( 'find_terms' ),
		"Language of the shop's content"                 => array( 'plan_price_update', 'plan_stock_update', 'plan_taxonomy_terms', 'plan_brand_assign', 'plan_product_update', 'plan_seo_update' ),
		'Changes: plan → preview → the manager approves' => array( 'plan_price_update', 'plan_stock_update', 'plan_taxonomy_terms', 'plan_brand_assign', 'plan_product_update', 'plan_seo_update' ),
		'Categories, tags and brands'                    => array( 'plan_taxonomy_terms', 'plan_brand_assign' ),
		'Product details'                                => array( 'plan_product_update' ),
		'Top customers'                                  => array( 'customer_report' ),
		'SEO and product texts'                          => array( 'plan_seo_update' ),
		'Menus'                                          => array( 'describe_menus' ),
		'Pages and banners (appearance)'                 => array( 'describe_page' ),
		'Store Assistant Pro'                            => array( 'coming_in_pro' ),
	);

	/**
	 * Section title → fact of the conversation that brings it (Prompt::system $facts).
	 */
	private const SECTION_FACTS = array(
		'Selected products' => 'selection',
		'Uploaded images'   => 'image',
	);

	/**
	 * Hub-only lines removed from core sections (the portal channel does not exist locally).
	 */
	private const HUB_ONLY_LINE = '- A message may carry a `<channel>portal</channel>` block';

	/**
	 * The system prompt for this request.
	 *
	 * @param array<int,string>   $tools  Canonical names of the tools offered in this request.
	 * @param bool                $has_wc WooCommerce is active.
	 * @param array<string,mixed> $facts  Facts of the conversation: `selection` (products were ticked), `image` (an image
	 *                                    was uploaded). Without facts their sections are left out.
	 * @return string
	 */
	public static function system( array $tools, bool $has_wc, array $facts = array() ): string {
		$offered = array_fill_keys( $tools, true );
		$out     = '';
		foreach ( self::sections() as $section ) {
			$title = (string) $section['title'];
			if ( 'Sites without WooCommerce' === $title && $has_wc ) {
				continue;
			}
			if ( array_key_exists( $title, self::SECTION_TOOLS ) && ! array_intersect_key( $offered, array_fill_keys( self::SECTION_TOOLS[ $title ], true ) ) ) {
				continue;
			}
			if ( array_key_exists( $title, self::SECTION_FACTS ) && empty( $facts[ self::SECTION_FACTS[ $title ] ] ) ) {
				continue;
			}
			$out .= self::localise( (string) $section['text'] );
		}
		return rtrim( $out ) . "\n\n" . self::edition_section();
	}

	/**
	 * Hub prompt version the sections come from.
	 */
	public static function version(): int {
		return (int) ( Json::resource( 'prompt.json' )['version'] ?? 0 );
	}

	/**
	 * Title prompt (one short call, or a local fallback).
	 */
	public static function title(): string {
		return (string) ( Json::resource( 'prompt.json' )['title'] ?? '' );
	}

	/**
	 * The hub sections.
	 *
	 * @return array<int,array{title:string,text:string}>
	 */
	public static function sections(): array {
		return (array) ( Json::resource( 'prompt.json' )['sections'] ?? array() );
	}

	/**
	 * Local wording: product name, no portal channel.
	 *
	 * @param string $text Section text.
	 */
	private static function localise( string $text ): string {
		$text  = str_replace(
			array( 'You are "AI Operations"', 'AI Operations → Settings' ),
			array( 'You are "AddSynapse Store Assistant"', 'AddSynapse → Store Assistant → Settings' ),
			$text
		);
		$lines = explode( "\n", $text );
		$lines = array_filter(
			$lines,
			static function ( $line ) {
				return 0 !== strpos( $line, self::HUB_ONLY_LINE );
			}
		);
		return implode( "\n", $lines );
	}

	/**
	 * What this installation can do (replaces the hub's package block): nothing is locked; the tools of the request are
	 * the truth (work on many items at once belongs to the separate Pro add-on — see the «Store Assistant Pro» section).
	 */
	private static function edition_section(): string {
		return "# This installation\n"
			. "- The assistant runs on the manager's own AI provider key; there is no package and no plan. The tools you have in THIS request are the only truth about what you can do, and every change covers one item (see \"Changes\").\n";
	}
}
