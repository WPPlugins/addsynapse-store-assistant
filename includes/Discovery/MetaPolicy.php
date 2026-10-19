<?php
/**
 * What product meta the AI may see (read-only): noise, secrets, size caps.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless rules of discovery (read only — this plugin never writes custom fields).
 *
 *  - NOISE: editor/housekeeping keys that tell nothing about the product → dropped.
 *  - SENSITIVE: download files, secrets, tokens, licences, sessions → value redacted
 *    (the key stays visible so the AI knows it exists). These are NEVER writable.
 *  - Large values (long strings, big arrays/serialized blobs) → summarized.
 */
final class MetaPolicy {

	/**
	 * Longest scalar value returned as-is (characters).
	 */
	public const MAX_VALUE_CHARS = 500;

	/**
	 * Largest array/object value returned as-is (JSON bytes).
	 */
	public const MAX_STRUCT_BYTES = 1500;

	/**
	 * Placeholder for redacted values.
	 */
	public const REDACTED = '[redacted]';

	/**
	 * Housekeeping keys (exact).
	 */
	private const NOISE_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',
		'_encloseme',
		'_pingme',
		'_thumbnail_id',
		'_wp_page_template',
		'_wc_review_count',
		'_wc_rating_count',
		'_wc_average_rating',
		'_product_version',
		'_transient_timeout',
	);

	/**
	 * Housekeeping key prefixes.
	 */
	private const NOISE_PREFIXES = array( '_oembed_', '_transient_', '_wp_trash_', '_edit_', '_elementor_css', '_elementor_page_assets' );

	/**
	 * Sensitive keys (exact). Download files expose protected file URLs.
	 */
	private const SENSITIVE_KEYS = array(
		'_downloadable_files',
		'_download_limit',
		'_download_expiry',
		'_product_url',
		'_wc_memberships_force_public',
	);

	/**
	 * Substrings that make any key sensitive (secrets, sessions, licences).
	 */
	private const SENSITIVE_PATTERN = '/(pass(word|wd)?|secret|token|api[_-]?key|auth|nonce|session|licen[cs]e|private[_-]?key|credential|signature|hash)/i';

	/**
	 * Whether a meta key is housekeeping noise.
	 *
	 * @param string $key Meta key.
	 */
	public static function is_noise( string $key ): bool {
		if ( in_array( $key, self::NOISE_KEYS, true ) ) {
			return true;
		}
		foreach ( self::NOISE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a meta key holds sensitive data (value never shown, never writable).
	 *
	 * @param string $key Meta key.
	 */
	public static function is_sensitive( string $key ): bool {
		return in_array( $key, self::SENSITIVE_KEYS, true ) || 1 === preg_match( self::SENSITIVE_PATTERN, $key );
	}

	/**
	 * WooCommerce-managed keys (edited through WC setters, never as raw meta).
	 *
	 * @param string $key Meta key.
	 */
	public static function is_wc_internal( string $key ): bool {
		static $internal = null;
		if ( null === $internal ) {
			$internal = array(
				'_sku',
				'_global_unique_id',
				'_price',
				'_regular_price',
				'_sale_price',
				'_sale_price_dates_from',
				'_sale_price_dates_to',
				'total_sales',
				'_tax_status',
				'_tax_class',
				'_manage_stock',
				'_stock',
				'_stock_status',
				'_backorders',
				'_low_stock_amount',
				'_sold_individually',
				'_weight',
				'_length',
				'_width',
				'_height',
				'_upsell_ids',
				'_crosssell_ids',
				'_purchase_note',
				'_default_attributes',
				'_virtual',
				'_downloadable',
				'_product_image_gallery',
				'_product_attributes',
				'_variation_description',
				'_original_id',
				'_cogs_total_value',
			);
		}//end if
		return in_array( $key, $internal, true ) || 0 === strpos( $key, 'attribute_' );
	}

	/**
	 * Classifies a key: noise | sensitive | wc_internal | custom.
	 *
	 * @param string $key Meta key.
	 */
	public static function kind( string $key ): string {
		if ( self::is_noise( $key ) ) {
			return 'noise';
		}
		if ( self::is_sensitive( $key ) ) {
			return 'sensitive';
		}
		if ( self::is_wc_internal( $key ) ) {
			return 'wc_internal';
		}
		return 'custom';
	}

	/**
	 * A meta value made safe and small for the AI.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value (already unserialized by WP/WC).
	 * @return array{value:mixed,type:string,redacted?:bool,summarized?:string}
	 */
	public static function describe( string $key, $value ): array {
		$type = self::type_of( $value );
		if ( self::is_sensitive( $key ) ) {
			return array(
				'value'    => self::REDACTED,
				'type'     => $type,
				'redacted' => true,
			);
		}
		if ( is_object( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		if ( is_array( $value ) ) {
			$json = (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( strlen( $json ) > self::MAX_STRUCT_BYTES ) {
				return array(
					'value'      => null,
					'type'       => $type,
					'summarized' => sprintf( '%s with %d items, %d bytes (too large to show)', $type, count( $value ), strlen( $json ) ),
				);
			}
			return array(
				'value' => self::redact_nested( $value ),
				'type'  => $type,
			);
		}
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				return array(
					'value'      => null,
					'type'       => 'serialized',
					'summarized' => sprintf( 'serialized data, %d bytes (not shown)', strlen( $value ) ),
				);
			}
			if ( mb_strlen( $value ) > self::MAX_VALUE_CHARS ) {
				return array(
					'value'      => mb_substr( $value, 0, self::MAX_VALUE_CHARS ) . '…',
					'type'       => $type,
					'summarized' => sprintf( 'text of %d characters, truncated', mb_strlen( $value ) ),
				);
			}
		}
		return array(
			'value' => $value,
			'type'  => $type,
		);
	}

	/**
	 * Redacts sensitive-looking keys inside a (small) array value.
	 *
	 * @param array<mixed> $value Value.
	 * @return array<mixed>
	 */
	private static function redact_nested( array $value ): array {
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && self::is_sensitive( $k ) ) {
				$value[ $k ] = self::REDACTED;
			} elseif ( is_array( $v ) ) {
				$value[ $k ] = self::redact_nested( $v );
			}
		}
		return $value;
	}

	/**
	 * Simple type name of a value.
	 *
	 * @param mixed $value Value.
	 */
	public static function type_of( $value ): string {
		if ( null === $value || '' === $value ) {
			return 'empty';
		}
		if ( is_bool( $value ) ) {
			return 'bool';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 'number';
		}
		if ( is_array( $value ) ) {
			return array_is_list( $value ) ? 'list' : 'object';
		}
		if ( is_object( $value ) ) {
			return 'object';
		}
		$value = (string) $value;
		if ( is_serialized( $value ) ) {
			return 'serialized';
		}
		if ( is_numeric( $value ) ) {
			return 'number';
		}
		if ( in_array( strtolower( $value ), array( 'yes', 'no', 'true', 'false', 'on', 'off' ), true ) ) {
			return 'bool';
		}
		if ( preg_match( '/^field_[0-9a-f]{6,}$/D', $value ) ) {
			return 'acf_reference';
		}
		if ( preg_match( '/^[\[{]/', $value ) && null !== json_decode( $value ) ) {
			return 'json';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) || preg_match( '/^\d{8}$/', $value ) ) {
			return 'date';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return 'url';
		}
		if ( preg_match( '/<[a-z][^>]*>/i', $value ) ) {
			return 'html';
		}
		return 'string';
	}
}
