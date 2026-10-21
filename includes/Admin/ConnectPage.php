<?php
/**
 * Claude / ChatGPT through AddSynapse: the addresses used by the setup steps (HomePage, path «mcp») and the Approval
 * log (every change AddSynapse wrote here after the owner approved it; shown under Settings).
 *
 * 1.3.0: «Connect AI» is no longer a menu item — its old address redirects to Home (HomePage::legacy_redirects()).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Remote\ApprovalLog;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses + approval log.
 */
final class ConnectPage {

	public const SLUG = 'addsynapse-store-assistant-connect';

	/** The AddSynapse MCP address added once to the AI app. */
	public const MCP_URL = \Addsynapse\StoreAssistant\Account\Api::DEFAULT_URL . '/mcp';

	/** Claude Directory page of the AddSynapse connector. */
	public const CLAUDE_URL = 'https://claude.ai/directory/connectors/store-manager-for-woocommerce';

	/** ChatGPT (connectors are added under Settings → Apps). */
	public const CHATGPT_URL = 'https://chatgpt.com/';

	/**
	 * Address shown to the AI app (filterable for development hubs).
	 */
	public static function mcp_url(): string {
		/**
		 * Filters the MCP address shown on the Connect AI page.
		 *
		 * @param string $url Address.
		 */
		return (string) apply_filters( 'addsynapse_assistant_mcp_url', self::MCP_URL );
	}

	/**
	 * Approval log table.
	 */
	public static function render_log(): void {
		$log = ApprovalLog::all();
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No changes yet. Approved changes made through AddSynapse appear here.', 'addsynapse-store-assistant' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped adsa-log"><thead><tr>';
		foreach ( array( __( 'When', 'addsynapse-store-assistant' ), __( 'Action', 'addsynapse-store-assistant' ), __( 'Change set', 'addsynapse-store-assistant' ), __( 'Changes', 'addsynapse-store-assistant' ), __( 'Result', 'addsynapse-store-assistant' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $log as $e ) {
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $e['time'] ) ) . '<br><small>' . esc_html( (string) $e['user'] ) . '</small></td>';
			echo '<td>' . esc_html( 'undo' === $e['action'] ? __( 'Undo', 'addsynapse-store-assistant' ) : __( 'Approve', 'addsynapse-store-assistant' ) ) . '</td>';
			echo '<td>#' . esc_html( (string) $e['changeset'] ) . '<br><small>' . esc_html( (string) $e['summary'] ) . '</small></td><td>';
			foreach ( (array) $e['rows'] as $r ) {
				echo '<div>' . esc_html( ApprovalLog::describe( (array) $r ) ) . '</div>';
			}
			echo '</td><td>' . esc_html( (string) $e['result'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
