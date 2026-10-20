<?php
/**
 * Shared plan → draft changeset logic for the plan abilities.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Abilities\Shared;
use Addsynapse\StoreAssistant\Admin\ProOffers;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A planner computes before/after values from the CURRENT product data and stores them as
 * a draft changeset. It never writes to products.
 */
abstract class Planner {

	public const PREVIEW_ROWS = 20;
	public const SKIPPED_ROWS = 20;

	public const NOTE = 'NOTHING has been changed yet. This is a DRAFT preview shown to the manager as a card with Approve / Reject buttons (shown in the manager\'s language). Only the manager can approve it; you cannot approve, apply, reject or undo it.';

	/**
	 * Engine.
	 *
	 * @var ChangesetService
	 */
	protected ChangesetService $service;

	/**
	 * Constructor.
	 *
	 * @param ChangesetService|null $service Engine.
	 */
	public function __construct( ?ChangesetService $service = null ) {
		$this->service = $service ?? new ChangesetService();
	}

	/**
	 * Builds one row (product + its changes).
	 *
	 * @param \WC_Product                    $product Product/variation.
	 * @param array<int,array<string,mixed>> $changes [{field, before, after}].
	 * @return array<string,mixed>
	 */
	protected static function row( \WC_Product $product, array $changes ): array {
		return array(
			'product' => $product,
			'changes' => $changes,
		);
	}

