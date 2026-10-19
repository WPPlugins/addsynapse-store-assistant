<?php
/**
 * Changesets REST controller: the ONLY way to approve / reject / revert (UI buttons).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

use Addsynapse\ConnectorCore\Changeset\Selection;
use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\Changesets\ChangesetRepository;
use Addsynapse\StoreAssistant\Changesets\ChangesetService;
use Addsynapse\StoreAssistant\Changesets\Fields;
use Addsynapse\StoreAssistant\Changesets\SeoFields;
use Addsynapse\StoreAssistant\Changesets\SeoText;
use Addsynapse\StoreAssistant\Adapters\Seo\AbstractSeoAdapter;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use Addsynapse\StoreAssistant\Changesets\Replanner;
use Addsynapse\StoreAssistant\Changesets\TermFields;
use Addsynapse\StoreAssistant\Changesets\CoreFields;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Routes are protected by RestRoutes::permission (logged in + wp_rest nonce +
 * manage_woocommerce); every lookup is scoped to the current user (others' ids → 404).
 * These endpoints are NOT abilities and are never exposed to the agent.
 */
class ChangesetsController {

	public const PER_PAGE_MAX = 100;

	/**
	 * Engine.
	 *
	 * @var ChangesetService
	 */
	private ChangesetService $service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service = new ChangesetService();
	}

	/**
	 * GET /changesets/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$changeset = $this->service->get_for_user( (int) $request['id'], get_current_user_id() );
		if ( ! $changeset ) {
			return ChangesetService::not_found();
		}
		$changeset = $this->service->ensure_progress( $changeset );
		return new WP_REST_Response( self::format( $changeset ) );
	}

	/**
	 * GET /changesets/{id}/items — rows grouped per product, paginated by product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function items( WP_REST_Request $request ) {
		$changeset = $this->service->get_for_user( (int) $request['id'], get_current_user_id() );
		if ( ! $changeset ) {
			return ChangesetService::not_found();
		}
		$repo = $this->service->repository();
		/**
		 * Extension point: the card data of a changeset whose lines an extension owns (see Changesets\ChangesetHandler).
		 * Null = the plugin's own view.
		 *
		 * @param array<string,mixed>|null $view      View.
		 * @param array<string,mixed>      $changeset Changeset.
		 */
		$view = apply_filters( 'addsynapse_assistant_changeset_items', null, $changeset );
		if ( is_array( $view ) ) {
			return new WP_REST_Response( $view );
		}
		$per_page = max( 1, min( self::PER_PAGE_MAX, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );
		$objects  = $repo->object_page( (int) $changeset['id'], $page, $per_page );
		$names    = (array) ( $changeset['params']['term_names'] ?? array() );
		$rows     = array();
		foreach ( $repo->items_for_objects( (int) $changeset['id'], $objects['ids'] ) as $item ) {
			$key = $item['object_id'];
			if ( ! isset( $rows[ $key ] ) ) {
				$parent       = $item['parent_id'] ? $item['parent_id'] : $item['object_id'];
				$rows[ $key ] = array(
					'object_id'   => $item['object_id'],
					'object_type' => (string) $item['object_type'],
					// Typed unit key: what the card's checkboxes select (partial approval, connector core Selection).
					'unit'        => Selection::key( (string) $item['object_type'], (int) $item['object_id'] ),
					'parent_id'   => $item['parent_id'],
					'name'        => (string) $item['label'],
					'sku'         => (string) $item['sku'],
					'edit_link'   => Shared::product_edit_link( $parent ),
					'changes'     => array(),
				);
			}
			$rows[ $key ]['changes'][] = self::change( $item, $names );
		}//end foreach
		$ordered = array();
		foreach ( $objects['ids'] as $id ) {
			if ( isset( $rows[ $id ] ) ) {
				$ordered[] = $rows[ $id ];
			}
		}
		return new WP_REST_Response(
			array(
				'rows'       => $ordered,
				// Every product of the changeset (all pages): the card's select-all / partial approval.
				'object_ids' => $repo->object_ids( (int) $changeset['id'] ),
				// The same units as typed keys (product:12, variation:34, post:5) — what a partial approval sends back.
				'units'      => $repo->unit_keys( (int) $changeset['id'] ),
				'new_terms'  => array(),
				'total'      => $objects['total'],
				'page'       => $page,
				'pages'      => (int) ceil( $objects['total'] / $per_page ),
				'currency'   => Shared::currency(),
				'_data'      => Shared::DATA_NOTICE,
			)
		);
	}

	/**
	 * One change for the UI. Sale dates get store-timezone texts (the browser's timezone is
	 * NOT the store's); term fields get term names (a "new:N" ref is a term this changeset
	 * creates on apply).
	 *
	 * @param array<string,mixed>  $item  Item.
	 * @param array<string,string> $names Plan-time names (fallback once a term is gone).
	 * @return array<string,mixed>
	 */
	public static function change( array $item, array $names = array() ): array {
		$field  = (string) $item['field'];
		$change = array(
			'field'  => $field,
			'before' => $item['before'],
			'after'  => $item['after'],
			'status' => (string) $item['status'],
			'error'  => (string) $item['error'],
		);
		if ( in_array( $field, array( 'date_on_sale_from', 'date_on_sale_to' ), true ) ) {
			$change['before_text'] = self::store_time( $item['before'] );
			$change['after_text']  = self::store_time( $item['after'] );
			// Wall-clock store time for the browser to format in the user's language.
			$change['before_local'] = is_numeric( $item['before'] ) ? wp_date( 'Y-m-d\TH:i', (int) $item['before'] ) : '';
			$change['after_local']  = is_numeric( $item['after'] ) ? wp_date( 'Y-m-d\TH:i', (int) $item['after'] ) : '';
		}
		if ( SeoFields::is_field( $field ) ) {
			$change += self::seo_change( $item );
		}
		if ( CoreFields::is_field( $field ) ) {
			// Localized texts (statuses, classes, units…) — plain text, never store HTML.
			$type                  = (string) $item['object_type'];
			$change['before_text'] = CoreFields::text( $field, $item['before'], $type );
			$change['after_text']  = CoreFields::text( $field, $item['after'], $type );
			if ( in_array( $field, array( 'image_id', 'gallery_image_ids' ), true ) ) {
				$change['before_images'] = CoreFields::images( $item['before'] );
				$change['after_images']  = CoreFields::images( $item['after'] );
			}
		}
		if ( Fields::is_term( $field ) ) {
			$taxonomy                 = TermFields::taxonomy( $field );
			$change['taxonomy']       = $taxonomy;
			$change['taxonomy_label'] = self::taxonomy_label( $taxonomy );
			$change['before_terms']   = self::terms( $field, $item['before'], $names );
			$change['after_terms']    = self::terms( $field, $item['after'], $names );
		}
		return $change;
	}

	/**
	 * SEO / product text change for the card: part, plain texts, what search engines show
	 * (the plugin template when the field is absent/empty, variables resolved), lengths and
	 * the length guidance. Texts only — the browser never renders store HTML.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return array<string,mixed>
	 */
	public static function seo_change( array $item ): array {
		$field   = (string) $item['field'];
		$part    = SeoFields::part( $field );
		$seo     = 'seo' === SeoFields::kind( $field );
		$adapter = $seo ? SeoAdapters::for_key( SeoFields::adapter_key( $field ) ) : null;
		$product = null;
		if ( $seo ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $item['object_id'] ) : null;
		}
		$type = 'product';
		$out  = array(
			'seo_part'   => $seo ? $part : 'product_' . $part,
			'seo_plugin' => $adapter ? $adapter->label() : '',
			'guide'      => $seo && isset( SeoText::GUIDE[ $part ] ) ? SeoText::GUIDE[ $part ] : null,
		);
		foreach ( array( 'before', 'after' ) as $side ) {
			$value = $item[ $side ];
			if ( '' !== SeoFields::unreadable( $value ) ) {
				$value = null;
			}
			$text                      = null === $value ? '' : ( $seo ? (string) $value : AbstractSeoAdapter::clean( (string) $value ) );
			$out[ $side . '_absent' ]  = $seo && null === $value;
			$out[ $side . '_text' ]    = $text;
			$effective                 = $text;
			$out[ $side . '_default' ] = false;
			if ( $adapter && $product && 'focus_keyword' !== $part ) {
				$custom                    = '' !== trim( $text );
				$effective                 = $adapter->render( $custom ? $text : $adapter->template( $part, $type ), $product );
				$out[ $side . '_default' ] = ! $custom;
			}
			$out[ $side . '_effective' ] = $effective;
			$out[ $side . '_chars' ]     = mb_strlen( $effective );
		}
		return $out;
	}

	/**
	 * Timestamp → "1 Jan 2027, 00:00" in the STORE timezone and the user's locale ('' = none).
	 *
	 * @param mixed $timestamp Timestamp.
	 */
	public static function store_time( $timestamp ): string {
		if ( null === $timestamp || '' === $timestamp || ! is_numeric( $timestamp ) ) {
			return '';
		}
		return (string) wp_date( 'j M Y, H:i', (int) $timestamp );
	}

	/**
	 * Term chips of a value: [{id, name, new}].
	 *
	 * @param string               $field Field.
	 * @param mixed                $value Value.
	 * @param array<string,string> $names Names.
	 * @return array<int,array<string,mixed>>
	 */
	private static function terms( string $field, $value, array $names ): array {
		$out = array();
		foreach ( TermFields::term_list( $field, $value ) as $id ) {
			if ( is_string( $id ) ) {
				$out[] = array(
					'id'   => 0,
					'name' => (string) ( $names[ $id ] ?? $id ),
					'new'  => true,
				);
				continue;
			}
			$term  = get_term( (int) $id );
			$out[] = array(
				'id'   => (int) $id,
				'name' => $term instanceof \WP_Term ? html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) : (string) ( $names[ (string) $id ] ?? '#' . $id ),
				'new'  => false,
			);
		}
		return $out;
	}

	/**
	 * Plural label of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	private static function taxonomy_label( string $taxonomy ): string {
		return TermFields::label( $taxonomy );
	}

	/**
	 * POST /changesets/{id}/approve — approve + apply (UI button only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve( WP_REST_Request $request ) {
		// Partial approval (checkboxes on the card): only these products are applied.
		// Typed unit keys ("variation:34"); a plain id = the product with that id only (connector core Selection).
		$object_ids = is_array( $request['object_ids'] ) ? array_values( $request['object_ids'] ) : null;
		$item_ids   = is_array( $request['item_ids'] ) ? array_map( 'intval', $request['item_ids'] ) : null;
		return self::respond( $this->service->approve( (int) $request['id'], get_current_user_id(), $object_ids, $item_ids ) );
	}

	/**
	 * POST /changesets/{id}/reject.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject( WP_REST_Request $request ) {
		return self::respond( $this->service->reject( (int) $request['id'], get_current_user_id() ) );
	}

	/**
	 * POST /changesets/{id}/revert — undo (UI button only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revert( WP_REST_Request $request ) {
		return self::respond( $this->service->revert( (int) $request['id'], get_current_user_id() ) );
	}

	/**
	 * POST /changesets/{id}/replan — «Επανάληψη»: the same plan again as a NEW draft (fresh
	 * before-values and expiry). Writes nothing to the store; still needs Έγκριση.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function replan( WP_REST_Request $request ) {
		$result = ( new Replanner( $this->service ) )->replan( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result['changeset'] ) {
			// Nothing to change any more, or the plan failed (e.g. a product is gone): the reason
			// is also stored in the conversation as the tool result.
			$message = $result['is_error'] ? (string) $result['result'] : __( 'Nothing would change now, so no new preview was created.', 'addsynapse-store-assistant' );
			return new WP_Error(
				$result['is_error'] ? 'addsynapse_assistant_replan_failed' : 'addsynapse_assistant_replan_nothing',
				$message,
				array(
					'status'          => 422,
					'conversation_id' => $result['conversation_id'],
				)
			);
		}
		return new WP_REST_Response(
			array(
				'changeset'       => self::format( $result['changeset'] ),
				'conversation_id' => $result['conversation_id'],
				'message_ids'     => $result['message_ids'],
			)
		);
	}

	/**
	 * Service result → response.
	 *
	 * @param array<string,mixed>|WP_Error|null $result Result.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return ChangesetService::not_found();
		}
		return new WP_REST_Response( self::format( $result ) );
	}

	/**
	 * Changeset for the UI.
	 *
	 * @param array<string,mixed> $c Changeset row.
	 * @return array<string,mixed>
	 */
	public static function format( array $c ): array {
		$counts = $c['counts'];
		$status = (string) $c['status'];
		// Items left out by a partial approval are not part of the progress.
		$total    = (int) ( $counts['items'] ?? 0 ) - (int) ( $counts[ ChangesetRepository::ITEM_EXCLUDED ] ?? 0 );
		$done     = $total - (int) ( $counts[ ChangesetRepository::ITEM_PENDING ] ?? 0 );
		$progress = array(
			'done'  => $done,
			'total' => $total,
		);
		if ( ChangesetRepository::REVERTING === $status ) {
			$left     = (int) ( $counts[ ChangesetRepository::ITEM_APPLIED ] ?? 0 );
			$handled  = (int) ( $counts[ ChangesetRepository::ITEM_REVERTED ] ?? 0 ) + (int) ( $counts[ ChangesetRepository::ITEM_REVERT_CONFLICT ] ?? 0 );
			$progress = array(
				'done'  => $handled,
				'total' => $handled + $left,
			);
		}
		$log = array();
		foreach ( (array) $c['log'] as $event ) {
			$user  = ! empty( $event['user_id'] ) ? get_userdata( (int) $event['user_id'] ) : null;
			$log[] = array_merge(
				$event,
				array(
					'at'   => MessageFormatter::iso( (string) ( $event['at'] ?? '' ) ),
					'user' => $user ? $user->display_name : '',
				)
			);
		}
		return array(
			'id'              => (int) $c['id'],
			'uuid'            => (string) $c['uuid'],
			'conversation_id' => (int) $c['conversation_id'],
			'operation'       => (string) $c['operation'],
			'status'          => $status,
			'summary'         => (string) $c['summary'],
			'params'          => $c['params'] ? $c['params'] : new \stdClass(),
			'counts'          => $counts ? $counts : new \stdClass(),
			'progress'        => $progress,
			'user'            => self::user_name( (int) $c['user_id'] ),
			'approved_by'     => self::user_name( (int) $c['approved_by'] ),
			'reverted_by'     => self::user_name( (int) $c['reverted_by'] ),
			'created_at'      => MessageFormatter::iso( (string) $c['created_at'] ),
			'expires_at'      => MessageFormatter::iso( (string) $c['expires_at'] ),
			'approved_at'     => MessageFormatter::iso( (string) ( $c['approved_at'] ?? '' ) ),
			'applied_at'      => MessageFormatter::iso( (string) ( $c['applied_at'] ?? '' ) ),
			'reverted_at'     => MessageFormatter::iso( (string) ( $c['reverted_at'] ?? '' ) ),
			'log'             => $log,
			'replanned_from'  => (int) ( $c['replanned_from'] ?? 0 ),
			'can'             => array(
				'approve' => ChangesetRepository::DRAFT === $status,
				'reject'  => ChangesetRepository::DRAFT === $status,
				'revert'  => in_array( $status, array( ChangesetRepository::APPLIED, ChangesetRepository::PARTIALLY_APPLIED ), true ),
				'replan'  => Replanner::can( $c ),
			),
		);
	}

	/**
	 * Display name of a user id ('' when none).
	 *
	 * @param int $user_id User.
	 */
	private static function user_name( int $user_id ): string {
		if ( $user_id < 1 ) {
			return '';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}
}
