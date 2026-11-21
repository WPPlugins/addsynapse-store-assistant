<?php
/**
 * Abilities (1.3.15): measurement codes and scripts added by the AI — read what the site has (ours, and codes other
 * plugins / the theme already add), and compute a draft. The AI sends only a provider + ID for a measurement code;
 * the code is built by the site. Custom JavaScript only while the owner turned it on in wp-admin, checked by JsGuard.
 * The plan ability writes nothing: AddSynapse keeps the draft and, after the owner approved it, sends the units to
 * apply-changes (DesignUnits → TrackingUnits). Only offered to the remote route; works with or without WooCommerce.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\Tracking\Detector;
use Addsynapse\StoreAssistant\Tracking\JsGuard;
use Addsynapse\StoreAssistant\Tracking\Providers;
use Addsynapse\StoreAssistant\Tracking\TrackingStore;
use Addsynapse\StoreAssistant\Tracking\TrackingUnits;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities describe-tracking and plan-tracking.
 */
final class TrackingAbilities {

	public const DESCRIBE = 'addsynapse-assistant/describe-tracking';
	public const PLAN     = 'addsynapse-assistant/plan-tracking';

	/** Tools of plan-tracking. */
	public const TOOLS = array( 'tracking_code', 'custom_script' );

	/**
	 * Registers the abilities.
	 */
	public function register(): void {
		$readonly = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => false,
		);
		wp_register_ability(
			self::DESCRIBE,
			array(
				'label'               => __( 'Describe the measurement codes and scripts', 'addsynapse-store-assistant' ),
				'description'         => 'Measurement codes (Google Analytics 4, Tag Manager, Google Ads, Meta Pixel, Microsoft Clarity, TikTok Pixel) and scripts added through AddSynapse, codes other plugins or the theme already add, the cookie banner, and whether JavaScript from the AI is allowed. Read-only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'check_page' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'describe' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $readonly,
			)
		);
		wp_register_ability(
			self::PLAN,
			array(
				'label'               => __( 'Preview a measurement code or script change', 'addsynapse-store-assistant' ),
				'description'         => 'Computes a measurement code change (provider + ID; the site builds the official code) or a script change (checked JavaScript, only while allowed in wp-admin). Writes nothing.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'tool', 'args' ),
					'properties'           => array(
						'tool' => array(
							'type' => 'string',
							'enum' => self::TOOLS,
						),
						'args' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
				'execute_callback'    => array( $this, 'plan' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $readonly,
			)
		);
	}

	/**
	 * Execute callback of describe-tracking.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>
	 */
	public function describe( $input = null ) {
		$input     = is_array( $input ) ? $input : array();
		$codes     = TrackingStore::codes();
		$cmp       = Detector::cmp();
		$providers = array();
		foreach ( Providers::ALL as $key => $info ) {
			$providers[] = array(
				'provider' => $key,
				'name'     => $info['name'],
				'example'  => $info['example'],
			);
		}
		$ours = array();
		foreach ( $codes as $provider => $id ) {
			$ours[] = array(
				'provider' => $provider,
				'name'     => Providers::name( $provider ),
				'id'       => $id,
			);
		}
		$scripts = array();
		foreach ( TrackingStore::scripts() as $key => $script ) {
			$problem   = JsGuard::check( $script['code'], TrackingStore::js_domains(), TrackingStore::own_host() );
			$scripts[] = array(
				'key'                 => $key,
				'label'               => $script['label'],
				'about'               => $script['about'],
				'pages'               => $script['pages'],
				'runs_on'             => TrackingUnits::pages_text( $script['pages'] ),
				'running'             => TrackingStore::js_allowed() && '' === $problem,
				'not_running_because' => ! TrackingStore::js_allowed() ? 'JavaScript from the AI is turned off in wp-admin.' : $problem,
				'code'                => $script['code'],
			);
		}
		$scan = Detector::scan( false !== ( $input['check_page'] ?? true ) );
		return array(
			'_data'         => Shared::DATA_NOTICE,
			'codes'         => $ours,
			'providers'     => $providers,
			'other_codes'   => $scan,
			'cookie_banner' => null === $cmp ? array(
				'found' => false,
				'note'  => 'No cookie banner (consent tool) found. In the EU, measurement and advertising codes need the visitor\'s consent: suggest a cookie banner plugin.',
			) : array(
				'found'       => true,
				'name'        => $cmp['name'],
				'consent_api' => $cmp['consent_api'],
			),
			'javascript'    => array(
				'allowed'         => TrackingStore::js_allowed(),
				'allowed_domains' => TrackingStore::js_domains(),
				'how_to_allow'    => TrackingStore::js_allowed() ? '' : TrackingUnits::SWITCH_OFF,
				'never_runs_on'   => 'checkout, cart, my account (and their endpoints), login / registration, wp-admin',
				'max_bytes'       => JsGuard::MAX_BYTES,
			),
			'scripts'       => $scripts,
		);
	}

	/**
	 * Execute callback of plan-tracking: {status draft|nothing_to_change, summary, units[], warnings[]}.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function plan( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'addsynapse_assistant_tracking_cap', 'Measurement codes and scripts need an administrator (manage_options).' );
		}
		$args = (array) ( $input['args'] ?? array() );
		return 'custom_script' === ( $input['tool'] ?? '' ) ? $this->plan_script( $args ) : $this->plan_codes( $args );
	}

	/**
	 * Measurement codes: args {codes: [{provider, id} | {provider, remove: true}]} (1–6).
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function plan_codes( array $args ) {
		$list = $args['codes'] ?? null;
		if ( null === $list && isset( $args['provider'] ) ) {
			$list = array( $args );
		}
		if ( ! is_array( $list ) || ! $list || count( $list ) > count( Providers::ALL ) ) {
			return new \WP_Error( 'addsynapse_assistant_tracking_input', 'codes is a list of 1–6 items: {provider, id} to add or change, {provider, remove: true} to remove.' );
		}
		$now   = TrackingStore::codes();
		$after = $now;
		$seen  = array();
		foreach ( array_values( $list ) as $n => $item ) {
			$provider = is_array( $item ) ? (string) ( $item['provider'] ?? '' ) : '';
			if ( ! Providers::known( $provider ) ) {
				return new \WP_Error( 'addsynapse_assistant_tracking_provider', sprintf( 'Item %1$d: «%2$s» is not a supported provider. Supported: %3$s.', $n + 1, $provider, implode( ', ', array_keys( Providers::ALL ) ) ) );
			}
			if ( isset( $seen[ $provider ] ) ) {
				return new \WP_Error( 'addsynapse_assistant_tracking_input', sprintf( 'Item %d: %s is named twice.', $n + 1, Providers::name( $provider ) ) );
			}
			$seen[ $provider ] = true;
			if ( ! empty( $item['remove'] ) ) {
				unset( $after[ $provider ] );
				continue;
			}
			$id = Providers::normalize( $provider, $item['id'] ?? null );
			if ( '' === $id ) {
				return new \WP_Error( 'addsynapse_assistant_tracking_id', sprintf( 'Item %1$d: %2$s', $n + 1, Providers::id_problem( $provider, $item['id'] ?? null ) ) );
			}
			$after[ $provider ] = $id;
		}
		$scan     = Detector::scan( true );
		$units    = array();
		$warnings = array();
		$changes  = array();
		foreach ( array_keys( $seen ) as $provider ) {
			$before = $now[ $provider ] ?? null;
			$new    = $after[ $provider ] ?? null;
			if ( $before === $new ) {
				continue;
			}
			$consent = null === $new ? '' : Detector::consent_note( $provider );
			if ( null !== $new ) {
				$warnings = array_merge( $warnings, Detector::warnings( $provider, $new, $scan, $after ) );
			}
			$changes[] = null === $new ? 'remove ' . Providers::name( $provider ) : ( null === $before ? 'add ' : 'change ' ) . Providers::name( $provider ) . ' ' . $new;
			$units[]   = array(
				'kind'    => 'tracking',
				'id'      => 0,
				'key'     => $provider,
				'name'    => Providers::name( $provider ) . ' code',
				'before'  => array( 'id' => $before ),
				'after'   => array( 'id' => $new ),
				'preview' => TrackingUnits::code_rows( $provider, $before, $new, $consent ),
			);
		}//end foreach
		if ( $units && ! $this->has_cache_purge() ) {
			$warnings[] = 'If the site uses a page cache that is not WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed or SiteGround, empty it after Approve so the change shows at once.';
		}
		return array(
			'status'    => $units ? 'draft' : 'nothing_to_change',
			'operation' => 'tracking_code',
			'summary'   => $units ? 'Measurement codes: ' . implode( '; ', $changes ) . '.' : 'The site already has exactly these measurement codes.',
			'_data'     => Shared::DATA_NOTICE,
			'units'     => $units,
			'warnings'  => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * A script: args {label, about, code, pages: "all" | "front_page" | [ids], key?} or {key, remove: true}.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function plan_script( array $args ) {
		$scripts = TrackingStore::scripts();
		$key     = isset( $args['key'] ) ? (string) $args['key'] : TrackingStore::key_of( (string) ( $args['label'] ?? '' ) );
		if ( ! TrackingStore::valid_key( $key ) ) {
			return new \WP_Error( 'addsynapse_assistant_script_input', 'key: lower-case letters, digits and dashes (from describe_tracking scripts).' );
		}
		$before = $scripts[ $key ] ?? null;
		if ( ! empty( $args['remove'] ) ) {
			if ( null === $before ) {
				return new \WP_Error( 'addsynapse_assistant_script_input', sprintf( 'There is no script «%s» on this site (see describe_tracking → scripts).', $key ) );
			}
			return $this->script_result( $key, $before, null, array() );
		}
		if ( ! TrackingStore::js_allowed() ) {
			TrackingStore::log_refusal( (string) ( $args['label'] ?? $key ), 'JavaScript from the AI is turned off.' );
			return new \WP_Error( 'addsynapse_assistant_js_off', TrackingUnits::SWITCH_OFF );
		}
		$after   = TrackingStore::shape(
			array(
				'label' => sanitize_text_field( (string) ( $args['label'] ?? '' ) ),
				'about' => sanitize_textarea_field( (string) ( $args['about'] ?? '' ) ),
				'pages' => $args['pages'] ?? 'all',
				'code'  => is_string( $args['code'] ?? null ) ? $args['code'] : '',
			)
		);
		$problem = TrackingUnits::script_problem( $after );
		if ( '' === $problem && null === $before && count( $scripts ) >= TrackingStore::MAX_SCRIPTS ) {
			$problem = sprintf( 'This site already has %d scripts added by the AI (the most).', TrackingStore::MAX_SCRIPTS );
		}
		if ( '' !== $problem ) {
			TrackingStore::log_refusal( $after['label'], $problem );
			return new \WP_Error( 'addsynapse_assistant_script_refused', 'The script was refused, nothing was planned: ' . $problem );
		}
		if ( null !== $before && wp_json_encode( $before ) === wp_json_encode( $after ) ) {
			return array(
				'status'  => 'nothing_to_change',
				'summary' => 'This script is already on the site exactly like this.',
				'units'   => array(),
			);
		}
		$warnings = array();
		if ( '' === $after['about'] ) {
			$warnings[] = 'The script has no description: say in plain words what it does.';
		}
		return $this->script_result( $key, $before, $after, $warnings );
	}

	/**
	 * The draft of one script.
	 *
	 * @param string                   $key      Key.
	 * @param array<string,mixed>|null $before   Before.
	 * @param array<string,mixed>|null $after    After.
	 * @param array<int,string>        $warnings Warnings.
	 * @return array<string,mixed>
	 */
	private function script_result( string $key, ?array $before, ?array $after, array $warnings ): array {
		$label = (string) ( $after['label'] ?? $before['label'] ?? $key );
		$verb  = null === $after ? 'Remove' : ( null === $before ? 'Add' : 'Change' );
		return array(
			'status'    => 'draft',
			'operation' => 'custom_script',
			'summary'   => sprintf( '%1$s the script «%2$s»%3$s.', $verb, $label, null === $after ? '' : ', on ' . TrackingUnits::pages_text( $after['pages'] ) ),
			'_data'     => Shared::DATA_NOTICE,
			'units'     => array(
				array(
					'kind'    => 'script',
					'id'      => 0,
					'key'     => $key,
					'name'    => 'Script: ' . $label,
					'before'  => array( 'script' => null === $before ? null : (string) wp_json_encode( $before ) ),
					'after'   => array( 'script' => null === $after ? null : (string) wp_json_encode( $after ) ),
					'preview' => TrackingUnits::script_rows( $before, $after ),
				),
			),
			'warnings'  => $warnings,
		);
	}

	/**
	 * Whether one of the page caches we can empty is active.
	 */
	private function has_cache_purge(): bool {
		return function_exists( 'rocket_clean_domain' ) || function_exists( 'w3tc_flush_all' ) || function_exists( 'wp_cache_clear_cache' )
			|| function_exists( 'sg_cachepress_purge_cache' ) || has_action( 'litespeed_purge_all' ) || ! self::page_cache_suspected();
	}

	/**
	 * A page cache is likely (advanced-cache.php drop-in or WP_CACHE).
	 */
	private static function page_cache_suspected(): bool {
		return ( defined( 'WP_CACHE' ) && WP_CACHE ) || file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	}
}
