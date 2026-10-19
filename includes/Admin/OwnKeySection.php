<?php
/**
 * Settings → «Your own AI key»: provider, model, key, compatibility mode, Save and test, usage.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Brain\Models;
use Addsynapse\StoreAssistant\Agent\ErrorMessages;
use Addsynapse\StoreAssistant\Byok\DirectClient;
use Addsynapse\StoreAssistant\Byok\KeyStore;
use Addsynapse\StoreAssistant\Byok\Usage;
use Addsynapse\StoreAssistant\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered (admin-post + nonce, administrators only). The key field is write-only: the page shows only the last
 * 4 characters. Nothing is sent to the provider before an administrator presses «Save and test» (Guideline 7), and the
 * key is never sent to the browser or to AddSynapse.
 */
final class OwnKeySection {

	public const ACTION_SAVE   = 'addsynapse_assistant_save_own_key';
	public const ACTION_DELETE = 'addsynapse_assistant_delete_own_key';

	/**
	 * Transient (per user, 5 minutes) with the text of the last failed test.
	 */
	private const ERROR_TRANSIENT = 'addsynapse_assistant_own_key_error_';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( $this, 'handle_delete' ) );
	}

	/**
	 * Who may change the key: the settings capability AND manage_options (administrators: the key is billed by its owner).
	 */
	public static function can_manage(): bool {
		return current_user_can( Config::settings_capability() ) && current_user_can( 'manage_options' );
	}

	/**
	 * Save + one tiny test call; only a passing test makes the chat use the key.
	 */
	public function handle_save(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_SAVE );
		$provider = isset( $_POST['addsynapse_assistant_ai_provider'] ) ? sanitize_key( wp_unslash( $_POST['addsynapse_assistant_ai_provider'] ) ) : '';
		$model    = isset( $_POST['addsynapse_assistant_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['addsynapse_assistant_ai_model'] ) ) : '';
		// An API key is opaque (like a password): never sanitize_text_field, which could change it. Read raw, trimmed,
		// and accepted only when it has no whitespace and a sane length; it is only ever stored encrypted and sent
		// to the provider's API in a request header.
		$key = trim( (string) filter_input( INPUT_POST, 'addsynapse_assistant_ai_key', FILTER_UNSAFE_RAW ) );
		if ( '' !== $key && ! KeyStore::valid_key( $key ) ) {
			self::redirect( 'own_key_bad_format' );
		}
		KeyStore::set_step_mode( ! empty( $_POST['addsynapse_assistant_ai_step_mode'] ) );
		if ( ! KeyStore::save( $provider, KeyStore::clean_model( $model ), '' !== $key ? $key : null ) ) {
			self::redirect( 'own_key_invalid' );
		}
		$result = ( new DirectClient() )->test( $provider, KeyStore::model(), KeyStore::key( $provider ) );
		if ( true === $result ) {
			KeyStore::mark_tested();
			self::redirect( 'own_key_ok' );
		}
		set_transient( self::ERROR_TRANSIENT . get_current_user_id(), ErrorMessages::for_code( $result->get_error_code(), $result->getMessage() ), 5 * MINUTE_IN_SECONDS );
		self::redirect( 'own_key_failed' );
	}

	/**
	 * Forget the key (the AI stops answering until a key is saved again; slash commands keep working).
	 */
	public function handle_delete(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ), 403 );
		}
		check_admin_referer( self::ACTION_DELETE );
		KeyStore::delete();
		self::redirect( 'own_key_deleted' );
	}

	/**
	 * Redirect back with a notice code.
	 *
	 * @param string $notice Notice.
	 */
	private static function redirect( string $notice ): void {
		wp_safe_redirect( SettingsPage::url( array( 'addsynapse_assistant_notice' => $notice ) ) . '#addsynapse-assistant-own-key' );
		exit;
	}

	/**
	 * Provider display name.
	 *
	 * @param string $provider Provider id.
	 */
	public static function label( string $provider ): string {
		$labels = array(
			'anthropic' => 'Anthropic (Claude)',
			'openai'    => 'OpenAI (GPT)',
		);
		return $labels[ $provider ] ?? '';
	}

	/**
	 * Renders the section.
	 *
	 * @param string $notice Notice code of this page load.
	 */
	public static function render( string $notice ): void {
		$settings = KeyStore::settings();
		echo '<div class="aiops-connector__card aiops-own-key" id="addsynapse-assistant-own-key">';
		echo '<h3>' . esc_html__( 'Your own AI key (free)', 'addsynapse-store-assistant' ) . '</h3>';
		echo '<p>' . esc_html__( 'Use the assistant with your own Anthropic or OpenAI API key. The plugin and every one of its tools are free; you pay your AI provider directly for what you use.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'When you chat, your message, the conversation, a summary of the site structure and the results of the tools the assistant uses for your question (for example product names, prices, stock and sales figures — never customer names, emails or phone numbers) are sent from this server to the AI provider you choose, under that provider\'s terms and privacy policy. An image you attach in the chat is sent with that message (a reduced copy; you can turn this off below: «The AI sees the images you upload»). Nothing is sent before you press «Save and test», and nothing goes to AddSynapse.', 'addsynapse-store-assistant' ) . '</p>';
		echo '<p class="description">';
		echo esc_html__( 'Anthropic:', 'addsynapse-store-assistant' ) . ' <a href="' . esc_url( 'https://www.anthropic.com/legal/commercial-terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'addsynapse-store-assistant' ) . '</a> · <a href="' . esc_url( 'https://www.anthropic.com/legal/privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'addsynapse-store-assistant' ) . '</a> — ';
		echo esc_html__( 'OpenAI:', 'addsynapse-store-assistant' ) . ' <a href="' . esc_url( 'https://openai.com/policies/business-terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'addsynapse-store-assistant' ) . '</a> · <a href="' . esc_url( 'https://openai.com/policies/privacy-policy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'addsynapse-store-assistant' ) . '</a>';
		echo '</p>';

		if ( 'own_key_ok' === $notice ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'The key works. The chat now uses your own AI key.', 'addsynapse-store-assistant' ) . '</strong></p></div>';
		} elseif ( 'own_key_failed' === $notice ) {
			$message = (string) get_transient( self::ERROR_TRANSIENT . get_current_user_id() );
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'The key was saved but the test failed, so the chat does not use it yet.', 'addsynapse-store-assistant' ) . '</strong> ' . esc_html( $message ) . '</p></div>';
		} elseif ( 'own_key_invalid' === $notice ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Choose a provider and one of its models (a model of the other provider cannot be used). Nothing was saved.', 'addsynapse-store-assistant' ) . '</p></div>';
		} elseif ( 'own_key_bad_format' === $notice ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This does not look like an API key (only letters, digits and - _ . : are allowed). Nothing was saved.', 'addsynapse-store-assistant' ) . '</p></div>';
		} elseif ( 'own_key_deleted' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Your AI key was removed from this site.', 'addsynapse-store-assistant' ) . '</p></div>';
		}
		if ( KeyStore::unreadable() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The saved key can no longer be read (the site\'s security keys changed). Enter it again.', 'addsynapse-store-assistant' ) . '</p></div>';
		}
		if ( KeyStore::active() ) {
			if ( self::can_manage() ) {
				/* translators: 1: provider name, 2: model id, 3: last characters of the key. */
				echo '<p class="aiops-connector__line"><strong>' . esc_html( sprintf( __( 'Active: %1$s, model %2$s, key %3$s.', 'addsynapse-store-assistant' ), self::label( $settings['provider'] ), $settings['model'], KeyStore::hint() ) ) . '</strong></p>';
			} else {
				// Shop managers see that the AI is set up, never any part of the key (administrators only).
				/* translators: 1: provider name, 2: model id. */
				echo '<p class="aiops-connector__line"><strong>' . esc_html( sprintf( __( 'Active: %1$s, model %2$s.', 'addsynapse-store-assistant' ), self::label( $settings['provider'] ), $settings['model'] ) ) . '</strong></p>';
			}
		}
		if ( ! self::can_manage() ) {
			echo '<p class="description">' . esc_html__( 'Only an administrator can change the AI key.', 'addsynapse-store-assistant' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		wp_nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="addsynapse-assistant-ai-provider">' . esc_html__( 'AI provider', 'addsynapse-store-assistant' ) . '</label></th><td><select id="addsynapse-assistant-ai-provider" name="addsynapse_assistant_ai_provider">';
		foreach ( Models::PROVIDERS as $provider ) {
			echo '<option value="' . esc_attr( $provider ) . '"' . selected( $settings['provider'], $provider, false ) . '>' . esc_html( self::label( $provider ) ) . '</option>';
		}
		echo '</select></td></tr>';
		// Only the models of the selected provider (settings.js swaps the list when the provider changes; the server
		// refuses a model of another provider). The recommended model comes first.
		$current   = '' !== $settings['provider'] ? $settings['provider'] : Models::PROVIDERS[0];
		$selected  = '' !== $settings['model'] && Models::matches( $current, $settings['model'] ) ? $settings['model'] : Models::default_model( $current );
		$catalog   = array();
		$recommend = __( '(recommended)', 'addsynapse-store-assistant' );
		foreach ( Models::PROVIDERS as $provider ) {
			$list = array();
			foreach ( Models::options( $provider ) as $id => $label ) {
				$list[] = array(
					'id'    => $id,
					'label' => $label . ( Models::default_model( $provider ) === $id ? ' ' . $recommend : '' ),
				);
			}
			$catalog[ $provider ] = $list;
		}
		echo '<tr><th scope="row"><label for="addsynapse-assistant-ai-model">' . esc_html__( 'Model', 'addsynapse-store-assistant' ) . '</label></th><td><select id="addsynapse-assistant-ai-model" name="addsynapse_assistant_ai_model" data-addsynapse-assistant-models="' . esc_attr( (string) wp_json_encode( $catalog ) ) . '">';
		foreach ( $catalog[ $current ] as $option ) {
			echo '<option value="' . esc_attr( $option['id'] ) . '"' . selected( $selected, $option['id'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
		}
		if ( '' !== $settings['model'] && $settings['model'] === $selected && ! isset( Models::options( $current )[ $selected ] ) ) {
			// A newer model of the same provider typed earlier stays selectable.
			echo '<option value="' . esc_attr( $selected ) . '" selected="selected">' . esc_html( $selected ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The models of the selected provider. Small, fast models cost the least.', 'addsynapse-store-assistant' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="addsynapse-assistant-ai-key">' . esc_html__( 'API key', 'addsynapse-store-assistant' ) . '</label></th><td>';
		if ( '' !== $settings['provider'] && KeyStore::from_constant( $settings['provider'] ) ) {
			/* translators: %s: PHP constant name. */
			echo '<p>' . esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'addsynapse-store-assistant' ), KeyStore::CONSTANTS[ $settings['provider'] ] ) ) . '</p>';
		} else {
			$hint        = KeyStore::hint();
			$placeholder = '' !== $hint
				/* translators: %s: last characters of the saved key. */
				? sprintf( __( 'Saved (%s) — leave empty to keep it', 'addsynapse-store-assistant' ), $hint )
				: '';
			echo '<input type="password" class="regular-text" id="addsynapse-assistant-ai-key" name="addsynapse_assistant_ai_key" value="" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $placeholder ) . '" />';
			echo '<p class="description">' . esc_html__( 'Stored encrypted in the database (not autoloaded) and used only on this server; it is never shown again or sent to the browser. For extra safety define it in wp-config.php instead: ADDSYNAPSE_ASSISTANT_ANTHROPIC_KEY or ADDSYNAPSE_ASSISTANT_OPENAI_KEY.', 'addsynapse-store-assistant' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Get a key:', 'addsynapse-store-assistant' ) . ' <a href="' . esc_url( 'https://console.anthropic.com/settings/keys' ) . '" target="_blank" rel="noopener noreferrer">Anthropic</a> · <a href="' . esc_url( 'https://platform.openai.com/api-keys' ) . '" target="_blank" rel="noopener noreferrer">OpenAI</a></p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Compatibility mode', 'addsynapse-store-assistant' ) . '</th><td><label><input type="checkbox" name="addsynapse_assistant_ai_step_mode" value="1"' . checked( KeyStore::step_mode(), true, false ) . ' /> ' . esc_html__( 'One AI step per request (for hosts that stop long requests or buffer the live answer).', 'addsynapse-store-assistant' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save and test', 'addsynapse-store-assistant' ), 'primary', 'submit', false );
		echo '</form>';

		if ( '' !== $settings['provider'] ) {
			self::render_usage();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aiops-own-key__remove">';
			wp_nonce_field( self::ACTION_DELETE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE ) . '" />';
			submit_button( __( 'Remove my key', 'addsynapse-store-assistant' ), 'link-delete', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Usage of the own key: this month and the last 30 days (estimate).
	 */
	public static function render_usage(): void {
		$summary = Usage::summary();
		echo '<h4 id="addsynapse-assistant-own-key-usage">' . esc_html__( 'Usage of your AI key', 'addsynapse-store-assistant' ) . '</h4>';
		echo '<table class="widefat striped aiops-own-key__usage"><thead><tr><th></th><th>' . esc_html__( 'AI calls', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Input tokens (of them from cache)', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Output tokens', 'addsynapse-store-assistant' ) . '</th><th>' . esc_html__( 'Estimated cost (USD)', 'addsynapse-store-assistant' ) . '</th></tr></thead><tbody>';
		$periods = array(
			'month'  => __( 'This month', 'addsynapse-store-assistant' ),
			'days30' => __( 'Last 30 days', 'addsynapse-store-assistant' ),
		);
		foreach ( $periods as $key => $label ) {
			$row  = $summary[ $key ];
			$cost = number_format_i18n( (float) $row['cost'], 4 );
			if ( $row['unknown'] > 0 ) {
				/* translators: %d: number of AI calls whose model has no known price. */
				$cost .= ' ' . sprintf( _n( '+ %d call with an unknown price', '+ %d calls with an unknown price', (int) $row['unknown'], 'addsynapse-store-assistant' ), (int) $row['unknown'] );
			}
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( (int) $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['tokens_in'] ) . ' (' . number_format_i18n( (int) $row['cache_read'] ) . ')' ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['tokens_out'] ) ) . '</td><td>' . esc_html( $cost ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'An estimate from this site\'s records at the providers\' list prices; your AI provider\'s dashboard shows the real bill.', 'addsynapse-store-assistant' ) . '</p>';
	}
}
