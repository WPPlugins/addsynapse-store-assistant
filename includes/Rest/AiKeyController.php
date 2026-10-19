<?php
/**
 * REST for the chat's «Use your own AI key» card: save + test, remove. The key is never returned.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

use Addsynapse\StoreAssistant\Admin\OwnKeySection;
use Addsynapse\StoreAssistant\Agent\ErrorMessages;
use Addsynapse\StoreAssistant\Byok\DirectClient;
use Addsynapse\StoreAssistant\Byok\KeyStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /ai-key {provider, model?, key?} · DELETE /ai-key. Same rules as Settings → «Your own AI key».
 */
final class AiKeyController {

	/**
	 * Base permission (nonce, logged in) + administrators only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		$base = RestRoutes::permission( $request );
		if ( true !== $base ) {
			return $base;
		}
		return OwnKeySection::can_manage() ? true : new WP_Error( 'rest_forbidden', __( 'Only an administrator can change the AI key.', 'addsynapse-store-assistant' ), array( 'status' => 403 ) );
	}

	/**
	 * What the UI may know about the key (never the key).
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$settings = KeyStore::settings();
		return array(
			'active'     => KeyStore::active(),
			'provider'   => $settings['provider'],
			'model'      => $settings['model'],
			// Last 4 characters: administrators only (the chat's /status is open to every user of the assistant).
			'hint'       => OwnKeySection::can_manage() ? KeyStore::hint() : '',
			'constant'   => '' !== $settings['provider'] && KeyStore::from_constant( $settings['provider'] ),
			'step_mode'  => KeyStore::step_mode(),
			'can_manage' => OwnKeySection::can_manage(),
		);
	}

	/**
	 * Save and test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$provider = sanitize_key( (string) $request['provider'] );
		$model    = KeyStore::clean_model( sanitize_text_field( (string) $request['model'] ) );
		// Opaque secret (like a password): not sanitize_text_field; trimmed and checked against the key alphabet.
		$key = trim( (string) $request['key'] );
		if ( '' !== $key && ! KeyStore::valid_key( $key ) ) {
			return new WP_Error( 'addsynapse_assistant_ai_key_invalid', __( 'This does not look like an API key.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		if ( ! KeyStore::save( $provider, $model, '' !== $key ? $key : null ) ) {
			return new WP_Error( 'addsynapse_assistant_ai_key_invalid', __( 'Choose a provider and one of its models (a model of the other provider cannot be used).', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		$result = ( new DirectClient() )->test( $provider, KeyStore::model(), KeyStore::key( $provider ) );
		if ( true !== $result ) {
			return new WP_Error( 'addsynapse_assistant_ai_key_test_failed', ErrorMessages::for_code( $result->get_error_code(), $result->getMessage() ), array( 'status' => 400 ) );
		}
		KeyStore::mark_tested();
		return new WP_REST_Response( self::state() );
	}

	/**
	 * Remove.
	 */
	public function delete(): WP_REST_Response {
		KeyStore::delete();
		return new WP_REST_Response( self::state() );
	}
}
