<?php
/**
 * Remote access for AI apps (Claude, ChatGPT…) through AddSynapse — off until the site owner approves it.
 *
 * Nothing here contacts any outside service. The owner starts the connection from their AI chat, and WordPress'
 * own «Authorize Application» screen (wp-admin/authorize-application.php) issues an Application Password to the
 * AddSynapse application (fixed app_id). This class then:
 *  - limits that password to this plugin's `addsynapse-assistant/v1/remote/*` routes (403 on every other REST
 *    route, refused over XML-RPC) — passwords created by hand (other app_id or none) are not touched;
 *  - accepts the same credentials in an `X-AddSynapse-Auth` header on these routes, for hosts that strip the
 *    standard Authorization header;
 *  - serves a fixed allow-list of read tools (abilities: store, pages/posts, menus, media, SEO) and the write tools
 *    (apply-price-changes, apply-changes)
 *    that write exactly the values of a change set the owner approved, check that everything still holds the
 *    expected «before» values first, and record every write in the approval log;
 *  - runs those writes only with a single-use approval ticket signed by AddSynapse (RemoteTicket).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Scope + REST routes for the AddSynapse application password.
 */
final class RemoteAccess {

	/** The fixed app_id AddSynapse passes to authorize-application.php. */
	public const APP_ID = '6f1c2a5e-8d3b-4c71-9e2a-ad5f0c3b7e41';

	/** REST namespace (shared with the chat) and our remote prefix. */
	public const REST_NAMESPACE = 'addsynapse-assistant/v1';
	public const ROUTE_PREFIX   = '/addsynapse-assistant/v1/remote';

	/** Fallback header for hosts that drop Authorization. */
	public const FALLBACK_HEADER = 'HTTP_X_ADDSYNAPSE_AUTH';

	/** Abilities the remote route may run (read-only + the one approved-write tool). */
	public const ALLOWED = array(
		'addsynapse-assistant/describe-store',
		'addsynapse-assistant/search-products',
		'addsynapse-assistant/get-product',
		'addsynapse-assistant/list-categories',
		'addsynapse-assistant/product-report',
		'addsynapse-assistant/get-seo',
		'addsynapse-assistant/describe-menus',
		'addsynapse-assistant/find-media',
		ListContentAbility::NAME,
		GetContentAbility::NAME,
		PlanMenuAbility::NAME,
		ReadStateAbility::NAME,
		ApplyPricesAbility::NAME,
		ApplyChangesAbility::NAME,
	);

	/** Write abilities (owner-approved change sets only; never agent tools). */
	public const REMOTE_WRITES = array( ApplyPricesAbility::NAME, ApplyChangesAbility::NAME );

	/**
	 * The current request is authenticated with our application password.
	 *
	 * @var bool
	 */
	private static $scoped = false;

	/**
	 * The application password record of this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $item = null;

	/**
	 * Hooks.
	 */
	public static function register_hooks(): void {
		self::maybe_use_fallback_header();
		add_action( 'application_password_did_authenticate', array( self::class, 'remember' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( self::class, 'gate' ), 10, 3 );
		add_action( 'wp_authenticate_application_password_errors', array( self::class, 'no_xmlrpc' ), 10, 3 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ), 20 );
	}