	/**
	 * Stores the draft (when there is something to change) and shapes the tool result.
	 *
	 * @param array<string,mixed> $plan operation, params, summary, rows, skipped, warnings, unchanged, totals, user_id, conversation_id.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function finish( array $plan ) {
		$names   = (array) ( $plan['names'] ?? array() );
		$items   = array();
		$preview = array();
		foreach ( $plan['rows'] as $row ) {
			$product = $row['product'];
			$label   = ProductSelector::label( $product );
			$sku     = (string) $product->get_sku( 'edit' );
			$changes = array();
			foreach ( $row['changes'] as $change ) {
				$items[]   = array(
					'object_type' => ProductSelector::object_type( $product ),
					'object_id'   => (int) $product->get_id(),
					'parent_id'   => (int) $product->get_parent_id(),
					'label'       => $label,
					'sku'         => $sku,
					'field'       => $change['field'],
					'before'      => $change['before'],
					'after'       => $change['after'],
				);
				$changes[] = array(
					'field'  => $change['field'],
					'before' => self::display( $change['field'], $change['before'], $names ),
					'after'  => self::display( $change['field'], $change['after'], $names ),
				);
			}
			if ( count( $preview ) < self::PREVIEW_ROWS ) {
				$preview[] = array(
					'id'        => (int) $product->get_id(),
					'parent_id' => (int) $product->get_parent_id(),
					'name'      => $label,
					'sku'       => $sku,
					'changes'   => $changes,
				);
			}
		}//end foreach

		$changeset_id = null;
		$expires_at   = null;
		if ( $items ) {
			$id = $this->service->create(
				array(
					'user_id'         => (int) $plan['user_id'],
					'conversation_id' => (int) $plan['conversation_id'],
					'operation'       => (string) $plan['operation'],
					'summary'         => (string) $plan['summary'],
					'params'          => $plan['params'],
					'items'           => $items,
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$changeset_id = $id;
			$row          = $this->service->repository()->find( $id );
			$expires_at   = $row ? str_replace( ' ', 'T', (string) $row['expires_at'] ) . 'Z' : null;
		}

		$skipped = array_values( $plan['skipped'] );
		return array(
			'changeset_id'      => $changeset_id,
			'status'            => $changeset_id ? ChangesetRepository::DRAFT : 'nothing_to_change',
			'operation'         => (string) $plan['operation'],
			'summary'           => (string) $plan['summary'],
			'params'            => $plan['params'],
			'expires_at'        => $expires_at,
			'counts'            => array(
				'products'  => count( $plan['rows'] ),
				'items'     => count( $items ),
				'skipped'   => count( $skipped ),
				'unchanged' => (int) $plan['unchanged'],
			),
			'totals'            => $plan['totals'],
			'currency'          => Shared::currency(),
			'preview'           => $preview,
			'preview_truncated' => count( $plan['rows'] ) > count( $preview ),
			'skipped'           => array_slice( $skipped, 0, self::SKIPPED_ROWS ),
			'warnings'          => array_values( array_unique( $plan['warnings'] ) ),
			'note'              => $changeset_id ? self::NOTE : 'Nothing would change, so no preview was created.',
			'_data'             => Shared::DATA_NOTICE,
		);
	}

	/**
	 * Human form of a stored value for the model (dates → store-time strings, term ids →
	 * names, a new term → "Name (new)").
	 *
	 * @param string                   $field Field.
	 * @param mixed                    $value Value.
	 * @param array<int|string,string> $names Term id / "new:N" → name.
	 * @return mixed
	 */
	public static function display( string $field, $value, array $names = array() ) {
		if ( SeoFields::is_field( $field ) ) {
			// Long descriptions: a plain excerpt + length (the card shows everything).
			return SeoFields::display( $field, $value );
		}
		if ( CoreFields::is_field( $field ) ) {
			return CoreFields::display( $field, $value );
		}
		if ( in_array( $field, array( 'date_on_sale_from', 'date_on_sale_to' ), true ) ) {
			return null === $value ? null : wp_date( 'Y-m-d H:i', (int) $value );
		}
		if ( Fields::is_term( $field ) ) {
			if ( null === $value ) {
				return null;
			}
			$out = array();
			foreach ( TermFields::term_list( $field, $value ) as $id ) {
				$name  = $names[ $id ] ?? ( is_int( $id ) ? self::term_name( $id ) : (string) $id );
				$out[] = is_string( $id ) ? $name . ' (new)' : $name;
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Term name by id ('#id' when gone).
	 *
	 * @param int $id Term id.
	 */
	public static function term_name( int $id ): string {
		$term = get_term( $id );
		return $term instanceof \WP_Term ? html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) : '#' . $id;
	}

	/**
	 * YYYY-MM-DD → timestamp in the store timezone (start or end of day).
	 *
	 * @param string $date YYYY-MM-DD.
	 * @param bool   $end  End of day.
	 * @return int|null Null when invalid.
	 */
	protected static function date_timestamp( string $date, bool $end ): ?int {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return null;
		}
		return $end ? $parsed->setTime( 23, 59, 59 )->getTimestamp() : $parsed->getTimestamp();
	}

	/**
	 * Selector part of the params (echoed in the result and stored with the changeset).
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	protected static function selector_params( array $input ): array {
		$out = array();
		foreach ( array( 'ids', 'skus' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] && array() !== $input[ $key ] ) {
				$out[ $key ] = is_array( $input[ $key ] ) ? array_slice( $input[ $key ], 0, 50 ) : $input[ $key ];
			}
		}
		return $out;
	}

	/**
	 * The answer to a request this plugin does not prepare: a change of several items at once (`bulk`) or a kind of
	 * change it has no tool for. A normal tool result (not an error): the chat shows it as a short notice with a «See Pro»
	 * button (the link is built by the chat, per feature), and the model tells the manager in one sentence and offers what
	 * it can do now. Once another plugin provides the Pro features (Admin\ProOffers::shown() false) the answer names no
	 * add-on.
	 *
	 * @param string $feature One of ComingInProAbility::FEATURES.
	 * @return array<string,string>
	 */
	public static function one_item_only( string $feature = 'bulk' ): array {
		$messages = array(
			'bulk'       => __( 'Changing many products at once (a category, a brand or the whole catalogue) is part of Store Assistant Pro. Here one change covers one product.', 'addsynapse-store-assistant' ),
			'attributes' => __( 'Product attributes and variations are part of Store Assistant Pro.', 'addsynapse-store-assistant' ),
			'terms'      => __( 'Creating, renaming or merging categories, tags and brands is part of Store Assistant Pro. Here a product can be assigned to existing ones.', 'addsynapse-store-assistant' ),
			'fields'     => __( 'Here you can change a product\'s name, short description, description and images. Other product settings are part of Store Assistant Pro.', 'addsynapse-store-assistant' ),
			'pages'      => __( 'Editing pages, posts, menus and the look of the site is part of Store Assistant Pro. Here the assistant can read them.', 'addsynapse-store-assistant' ),
			'coupons'    => __( 'Coupons are part of Store Assistant Pro.', 'addsynapse-store-assistant' ),
			'orders'     => __( 'Creating or changing orders is part of Store Assistant Pro.', 'addsynapse-store-assistant' ),
			'import'     => __( 'Updating products from a CSV file is part of Store Assistant Pro.', 'addsynapse-store-assistant' ),
			'content'    => __( 'Writing new posts and pages, and alt texts or SEO texts for many items at once, is part of Store Assistant Pro.', 'addsynapse-store-assistant' ),
			'history'    => __( 'The full change history, with Undo of older changes, is part of Store Assistant Pro.', 'addsynapse-store-assistant' ),
			'other'      => __( 'This is part of Store Assistant Pro. Here the assistant reads your site and changes one product at a time.', 'addsynapse-store-assistant' ),
		);
		$feature  = isset( $messages[ $feature ] ) ? $feature : 'bulk';
		if ( ! ProOffers::shown() ) {
			return array(
				'status'  => 'pro_feature',
				'feature' => $feature,
				'message' => __( 'This change is not available in this chat.', 'addsynapse-store-assistant' ),
				'note'    => 'Nothing was prepared. Tell the manager in one short sentence that this chat cannot make this change, then offer what you can do now. Do not split the request into many single-item changes on your own.',
			);
		}
		return array(
			'status'  => 'pro_feature',
			'feature' => $feature,
			'message' => $messages[ $feature ],
			'note'    => 'Nothing was prepared. Tell the manager in one short sentence that this is part of Store Assistant Pro, a separate add-on; the chat shows a "See Pro" button under your answer, so add no link. Then offer what you can do now (reading, or changing ONE product). Do not split the request into many single-item changes on your own.',
		);
	}
}
