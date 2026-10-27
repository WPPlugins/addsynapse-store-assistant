<?php
/**
 * wp-admin approval of the changes AddSynapse may not apply by itself (CouponBrake: big or unbounded coupons). AddSynapse
 * hands the draft over (ability hold-changes — nothing is written); a site administrator sees every value on this page
 * (AddSynapse → Approvals) and approves it, discards it, or later undoes it — the same all-or-nothing writes and approval
 * log as the remote route (ApplyChangesAbility), here run by the logged-in administrator (capability + nonce).
 *
 * Kept in one non-autoloaded option: at most MAX entries, a draft not approved within HELD_TTL expires.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Admin\AddsynapseMenu;

defined( 'ABSPATH' ) || exit;

/**
 * Held change sets + their wp-admin page.
 */
final class AdminApprovals {

	public const OPTION   = 'addsynapse_assistant_admin_approvals';
	public const PAGE     = 'addsynapse-approvals';
	public const ACTION   = 'addsynapse_assistant_approval';
	public const MAX      = 20;
	public const HELD_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Hooks (wp-admin).
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), AddsynapseMenu::PRIORITY_SUBMENU );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * «Approvals» under AddSynapse while something waits; the page itself always exists (links from AddSynapse).
	 */
	public static function add_menu(): void {
		$cap = \Addsynapse\StoreAssistant\Config::capability();
		if ( self::waiting() > 0 ) {
			/* translators: %d: number of drafts waiting. */
			$label = sprintf( __( 'Approvals %s', 'addsynapse-store-assistant' ), '<span class="awaiting-mod">' . (int) self::waiting() . '</span>' );
			AddsynapseMenu::add_page( __( 'Approvals', 'addsynapse-store-assistant' ), $label, $cap, self::PAGE, array( self::class, 'render' ) );
			return;
		}
		add_submenu_page( '', __( 'Approvals', 'addsynapse-store-assistant' ), __( 'Approvals', 'addsynapse-store-assistant' ), $cap, self::PAGE, array( self::class, 'render' ) );
	}

	/**
	 * The page of one held change set.
	 *
	 * @param string $id Change set id.
	 */
	public static function url( string $id = '' ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( '' !== $id ? '&changeset=' . rawurlencode( $id ) : '' ) );
	}

	/**
	 * AddSynapse hands over a draft that needs this page. Checked like an approval (fields, «before» values) but nothing is
	 * written; the same change set again replaces its held copy (while not approved yet).
	 *
	 * @param array<string,mixed> $input {changeset, summary, items}.
	 * @return array<string,mixed>
	 */
	public static function hold( array $input ): array {
		$id    = (string) $input['changeset'];
		if ( ! empty( $input['status_only'] ) ) {
			return self::status( $id );
		}
		$items = array_values( (array) $input['items'] );
		$need  = false;
		foreach ( $items as $item ) {
			$item = (array) $item;
			if ( 'coupon' !== ( $item['kind'] ?? '' ) ) {
				return self::refused( 'Only coupons are approved in wp-admin.' );
			}
			$need = $need || CouponBrake::needs_admin( 'coupon', (int) $item['id'], is_array( $item['set'] ) ? $item['set'] : null );
		}
		if ( ! $need ) {
			return self::refused( 'This change does not need the wp-admin approval page.' );
		}
		$conflict = ApplyChangesAbility::conflicts( $items );
		if ( $conflict ) {
			return array(
				'ok'        => false,
				'conflicts' => $conflict,
			);
		}
		$all = self::all();
		if ( isset( $all[ $id ] ) && 'held' !== $all[ $id ]['status'] ) {
			return self::refused( 'This change set was already decided in wp-admin.' );
		}
		$all[ $id ] = array(
			'changeset' => $id,
			'summary'   => mb_substr( sanitize_text_field( (string) ( $input['summary'] ?? '' ) ), 0, 200 ),
			'items'     => $items,
			'created'   => time(),
			'status'    => 'held',
		);
		self::save( $all );
		return array(
			'ok'          => true,
			'held'        => true,
			'approve_url' => self::url( $id ),
		);
	}

	/**
	 * What an administrator decided on this page, so AddSynapse can show it on its Changes page. Reads only.
	 *
	 * @param string $id Change set id.
	 * @return array<string,mixed>
	 */
	public static function status( string $id ): array {
		$entry = self::all()[ $id ] ?? null;
		if ( ! is_array( $entry ) ) {
			return array(
				'ok'     => true,
				'status' => 'none',
			);
		}
		$status = (string) $entry['status'];
		if ( 'held' === $status && time() - (int) $entry['created'] > self::HELD_TTL ) {
			$status = 'expired';
		}
		$out = array(
			'ok'     => true,
			'status' => $status,
		);
		if ( isset( $entry['at'] ) ) {
			$out['at'] = gmdate( 'c', (int) $entry['at'] );
		}
		if ( ! empty( $entry['undo'][0]['id'] ) ) {
			$out['id'] = (int) $entry['undo'][0]['id'];
		}
		return $out;
	}

	/**
	 * Approve / Discard / Undo (admin-post, POST, nonce, capability).
	 */
	public static function handle(): void {
		$id = isset( $_POST['changeset'] ) ? sanitize_text_field( wp_unslash( $_POST['changeset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below.
		check_admin_referer( self::ACTION . '_' . $id );
		if ( ! AbilitiesRegistrar::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'addsynapse-store-assistant' ), 403 );
		}
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$result = self::decide( $id, $do );
		wp_safe_redirect( add_query_arg( 'done', rawurlencode( $result ), self::url( $id ) ) );
		exit;
	}

	/**
	 * Runs a decision of the logged-in administrator. Returns a short result code for the page.
	 *
	 * @param string $id Change set id.
	 * @param string $do approve|discard|undo.
	 */
	public static function decide( string $id, string $do ): string {
		$all   = self::all();
		$entry = $all[ $id ] ?? null;
		if ( ! is_array( $entry ) || ! AbilitiesRegistrar::can_manage() ) {
			return 'not_found';
		}
		if ( 'discard' === $do && 'held' === $entry['status'] ) {
			$all[ $id ]['status'] = 'discarded';
			$all[ $id ]['at']     = time();
			self::save( $all );
			return 'discarded';
		}
		if ( 'approve' === $do && 'held' === $entry['status'] ) {
			if ( time() - (int) $entry['created'] > self::HELD_TTL ) {
				return 'expired';
			}
			$r = ApplyChangesAbility::run_in_admin(
				array(
					'changeset' => $id,
					'action'    => 'approve',
					'summary'   => mb_substr( 'wp-admin: ' . (string) $entry['summary'], 0, 200 ),
					'items'     => $entry['items'],
				)
			);
			if ( empty( $r['ok'] ) ) {
				return 'conflict';
			}
			$undo = array();
			foreach ( $entry['items'] as $i => $item ) {
				$undo[] = array(
					'kind'   => 'coupon',
					'id'     => (int) ( $r['ids'][ $i ] ?? $item['id'] ),
					'expect' => $item['set'],
					'set'    => $item['expect'],
				);
			}
			$all[ $id ]['status'] = 'applied';
			$all[ $id ]['undo']   = $undo;
			$all[ $id ]['at']     = time();
			$all[ $id ]['by']     = get_current_user_id();
			self::save( $all );
			return 'applied';
		}
		if ( 'undo' === $do && 'applied' === $entry['status'] && ! empty( $entry['undo'] ) ) {
			$r = ApplyChangesAbility::run_in_admin(
				array(
					'changeset' => $id,
					'action'    => 'undo',
					'summary'   => mb_substr( 'wp-admin: ' . (string) $entry['summary'], 0, 200 ),
					'items'     => $entry['undo'],
				)
			);
			if ( empty( $r['ok'] ) ) {
				return 'undo_refused';
			}
			$all[ $id ]['status'] = 'undone';
			$all[ $id ]['at']     = time();
			self::save( $all );
			return 'undone';
		}
		return 'nothing';
	}

	/**
	 * Drafts waiting for a decision (not expired).
	 */
	public static function waiting(): int {
		$n = 0;
		foreach ( self::all() as $entry ) {
			if ( 'held' === $entry['status'] && time() - (int) $entry['created'] <= self::HELD_TTL ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Every entry, by change set id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? array_filter(
			$all,
			static function ( $e ) {
				return is_array( $e ) && isset( $e['status'], $e['items'] ) && is_array( $e['items'] );
			}
		) : array();
	}

	/**
	 * Saves (never autoloaded), newest MAX kept.
	 *
	 * @param array<string,array<string,mixed>> $all Entries.
	 */
	private static function save( array $all ): void {
		uasort(
			$all,
			static function ( $a, $b ) {
				return (int) ( $b['created'] ?? 0 ) <=> (int) ( $a['created'] ?? 0 );
			}
		);
		update_option( self::OPTION, array_slice( $all, 0, self::MAX, true ), false );
	}

	/**
	 * A refusal of hold().
	 *
	 * @param string $reason Why.
	 * @return array<string,mixed>
	 */
	private static function refused( string $reason ): array {
		return array(
			'ok'        => false,
			'conflicts' => array( array( 'reason' => $reason ) ),
		);
	}

	/**
	 * The page.
	 */
	public static function render(): void {
		if ( ! AbilitiesRegistrar::can_manage() ) {
			return;
		}
		$all  = self::all();
		$only = isset( $_GET['changeset'] ) ? sanitize_text_field( wp_unslash( $_GET['changeset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter only.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( '' !== $only && isset( $all[ $only ] ) ) {
			$all = array( $only => $all[ $only ] );
		}
		echo '<div class="wrap" data-adsa-approvals>';
		echo '<h1>' . esc_html__( 'Approvals', 'addsynapse-store-assistant' ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: percent limit, 2: fixed amount limit. */
				__( 'For safety, a coupon your AI prepared is approved here, by a site administrator, not in the AI chat, when it gives more than %1$d%% off, a fixed discount above %2$d, free shipping without a usage limit, or has neither a usage limit nor an expiry date. Check every value before you approve it.', 'addsynapse-store-assistant' ),
				CouponBrake::MAX_PERCENT,
				CouponBrake::MAX_FIXED
			)
		) . '</p>';
		$notes = array(
			'applied'      => __( 'Approved: the coupon is saved. You can undo it here.', 'addsynapse-store-assistant' ),
			'discarded'    => __( 'Discarded. Nothing was changed.', 'addsynapse-store-assistant' ),
			'undone'       => __( 'Undone: the previous values are back.', 'addsynapse-store-assistant' ),
			'conflict'     => __( 'Not applied: the coupon changed since the draft (or the code is taken). Nothing was changed. Ask your AI for a new draft.', 'addsynapse-store-assistant' ),
			'undo_refused' => __( 'Not undone: the coupon changed since, or an order already used it. Nothing was changed.', 'addsynapse-store-assistant' ),
			'expired'      => __( 'This draft has expired. Ask your AI for a new one.', 'addsynapse-store-assistant' ),
		);
		if ( isset( $notes[ $done ] ) ) {
			$ok = in_array( $done, array( 'applied', 'discarded', 'undone' ), true );
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '" data-adsa-approval-result="' . esc_attr( $done ) . '"><p>' . esc_html( $notes[ $done ] ) . '</p></div>';
		}
		if ( empty( $all ) ) {
			echo '<p>' . esc_html__( 'Nothing is waiting for your approval.', 'addsynapse-store-assistant' ) . '</p></div>';
			return;
		}
		$labels = array(
			'held'      => __( 'Waiting for your approval', 'addsynapse-store-assistant' ),
			'applied'   => __( 'Approved', 'addsynapse-store-assistant' ),
			'undone'    => __( 'Undone', 'addsynapse-store-assistant' ),
			'discarded' => __( 'Discarded', 'addsynapse-store-assistant' ),
		);
		foreach ( $all as $id => $entry ) {
			$expired = 'held' === $entry['status'] && time() - (int) $entry['created'] > self::HELD_TTL;
			echo '<div class="card" style="max-width:820px" data-adsa-approval="' . esc_attr( (string) $id ) . '" data-status="' . esc_attr( (string) $entry['status'] ) . '">';
			echo '<h2>' . esc_html( (string) $entry['summary'] ) . '</h2>';
			echo '<p><strong>' . esc_html( $expired ? __( 'Expired', 'addsynapse-store-assistant' ) : ( $labels[ $entry['status'] ] ?? (string) $entry['status'] ) ) . '</strong></p>';
			foreach ( $entry['items'] as $item ) {
				$before = is_array( $item['expect'] ?? null ) ? $item['expect'] : array();
				$after  = is_array( $item['set'] ?? null ) ? $item['set'] : array();
				echo '<table class="widefat striped" style="margin-bottom:12px"><thead><tr><th>' . esc_html__( 'Field', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Now', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'After approval', 'addsynapse-store-assistant' ) . '</th></tr></thead><tbody>';
				foreach ( array_keys( $after + $before ) as $field ) {
					echo '<tr><td>' . esc_html( (string) $field ) . '</td><td>' . esc_html( self::show( $before[ $field ] ?? null, empty( $before ) ) ) . '</td><td><strong>' . esc_html( self::show( $after[ $field ] ?? null, false ) ) . '</strong></td></tr>';
				}
				echo '</tbody></table>';
			}
			$buttons = array();
			if ( 'held' === $entry['status'] && ! $expired ) {
				$buttons = array(
					'approve' => __( 'Approve', 'addsynapse-store-assistant' ),
					'discard' => __( 'Discard', 'addsynapse-store-assistant' ),
				);
			} elseif ( 'applied' === $entry['status'] ) {
				$buttons = array( 'undo' => __( 'Undo', 'addsynapse-store-assistant' ) );
			}
			if ( $buttons ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::ACTION . '_' . $id );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="changeset" value="' . esc_attr( (string) $id ) . '">';
				foreach ( $buttons as $do => $label ) {
					echo '<button type="submit" name="do" value="' . esc_attr( $do ) . '" class="button ' . ( 'discard' === $do ? '' : 'button-primary' ) . '" style="margin-right:8px" data-adsa-approval-do="' . esc_attr( $do ) . '">' . esc_html( $label ) . '</button>';
				}
				echo '</form>';
			}
			echo '</div>';
		}//end foreach
		echo '</div>';
	}

	/**
	 * A value in words.
	 *
	 * @param mixed $v     Value.
	 * @param bool  $isnew No «before» (a new coupon).
	 */
	private static function show( $v, bool $isnew ): string {
		if ( $isnew ) {
			return '—';
		}
		if ( is_bool( $v ) ) {
			return $v ? __( 'yes', 'addsynapse-store-assistant' ) : __( 'no', 'addsynapse-store-assistant' );
		}
		if ( is_array( $v ) ) {
			return implode( ', ', array_map( 'strval', $v ) );
		}
		return null === $v || '' === $v ? '—' : (string) $v;
	}
}