	/**
	 * Hosts that strip «Authorization»: take the same Basic credentials from X-AddSynapse-Auth, only for our
	 * remote routes and only when no standard credentials arrived.
	 */
	private static function maybe_use_fallback_header(): void {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- credentials are compared by core, never stored or printed.
		if ( empty( $_SERVER[ self::FALLBACK_HEADER ] ) || ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return;
		}
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) $_SERVER['REQUEST_URI'] ) : '';
		$route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( false === strpos( $uri . ' ' . $route, ltrim( self::ROUTE_PREFIX, '/' ) ) ) {
			return;
		}
		$value = trim( (string) $_SERVER[ self::FALLBACK_HEADER ] );
		// phpcs:enable
		if ( 0 !== stripos( $value, 'basic ' ) ) {
			return;
		}
		$decoded = base64_decode( substr( $value, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials.
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return;
		}
		list( $user, $pass )      = explode( ':', $decoded, 2 );
		$_SERVER['PHP_AUTH_USER'] = $user;
		$_SERVER['PHP_AUTH_PW']   = $pass;
	}

	/**
	 * Remembers whether our application password authenticated this request.
	 *
	 * @param \WP_User            $user User.
	 * @param array<string,mixed> $item Application password record.
	 */
	public static function remember( $user, $item ): void {
		self::$scoped = is_array( $item ) && isset( $item['app_id'] ) && self::APP_ID === $item['app_id'];
		self::$item   = self::$scoped ? $item : null;
	}

	/**
	 * Our password only opens our remote routes.
	 *
	 * @param mixed            $result  Short-circuit value.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function gate( $result, $server, $request ) {
		if ( ! self::$scoped ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( self::ROUTE_PREFIX === $route || 0 === strpos( $route, self::ROUTE_PREFIX . '/' ) ) {
			self::maybe_confirm_pending( $route, $request );
			if ( ! RelayClaim::is_pending( is_array( self::$item ) ? (string) ( self::$item['uuid'] ?? '' ) : '' ) ) {
				PlanInfo::capture( $request->get_header( 'x_addsynapse_plan' ) );
			}
			return $result;
		}
		return new \WP_Error( 'addsynapse_assistant_scoped', 'This application password only works for the AddSynapse remote routes.', array( 'status' => 403 ) );
	}

	/**
	 * A claimed password that waits for AddSynapse (RelayClaim, 22/10/2026): its first real use — anything but the claim's own
	 * check (`ping?pending=1`) or a disconnect — means the member who started the connection completed it on AddSynapse.
	 *
	 * @param string           $route   Route.
	 * @param \WP_REST_Request $request Request.
	 */
	private static function maybe_confirm_pending( string $route, $request ): void {
		$uuid = is_array( self::$item ) && isset( self::$item['uuid'] ) ? (string) self::$item['uuid'] : '';
		if ( '' === $uuid || ! RelayClaim::is_pending( $uuid ) || self::ROUTE_PREFIX . '/disconnect' === $route
			|| ( self::ROUTE_PREFIX . '/ping' === $route && $request->get_param( 'pending' ) ) ) {
			return;
		}
		RelayClaim::confirm_pending( get_current_user_id(), $uuid );
	}

	/**
	 * Application passwords also work over XML-RPC; ours must not.
	 *
	 * @param \WP_Error           $error Errors collector.
	 * @param \WP_User            $user  User.
	 * @param array<string,mixed> $item  Application password record.
	 */
	public static function no_xmlrpc( $error, $user, $item ): void {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && is_array( $item ) && isset( $item['app_id'] ) && self::APP_ID === $item['app_id'] ) {
			$error->add( 'addsynapse_assistant_scoped', 'This application password only works for the AddSynapse remote routes.' );
		}
	}

	/**
	 * The two extra abilities (categories list, approved price write).
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		// Site tools (pages, posts, menus, media, page/post SEO): with or without WooCommerce.
		( new ListContentAbility() )->register();
		( new GetContentAbility() )->register();
		( new PlanMenuAbility() )->register();
		( new ReadStateAbility() )->register();
		( new ApplyChangesAbility() )->register();
		if ( ! Environment::has_woocommerce() ) {
			return;
		}
		( new ListCategoriesAbility() )->register();
		( new ApplyPricesAbility() )->register();
	}

	/**
	 * REST routes.
	 */
	public static function register_routes(): void {
		$manage = array( AbilitiesRegistrar::class, 'can_manage' );
		register_rest_route(
			self::REST_NAMESPACE,
			'/remote/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'ping' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/remote/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'run' ),
				'permission_callback' => $manage,
				'args'                => array(
					'ability' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::ALLOWED,
					),
					'input'   => array(
						'type'    => 'object',
						'default' => array(),
					),
					'ticket'  => array(
						'type' => 'object',
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/remote/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'disconnect' ),
				'permission_callback' => $manage,
			)
		);
		// Connection self-check (wp-admin «Check connectivity»): tells only whether the auth headers arrived.
		register_rest_route(
			self::REST_NAMESPACE,
			'/remote/echo-auth',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'echo_auth' ),
				'permission_callback' => '__return_true',
			)
		);
		// Public discovery (AddSynapse onboarding): «is the Store Assistant with AI-app connections active here?» — only the
		// plugin name, `relay` and `min_version_ok` (the exact version only for a signed-in manager). No store data, no settings, nothing about users.
		register_rest_route(
			self::REST_NAMESPACE,
			'/hello',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'hello' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/connect/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( SetupStatus::class, 'check' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/connect/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( SetupStatus::class, 'status' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Who/what is connected — the first call AddSynapse makes after the owner approved.
	 *
	 * @return \WP_REST_Response
	 */
	public static function ping() {
		$user = wp_get_current_user();
		$item = self::$item;
		return rest_ensure_response(
			array(
				'ok'             => true,
				'plugin_version' => ADDSYNAPSE_ASSISTANT_VERSION,
				'site_name'      => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'home_url'       => home_url( '/' ),
				'woocommerce'    => Environment::has_woocommerce(),
				'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'user'           => array(
					'login'      => $user->user_login,
					'roles'      => array_values( (array) $user->roles ),
					'can_manage' => current_user_can( Config::capability() ),
				),
				'scoped'         => self::$scoped,
				'password_uuid'  => is_array( $item ) && isset( $item['uuid'] ) ? (string) $item['uuid'] : null,
				'site_type'      => Environment::site_type(),
				'tools'          => self::available(),
			)
		);
	}

	/**
	 * The allowed tools this site really has (a plain WordPress site: no product/write tools), so AddSynapse can hide the rest.
	 *
	 * @return string[]
	 */
	public static function available(): array {
		if ( ! function_exists( 'wp_has_ability' ) ) {
			return self::ALLOWED;
		}
		return array_values( array_filter( self::ALLOWED, 'wp_has_ability' ) );
	}

	/**
	 * Runs one allowed ability as the authenticated user (its own schema + permission check apply).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run( $request ) {
		$name = (string) $request->get_param( 'ability' );
		if ( ! in_array( $name, self::ALLOWED, true ) || ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'addsynapse_assistant_unknown_tool', 'Unknown tool.', array( 'status' => 404 ) );
		}
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return new \WP_Error( 'addsynapse_assistant_unknown_tool', 'Tool not available on this site.', array( 'status' => 404 ) );
		}
		$input = $request->get_param( 'input' );
		$input = is_array( $input ) ? $input : array();
		// Writes run only with a ticket AddSynapse signed when the owner approved (RemoteTicket): one use, 5 minutes.
		if ( in_array( $name, self::REMOTE_WRITES, true ) ) {
			$refused = RemoteTicket::verify( $request->get_param( 'ticket' ), $name, $input );
			if ( null !== $refused ) {
				ApprovalLog::add( (string) ( $input['changeset'] ?? '' ), (string) ( $input['action'] ?? '' ), (string) ( $input['summary'] ?? '' ), array(), 'refused: ' . $refused->get_error_code() );
				return $refused;
			}
		}
		// Abilities without an input schema take null; the others get an object (an empty one is valid).
		$result = $ability->execute( ( empty( $input ) && empty( $ability->get_input_schema() ) ) ? null : $input );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return rest_ensure_response( array( 'result' => $result ) );
	}

	/**
	 * Revokes the application password used for this request (AddSynapse «Disconnect»).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function disconnect() {
		if ( ! self::$scoped || empty( self::$item['uuid'] ) ) {
			return new \WP_Error( 'addsynapse_assistant_not_remote', 'Only the AddSynapse application can disconnect itself.', array( 'status' => 400 ) );
		}
		$deleted = \WP_Application_Passwords::delete_application_password( get_current_user_id(), (string) self::$item['uuid'] );
		PlanInfo::forget();
		return rest_ensure_response( array( 'deleted' => true === $deleted ) );
	}

	/**
	 * Revokes EVERY AddSynapse application password (matched by our fixed app_id — never by name; passwords the
	 * owner created for other apps stay) of every user of this site, and forgets the connection state (setup check,
	 * used ticket nonces). Runs on deactivation; uninstall.php does the same without loading this class.
	 *
	 * Multisite: application passwords belong to the user (network-wide); this revokes them for the users of the
	 * current site only — the site where the plugin is being deactivated. The approval log stays until uninstall.
	 *
	 * @return int Passwords revoked.
	 */
	public static function revoke_all(): int {
		global $wpdb;
		$revoked = 0;
		if ( class_exists( '\WP_Application_Passwords' ) ) {
			$users = get_users(
				array(
					'meta_key' => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only users who have application passwords; runs on deactivation only.
					'fields'   => 'ID',
					'number'   => -1,
				)
			);
			foreach ( $users as $user_id ) {
				foreach ( \WP_Application_Passwords::get_user_application_passwords( (int) $user_id ) as $item ) {
					if ( ( $item['app_id'] ?? '' ) === self::APP_ID && ! empty( $item['uuid'] )
						&& true === \WP_Application_Passwords::delete_application_password( (int) $user_id, (string) $item['uuid'] ) ) {
						++$revoked;
					}
				}
			}
		}
		delete_option( SetupStatus::CHECK_OPTION );
		delete_option( RelayClaim::CLAIMED_OPTION );
		delete_option( RelayClaim::PENDING_OPTION );
		PlanInfo::forget();
		wp_clear_scheduled_hook( RelayClaim::PENDING_CRON );
		$nonces = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( RemoteTicket::NONCE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $nonces as $nonce ) {
			delete_option( $nonce );
		}
		return $revoked;
	}

	/**
	 * Public discovery for the AddSynapse onboarding: plugin, relay support, «new enough» — nothing else.
	 *
	 * @return \WP_REST_Response
	 */
	public static function hello() {
		// Anonymous callers learn only «new enough for AddSynapse» (no exact version to fingerprint); managers see the version.
		$data = array(
			'plugin'         => 'store-assistant',
			'relay'          => true,
			'min_version_ok' => true,
		);
		if ( current_user_can( Config::capability() ) ) {
			$data['version'] = ADDSYNAPSE_ASSISTANT_VERSION;
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Whether the test headers of the loopback self-check arrived (never echoes values).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function echo_auth( $request ) {
		$auth = (string) $request->get_header( 'authorization' );
		if ( '' === $auth && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = 'x';
		}
		return rest_ensure_response(
			array(
				'authorization' => '' !== $auth,
				'fallback'      => '' !== (string) $request->get_header( 'x_addsynapse_auth' ),
			)
		);
	}
}
