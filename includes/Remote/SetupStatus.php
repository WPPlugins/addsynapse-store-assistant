<?php
/**
 * Setup page helpers: the connectivity self-check (a loopback request to this same site — nothing leaves the
 * server) and the «is AddSynapse connected / has it read the site» status that turns steps 3–4 green.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Self-check + connection status.
 */
final class SetupStatus {

	/** Result of the last self-check (forgotten on deactivation and uninstall). */
	public const CHECK_OPTION = 'addsynapse_assistant_connect_check';

	/**
	 * Runs the checks (wp-admin button).
	 *
	 * @return \WP_REST_Response
	 */
	public static function check() {
		$checks = array();
		// 1.3.4: AddSynapse connects with this plugin's own key (RelayKeys), so WordPress' Application Passwords (off by
		// default with Wordfence) are not needed any more; only HTTPS is (a `local` development site is the exception).
		$https    = 0 === strpos( home_url( '/' ), 'https://' ) || ( 'local' === wp_get_environment_type() && RelayKeys::transport_ok() );
		$checks[] = array(
			'id'        => 'https',
			'ok'        => $https,
			'label'     => __( 'The site uses HTTPS', 'addsynapse-store-assistant' ),
			'advice'    => $https ? '' : __( 'AddSynapse connects only to sites that use HTTPS. Switch the site address to https:// (Settings → General).', 'addsynapse-store-assistant' ),
			'fix_url'   => $https ? '' : admin_url( 'options-general.php' ),
			'fix_label' => $https ? '' : __( 'Open General Settings', 'addsynapse-store-assistant' ),
		);
		// WooCommerce is not required: a plain WordPress site connects too (site tools only) — a note, never a failed check.
		$has_wc   = Environment::has_woocommerce();
		$checks[] = array(
			'id'     => 'woocommerce',
			'ok'     => true,
			'note'   => ! $has_wc,
			'label'  => $has_wc ? __( 'WooCommerce is active', 'addsynapse-store-assistant' ) : __( 'WooCommerce is not active — site tools only', 'addsynapse-store-assistant' ),
			'advice' => $has_wc ? '' : __( 'You can connect this site: your AI reads its pages, posts and SEO. Product, order and coupon tools need WooCommerce.', 'addsynapse-store-assistant' ),
		);
		$response = wp_remote_get(
			rest_url( RemoteAccess::REST_NAMESPACE . '/remote/echo-auth' ),
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'headers'   => array(
					'Authorization'     => 'AddSynapseCheck 1',
					'X-AddSynapse-Auth' => 'AddSynapseCheck 1',
				),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body     = is_wp_error( $response ) ? array() : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body     = is_array( $body ) ? $body : array();
		$checks[] = array(
			'id'     => 'rest',
			'ok'     => 200 === $code,
			'label'  => __( 'The REST API answers', 'addsynapse-store-assistant' ),
			'advice' => 200 === $code ? '' : sprintf(
				/* translators: %s: HTTP status or error message */
				__( 'The site could not reach its own REST API (%s). A firewall or a «disable REST API» setting may block it.', 'addsynapse-store-assistant' ),
				is_wp_error( $response ) ? $response->get_error_message() : (string) $code
			),
		);
		// The connection key travels in AddSynapse's own header (X-AddSynapse-Auth); the standard one is not needed.
		$fallback = ! empty( $body['fallback'] );
		$checks[] = array(
			'id'     => 'auth_header',
			'ok'     => $fallback,
			'label'  => __( 'Sign-in header reaches WordPress', 'addsynapse-store-assistant' ),
			'advice' => $fallback ? '' : __( 'A firewall or your host removes the X-AddSynapse-Auth header. Ask your host to pass it to PHP.', 'addsynapse-store-assistant' ),
		);
		$ok       = true;
		foreach ( $checks as $c ) {
			$ok = $ok && $c['ok'];
		}
		update_option(
			self::CHECK_OPTION,
			array(
				'ok'   => $ok,
				'time' => time(),
			),
			false
		);
		return rest_ensure_response(
			array(
				'ok'     => $ok,
				'checks' => $checks,
			)
		);
	}

	/**
	 * Connection status (polled by the setup page).
	 *
	 * @return \WP_REST_Response
	 */
	public static function status() {
		return rest_ensure_response( self::connection() );
	}

	/**
	 * The newest AddSynapse connection key or application password of any user who can manage the shop.
	 *
	 * @return array<string,mixed>
	 */
	public static function connection(): array {
		$found   = null;
		$claim   = get_option( RelayClaim::CLAIMED_OPTION, array() );
		$claim   = is_array( $claim ) ? $claim : array();
		$claimed = false;
		foreach ( RelayKeys::listing() as $key ) {
			$user = get_userdata( $key['user'] );
			if ( ! $user instanceof \WP_User || ! user_can( $user, Config::capability() ) ) {
				continue;
			}
			if ( ! empty( $claim['uuid'] ) && hash_equals( (string) $claim['uuid'], RelayKeys::REF_PREFIX . $key['id'] ) ) {
				$claimed = true;
			}
			if ( null === $found || $key['created'] > (int) $found['created'] ) {
				$found = array(
					'created'   => $key['created'],
					'last_used' => $key['last_used'] > 0 ? $key['last_used'] : null,
					'name'      => 'AddSynapse',
					'user'      => (string) $user->user_login,
				);
			}
		}
		if ( class_exists( '\WP_Application_Passwords' ) ) {
			$users = get_users(
				array(
					'capability' => Config::capability(),
					'fields'     => array( 'ID', 'user_login' ),
					'number'     => 50,
				)
			);
			foreach ( $users as $u ) {
				foreach ( \WP_Application_Passwords::get_user_application_passwords( (int) $u->ID ) as $item ) {
					if ( ( $item['app_id'] ?? '' ) !== RemoteAccess::APP_ID ) {
						continue;
					}
					if ( ! empty( $claim['uuid'] ) && hash_equals( (string) $claim['uuid'], (string) ( $item['uuid'] ?? '' ) ) ) {
						$claimed = true;
					}
					if ( null === $found || (int) $item['created'] > (int) $found['created'] ) {
						$found = array(
							'created'   => (int) $item['created'],
							'last_used' => empty( $item['last_used'] ) ? null : (int) $item['last_used'],
							'name'      => (string) $item['name'],
							'user'      => (string) $u->user_login,
						);
					}
				}
			}
		}//end if
		$check = get_option( self::CHECK_OPTION, array() );
		return array(
			'checked'      => is_array( $check ) && ! empty( $check['ok'] ),
			// A real check ran and failed (the only case step 1 may stay open on a connected site).
			'check_failed' => is_array( $check ) && array_key_exists( 'ok', $check ) && empty( $check['ok'] ),
			'connected'    => null !== $found,
			'used'         => null !== $found && null !== $found['last_used'],
			// Connected for sure: AddSynapse confirmed this password (claim) or already used it. The wizard then shows every step done.
			'claimed'      => $claimed,
			'ready'        => null !== $found && ( $claimed || null !== $found['last_used'] ),
			'detail'       => $found,
			'log_count'    => count( ApprovalLog::all() ),
		);
	}
}
