<?php
/**
 * REST route registration (namespace addsynapse-assistant/v1).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

use Addsynapse\StoreAssistant\Agent\Selection;
use Addsynapse\StoreAssistant\Config;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Every route requires: a logged-in user, a valid `wp_rest` nonce (X-WP-Nonce)
 * and the manage_woocommerce capability. Ownership is enforced per conversation.
 */
final class RestRoutes {

	public const NAMESPACE = 'addsynapse-assistant/v1';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'use_user_locale' ), 10, 3 );
	}

	/**
	 * Set while one of our routes runs: its texts use the admin's own language.
	 *
	 * @var bool
	 */
	private static bool $own_route = false;

	/**
	 * Our routes answer in the admin's own language (texts stored in the chat, e.g. /help,
	 * command errors, card labels), whether or not the client sent `_locale=user` — like
	 * WordPress does for `_locale=user`, but for the plugin's own text domain only, so it works
	 * without a core language pack (switch_to_locale() refuses uninstalled languages).
	 *
	 * @param mixed           $response Response so far (passed through).
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function use_user_locale( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( (string) $request->get_route(), '/' . self::NAMESPACE . '/' ) || ! is_user_logged_in() ) {
			return $response;
		}
		self::$own_route = true;
		if ( false === has_filter( 'determine_locale', array( self::class, 'user_locale' ) ) ) {
			add_filter( 'determine_locale', array( self::class, 'user_locale' ) );
		}
		if ( get_user_locale() !== get_locale() ) {
			// Loaded earlier (e.g. ability labels on init) in the site language: unloaded as reloadable, WordPress loads it
			// again just in time in the user's language.
			unload_textdomain( 'addsynapse-store-assistant', true );
		}
		return $response;
	}

	/**
	 * `determine_locale` filter while one of our routes runs.
	 *
	 * @param string $locale Locale.
	 */
	public static function user_locale( $locale ): string {
		$user = self::$own_route && is_user_logged_in() ? get_user_locale() : '';
		return '' !== $user ? $user : (string) $locale;
	}

	/**
	 * Ends the per-route locale (tests / nested dispatches).
	 */
	public static function reset_locale(): void {
		self::$own_route = false;
		remove_filter( 'determine_locale', array( self::class, 'user_locale' ) );
		unload_textdomain( 'addsynapse-store-assistant', true );
	}

	/**
	 * Shared permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'addsynapse-store-assistant' ), array( 'status' => 401 ) );
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Your session has expired. Please reload the page.', 'addsynapse-store-assistant' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( Config::capability() ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to use Store Assistant.', 'addsynapse-store-assistant' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$conversations = new ConversationsController();
		$chat          = new ChatController();
		$permission    = array( self::class, 'permission' );
		$id_arg        = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		$title_arg     = array(
			'type'      => 'string',
			'maxLength' => 255,
		);
		$send_args     = $id_arg + array(
			'content'   => array(
				'type'      => 'string',
				'maxLength' => ChatController::MAX_MESSAGE_CHARS,
				'default'   => '',
			),
			'retry'     => array(
				'type'    => 'boolean',
				'default' => false,
			),
			// An image uploaded in the chat and sent with this message (its attachment id only).
			'image'     => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'attachment_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			// Products checked in the result tables (ids only; stored on the user message).
			'selection' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'product_ids' => array(
						'type'     => 'array',
						'maxItems' => Selection::MAX,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			),
		);

		// The manager's own AI key from the chat's first-run card (same rules as Settings → «Your own AI key»).
		$ai_key = new AiKeyController();
		register_rest_route(
			self::NAMESPACE,
			'/ai-key',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $ai_key, 'save' ),
					'permission_callback' => array( AiKeyController::class, 'permission' ),
					'args'                => array(
						'provider' => array(
							'type'     => 'string',
							'required' => true,
						),
						'model'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'key'      => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $ai_key, 'delete' ),
					'permission_callback' => array( AiKeyController::class, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $chat, 'status' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $conversations, 'index' ),
					'permission_callback' => $permission,
					'args'                => array(
						'search' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $conversations, 'create' ),
					'permission_callback' => $permission,
					'args'                => array( 'title' => $title_arg ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $conversations, 'show' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $conversations, 'rename' ),
					'permission_callback' => $permission,
					'args'                => $id_arg + array( 'title' => $title_arg + array( 'required' => true ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $conversations, 'archive' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)/messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $conversations, 'messages' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $chat, 'send' ),
					'permission_callback' => $permission,
					'args'                => $send_args,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)/stop',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'stop' ),
				'permission_callback' => $permission,
				'args'                => $id_arg,
			)
		);

		// Another page of a product table (read-only abilities in ChatController::PAGEABLE).
		register_rest_route(
			self::NAMESPACE,
			'/results/page',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'result_page' ),
				'permission_callback' => $permission,
				'args'                => array(
					'ability' => array(
						'type'     => 'string',
						'enum'     => ChatController::PAGEABLE,
						'required' => true,
					),
					'input'   => array(
						'type'    => 'object',
						'default' => array(),
					),
					'page'    => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'maximum'  => 1000,
						'required' => true,
					),
				),
			)
		);

		// Images uploaded in the chat (media library, pending until a plan uses them).
		$images     = new ImagesController();
		$image_perm = array( ImagesController::class, 'permission' );
		register_rest_route(
			self::NAMESPACE,
			'/images',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $images, 'create' ),
				'permission_callback' => $image_perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/images/(?P<attachment_id>\d{1,18})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $images, 'delete' ),
				'permission_callback' => $image_perm,
			)
		);

		// Change sets: approve / reject / revert / replan are UI buttons ONLY (never agent tools).
		$changesets = new ChangesetsController();
		$paging     = array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => ChangesetsController::PER_PAGE_MAX,
				'default' => 20,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/changesets/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $changesets, 'show' ),
				'permission_callback' => $permission,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/changesets/(?P<id>\d+)/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $changesets, 'items' ),
				'permission_callback' => $permission,
				'args'                => $id_arg + $paging,
			)
		);
		// Partial approval: the units (object_ids: typed keys "variation:34", a plain id = product) or items (item_ids)
		// to apply; the rest of the draft becomes `excluded`. Validated against the changeset by the engine.
		$id_list   = array(
			'type'     => 'array',
			'maxItems' => 500,
			'items'    => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$unit_list = array(
			'type'     => 'array',
			'maxItems' => 500,
			'items'    => array(
				'type'      => array( 'integer', 'string' ),
				'minimum'   => 1,
				'maxLength' => 50,
				'pattern'   => '^([1-9][0-9]{0,17}|[a-z][a-z_]{0,31}:[1-9][0-9]{0,17})$',
			),
		);
		foreach ( array( 'approve', 'reject', 'revert', 'replan' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/changesets/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $changesets, $action ),
					'permission_callback' => $permission,
					'args'                => 'approve' === $action ? $id_arg + array(
						'object_ids' => $unit_list,
						'item_ids'   => $id_list,
					) : $id_arg,
				)
			);
		}

		// The optional AddSynapse account from the chat's «Try Pro» dialog (administrators; same as the Settings card).
		register_rest_route(
			self::NAMESPACE,
			'/account/optin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( \Addsynapse\StoreAssistant\Account\OptIn::class, 'rest_create' ),
				'permission_callback' => array( AiKeyController::class, 'permission' ),
				'args'                => array(
					'email'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'marketing' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)/stream',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $chat, 'stream' ),
				'permission_callback' => $permission,
				'args'                => $send_args,
			)
		);
	}
}
