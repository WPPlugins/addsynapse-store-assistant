<?php
/**
 * Ability: writes a change set the shop owner approved on AddSynapse (or restores it on Undo) — price/stock
 * fields of products and variations, SEO title/description of products, pages and posts, coupons, page/post content and
 * status, featured images, navigation menus and the site's look (StateUnits, Design\DesignUnits). Never
 * offered to the in-admin chat (no agent meta) — only to the remote route.
 *
 * All-or-nothing pre-check: every unit must still hold the expected («before») values, otherwise nothing is
 * written and the conflicts are returned. Every write is recorded in the approval log.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use Addsynapse\StoreAssistant\Design\DesignUnits;
use Addsynapse\StoreAssistant\Media\ImageUsage;
use Addsynapse\StoreAssistant\Media\MediaSideload;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/apply-changes.
 */
final class ApplyChangesAbility {

	public const NAME      = 'addsynapse-assistant/apply-changes';
	public const MAX_ITEMS = 100;

	/**
	 * True only while a logged-in administrator decides on the wp-admin approval page (AdminApprovals): the coupon ceiling
	 * (CouponBrake) is for approvals that come from AddSynapse.
	 *
	 * @var bool
	 */
	private static $in_admin = false;

	/**
	 * The action of the call being checked (approve|undo).
	 *
	 * @var string
	 */
	private $action = '';

	/**
	 * Indexes of the items that create a term (kind term, id 0): product_terms items may point at them with "@N".
	 *
	 * @var int[]
	 */
	private $refs = array();

	/**
	 * 1.3.16: indexes of the items that add an image (kind media, id 0): product_images items may point at them with "@N".
	 *
	 * @var int[]
	 */
	private $media_refs = array();

