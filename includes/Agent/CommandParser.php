<?php
/**
 * Slash-command parser: "/search παπούτσια stock=outofstock" → ability + input.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

use Addsynapse\StoreAssistant\I18n\Locales;
use Addsynapse\StoreAssistant\Abilities\DescribeMenusAbility;
use Addsynapse\StoreAssistant\Abilities\DescribePageAbility;
use Addsynapse\StoreAssistant\Abilities\FindMediaAbility;
use Addsynapse\StoreAssistant\Abilities\DescribeStoreAbility;
use Addsynapse\StoreAssistant\Abilities\FindTermsAbility;
use Addsynapse\StoreAssistant\Abilities\GetProductAbility;
use Addsynapse\StoreAssistant\Abilities\GetProductRawAbility;
use Addsynapse\StoreAssistant\Abilities\ListOrdersAbility;
use Addsynapse\StoreAssistant\Abilities\CustomerReportAbility;
use Addsynapse\StoreAssistant\Abilities\PlanPriceUpdateAbility;
use Addsynapse\StoreAssistant\Abilities\PlanStockUpdateAbility;
use Addsynapse\StoreAssistant\Abilities\PlanBrandAssignAbility;
use Addsynapse\StoreAssistant\Abilities\PlanProductUpdateAbility;
use Addsynapse\StoreAssistant\Abilities\PlanTaxonomyTermsAbility;
use Addsynapse\StoreAssistant\Abilities\ProductReportAbility;
use Addsynapse\StoreAssistant\Abilities\SearchProductsAbility;
use Addsynapse\StoreAssistant\Abilities\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Pure parser (no side effects). Returns:
 *  - null                                        not a command (normal chat),
 *  - {type:help}                                 /help,
 *  - {type:ability, command, ability, input}     a tool call,
 *  - {type:error, message}                       a command with bad arguments.
 *
 * Syntax: positional words plus key=value options; values with spaces go in double
 * quotes (cat="Αξεσουάρ προπόνησης"). Limits are clamped to 1..100.
 */
final class CommandParser {

	/**
	 * Commands handled here (anything else starting with "/" is unknown).
	 */
	public const COMMANDS = array( 'help', 'search', 'product', 'report', 'orders', 'customers', 'store', 'raw', 'terms', 'price', 'stock', 'terms-set', 'brand', 'menus', 'page', 'media', 'product-set' );

	/**
	 * Report aliases → report.
	 */
	private const REPORT_ALIASES = array(
		'low'                 => 'low_stock',
		'low_stock'           => 'low_stock',
		'lowstock'            => 'low_stock',
		'out'                 => 'out_of_stock',
		'out_of_stock'        => 'out_of_stock',
		'outofstock'          => 'out_of_stock',
		'noimage'             => 'missing_image',
		'images'              => 'missing_image',
		'missing_image'       => 'missing_image',
		'nodesc'              => 'missing_description',
		'descriptions'        => 'missing_description',
		'missing_description' => 'missing_description',
		'noprice'             => 'missing_price',
		'prices'              => 'missing_price',
		'missing_price'       => 'missing_price',
		'best'                => 'best_sellers',
		'top'                 => 'best_sellers',
		'bestsellers'         => 'best_sellers',
		'best_sellers'        => 'best_sellers',
		'sales'               => 'sales_summary',
		'sales_summary'       => 'sales_summary',
	);

	/**
	 * Stock aliases → stock_status.
	 */
	private const STOCK_ALIASES = array(
		'instock'     => 'instock',
		'in'          => 'instock',
		'outofstock'  => 'outofstock',
		'out'         => 'outofstock',
		'onbackorder' => 'onbackorder',
		'backorder'   => 'onbackorder',
	);

	/**
	 * Parses a chat message.
	 *
	 * @param string $text Message.
	 * @return array<string,mixed>|null
	 */
	public static function parse( string $text ): ?array {
		$text = trim( $text );
		if ( ! preg_match( '/^\/([a-z_]+(?:-[a-z_]+)*)(?:\s+(.*))?$/isu', $text, $m ) ) {
			return null;
		}
		$command = strtolower( $m[1] );
		if ( ! in_array( $command, self::COMMANDS, true ) ) {
			return self::error(
				sprintf(
					/* translators: %s: the command the user typed, e.g. /foo */
					__( 'Unknown command %s.', 'addsynapse-store-assistant' ),
					'`/' . $command . '`'
				)
			);
		}
		if ( 'help' === $command ) {
			return array( 'type' => 'help' );
		}
		if ( 'product-set' === $command ) {
			list( $words, $options ) = self::tokenize( (string) ( $m[2] ?? '' ) );
			return self::parse_product_set( $words, $options );
		}
		if ( 'terms-set' === $command || 'brand' === $command ) {
			// Order matters here: selectors come before the mode word, the target after it.
			$tokens = self::tokens( (string) ( $m[2] ?? '' ) );
			return 'brand' === $command ? self::parse_brand( $tokens ) : self::parse_terms_set( $tokens );
		}
		list( $words, $options ) = self::tokenize( (string) ( $m[2] ?? '' ) );
		return call_user_func( array( self::class, 'parse_' . $command ), $words, $options );
	}

	/**
	 * Expands `id=@selection` / `ids=@selection` into the checked product ids.
	 *
	 * @param string         $text      Message.
	 * @param array<int,int> $selection Checked product ids.
	 * @return string|null The text (unchanged when it has no `@selection`), or null when it uses
	 *                     `@selection` but nothing is selected.
	 */
	public static function expand_selection( string $text, array $selection ): ?string {
		if ( ! preg_match( '/^\s*\//', $text ) || false === stripos( $text, '@selection' ) ) {
			return $text;
		}
		$ids = Selection::sanitize( $selection );
		if ( ! $ids ) {
			return null;
		}
		return (string) preg_replace( '/\b(ids?)=@selection\b/i', '$1=' . implode( ',', $ids ), $text );
	}

