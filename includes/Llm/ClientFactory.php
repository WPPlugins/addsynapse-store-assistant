<?php
/**
 * The LLM client of the plugin.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Llm;

use Addsynapse\StoreAssistant\Byok\DirectClient;
use Addsynapse\StoreAssistant\Seams;

defined( 'ABSPATH' ) || exit;

/**
 * The manager's own AI key (Anthropic / OpenAI), saved and tested in Settings. There is no filter to plug another
 * client in. No key → is_configured() is false: the AI asks for a key (no call is made), while slash commands still
 * run on the site.
 */
final class ClientFactory {

	/**
	 * Builds the client (the PHPUnit suite injects a mock through Seams `llm_client`).
	 */
	public static function make(): LlmClient {
		$client = Seams::get( 'llm_client', LlmClient::class );
		if ( null !== $client ) {
			return $client;
		}
		return new DirectClient();
	}
}