	/**
	 * 1.3.16: the items of the call being checked (Undo of an added image: the product images of the same call that let it go).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $items = array();

	/**
	 * Indexes of the image-adding items of a change set.
	 *
	 * @param array<int,mixed> $items Items.
	 * @return int[]
	 */
	private static function media_refs( array $items ): array {
		$out = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$item = (array) $item;
			if ( 'media' === ( $item['kind'] ?? '' ) && 0 === (int) ( $item['id'] ?? 0 ) ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * Whether the product images of this call stop using an image (Undo: the product gets its previous images back first).
	 *
	 * @param int $image Attachment id.
	 */
	private function released_here( int $image ): bool {
		foreach ( $this->items as $item ) {
			if ( ProductImages::handles( (string) ( $item['kind'] ?? '' ) ) && in_array( $image, ProductImages::ids( $item['expect'] ?? null ), true )
				&& ! in_array( $image, ProductImages::ids( $item['set'] ?? null ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Indexes of the term-creating items of a change set.
	 *
	 * @param array<int,mixed> $items Items.
	 * @return int[]
	 */
	private static function term_refs( array $items ): array {
		$out = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$item = (array) $item;
			if ( 'term' === ( $item['kind'] ?? '' ) && 0 === (int) ( $item['id'] ?? 0 ) ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * Runs a change set approved (or undone) on the wp-admin approval page by the logged-in administrator.
	 *
	 * @param array<string,mixed> $input Same shape as the ability input.
	 * @return array<string,mixed>
	 */
	public static function run_in_admin( array $input ): array {
		self::$in_admin = true;
		try {
			return ( new self() )->execute( $input );
		} finally {
			self::$in_admin = false;
		}
	}

	/**
	 * The conflicts of these units as the wp-admin page would see them (hold-changes: checked, nothing written).
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	public static function conflicts( array $items ): array {
		self::$in_admin = true;
		try {
			$self             = new self();
			$self->refs       = self::term_refs( $items );
			$self->media_refs = self::media_refs( $items );
			$self->items      = array_map(
				static function ( $item ) {
					return (array) $item;
				},
				array_values( $items )
			);
			$out              = array();
			foreach ( array_values( $items ) as $i => $item ) {
				$reason = $self->check( (array) $item, SeoAdapters::current()->key() );
				if ( '' !== $reason ) {
					$out[] = array(
						'index'  => $i,
						'reason' => $reason,
					);
				}
			}
			return $out;
		} finally {
			self::$in_admin = false;
		}//end try
	}

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		$values = array(
			'type'                 => array( 'object', 'null' ),
			'additionalProperties' => true,
		);
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Apply an approved change set', 'addsynapse-store-assistant' ),
				'description'         => 'Writes the values of a change set the shop owner approved (or restores them on Undo): price/stock fields of products and variations, SEO title/description/focus keyword, coupons, page/post content and status, featured images, navigation menus, the name, description and short description of products, the main image and gallery of products and variations (also with images the same change set adds from a link), new orders (Undo cancels them and moves them to the trash), custom fields of products, alt text / title / caption / description of media items, new posts and pages (Undo moves them to the trash), the categories, tags, brand and global attributes of a product (and the terms the change set creates for them), and the look of the site (styles, Additional CSS, theme settings, widgets, templates, Elementor). Refuses everything if anything no longer holds the expected values. A coupon above 50% off is refused here: it is approved in wp-admin (hold-changes).',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'changeset', 'action', 'items' ),
					'properties'           => array(
						'changeset' => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
						'action'    => array(
							'type' => 'string',
							'enum' => array( 'approve', 'undo' ),
						),
						'summary'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'items'     => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX_ITEMS,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'kind', 'id', 'expect', 'set' ),
								'properties'           => array(
									'kind'    => array(
										'type' => 'string',
										'enum' => StateUnits::KINDS,
									),
									'id'      => array(
										'type'    => 'integer',
										'minimum' => 0,
									),
									'adapter' => array( 'type' => 'string' ),
									'key'     => array(
										'type'      => 'string',
										'maxLength' => 300,
									),
									'expect'  => $values,
									'set'     => $values,
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 * @throws \Throwable When WordPress refuses a design write (the design units written before it are put back first).
	 */
	public function execute( $input ) {
		// 1.3.20: links were checked when the draft was made; Approve / Undo never refuse (or ask the site) because of a link.
		\Addsynapse\StoreAssistant\Links\OwnLinks::off();
		$this->action = (string) ( $input['action'] ?? '' );
		$items        = array_values( (array) $input['items'] );
		$this->refs   = self::term_refs( $items );
		// 1.3.16: product images may point at the images this change set adds ("@N").
		$this->media_refs = self::media_refs( $items );
		$this->items      = array_map(
			static function ( $item ) {
				return (array) $item;
			},
			$items
		);
		$conflicts        = array();
		$adapter          = SeoAdapters::current()->key();
		foreach ( $items as $i => $item ) {
			$conflict = $this->check( (array) $item, $adapter );
			if ( '' !== $conflict ) {
				$conflicts[] = array(
					'index'  => $i,
					'kind'   => (string) $item['kind'],
					'id'     => (int) $item['id'],
					'reason' => $conflict,
				);
			}
		}
		if ( $conflicts ) {
			$brake = in_array( CouponBrake::message(), array_column( $conflicts, 'reason' ), true );
			ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), array(), $brake ? 'refused: ' . CouponBrake::ERROR : 'refused: ' . count( $conflicts ) . ' conflict(s)' );
			// A big coupon: the typed refusal of the Connector («approve it in the shop admin»), with the page to go to.
			return array(
				'ok'        => false,
				'conflicts' => $conflicts,
			) + ( $brake ? array(
				'error'       => CouponBrake::ERROR,
				'message'     => CouponBrake::message(),
				'approve_url' => AdminApprovals::url( (string) $input['changeset'] ),
			) : array() );
		}
		$ids     = array();
		$rows    = array();
		$written = array();
		$design  = array();
		$notify  = array();
		$created = array();
		$kept    = array();
		// 1.3.9: new terms first (product_terms items point at them with "@N"); the Undo of a created term last (deleted only
		// once the products of this change set no longer use it).
		$first = array();
		$last  = array();
		$rest  = array();
		foreach ( $items as $index => $item ) {
			$is_term  = 'term' === (string) $item['kind'];
			$is_media = 'media' === (string) $item['kind'];
			if ( ( $is_term || $is_media ) && 0 === (int) $item['id'] ) {
				$first[] = $index;
			} elseif ( ( $is_term || $is_media ) && ! is_array( $item['set'] ) ) {
				// 1.3.16: an added image is deleted last, once the products of this change set no longer use it.
				$last[] = $index;
			} else {
				$rest[] = $index;
			}
		}
		foreach ( array_merge( $first, $rest, $last ) as $index ) {
			$item = $items[ $index ];
			$kind = (string) $item['kind'];
			if ( ProductTerms::handles( $kind ) ) {
				$set    = is_array( $item['set'] ) ? $item['set'] : null;
				$before = (int) $item['id'] ? ProductTerms::read( $kind, (int) $item['id'] ) : array();
				$id     = (int) $item['id'];
				if ( 'term' === $kind && null === $set ) {
					if ( ! ProductTerms::delete_created( $id ) ) {
						$kept[] = $index;
					}
				} elseif ( 'term' === $kind ) {
					$id                = ProductTerms::write( $kind, 0, $set, (string) $input['changeset'] );
					$created[ $index ] = $id;
				} else {
					foreach ( $set as $field => $value ) {
						$set[ $field ] = ProductTerms::resolve( (string) $field, $value, $created );
					}
					ProductTerms::write( $kind, $id, $set, (string) $input['changeset'] );
					$stored            = ProductTerms::read( $kind, $id );
					$written[ $index ] = array_intersect_key( (array) $stored['values'], $set );
				}
				$ids[ $index ]  = $id;
				$rows[ $index ] = array(
					'id'   => $id,
					'kind' => $kind,
					'name' => ! empty( $before['exists'] ) ? (string) $before['name'] : (string) ( $set['name'] ?? '' ),
					'from' => ProductTerms::for_log( $item['expect'] ),
					'to'   => in_array( $index, $kept, true ) ? 'kept (in use)' : ProductTerms::for_log( $set ),
				);
				continue;
			}//end if
			if ( FieldUnits::handles( $kind ) ) {
				// 1.3.10: custom fields, media texts, a new post (created on Approve, trashed on Undo).
				$set    = is_array( $item['set'] ) ? $item['set'] : null;
				$id     = (int) $item['id'];
				$before = $id ? FieldUnits::read( $kind, $id ) : array();
				$id     = FieldUnits::write( $kind, $id, $set, (string) $input['changeset'], 'undo' === $this->action );
				if ( null !== $set ) {
					$stored            = FieldUnits::read( $kind, $id );
					$written[ $index ] = array_intersect_key( (array) $stored['values'], $set ) + array_fill_keys( array_keys( $set ), null );
				}
				$ids[ $index ]  = $id;
				$rows[ $index ] = array(
					'id'   => $id,
					'kind' => $kind,
					'name' => ! empty( $before['exists'] ) ? (string) $before['name'] : ( OrderCreate::KIND === $kind ? __( 'New order', 'addsynapse-store-assistant' ) : (string) ( $set['title'] ?? $set['name'] ?? '' ) ),
					'from' => FieldUnits::for_log( $item['expect'] ),
					'to'   => null === $set ? 'trash' : FieldUnits::for_log( $set ),
				);
				continue;
			}//end if
			if ( DesignUnits::handles( $kind ) ) {
				$key    = (string) ( $item['key'] ?? '' );
				$before = DesignUnits::read( $kind, $key );
				try {
					DesignUnits::write( $kind, $key, (array) $item['set'] );
				} catch ( \Throwable $e ) {
					// A design change is several places at most: put the ones already written back, then report the failure.
					foreach ( array_reverse( $design ) as $done ) {
						try {
							DesignUnits::write( $done[0], $done[1], $done[2] );
						} catch ( \Throwable $ignored ) {
							unset( $ignored );
						}
					}
					ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), array(), 'failed: ' . mb_substr( $e->getMessage(), 0, 150 ) );
					throw $e;
				}
				$design[]       = array( $kind, $key, (array) $item['expect'] );
				$ids[ $index ]  = 0;
				$rows[ $index ] = array(
					'id'   => 0,
					'kind' => $kind,
					'name' => ! empty( $before['exists'] ) ? (string) $before['name'] : $key,
					'from' => DesignUnits::for_log( $kind, $item['expect'] ),
					'to'   => DesignUnits::for_log( $kind, $item['set'] ),
				);
				continue;
			}//end if
			$set = is_array( $item['set'] ) ? $item['set'] : null;
			if ( 'media' === $kind && null === $set && self::image_in_use( (int) $item['id'] ) ) {
				// 1.3.16: still used somewhere else after the products got their images back: kept, never deleted.
				$kept[]         = $index;
				$ids[ $index ]  = (int) $item['id'];
				$rows[ $index ] = array(
					'id'   => (int) $item['id'],
					'kind' => $kind,
					'name' => wp_basename( (string) get_attached_file( (int) $item['id'] ) ),
					'from' => StateUnits::for_log( $kind, $item['expect'] ),
					'to'   => 'kept (in use)',
				);
				continue;
			}
			if ( ProductImages::handles( $kind ) && null !== $set ) {
				$set = ProductImages::resolve( $set, $created );
			}
			$before = (int) $item['id'] ? StateUnits::read(
				array(
					'kind' => $kind,
					'id'   => (int) $item['id'],
				)
			) : array();
			try {
				$id = StateUnits::write( $kind, (int) $item['id'], $set, (string) $input['changeset'], 'undo' === $this->action );
			} catch ( \Throwable $e ) {
				// 1.3.16: one image of several could not be downloaded: the images this call already added are deleted again
				// (nothing else was written yet: new images come first).
				foreach ( 'media' === $kind && 0 === (int) $item['id'] ? $this->media_refs : array() as $ref ) {
					if ( ! empty( $created[ $ref ] ) && 'media' === (string) $items[ $ref ]['kind'] ) {
						wp_delete_attachment( (int) $created[ $ref ], true );
					}
				}
				ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), array(), 'failed: ' . mb_substr( $e->getMessage(), 0, 150 ) );
				throw $e;
			}
			$ids[ $index ] = $id;
			if ( 'media' === $kind && 0 === (int) $item['id'] ) {
				$created[ $index ] = $id;
			}
			if ( ProductImages::handles( $kind ) && null !== $set ) {
				// 1.3.16: the ids as stored ("@N" resolved): AddSynapse records these as the values after Approve.
				$stored            = ProductImages::read( $id );
				$written[ $index ] = array_intersect_key( (array) $stored['values'], $set );
			}
			if ( 'content' === $kind && null !== $set ) {
				// 1.3.10: the texts as WordPress stored them (cleaned): AddSynapse records these as the values after Approve.
				$stored            = StateUnits::read(
					array(
						'kind' => $kind,
						'id'   => $id,
					)
				);
				$written[ $index ] = array_intersect_key( (array) $stored['values'], $set );
			}
			if ( 'menu' === $kind ) {
				// What the menu adapter stored (new items now have ids): AddSynapse expects exactly this on Undo.
				$stored            = StateUnits::read(
					array(
						'kind' => $kind,
						'id'   => $id,
					)
				);
				$written[ $index ] = $stored['values'];
			}
			$rows[ $index ] = array(
				'id'   => $id,
				'kind' => $kind,
				'name' => ! empty( $before['exists'] ) ? (string) $before['name'] : (string) ( $set['code'] ?? '' ),
				'from' => StateUnits::for_log( $kind, $item['expect'] ),
				'to'   => StateUnits::for_log( $kind, $set ),
			);
			if ( 'coupon' === $kind && null !== $set && 'approve' === $input['action'] && ! self::$in_admin ) {
				$notify[] = array( $id, $set, 0 === (int) $item['id'] );
			}
		}//end foreach
		ksort( $ids );
		ksort( $rows );
		$rows = array_values( $rows );
		ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), $rows, 'ok' );
		// A coupon approved from AddSynapse: the site's administrator gets a short notice (security review 22/10/2026).
		foreach ( $notify as $n ) {
			CouponBrake::notify( (int) $n[0], (array) $n[1], (bool) $n[2] );
		}
		return array(
			'ok'      => true,
			'ids'     => array_values( $ids ),
			'written' => $written,
		) + ( $kept ? array( 'kept' => $kept ) : array() );
	}

	/**
	 * Why this unit may not be written now ('' = it may).
	 *
	 * @param array<string,mixed> $item    Item.
	 * @param string              $adapter Active SEO adapter key.
	 */
	private function check( array $item, string $adapter ): string {
		$kind   = (string) $item['kind'];
		$id     = (int) $item['id'];
		$expect = is_array( $item['expect'] ) ? $item['expect'] : null;
		$set    = is_array( $item['set'] ) ? $item['set'] : null;
		if ( DesignUnits::handles( $kind ) ) {
			return $this->check_design( $kind, (string) ( $item['key'] ?? '' ), $expect, $set );
		}
		if ( ProductTerms::handles( $kind ) ) {
			return $this->check_terms( $kind, $id, $expect, $set );
		}
		if ( FieldUnits::handles( $kind ) ) {
			return FieldUnits::problem( $kind, $id, $expect, $set );
		}
		if ( ProductImages::handles( $kind ) ) {
			return ProductImages::problem( $id, $expect, $set, $this->media_refs );
		}
		if ( null !== $set ) {
			$invalid = StateUnits::invalid( $kind, $set );
			if ( '' !== $invalid ) {
				return $invalid;
			}
		}
		// Owner 22/10/2026: a coupon above 50 % off (new, raised, switched to percent, or brought back by an Undo) is never
		// written from AddSynapse — only on the wp-admin approval page (whatever AddSynapse sends).
		if ( ! self::$in_admin && CouponBrake::needs_admin( $kind, $id, $set, 'undo' === $this->action ) ) {
			return CouponBrake::message();
		}
		// 1.3.7 plan_media_upload: a new image (id 0) is downloaded only now, after the owner approved — the address is checked
		// again here (public https host), and once more by WordPress on every redirect.
		if ( 'media' === $kind && 0 === $id ) {
			if ( null !== $expect || null === $set ) {
				return 'A new image needs its address and no expected values.';
			}
			if ( ! current_user_can( 'upload_files' ) ) {
				return 'This user may not upload files.';
			}
			return MediaSideload::url_problem( $set['url'] );
		}
		if ( 'coupon' === $kind && 0 === $id ) {
			if ( null !== $expect || null === $set || empty( $set['code'] ) ) {
				return 'A new coupon needs a code and no expected values.';
			}
			return wc_get_coupon_id_by_code( (string) $set['code'] ) ? 'A coupon with this code already exists.' : '';
		}
		if ( null === $expect || ( null === $set && ! in_array( $kind, array( 'coupon', 'media' ), true ) ) ) {
			return 'Expected and new values are required.';
		}
		if ( 'seo' === $kind && ( ! SeoAdapters::current()->supports_seo() || ( isset( $item['adapter'] ) && $item['adapter'] !== $adapter ) ) ) {
			return 'The SEO plugin changed since the draft (now: ' . $adapter . ').';
		}
		if ( 'menu' === $kind && isset( $item['adapter'] ) && \Addsynapse\StoreAssistant\Adapters\Menu\MenuAdapters::current()->kind() !== $item['adapter'] ) {
			return 'The theme\'s menu type changed since the draft.';
		}
		$now = StateUnits::read(
			array(
				'kind' => $kind,
				'id'   => $id,
			)
		);
		if ( empty( $now['exists'] ) ) {
			return (string) ( $now['reason'] ?? 'Not found.' );
		}
		// Undo of a coupon the change set created deletes it — never once an order used it (the orders keep the code).
		if ( 'coupon' === $kind && null === $set && ! empty( $now['values']['usage_count'] ) ) {
			return sprintf( 'The coupon was already used in %d order(s), so it is not deleted.', (int) $now['values']['usage_count'] );
		}
		if ( ! StateUnits::holds( $kind, (array) $now['values'], $expect ) ) {
			return 'Changed since the draft.';
		}
		// Undo of an added image deletes it — never while the site uses it (a page, the logo, the site icon…).
		if ( 'media' === $kind && null === $set && self::image_in_use( $id ) && ! $this->released_here( $id ) ) {
			return 'The image is used on the site now, so it is not deleted. Remove it there first (or keep it).';
		}
		if ( 'template' === $kind && null !== $set ) {
			return PageTemplates::problem( $id, $set['template'] ?? null, 'undo' === $this->action ? (string) ( $set['template'] ?? '' ) : '' );
		}
		return '';
	}

	/**
	 * Checks of a product_terms / term unit.
	 *
	 * @param string                   $kind   Kind.
	 * @param int                      $id     Id.
	 * @param array<string,mixed>|null $expect Expected values.
	 * @param array<string,mixed>|null $set    New values.
	 */
	private function check_terms( string $kind, int $id, ?array $expect, ?array $set ): string {
		if ( null !== $set ) {
			$invalid = ProductTerms::invalid( $kind, $set, 'approve' === $this->action ? $this->refs : array() );
			if ( '' !== $invalid ) {
				return $invalid;
			}
		}
		if ( 'term' === $kind && 0 === $id ) {
			if ( null !== $expect || null === $set ) {
				return 'A new term needs its taxonomy and name and no expected values.';
			}
			$object = get_taxonomy( (string) $set['taxonomy'] );
			if ( ! $object || ! current_user_can( $object->cap->edit_terms ) ) {
				return 'This user may not add terms here.';
			}
			$existing = \Addsynapse\StoreAssistant\Changesets\TermsPlanner::find_exact( (string) $set['name'], (string) $set['taxonomy'] );
			return $existing instanceof \WP_Term ? sprintf( 'A term named "%s" already exists (#%d).', $existing->name, $existing->term_id ) : '';
		}
		if ( null === $expect || ( null === $set && 'term' !== $kind ) ) {
			return 'Expected and new values are required.';
		}
		$now = ProductTerms::read( $kind, $id );
		if ( empty( $now['exists'] ) ) {
			return (string) ( $now['reason'] ?? 'Not found.' );
		}
		if ( ! ProductTerms::holds( $kind, (array) $now['values'], $expect ) ) {
			return 'Changed since the draft.';
		}
		return null === $set ? '' : ProductTerms::problem( $kind, (array) $now['values'], $set );
	}

	/**
	 * Whether an image is used anywhere on the site (content, featured images, logo, site icon).
	 *
	 * @param int $id Attachment id.
	 */
	private static function image_in_use( int $id ): bool {
		$mods = get_theme_mods();
		foreach ( array( get_option( 'site_logo' ), get_option( 'site_icon' ), is_array( $mods ) ? ( $mods['custom_logo'] ?? 0 ) : 0 ) as $used ) {
			if ( is_numeric( $used ) && (int) $used === $id ) {
				return true;
			}
		}
		return ImageUsage::referenced( $id );
	}

	/**
	 * Why a design unit may not be written now ('' = it may): it must hold the expected values and the new values must be
	 * ones WordPress keeps as they are (checked again here, whatever prepared them).
	 *
	 * @param string                   $kind   Kind.
	 * @param string                   $key    Key.
	 * @param array<string,mixed>|null $expect Expected values.
	 * @param array<string,mixed>|null $set    New values.
	 */
	private function check_design( string $kind, string $key, ?array $expect, ?array $set ): string {
		if ( null === $expect || null === $set || '' === $key ) {
			return 'Key, expected and new values are required.';
		}
		$now = DesignUnits::read( $kind, $key );
		if ( empty( $now['exists'] ) ) {
			return (string) ( $now['reason'] ?? 'Not found.' );
		}
		if ( ! DesignUnits::holds( $kind, (array) $now['values'], $expect ) ) {
			return 'Changed since the draft.';
		}
		return DesignUnits::invalid( $kind, $key, $set, (array) $now['values'] );
	}
}