	/**
	 * Expands `=@upload` (the image uploaded in the chat) into its attachment id — e.g.
	 * `/product-set id=423 image=@upload`,
	 * `/content 405 img1.image=@upload`.
	 *
	 * @param string $text     Message.
	 * @param int    $image_id Attachment id (0 = none known).
	 * @return string|null The text (unchanged without `@upload`), or null when it uses `@upload`
	 *                     but no image was uploaded.
	 */
	public static function expand_image( string $text, int $image_id ): ?string {
		if ( ! preg_match( '/^\s*\//', $text ) || ! preg_match( '/=@(upload|image)\b/i', $text ) ) {
			return $text;
		}
		if ( $image_id < 1 ) {
			return null;
		}
		return (string) preg_replace( '/=@(upload|image)\b/i', '=' . $image_id, $text );
	}

	/**
	 * Splits arguments into positional words and key=value options.
	 *
	 * @param string $args Arguments.
	 * @return array{0:array<int,string>,1:array<string,string>}
	 */
	public static function tokenize( string $args ): array {
		$words   = array();
		$options = array();
		preg_match_all( '/([a-z_]+)=(?:"([^"]*)"|(\S*))|"([^"]*)"|(\S+)/u', $args, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( '' !== ( $match[1] ?? '' ) ) {
				$options[ $match[1] ] = trim( '' !== ( $match[2] ?? '' ) ? $match[2] : (string) ( $match[3] ?? '' ) );
			} elseif ( isset( $match[5] ) && '' !== $match[5] ) {
				$words[] = $match[5];
			} elseif ( isset( $match[4] ) && '' !== $match[4] ) {
				$words[] = $match[4];
			}
		}
		return array( $words, $options );
	}

