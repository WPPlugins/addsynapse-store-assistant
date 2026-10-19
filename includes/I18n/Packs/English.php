<?php
/**
 * English words people type (source language).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\I18n\Packs;

use Addsynapse\StoreAssistant\I18n\LocalePack;

defined( 'ABSPATH' ) || exit;

/**
 * English (and language-neutral tokens such as 1/0/true/false).
 */
final class English extends LocalePack {

	/**
	 * {@inheritDoc}
	 */
	public function code(): string {
		return 'en';
	}

	/**
	 * {@inheritDoc}
	 */
	public function lexicon(): array {
		return array(
			'yes'             => array( '1', 'yes', 'y', 'true', 'on' ),
			'no'              => array( '0', 'no', 'n', 'false', 'off' ),
			'any'             => array( '', 'any', 'all' ),
			'refresh'         => array( 'refresh', 'fresh' ),
			'page_front'      => array( 'front', 'home', 'homepage' ),
			'page_shop'       => array( 'shop' ),
			'brand'           => array( 'brand', 'brands' ),
			'brand_fragments' => array( 'brand', 'marka', 'marque', 'marke', 'manufacturer', 'maker' ),
			'periods'         => array(
				'today' => 'today',
				'7d'    => '7d',
				'week'  => '7d',
				'30d'   => '30d',
				'month' => 'month',
			),
			'taxonomies'      => array(
				'category'   => 'product_cat',
				'categories' => 'product_cat',
				'tag'        => 'product_tag',
				'tags'       => 'product_tag',
			),
			'import_targets'  => array(
				'sku'           => 'sku',
				'code'          => 'sku',
				'price'         => 'regular_price',
				'regular'       => 'regular_price',
				'regular_price' => 'regular_price',
				'sale'          => 'sale_price',
				'sale_price'    => 'sale_price',
				'stock'         => 'stock',
				'qty'           => 'stock',
			),
			// Normalized like ImportMapping::normalize (lower case, no accents/spaces/punctuation).
			// Checked in this order: "sale price" is a sale price although it contains "price".
			'csv_columns'     => array(
				'sale_price'    => array( 'saleprice', 'sale', 'special', 'offer', 'discount', 'promo' ),
				'stock'         => array( 'stock', 'stockquantity', 'qty', 'quantity', 'inventory' ),
				'sku'           => array( 'sku', 'code', 'productcode', 'itemcode', 'reference', 'ref' ),
				'regular_price' => array( 'regularprice', 'price', 'retail', 'retailprice' ),
			),
			'csv_not'         => array( 'sales', 'lowstock', 'backorder', 'barcode', 'ean', 'upc', 'gtin', 'mpn', 'status', 'instock', 'cost', 'wholesale', 'date' ),
		);
	}
}
