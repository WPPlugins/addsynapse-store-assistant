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
			$self = new self();
			$out  = array();
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
		}
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
				'description'         => 'Writes the values of a change set the shop owner approved (or restores them on Undo): price/stock fields of products and variations, SEO title/description, coupons, page/post content and status, featured images, navigation menus, and the look of the site (styles, Additional CSS, theme settings, widgets, templates, Elementor). Refuses everything if anything no longer holds the expected values. A coupon above 50% off is refused here: it is approved in wp-admin (hold-changes).',
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
		$this->action = (string) ( $input['action'] ?? '' );
		$items        = array_values( (array) $input['items'] );
		$conflicts = array();
		$adapter   = SeoAdapters::current()->key();
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
		foreach ( $items as $index => $item ) {
			$kind = (string) $item['kind'];
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
				$design[] = array( $kind, $key, (array) $item['expect'] );
				$ids[]    = 0;
				$rows[]   = array(
					'id'   => 0,
					'kind' => $kind,
					'name' => ! empty( $before['exists'] ) ? (string) $before['name'] : $key,
					'from' => DesignUnits::for_log( $kind, $item['expect'] ),
					'to'   => DesignUnits::for_log( $kind, $item['set'] ),
				);
				continue;
			}//end if
			$set    = is_array( $item['set'] ) ? $item['set'] : null;
			$before = (int) $item['id'] ? StateUnits::read(
				array(
					'kind' => $kind,
					'id'   => (int) $item['id'],
				)
			) : array();
			$id     = StateUnits::write( $kind, (int) $item['id'], $set );
			$ids[]  = $id;
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
			$rows[] = array(
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
		ApprovalLog::add( (string) $input['changeset'], (string) $input['action'], (string) ( $input['summary'] ?? '' ), $rows, 'ok' );
		// A coupon approved from AddSynapse: the site's administrator gets a short notice (security review 22/10/2026).
		foreach ( $notify as $n ) {
			CouponBrake::notify( (int) $n[0], (array) $n[1], (bool) $n[2] );
		}
		return array(
			'ok'      => true,
			'ids'     => $ids,
			'written' => $written,
		);
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
		if ( 'media' === $kind && null === $set && self::image_in_use( $id ) ) {
			return 'The image is used on the site now, so it is not deleted. Remove it there first (or keep it).';
		}
		if ( 'template' === $kind && null !== $set ) {
			return PageTemplates::problem( $id, $set['template'] ?? null, 'undo' === $this->action ? (string) ( $set['template'] ?? '' ) : '' );
		}
		return '';
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