	/**
	 * /search <text> [cat= tag= min= max= stock= type= status= limit= page=].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_search( array $words, array $options ): array {
		$input = array();
		if ( $words ) {
			$input['query'] = mb_substr( implode( ' ', $words ), 0, 200 );
		}
		$map = array(
			'cat'          => 'category',
			'category'     => 'category',
			'tag'          => 'tag',
			'min'          => 'price_min',
			'price_min'    => 'price_min',
			'max'          => 'price_max',
			'price_max'    => 'price_max',
			'stock'        => 'stock_status',
			'stock_status' => 'stock_status',
			'type'         => 'type',
			'status'       => 'status',
			'limit'        => 'limit',
			'page'         => 'page',
			'q'            => 'query',
			'sku'          => 'query',
			'brand'        => 'brand',
		);
		if ( isset( $options['attr'] ) || isset( $options['attribute'] ) ) {
			$attribute = self::attribute_filter( (string) ( $options['attr'] ?? $options['attribute'] ) );
			if ( null === $attribute ) {
				return self::bad_value( isset( $options['attr'] ) ? 'attr' : 'attribute', (string) ( $options['attr'] ?? $options['attribute'] ) );
			}
			$input['attribute'] = $attribute;
			unset( $options['attr'], $options['attribute'] );
		}
		foreach ( array( 'sort', 'order' ) as $key ) {
			if ( ! isset( $options[ $key ] ) ) {
				continue;
			}
			$value = strtolower( $options[ $key ] );
			$valid = 'sort' === $key ? SearchProductsAbility::ORDERBY : array( 'asc', 'desc' );
			if ( ! in_array( $value, $valid, true ) ) {
				return self::bad_value( $key, $options[ $key ] );
			}
			$input[ 'sort' === $key ? 'orderby' : 'order' ] = $value;
			unset( $options[ $key ] );
		}
		foreach ( $options as $key => $value ) {
			if ( ! isset( $map[ $key ] ) ) {
				return self::unknown_option( 'search', $key );
			}
			$field = $map[ $key ];
			switch ( $field ) {
				case 'price_min':
				case 'price_max':
					$number = self::number( $value );
					if ( null === $number ) {
						return self::bad_value( $key, $value );
					}
					$input[ $field ] = $number;
					break;
				case 'stock_status':
					$stock = self::STOCK_ALIASES[ strtolower( $value ) ] ?? null;
					if ( null === $stock ) {
						return self::bad_value( $key, $value );
					}
					$input[ $field ] = $stock;
					break;
				case 'type':
					if ( ! in_array( $value, array( 'simple', 'variable', 'grouped', 'external' ), true ) ) {
						return self::bad_value( $key, $value );
					}
					$input[ $field ] = $value;
					break;
				case 'status':
					if ( ! in_array( $value, array( 'any', 'publish', 'draft', 'pending', 'private' ), true ) ) {
						return self::bad_value( $key, $value );
					}
					$input[ $field ] = $value;
					break;
				case 'limit':
					$input['limit'] = Shared::limit( (int) $value );
					break;
				case 'page':
					$input['page'] = max( 1, min( 1000, (int) $value ) );
					break;
				default:
					if ( '' !== $value ) {
						$input[ $field ] = mb_substr( $value, 0, 200 );
					}
			}//end switch
		}//end foreach
		return self::ability( 'search', SearchProductsAbility::NAME, $input );
	}

	/**
	 * /product <id|SKU>.
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_product( array $words, array $options ): array {
		foreach ( array_keys( $options ) as $key ) {
			if ( ! in_array( $key, array( 'id', 'sku' ), true ) ) {
				return self::unknown_option( 'product', $key );
			}
		}
		$ref = $options['id'] ?? $options['sku'] ?? implode( ' ', $words );
		$ref = ltrim( trim( $ref ), '#' );
		if ( '' === $ref ) {
			return self::error( __( 'Give a product id or SKU, e.g. /product 123 or /product SKU-1.', 'addsynapse-store-assistant' ) );
		}
		if ( ! isset( $options['sku'] ) && preg_match( '/^\d{1,18}$/', $ref ) ) {
			return self::ability( 'product', GetProductAbility::NAME, array( 'id' => (int) $ref ) );
		}
		return self::ability( 'product', GetProductAbility::NAME, array( 'sku' => mb_substr( $ref, 0, 100 ) ) );
	}

	/**
	 * /store [refresh].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_store( array $words, array $options ): array {
		if ( $options ) {
			return self::unknown_option( 'store', (string) array_key_first( $options ) );
		}
		$input = array();
		foreach ( $words as $word ) {
			if ( Locales::is( 'refresh', $word ) ) {
				$input['refresh'] = true;
			} else {
				return self::bad_value( 'store', $word );
			}
		}
		return self::ability( 'store', DescribeStoreAbility::NAME, $input );
	}

	/**
	 * /raw <id|SKU>.
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_raw( array $words, array $options ): array {
		$parsed = self::parse_product( $words, $options );
		if ( 'ability' !== $parsed['type'] ) {
			return 'error' === $parsed['type'] ? self::error( str_replace( '/product', '/raw', $parsed['message'] ) ) : $parsed;
		}
		return self::ability( 'raw', GetProductRawAbility::NAME, $parsed['input'] );
	}

	/**
	 * /terms <taxonomy> <query…> [limit=].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_terms( array $words, array $options ): array {
		$input = array();
		foreach ( $options as $key => $value ) {
			if ( 'limit' === $key ) {
				$input['limit'] = max( 1, min( 20, (int) $value ) );
			} elseif ( in_array( $key, array( 'tax', 'taxonomy' ), true ) ) {
				array_unshift( $words, $value );
			} else {
				return self::unknown_option( 'terms', $key );
			}
		}
		if ( count( $words ) < 2 ) {
			return self::error( __( 'Give a taxonomy and a name, e.g. /terms brand adidas or /terms pa_color red.', 'addsynapse-store-assistant' ) );
		}
		$input['taxonomy'] = mb_substr( (string) array_shift( $words ), 0, 100 );
		$input['query']    = mb_substr( implode( ' ', $words ), 0, 100 );
		return self::ability( 'terms', FindTermsAbility::NAME, $input );
	}

	/**
	 * /report <type> [period|threshold] [from= to= threshold= limit=].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_report( array $words, array $options ): array {
		$type   = strtolower( (string) array_shift( $words ) );
		$report = self::REPORT_ALIASES[ $type ] ?? null;
		if ( null === $report ) {
			return self::error(
				'' === $type
					? __( 'Which report? Try /report low_stock or /report sales 30d.', 'addsynapse-store-assistant' )
					/* translators: %s: report name the user typed */
					: sprintf( __( 'Unknown report %s.', 'addsynapse-store-assistant' ), '`' . $type . '`' )
			);
		}
		$input = array( 'report' => $report );
		$sales = in_array( $report, ProductReportAbility::SALES_REPORTS, true );
		foreach ( $words as $word ) {
			$period = Locales::lookup( 'periods', $word );
			if ( $sales && $period ) {
				$input['period'] = $period;
			} elseif ( 'low_stock' === $report && preg_match( '/^\d+$/', $word ) ) {
				$input['threshold'] = min( 100000, (int) $word );
			} else {
				return self::bad_value( $report, $word );
			}
		}
		foreach ( $options as $key => $value ) {
			$filter = self::report_filter( $input, $key, $value );
			if ( false !== $filter ) {
				if ( null !== $filter ) {
					return $filter;
				}
				continue;
			}
			if ( 'limit' === $key ) {
				$input['limit'] = Shared::limit( (int) $value );
			} elseif ( 'threshold' === $key && 'low_stock' === $report && preg_match( '/^\d+$/', $value ) ) {
				$input['threshold'] = min( 100000, (int) $value );
			} elseif ( $sales && in_array( $key, array( 'from', 'to', 'date_from', 'date_to', 'period' ), true ) ) {
				$error = self::apply_period_option( $input, $key, $value );
				if ( $error ) {
					return $error;
				}
			} else {
				return self::unknown_option( 'report', $key );
			}
		}//end foreach
		return self::ability( 'report', ProductReportAbility::NAME, $input );
	}

	/**
	 * /customers [period] [sort=spent|orders limit= from= to=].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_customers( array $words, array $options ): array {
		$input = array();
		foreach ( $words as $word ) {
			$period = Locales::lookup( 'periods', $word );
			if ( $period ) {
				$input['period'] = $period;
			} elseif ( in_array( strtolower( $word ), CustomerReportAbility::SORTS, true ) ) {
				$input['sort'] = strtolower( $word );
			} else {
				return self::bad_value( 'customers', $word );
			}
		}
		foreach ( $options as $key => $value ) {
			if ( 'limit' === $key ) {
				$input['limit'] = Shared::limit( (int) $value );
			} elseif ( 'sort' === $key ) {
				if ( ! in_array( strtolower( $value ), CustomerReportAbility::SORTS, true ) ) {
					return self::bad_value( $key, $value );
				}
				$input['sort'] = strtolower( $value );
			} elseif ( in_array( $key, array( 'from', 'to', 'date_from', 'date_to', 'period' ), true ) ) {
				$error = self::apply_period_option( $input, $key, $value );
				if ( $error ) {
					return $error;
				}
			} else {
				return self::unknown_option( 'customers', $key );
			}
		}
		return self::ability( 'customers', CustomerReportAbility::NAME, $input );
	}

	/**
	 * Product filters of /report: cat= tag= brand= attr=<attribute>:<value> ids=1,2,3.
	 *
	 * @param array<string,mixed> $input Input (by ref).
	 * @param string              $key   Option.
	 * @param string              $value Value.
	 * @return array<string,mixed>|false|null false = not a filter option; null = applied; array = error.
	 */
	private static function report_filter( array &$input, string $key, string $value ) {
		$map = array(
			'cat'      => 'category',
			'category' => 'category',
			'tag'      => 'tag',
			'brand'    => 'brand',
		);
		if ( isset( $map[ $key ] ) ) {
			if ( '' === trim( $value ) ) {
				return self::bad_value( $key, $value );
			}
			$input[ $map[ $key ] ] = mb_substr( trim( $value ), 0, 200 );
			return null;
		}
		if ( 'attr' === $key || 'attribute' === $key ) {
			$attribute = self::attribute_filter( $value );
			if ( null === $attribute ) {
				return self::bad_value( $key, $value );
			}
			$input['attribute'] = $attribute;
			return null;
		}
		if ( 'ids' === $key || 'id' === $key ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY ) ) ) ) );
			if ( ! $ids || count( $ids ) > 500 ) {
				return self::bad_value( $key, $value );
			}
			$input['product_ids'] = $ids;
			return null;
		}
		return false;
	}

	/**
	 * `pa_color:red` / `"Χρώμα:Κόκκινο"` → {name, value}.
	 *
	 * @param string $value Option value.
	 * @return array{name:string,value:string}|null
	 */
	private static function attribute_filter( string $value ): ?array {
		$parts = explode( ':', $value, 2 );
		if ( 2 !== count( $parts ) || '' === trim( $parts[0] ) || '' === trim( $parts[1] ) ) {
			return null;
		}
		return array(
			'name'  => mb_substr( trim( $parts[0] ), 0, 100 ),
			'value' => mb_substr( trim( $parts[1] ), 0, 200 ),
		);
	}

	/**
	 * /orders [status] [period] [status= from= to= min= max= limit= page=].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_orders( array $words, array $options ): array {
		$input = array();
		foreach ( $words as $word ) {
			$period = Locales::lookup( 'periods', $word );
			if ( $period ) {
				$input['period'] = $period;
			} elseif ( preg_match( '/^[a-z0-9_-]{1,30}$/', $word ) && ! isset( $input['status'] ) ) {
				$input['status'] = $word;
			} else {
				return self::bad_value( 'orders', $word );
			}
		}
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'status':
					if ( ! preg_match( '/^[a-z0-9_-]{1,30}$/', $value ) ) {
						return self::bad_value( $key, $value );
					}
					$input['status'] = $value;
					break;
				case 'min':
				case 'total_min':
				case 'max':
				case 'total_max':
					$number = self::number( $value );
					if ( null === $number ) {
						return self::bad_value( $key, $value );
					}
					$input[ in_array( $key, array( 'min', 'total_min' ), true ) ? 'total_min' : 'total_max' ] = $number;
					break;
				case 'limit':
					$input['limit'] = Shared::limit( (int) $value );
					break;
				case 'page':
					$input['page'] = max( 1, min( 1000, (int) $value ) );
					break;
				case 'from':
				case 'to':
				case 'date_from':
				case 'date_to':
				case 'period':
					$error = self::apply_period_option( $input, $key, $value );
					if ( $error ) {
						return $error;
					}
					break;
				default:
					return self::unknown_option( 'orders', $key );
			}//end switch
		}//end foreach
		return self::ability( 'orders', ListOrdersAbility::NAME, $input );
	}

	/**
	 * /price <+10% | -5% | +2 | -2 | set 19.90> [cat= tag= q= sku= id= stock= type= status= all target=regular|sale from= to= round=none|2dp|x.90|x.99].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_price( array $words, array $options ): array {
		$input = array();
		$error = self::plan_selectors( 'price', $options, $input, array( 'target', 'from', 'to', 'round', 'rounding' ) );
		if ( $error ) {
			return $error;
		}
		foreach ( array( 'target', 'from', 'to', 'round', 'rounding' ) as $key ) {
			if ( ! isset( $options[ $key ] ) ) {
				continue;
			}
			$value = $options[ $key ];
			if ( 'target' === $key ) {
				if ( ! in_array( $value, array( 'regular', 'sale' ), true ) ) {
					return self::bad_value( $key, $value );
				}
				$input['target'] = $value;
			} elseif ( 'from' === $key || 'to' === $key ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					return self::bad_value( $key, $value );
				}
				$input[ 'from' === $key ? 'sale_from' : 'sale_to' ] = $value;
			} else {
				$map = array(
					'none' => 'none',
					'2'    => '2dp',
					'2dp'  => '2dp',
					'90'   => 'x.90',
					'.90'  => 'x.90',
					'x.90' => 'x.90',
					'99'   => 'x.99',
					'.99'  => 'x.99',
					'x.99' => 'x.99',
				);
				if ( ! isset( $map[ $value ] ) ) {
					return self::bad_value( $key, $value );
				}
				$input['rounding'] = $map[ $value ];
			}//end if
		}//end foreach
		$set = false;
		foreach ( $words as $word ) {
			$lower = mb_strtolower( $word );
			if ( in_array( $lower, array( 'set', '=' ), true ) ) {
				$set = true;
			} elseif ( in_array( $lower, array( 'sale', 'regular' ), true ) ) {
				$input['target'] = $lower;
			} elseif ( 'all' === $lower ) {
				$input['all'] = true;
			} elseif ( preg_match( '/^([+-]?\d+(?:[.,]\d+)?)%$/', $word, $m ) && ! $set ) {
				$input['mode']  = 'percent';
				$input['value'] = (float) str_replace( ',', '.', $m[1] );
			} elseif ( preg_match( '/^[+-]\d+(?:[.,]\d+)?$/', $word ) && ! $set ) {
				$input['mode']  = 'fixed';
				$input['value'] = (float) str_replace( ',', '.', $word );
			} elseif ( $set && preg_match( '/^\d+(?:[.,]\d+)?$/', $word ) ) {
				$input['mode']  = 'set';
				$input['value'] = (float) str_replace( ',', '.', $word );
			} else {
				return self::bad_value( 'price', $word );
			}
		}//end foreach
		if ( ! isset( $input['mode'] ) ) {
			return self::error( __( 'Say the change: +10% / -5% (percent), +2 / -2 (amount) or set 19.90 (exact price). Example: /price cat=kreatines +10%', 'addsynapse-store-assistant' ) );
		}
		return self::ability( 'price', PlanPriceUpdateAbility::NAME, $input );
	}

	/**
	 * /stock <set 5 | adjust -2 | +3 | -2> [sku= id= cat= tag= q= stock= type= status= all items="SKU:5,SKU2:3" enable=yes].
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_stock( array $words, array $options ): array {
		$input = array();
		$error = self::plan_selectors( 'stock', $options, $input, array( 'items', 'enable' ) );
		if ( $error ) {
			return $error;
		}
		if ( isset( $options['enable'] ) ) {
			$input['enable_manage_stock'] = true === Locales::yes_no( $options['enable'] );
		}
		if ( isset( $options['items'] ) ) {
			$items = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $options['items'] ) ) ) as $pair ) {
				if ( ! preg_match( '/^(.+):([+-]?\d+)$/u', $pair, $m ) ) {
					return self::bad_value( 'items', $pair );
				}
				$ref     = trim( $m[1] );
				$items[] = ( preg_match( '/^#?\d{1,18}$/', $ref ) ? array( 'id' => (int) ltrim( $ref, '#' ) ) : array( 'sku' => mb_substr( $ref, 0, 100 ) ) ) + array( 'qty' => (int) $m[2] );
			}
			$input['items'] = array_slice( $items, 0, 500 );
		}
		$mode = '';
		foreach ( $words as $word ) {
			$lower = mb_strtolower( $word );
			if ( in_array( $lower, array( 'set', 'adjust' ), true ) ) {
				$mode = $lower;
			} elseif ( 'all' === $lower ) {
				$input['all'] = true;
			} elseif ( preg_match( '/^[+-]?\d+$/', $word ) ) {
				if ( '' === $mode ) {
					$mode = preg_match( '/^[+-]/', $word ) ? 'adjust' : 'set';
				}
				$input['value'] = (int) $word;
			} else {
				return self::bad_value( 'stock', $word );
			}
		}
		if ( '' === $mode || ( ! isset( $input['value'] ) && empty( $input['items'] ) ) ) {
			return self::error( __( 'Say the change: set 5 or adjust -2. Example: /stock sku=NUM-07 set 5', 'addsynapse-store-assistant' ) );
		}
		$input['mode'] = $mode;
		return self::ability( 'stock', PlanStockUpdateAbility::NAME, $input );
	}

	/**
	 * /page <id|slug|"title"|front|shop> — the elements of one page.
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_page( array $words, array $options ): array {
		foreach ( array_keys( $options ) as $key ) {
			return self::unknown_option( 'page', $key );
		}
		$arg = trim( implode( ' ', $words ) );
		if ( '' === $arg ) {
			return self::error( __( 'Say which page, e.g. /page front · /page shop · /page 42 · /page "About us"', 'addsynapse-store-assistant' ) );
		}
		$page = self::page_ref( $arg );
		return self::ability( 'page', DescribePageAbility::NAME, null !== $page ? $page : array( 'query' => mb_substr( $arg, 0, 200 ) ) );
	}

	/**
	 * /media [words] [product=1,2] [recent] [limit=]
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_media( array $words, array $options ): array {
		$input = array();
		foreach ( $options as $key => $value ) {
			if ( 'product' === $key || 'products' === $key ) {
				$ids = array_values( array_filter( array_map( 'intval', explode( ',', $value ) ) ) );
				if ( ! $ids ) {
					return self::bad_value( $key, $value );
				}
				$input['product_ids'] = array_slice( $ids, 0, 20 );
			} elseif ( 'limit' === $key ) {
				$input['limit'] = max( 1, min( 24, (int) $value ) );
			} else {
				return self::unknown_option( 'media', $key );
			}
		}
		$words = array_values(
			array_filter(
				$words,
				static function ( $word ) use ( &$input ) {
					if ( 'recent' === strtolower( $word ) ) {
						$input['recent'] = true;
						return false;
					}
					return true;
				}
			)
		);
		if ( $words ) {
			$input['query'] = mb_substr( implode( ' ', $words ), 0, 100 );
		}
		return self::ability( 'media', FindMediaAbility::NAME, $input );
	}

	/**
	 * Page words: id, front/home/αρχική, shop/κατάστημα (null = a title/slug to search).
	 *
	 * @param string $arg Argument.
	 * @return array<string,mixed>|null
	 */
	private static function page_ref( string $arg ): ?array {
		if ( preg_match( '/^\d{1,18}$/', $arg ) && (int) $arg > 0 ) {
			return array( 'page_id' => (int) $arg );
		}
		if ( Locales::is( 'page_front', $arg ) ) {
			return array( 'front_page' => true );
		}
		if ( Locales::is( 'page_shop', $arg ) ) {
			return array( 'shop_page' => true );
		}
		return null;
	}

	/**
	 * Ordered tokens: [key, value] for key=value, [null, word] for words.
	 *
	 * @param string $args Arguments.
	 * @return array<int,array{0:string|null,1:string}>
	 */
	public static function tokens( string $args ): array {
		$out = array();
		preg_match_all( '/([a-z_]+)=(?:"([^"]*)"|(\S*))|"([^"]*)"|(\S+)/u', $args, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( '' !== ( $match[1] ?? '' ) ) {
				$out[] = array( $match[1], trim( '' !== ( $match[2] ?? '' ) ? $match[2] : (string) ( $match[3] ?? '' ) ) );
			} elseif ( isset( $match[5] ) && '' !== $match[5] ) {
				$out[] = array( null, $match[5] );
			} elseif ( isset( $match[4] ) && '' !== $match[4] ) {
				$out[] = array( null, $match[4] );
			}
		}
		return $out;
	}

	/**
	 * Splits ordered tokens at the first mode word: selectors (+ title~X) before it, the
	 * rest after it. "--create" / "create" anywhere sets create_missing.
	 *
	 * @param string                                   $command Command.
	 * @param array<int,array{0:string|null,1:string}> $tokens  Tokens.
	 * @param array<int,string>                        $modes   Mode words.
	 * @return array{input:array<string,mixed>,mode:string,after:array<int,array{0:string|null,1:string}>}|array{type:string,message:string}
	 */
	private static function split_plan_tokens( string $command, array $tokens, array $modes ): array {
		$input   = array();
		$options = array();
		$mode    = '';
		$after   = array();
		foreach ( $tokens as $token ) {
			list( $key, $value ) = $token;
			$lower               = mb_strtolower( $value );
			if ( null === $key && in_array( $lower, array( '--create', 'create', '--new' ), true ) ) {
				$input['create_missing'] = true;
				continue;
			}
			if ( '' !== $mode ) {
				$after[] = $token;
				continue;
			}
			if ( null === $key && in_array( $lower, $modes, true ) ) {
				$mode = $lower;
			} elseif ( null === $key && preg_match( '/^(?:title|name|q)~(.+)$/u', $value, $m ) ) {
				$input['query'] = mb_substr( $m[1], 0, 200 );
			} elseif ( null === $key && 'all' === $lower ) {
				$input['all'] = true;
			} elseif ( null !== $key ) {
				$options[ $key ] = $value;
			} else {
				return self::bad_value( $command, $value );
			}
		}//end foreach
		$error = self::plan_selectors( $command, $options, $input, array() );
		if ( $error ) {
			return $error;
		}
		return array(
			'input' => $input,
			'mode'  => $mode,
			'after' => $after,
		);
	}

	/**
	 * /terms-set <selectors> <add|remove|replace> <cat=|tag=|brand=|pa_x=|tax=… terms=…> [--create].
	 * Example: /terms-set cat=proteines add tag=vegan,bio --create
	 *
	 * @param array<int,array{0:string|null,1:string}> $tokens Tokens.
	 * @return array<string,mixed>
	 */
	private static function parse_terms_set( array $tokens ): array {
		$usage = __( 'Say the products, the change and the terms. Example: /terms-set cat=proteines add tag=vegan (add | remove | replace; cat= tag= brand= pa_color= or tax= terms=; --create to allow new terms)', 'addsynapse-store-assistant' );
		$split = self::split_plan_tokens( 'terms-set', $tokens, array( 'add', 'remove', 'replace', 'set' ) );
		if ( isset( $split['type'] ) ) {
			return $split;
		}
		if ( '' === $split['mode'] ) {
			return self::error( $usage );
		}
		$input         = $split['input'];
		$input['mode'] = 'set' === $split['mode'] ? 'replace' : $split['mode'];
		$taxonomy      = '';
		$list          = '';
		foreach ( $split['after'] as $token ) {
			list( $key, $value ) = $token;
			if ( null === $key ) {
				return self::bad_value( 'terms-set', $value );
			}
			$map = array(
				'cat'      => 'product_cat',
				'category' => 'product_cat',
				'tag'      => 'product_tag',
				'brand'    => 'brand',
			);
			if ( 'tax' === $key || 'taxonomy' === $key ) {
				$taxonomy = $value;
			} elseif ( 'terms' === $key ) {
				$list = $value;
			} elseif ( isset( $map[ $key ] ) || 0 === strpos( $key, 'pa_' ) ) {
				$taxonomy = $map[ $key ] ?? $key;
				$list     = $value;
			} else {
				return self::unknown_option( 'terms-set', $key );
			}
		}//end foreach
		if ( '' === $taxonomy || '' === trim( $list ) ) {
			return self::error( $usage );
		}
		$input['taxonomy'] = mb_substr( $taxonomy, 0, 100 );
		foreach ( array_filter( array_map( 'trim', explode( ',', $list ) ), 'strlen' ) as $term ) {
			if ( preg_match( '/^#?\d{1,18}$/', $term ) ) {
				$input['term_ids'][] = (int) ltrim( $term, '#' );
			} else {
				$input['term_names'][] = mb_substr( $term, 0, 100 );
			}
		}
		return self::ability( 'terms-set', PlanTaxonomyTermsAbility::NAME, $input );
	}

	/**
	 * /brand <selectors> <set|add|remove> <brand name|id> [--create].
	 * Example: /brand title~Adidas set Adidas --create
	 *
	 * @param array<int,array{0:string|null,1:string}> $tokens Tokens.
	 * @return array<string,mixed>
	 */
	private static function parse_brand( array $tokens ): array {
		$usage = __( 'Say the products, the change and the brand. Example: /brand title~Adidas set Adidas (set | add | remove; --create to allow a new brand)', 'addsynapse-store-assistant' );
		$split = self::split_plan_tokens( 'brand', $tokens, array( 'set', 'add', 'remove' ) );
		if ( isset( $split['type'] ) ) {
			return $split;
		}
		$words = array();
		foreach ( $split['after'] as $token ) {
			if ( null !== $token[0] ) {
				return self::unknown_option( 'brand', $token[0] );
			}
			$words[] = $token[1];
		}
		$name = trim( implode( ' ', $words ) );
		if ( '' === $split['mode'] || '' === $name ) {
			return self::error( $usage );
		}
		$input         = $split['input'];
		$input['mode'] = $split['mode'];
		if ( preg_match( '/^#?\d{1,18}$/', $name ) ) {
			$input['brand_id'] = (int) ltrim( $name, '#' );
		} else {
			$input['brand'] = mb_substr( $name, 0, 100 );
		}
		return self::ability( 'brand', PlanBrandAssignAbility::NAME, $input );
	}

	/**
	 * /product-set options → `set` keys of plan-product-update.
	 */
	private const PRODUCT_SET_KEYS = array(
		'name'              => 'name',
		'sku'               => 'sku',
		'status'            => 'status',
		'visibility'        => 'catalog_visibility',
		'featured'          => 'featured',
		'weight'            => 'weight',
		'length'            => 'length',
		'width'             => 'width',
		'height'            => 'height',
		'shipping'          => 'shipping_class',
		'shipping_class'    => 'shipping_class',
		'tax_status'        => 'tax_status',
		'tax_class'         => 'tax_class',
		'manage_stock'      => 'manage_stock',
		'low_stock'         => 'low_stock_amount',
		'backorders'        => 'backorders',
		'sold_individually' => 'sold_individually',
		'note'              => 'purchase_note',
		'purchase_note'     => 'purchase_note',
		'menu_order'        => 'menu_order',
		'virtual'           => 'virtual',
		'short'             => 'short_description',
		'desc'              => 'description',
		'description'       => 'description',
		'downloadable'      => 'downloadable',
		'slug'              => 'slug',
		'type'              => 'product_type',
	);

	/**
	 * /product-set <id=|ids=|skus=|cat=|tag=|q=> field=value … — a product details change.
	 * Example: /product-set id=423 name="Νέο όνομα" weight=0,5 featured=yes image=12
	 * (sku= SETS the SKU; select by SKU with skus=).
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_product_set( array $words, array $options ): array {
		$usage    = __( 'Say the product and the fields. Example: /product-set id=423 name="New name" image=12 (the product with id= or skus=; fields: name= short= desc= image= gallery_add=)', 'addsynapse-store-assistant' );
		$input    = array();
		$set      = array();
		$images   = array();
		$selector = array();
		foreach ( $options as $key => $value ) {
			if ( in_array( $key, array( 'id', 'ids', 'cat', 'category', 'tag', 'q', 'query', 'all' ), true ) ) {
				$selector[ $key ] = $value;
			} elseif ( 'skus' === $key ) {
				$selector['sku'] = $value;
			} elseif ( in_array( $key, array( 'image', 'featured_image' ), true ) ) {
				if ( ! preg_match( '/^#?\d{1,18}$/', $value ) ) {
					return self::bad_value( $key, $value );
				}
				$images['featured'] = (int) ltrim( $value, '#' );
			} elseif ( in_array( $key, array( 'gallery', 'gallery_add', 'gallery_remove' ), true ) ) {
				$ids = array();
				foreach ( array_filter( array_map( 'trim', explode( ',', $value ) ) ) as $id ) {
					if ( ! preg_match( '/^#?\d{1,18}$/', $id ) ) {
						return self::bad_value( $key, $id );
					}
					$ids[] = (int) ltrim( $id, '#' );
				}
				$images[ $key ] = array_slice( $ids, 0, 30 );
			} elseif ( isset( self::PRODUCT_SET_KEYS[ $key ] ) ) {
				$field = self::PRODUCT_SET_KEYS[ $key ];
				if ( in_array( $field, array( 'featured', 'manage_stock', 'sold_individually', 'virtual', 'downloadable' ), true ) ) {
					$bool = self::yes_no( $value );
					if ( null === $bool ) {
						return self::bad_value( $key, $value );
					}
					$set[ $field ] = $bool;
				} elseif ( 'menu_order' === $field ) {
					if ( ! preg_match( '/^-?\d{1,6}$/', $value ) ) {
						return self::bad_value( $key, $value );
					}
					$set[ $field ] = (int) $value;
				} elseif ( 'low_stock_amount' === $field ) {
					if ( in_array( strtolower( $value ), array( '', 'default', 'none' ), true ) ) {
						$set[ $field ] = null;
					} elseif ( preg_match( '/^\d{1,9}$/', $value ) ) {
						$set[ $field ] = (int) $value;
					} else {
						return self::bad_value( $key, $value );
					}
				} else {
					$set[ $field ] = $value;
				}//end if
			} else {
				return self::unknown_option( 'product-set', $key );
			}//end if
		}//end foreach
		if ( $words ) {
			return self::error( $usage );
		}
		$error = self::plan_selectors( 'product-set', $selector, $input, array() );
		if ( $error ) {
			return $error;
		}
		if ( ! $set && ! $images ) {
			return self::error( $usage );
		}
		if ( $set ) {
			$input['set'] = $set;
		}
		if ( $images ) {
			$input['images'] = $images;
		}
		return self::ability( 'product-set', PlanProductUpdateAbility::NAME, $input );
	}

	/**
	 * Yes/no word → bool (null = not a yes/no).
	 *
	 * @param string $value Value.
	 */
	private static function yes_no( string $value ): ?bool {
		return Locales::yes_no( $value );
	}

	/**
	 * /menus [menu] — the menus, locations and item trees.
	 *
	 * @param array<int,string>    $words   Words.
	 * @param array<string,string> $options Options.
	 * @return array<string,mixed>
	 */
	private static function parse_menus( array $words, array $options ): array {
		$menu = trim( (string) ( $options['menu'] ?? implode( ' ', $words ) ) );
		return self::ability( 'menus', DescribeMenusAbility::NAME, '' !== $menu ? array( 'menu' => mb_substr( $menu, 0, 100 ) ) : array() );
	}

	/**
	 * Selector options shared by /price and /stock (sku= and id= accept comma lists).
	 *
	 * @param string               $command Command.
	 * @param array<string,string> $options Options.
	 * @param array<string,mixed>  $input   Input (by ref).
	 * @param array<int,string>    $extra   Other options the command handles itself.
	 * @return array<string,mixed>|null Error, or null.
	 */
	private static function plan_selectors( string $command, array $options, array &$input, array $extra ): ?array {
		$map = array(
			'cat'      => 'category',
			'category' => 'category',
			'tag'      => 'tag',
			'q'        => 'query',
			'query'    => 'query',
			'stock'    => 'stock_status',
			'type'     => 'type',
			'status'   => 'status',
		);
		foreach ( $options as $key => $value ) {
			if ( in_array( $key, $extra, true ) ) {
				continue;
			}
			if ( 'sku' === $key || 'skus' === $key ) {
				$input['skus'] = array_slice( array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) ), 0, 500 );
			} elseif ( 'id' === $key || 'ids' === $key ) {
				$ids = array();
				foreach ( array_filter( array_map( 'trim', explode( ',', $value ) ) ) as $id ) {
					if ( ! preg_match( '/^#?\d{1,18}$/', $id ) ) {
						return self::bad_value( $key, $id );
					}
					$ids[] = (int) ltrim( $id, '#' );
				}
				$input['ids'] = array_slice( $ids, 0, 500 );
			} elseif ( 'all' === $key ) {
				$input['all'] = in_array( strtolower( $value ), array( '1', 'yes', 'true' ), true );
			} elseif ( isset( $map[ $key ] ) ) {
				$field = $map[ $key ];
				if ( 'stock_status' === $field ) {
					$stock = self::STOCK_ALIASES[ strtolower( $value ) ] ?? null;
					if ( null === $stock ) {
						return self::bad_value( $key, $value );
					}
					$value = $stock;
				}
				if ( 'status' === $field && ! in_array( $value, array( 'any', 'publish', 'draft', 'pending', 'private' ), true ) ) {
					return self::bad_value( $key, $value );
				}
				if ( 'type' === $field && ! in_array( $value, array( 'simple', 'variable', 'external' ), true ) ) {
					return self::bad_value( $key, $value );
				}
				if ( '' !== $value ) {
					$input[ $field ] = mb_substr( $value, 0, 200 );
				}
			} else {
				return self::unknown_option( $command, $key );
			}//end if
		}//end foreach
		return null;
	}

	/**
	 * Applies from=/to=/period= options.
	 *
	 * @param array<string,mixed> $input Input (by ref).
	 * @param string              $key   Option.
	 * @param string              $value Value.
	 * @return array<string,mixed>|null Error, or null.
	 */
	private static function apply_period_option( array &$input, string $key, string $value ): ?array {
		if ( 'period' === $key ) {
			$period = Locales::lookup( 'periods', $value ) ?? ( 'custom' === $value ? 'custom' : null );
			if ( ! $period ) {
				return self::bad_value( $key, $value );
			}
			$input['period'] = $period;
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return self::bad_value( $key, $value );
		}
		$input[ in_array( $key, array( 'from', 'date_from' ), true ) ? 'date_from' : 'date_to' ] = $value;
		$input['period'] = 'custom';
		return null;
	}

	/**
	 * Parses a number ("12,5" allowed).
	 *
	 * @param string $value Value.
	 */
	private static function number( string $value ): ?float {
		$value = str_replace( ',', '.', trim( $value ) );
		return is_numeric( $value ) && (float) $value >= 0 ? (float) $value : null;
	}

	/**
	 * Ability result.
	 *
	 * @param string              $command Command.
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return array<string,mixed>
	 */
	private static function ability( string $command, string $ability, array $input ): array {
		return array(
			'type'    => 'ability',
			'command' => $command,
			'ability' => $ability,
			'input'   => $input,
		);
	}

	/**
	 * Error result.
	 *
	 * @param string $message Message.
	 * @return array{type:string,message:string}
	 */
	private static function error( string $message ): array {
		return array(
			'type'    => 'error',
			'message' => $message,
		);
	}

	/**
	 * Unknown option error.
	 *
	 * @param string $command Command.
	 * @param string $key     Option.
	 * @return array{type:string,message:string}
	 */
	private static function unknown_option( string $command, string $key ): array {
		return self::error(
			sprintf(
				/* translators: 1: option name, 2: command name */
				__( 'Unknown option %1$s for %2$s.', 'addsynapse-store-assistant' ),
				'`' . $key . '`',
				'`/' . $command . '`'
			)
		);
	}

	/**
	 * Bad value error.
	 *
	 * @param string $key   Option / context.
	 * @param string $value Value.
	 * @return array{type:string,message:string}
	 */
	private static function bad_value( string $key, string $value ): array {
		return self::error(
			sprintf(
				/* translators: 1: the value the user typed, 2: option or command name */
				__( 'Invalid value %1$s for %2$s.', 'addsynapse-store-assistant' ),
				'`' . str_replace( '`', '', mb_substr( $value, 0, 50 ) ) . '`',
				'`' . $key . '`'
			)
		);
	}
}
