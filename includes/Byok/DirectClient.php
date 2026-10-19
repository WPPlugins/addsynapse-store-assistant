<?php
/**
 * LLM client that calls the manager's own AI provider directly (BYOK) — the free assistant's brain.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Byok;

use Addsynapse\StoreAssistant\Brain\Classifier;
use Addsynapse\StoreAssistant\Brain\DataBlocks;
use Addsynapse\StoreAssistant\Brain\ReplyLanguage;
use Addsynapse\StoreAssistant\Brain\Models;
use Addsynapse\StoreAssistant\Brain\Prompt;
use Addsynapse\StoreAssistant\Brain\TokenEstimate;
use Addsynapse\StoreAssistant\Brain\ToolCatalog;
use Addsynapse\StoreAssistant\Brain\Provider\Adapters;
use Addsynapse\StoreAssistant\Brain\Provider\ProviderAdapter;
use Addsynapse\StoreAssistant\Brain\Provider\ProviderError;
use Addsynapse\StoreAssistant\Brain\Provider\Sse;
use Addsynapse\StoreAssistant\Brain\Provider\StreamingAdapter;
use Addsynapse\StoreAssistant\Agent\Extensions;
use Addsynapse\StoreAssistant\Agent\Selection;
use Addsynapse\StoreAssistant\Agent\ToolRegistry;
use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Discovery\StoreSummary;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\I18n\Locales;
use Addsynapse\StoreAssistant\Llm\LlmClient;
use Addsynapse\StoreAssistant\Media\ImageUploads;
use Addsynapse\StoreAssistant\Llm\LlmException;
use Addsynapse\StoreAssistant\Llm\LlmResponse;
use Addsynapse\StoreAssistant\Seams;

defined( 'ABSPATH' ) || exit;

/**
 * The LlmClient of the plugin: AgentLoop (tools, drafts, approval) talks to the manager's own AI provider. Every tool
 * of the plugin is available with the own key (nothing locked — wordpress.org guideline 5). Per call:
 *  - router = one small-model call per NEW manager message (packages/assistant-brain Classifier): which tool bundles
 *    the question needs, a short title, or a polite out-of-scope reply;
 *  - system = brain sections for the tools of THIS request + a note about this installation + store summary + locale
 *    (stable bytes during a conversation → prompt cache);
 *  - tools  = the agent's runnable abilities (ToolRegistry::definitions), renamed to the canonical names the prompt
 *    uses, with the tuned descriptions of the brain's catalog;
 *  - every user message gets its own reply-language block (deterministic → the history prefix stays stable);
 *  - long-prompt guard: above 85 % of the model's expensive-context threshold, old tool results of the turn are
 *    compacted, then tools are switched off for this call.
 * The key stays on the server; errors are redacted by the adapters.
 */
class DirectClient implements LlmClient {

	public const MAX_TOKENS      = 8000;
	public const TIMEOUT         = 120;
	public const ROUTE_TRANSIENT = 'addsynapse_assistant_route_';
	public const STUB            = '<compacted_result>Older tool result removed to keep the request small. Call the tool again if you need it.</compacted_result>';

	/**
	 * Transport.
	 *
	 * @var ProviderTransport
	 */
	private ProviderTransport $transport;

	/**
	 * Constructor.
	 *
	 * @param ProviderTransport|null $transport Transport.
	 */
	public function __construct( ?ProviderTransport $transport = null ) {
		$this->transport = $transport ?? Seams::get( 'provider_transport', ProviderTransport::class ) ?? new ProviderTransport();
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return KeyStore::active();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $request      Request (AgentLoop shape).
	 * @param callable|null       $on_event     Events.
	 * @param callable|null       $should_abort Stop poll (checked while the answer streams).
	 * @throws LlmException On provider errors.
	 */
	public function create_message( array $request, ?callable $on_event = null, ?callable $should_abort = null ): LlmResponse {
		$provider = KeyStore::provider();
		$adapter  = Adapters::make( $provider );
		$key      = KeyStore::key( $provider );
		if ( ! $adapter || '' === $key ) {
			throw new LlmException( 'not_configured', 'No AI provider key is configured.' );
		}
		$model = KeyStore::model();
		if ( $on_event ) {
			$on_event( array( 'type' => 'thinking' ) );
		}
		// 1. Router (small model, once per new manager message): tool bundles, out-of-scope reply, title.
		$route = $this->route( $request, $provider, $key, $adapter );
		if ( Classifier::refuses( $route ) ) {
			$response        = new LlmResponse(
				array(
					array(
						'type' => 'text',
						'text' => (string) $route['reply'],
					),
				),
				'end_turn',
				(array) ( $route['usage'] ?? array() ),
				Classifier::model( $provider )
			);
			$response->title = (string) ( $route['title'] ?? '' );
			if ( $on_event ) {
				$on_event(
					array(
						'type' => 'text_delta',
						'text' => $response->text(),
					)
				);
			}
			return $response;
		}//end if
		// 2. Main call with the bundle's tools (+ request_tools); one escalation to every tool when asked.
		$canonical = $this->canonical_request( $request, $model, $provider, (array) $route['bundles'] );
		$names     = $canonical['_names'];
		unset( $canonical['_names'] );
		$out = $this->main_call( $adapter, $provider, $canonical, $key, $names, $on_event, $should_abort );
		if ( self::asks_for_tools( $out['content'] ) ) {
			$this->remember( (int) ( $request['addsynapse_assistant_conversation_id'] ?? 0 ), array( Classifier::FULL ) );
			$first     = $out['usage'];
			$canonical = $this->canonical_request( $request, $model, $provider, array( Classifier::FULL ) );
			$names     = $canonical['_names'];
			unset( $canonical['_names'] );
			$out          = $this->main_call( $adapter, $provider, $canonical, $key, $names, $on_event, $should_abort );
			$out['usage'] = self::add_usage( $out['usage'], $first );
		}
		$out['usage'] = self::add_usage( $out['usage'], (array) ( $route['usage'] ?? array() ) );

		$content = array();
		foreach ( $out['content'] as $block ) {
			if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$block['name'] = $names[ (string) $block['name'] ] ?? (string) $block['name'];
				if ( $on_event && empty( $out['streamed'] ) ) {
					$on_event(
						array(
							'type' => 'tool_use_start',
							'id'   => (string) ( $block['id'] ?? '' ),
							'name' => $block['name'],
						)
					);
				}
			}
			$content[] = $block;
		}
		$response        = new LlmResponse( $content, $out['stop_reason'], $out['usage'], '' !== $out['model'] ? $out['model'] : $model, ! empty( $out['aborted'] ) );
		$response->title = (string) ( $route['title'] ?? '' );
		if ( $on_event && empty( $out['streamed'] ) && '' !== $response->text() ) {
			$on_event(
				array(
					'type' => 'text_delta',
					'text' => $response->text(),
				)
			);
		}
		return $response;
	}

	/**
	 * One provider call, provider errors → LlmException with chat-friendly codes.
	 *
	 * @param ProviderAdapter     $adapter   Adapter.
	 * @param string              $provider  Provider.
	 * @param array<string,mixed> $canonical Canonical request.
	 * @param string              $key       Key.
	 * @return array<string,mixed>
	 * @throws LlmException On errors.
	 */
	public function call( ProviderAdapter $adapter, string $provider, array $canonical, string $key ): array {
		try {
			$http   = $adapter->build( $canonical, $key );
			$answer = $this->transport->post( $provider, $http, self::TIMEOUT );
			return $adapter->parse( (int) $answer['status'], (string) $answer['body'], $key );
		} catch ( ProviderError $e ) {
			$error = self::exception( $e );
			throw $error;
		}
	}

	/**
	 * Maps a provider error to the plugin's error codes (ErrorMessages).
	 *
	 * @param ProviderError $e Error.
	 */
	public static function exception( ProviderError $e ): LlmException {
		$map = array(
			'invalid_key'      => 'byok_invalid_key',
			'quota'            => 'byok_quota',
			'rate_limited'     => 'rate_limited',
			'overloaded'       => 'overloaded',
			'server'           => 'server',
			'network'          => 'network',
			'context_too_long' => 'conversation_limit',
		);
		return LlmException::from_provider( $map[ $e->error_code() ] ?? 'bad_request', $e->getMessage() );
	}

	/**
	 * Builds the canonical (Anthropic-shaped) request.
	 *
	 * @param array<string,mixed> $request  AgentLoop request.
	 * @param string              $model    Model.
	 * @param string              $provider Provider.
	 * @param array<int,string>   $bundles  Router bundles (full = every tool).
	 * @return array<string,mixed> With `_names` = canonical → local tool name.
	 */
	public function canonical_request( array $request, string $model, string $provider, array $bundles = array( Classifier::FULL ) ): array {
		$names       = array();
		$tools       = array();
		$all         = array();
		$definitions = isset( $request['tools'] ) ? (array) $request['tools'] : ( new ToolRegistry() )->definitions();
		// Tools another plugin adds to this request (Agent\Extensions): always offered, and one with the name of a
		// built-in tool replaces it.
		$extension = (array) ( $request['addsynapse_assistant_extension']['tools'] ?? array() );
		$replaced  = array();
		foreach ( $extension as $tool ) {
			$replaced[ substr( (string) $tool['name'], strlen( Extensions::PREFIX ) ) ] = true;
		}
		$definitions = array_values(
			array_filter(
				$definitions,
				static function ( $tool ) use ( $replaced ) {
					return ! isset( $replaced[ ToolCatalog::canonical( str_replace( '__', '/', (string) $tool['name'] ) ) ] );
				}
			)
		);
		foreach ( $definitions as $tool ) {
			$all[] = ToolCatalog::canonical( str_replace( '__', '/', (string) $tool['name'] ) );
		}
		$only = Classifier::offer( $all, $bundles );
		$only = null === $only ? null : array_flip( $only );
		foreach ( $definitions as $tool ) {
			$local = (string) $tool['name'];
			$name  = ToolCatalog::canonical( str_replace( '__', '/', $local ) );
			if ( null !== $only && ! isset( $only[ $name ] ) ) {
				continue;
			}
			$names[ $name ] = $local;
			$description    = ToolCatalog::description( $name );
			$tools[]        = array(
				'name'         => $name,
				'description'  => '' !== $description ? $description : (string) ( $tool['description'] ?? '' ),
				'input_schema' => $tool['input_schema'] ?? array( 'type' => 'object' ),
			);
		}
		foreach ( $extension as $tool ) {
			$local          = (string) $tool['name'];
			$name           = substr( $local, strlen( Extensions::PREFIX ) );
			$names[ $name ] = $local;
			$tools[]        = array(
				'name'         => $name,
				'description'  => (string) ( $tool['description'] ?? '' ),
				'input_schema' => $tool['input_schema'] ?? array( 'type' => 'object' ),
			);
		}
		if ( null !== $only ) {
			$tools[] = Classifier::request_tools_tool();
		}
		$locals   = array_flip( $names );
		$admin    = Locales::clean_locale( (string) get_user_locale() );
		$messages = array();
		$history  = array_values( (array) ( $request['messages'] ?? array() ) );
		$last     = self::newest_user_text( $history );
		foreach ( $history as $i => $message ) {
			$content = is_array( $message['content'] ?? null ) ? $message['content'] : array(
				array(
					'type' => 'text',
					'text' => (string) ( $message['content'] ?? '' ),
				),
			);
			foreach ( $content as $j => $block ) {
				if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
					$local                 = (string) ( $block['name'] ?? '' );
					$content[ $j ]['name'] = $locals[ $local ] ?? ToolCatalog::canonical( str_replace( '__', '/', $local ) );
				}
			}
			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$content = $this->with_data_blocks( $content, $admin, $i === $last ? $request : array() );
			}
			$messages[] = array(
				'role'    => (string) ( $message['role'] ?? 'user' ),
				'content' => $content,
			);
		}//end foreach
		$canonical           = array(
			'model'      => $model,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $this->system( array_keys( $names ), $admin, (string) ( $request['addsynapse_assistant_extension']['addendum'] ?? '' ) ),
			'tools'      => $tools,
			'messages'   => $messages,
			'cache'      => true,
			'cache_key'  => substr( md5( home_url() . '|' . implode( ',', array_keys( $names ) ) ), 0, 32 ),
		);
		$canonical           = self::guard( $canonical, $provider );
		$canonical['_names'] = $names;
		return $canonical;
	}

	/**
	 * System prompt: sections + installation note + store summary + locale.
	 *
	 * @param array<int,string> $tools    Canonical tool names.
	 * @param string            $admin    Admin locale.
	 * @param string            $addendum Instructions another plugin adds to this request ('' = none).
	 */
	private function system( array $tools, string $admin, string $addendum = '' ): string {
		$system = Prompt::system( $tools, Environment::has_woocommerce() );
		$locale = DataBlocks::locale( $admin, Locales::clean_locale( (string) get_locale() ) );
		if ( null !== $locale ) {
			$system .= "\n" . $locale . "\n";
		}
		$store = self::store_state();
		if ( $store && is_array( $store['summary'] ?? null ) ) {
			$system .= "\n" . DataBlocks::store_summary( $store['summary'] ) . "\n";
		}
		if ( '' !== $addendum ) {
			$system .= "\n# Instructions of an add-on installed by the manager\n"
				. "- An add-on adds the tools and instructions below to this request. Where they allow more than one item per change, they take precedence over \"every change covers one item\".\n\n"
				. $addendum . "\n";
		}
		return $system;
	}

	/**
	 * Index of the newest user message that carries text (a tool-results round is a user message without text): its
	 * data blocks stay attached to it in every model call of the turn (-1 = none).
	 *
	 * @param array<int,array<string,mixed>> $history Messages.
	 */
	private static function newest_user_text( array $history ): int {
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === ( $history[ $i ]['role'] ?? '' ) && '' !== self::user_text( $history[ $i ] ) ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Reply-language DATA block in front of every user message's text; the facts of an image uploaded with a message
	 * (every message that has one); for the newest message also the ticked products and — with the «The AI sees the
	 * images you upload» setting — the image itself (a reduced copy, as an image content block).
	 *
	 * @param array<int,array<string,mixed>> $content Blocks.
	 * @param string                         $admin   Admin locale.
	 * @param array<string,mixed>            $request AgentLoop request (newest message only; [] for older ones).
	 * @return array<int,array<string,mixed>>
	 */
	private function with_data_blocks( array $content, string $admin, array $request ): array {
		$upload = ImageUploads::from_blocks( $content );
		$pixels = null;
		if ( $upload && $request && ImageUploads::vision_enabled() ) {
			$pixels = ImageUploads::vision_copy( (int) $upload['attachment_id'] );
		}
		foreach ( $content as $j => $block ) {
			if ( 'text' !== ( $block['type'] ?? '' ) ) {
				continue;
			}
			$text   = (string) ( $block['text'] ?? '' );
			$prefix = DataBlocks::reply_language( $text, $admin ) . "\n";
			if ( $request ) {
				$selection = Selection::sanitize( $request['addsynapse_assistant_selection'] ?? array() );
				if ( $selection ) {
					$prefix .= DataBlocks::selection( $selection ) . "\n";
				}
			}
			$image = $upload ? DataBlocks::image( $upload, null !== $pixels ) : null;
			if ( null !== $image ) {
				$prefix .= $image . "\n";
			}
			$content[ $j ]['text'] = $prefix . $text;
			if ( null !== $pixels ) {
				array_splice(
					$content,
					$j + 1,
					0,
					array(
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $pixels['media_type'],
								'data'       => $pixels['data'],
							),
						),
					)
				);
			}
			break;
		}//end foreach
		return $content;
	}

	/**
	 * Long-prompt guard (the 100k trap: above the threshold some models bill the WHOLE prompt at a higher rate).
	 *
	 * @param array<string,mixed> $canonical Request.
	 * @param string              $provider  Provider.
	 * @return array<string,mixed>
	 */
	public static function guard( array $canonical, string $provider ): array {
		$threshold = Models::long_context( $provider, (string) $canonical['model'] );
		$threshold = $threshold > 0 ? $threshold : 200000;
		$limit     = (int) floor( $threshold * 0.85 );
		if ( TokenEstimate::request( $canonical ) <= $limit ) {
			return $canonical;
		}
		// 1. Compact tool results except those of the two newest messages.
		$count = count( $canonical['messages'] );
		foreach ( $canonical['messages'] as $i => $message ) {
			if ( $i >= $count - 2 || ! is_array( $message['content'] ) ) {
				continue;
			}
			foreach ( $message['content'] as $j => $block ) {
				if ( 'tool_result' === ( $block['type'] ?? '' ) && strlen( (string) ( is_string( $block['content'] ?? null ) ? $block['content'] : '' ) ) > 400 ) {
					$canonical['messages'][ $i ]['content'][ $j ]['content'] = self::STUB;
				}
			}
		}
		// 2. Still too big: answer with what is there, no more tool calls in this request.
		if ( TokenEstimate::request( $canonical ) > $limit ) {
			$canonical['tool_choice'] = 'none';
		}
		return $canonical;
	}

	/**
	 * Proves a key works with one tiny call (Settings → Save & test). No store data is sent.
	 *
	 * @param string $provider Provider.
	 * @param string $model    Model.
	 * @param string $key      Key.
	 * @return true|LlmException
	 */
	public function test( string $provider, string $model, string $key ) {
		$adapter = Adapters::make( $provider );
		if ( ! $adapter || '' === $key ) {
			return new LlmException( 'not_configured', 'No AI provider key is configured.' );
		}
		try {
			$this->call( $adapter, $provider, $adapter->test_request( $model ), $key );
			return true;
		} catch ( LlmException $e ) {
			return $e;
		}
	}

	/**
	 * Route of this call: classify a NEW manager message (once), else reuse the conversation's bundles.
	 *
	 * @param array<string,mixed> $request  AgentLoop request.
	 * @param string              $provider Provider.
	 * @param string              $key      Key.
	 * @param ProviderAdapter     $adapter  Adapter.
	 * @return array<string,mixed> bundles, scope, confidence, reply, title, usage, source.
	 */
	public function route( array $request, string $provider, string $key, ProviderAdapter $adapter ): array {
		$conversation = (int) ( $request['addsynapse_assistant_conversation_id'] ?? 0 );
		$history      = array_values( (array) ( $request['messages'] ?? array() ) );
		$stored       = $conversation > 0 ? get_transient( self::ROUTE_TRANSIENT . $conversation ) : false;
		$stored       = is_array( $stored ) ? $stored : array();
		$text         = self::user_text( $history ? end( $history ) : array() );
		$full         = array(
			'bundles' => array( Classifier::FULL ),
			'scope'   => 'in',
			'title'   => '',
			'source'  => 'full',
		);
		if ( '' === $text ) {
			// Tool round of the same message: same bundles as its first call.
			return array( 'bundles' => (array) ( $stored['bundles'] ?? array( Classifier::FULL ) ) ) + $full;
		}
		// PHPUnit only (Seams): one provider call per answer, without the router.
		if ( null !== Seams::get( 'no_router', 'array' ) ) {
			return $full;
		}
		// Deterministic cases (no router call).
		if ( ! empty( $request['addsynapse_assistant_image'] ) ) {
			return $this->remember( $conversation, array( Classifier::FULL ), $stored ) + $full;
		}
		$users    = 0;
		$previous = '';
		foreach ( $history as $message ) {
			if ( '' !== self::user_text( $message ) ) {
				++$users;
			}
		}
		for ( $i = count( $history ) - 2; $i >= 0 && '' === $previous; $i-- ) {
			if ( 'assistant' === ( $history[ $i ]['role'] ?? '' ) && is_array( $history[ $i ]['content'] ?? null ) ) {
				foreach ( $history[ $i ]['content'] as $block ) {
					if ( 'text' === ( $block['type'] ?? '' ) ) {
						$previous .= (string) $block['text'];
					}
				}
			}
		}
		$admin = Locales::clean_locale( (string) get_user_locale() );
		$ctx   = array(
			'message'        => $text,
			'site_type'      => Environment::has_woocommerce() ? 'shop' : 'site',
			'selection'      => count( Selection::sanitize( $request['addsynapse_assistant_selection'] ?? array() ) ),
			'previous'       => $previous,
			'first'          => 1 === $users,
			'reply_language' => ReplyLanguage::detect( $text, '' !== $admin ? $admin : null ),
		);
		$call  = array(
			'model'      => Classifier::model( $provider ),
			'max_tokens' => 400,
			'effort'     => 'low',
			'system'     => Classifier::prompt(),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => Classifier::input( $ctx ),
				),
			),
		);
		$usage = array();
		try {
			$http   = $adapter->build( $call, $key );
			$answer = $this->transport->post( $provider, $http, 15 );
			$out    = $adapter->parse( (int) $answer['status'], (string) $answer['body'], $key );
			$usage  = $out['usage'];
			$reply  = '';
			foreach ( $out['content'] as $block ) {
				if ( 'text' === ( $block['type'] ?? '' ) ) {
					$reply .= (string) $block['text'];
				}
			}
			$decision = Classifier::decide( Classifier::parse( $reply ) );
		} catch ( ProviderError $e ) {
			// The main call reports real key/quota errors; the router just falls back to every tool.
			$decision = Classifier::decide( null );
		}
		$decision['bundles'] = $this->remember( $conversation, (array) $decision['bundles'], $stored )['bundles'];
		$decision['usage']   = $usage;
		return $decision;
	}

	/**
	 * Stores the bundles of a conversation (sticky while warm: 5 minutes = the prompt cache lifetime).
	 *
	 * @param int                 $conversation Conversation.
	 * @param array<int,string>   $bundles      Bundles.
	 * @param array<string,mixed> $stored       Previous route.
	 * @return array{bundles:array<int,string>}
	 */
	private function remember( int $conversation, array $bundles, array $stored = array() ): array {
		$warm    = ! empty( $stored['at'] ) && time() - (int) $stored['at'] < 300;
		$bundles = Classifier::sticky( $bundles, $warm ? (array) ( $stored['bundles'] ?? array() ) : null );
		if ( $conversation > 0 ) {
			set_transient(
				self::ROUTE_TRANSIENT . $conversation,
				array(
					'bundles' => $bundles,
					'at'      => time(),
				),
				HOUR_IN_SECONDS
			);
		}
		return array( 'bundles' => $bundles );
	}

	/**
	 * Text of a user message (empty for tool results).
	 *
	 * @param mixed $message Message.
	 */
	private static function user_text( $message ): string {
		if ( ! is_array( $message ) || 'user' !== ( $message['role'] ?? '' ) ) {
			return '';
		}
		if ( is_string( $message['content'] ?? null ) ) {
			return (string) $message['content'];
		}
		foreach ( (array) ( $message['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) && '' !== trim( (string) ( $block['text'] ?? '' ) ) ) {
				return (string) $block['text'];
			}
		}
		return '';
	}

	/**
	 * The model asked for every tool.
	 *
	 * @param array<int,array<string,mixed>> $content Blocks.
	 */
	private static function asks_for_tools( array $content ): bool {
		foreach ( $content as $block ) {
			if ( 'tool_use' === ( $block['type'] ?? '' ) && Classifier::REQUEST_TOOLS === ( $block['name'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sum of two usages.
	 *
	 * @param array<string,int> $a Usage.
	 * @param array<string,int> $b Usage.
	 * @return array<string,int>
	 */
	private static function add_usage( array $a, array $b ): array {
		foreach ( $b as $k => $v ) {
			$a[ $k ] = (int) ( $a[ $k ] ?? 0 ) + (int) $v;
		}
		return $a;
	}

	/**
	 * The main model call: streamed (live text, tool chips, Stop) when the browser listens and streaming is on
	 * (ADDSYNAPSE_ASSISTANT_STREAMING), otherwise one JSON answer.
	 *
	 * @param ProviderAdapter      $adapter      Adapter.
	 * @param string               $provider     Provider.
	 * @param array<string,mixed>  $canonical    Request.
	 * @param string               $key          Key.
	 * @param array<string,string> $names        Canonical → local tool names.
	 * @param callable|null        $on_event     Events.
	 * @param callable|null        $should_abort Stop poll.
	 * @return array<string,mixed> Canonical response (+ streamed, aborted).
	 * @throws LlmException On errors.
	 */
	private function main_call( ProviderAdapter $adapter, string $provider, array $canonical, string $key, array $names, ?callable $on_event, ?callable $should_abort ): array {
		if ( ! $on_event || ! $adapter instanceof StreamingAdapter || ! Config::streaming_enabled() ) {
			return $this->call( $adapter, $provider, $canonical, $key );
		}
		$canonical['stream'] = true;
		$sse                 = new Sse();
		$state               = array();
		$text                = '';
		$failure             = null;
		try {
			$http   = $adapter->build( $canonical, $key );
			$answer = $this->transport->post(
				$provider,
				$http,
				self::TIMEOUT,
				static function ( string $chunk ) use ( $sse, $adapter, &$state, &$text, &$failure, $on_event, $names ): bool {
					try {
						foreach ( $sse->feed( $chunk ) as $event ) {
							$step  = $adapter->stream_event( $state, (string) $event['event'], $event['data'] );
							$state = $step['state'];
							if ( '' !== $step['text'] ) {
								$text .= $step['text'];
								$on_event(
									array(
										'type' => 'text_delta',
										'text' => $step['text'],
									)
								);
							}
							if ( $step['tool'] ) {
								$on_event(
									array(
										'type' => 'tool_use_start',
										'id'   => $step['tool']['id'],
										'name' => $names[ $step['tool']['name'] ] ?? $step['tool']['name'],
									)
								);
							}
						}//end foreach
					} catch ( ProviderError $e ) {
						$failure = $e;
						return false;
					}//end try
					return true;
				},
				static function () use ( $should_abort ): bool {
					return ! ( $should_abort && $should_abort() );
				}
			);
			if ( $failure ) {
				throw $failure;
			}
			if ( (int) $answer['status'] >= 400 ) {
				$adapter->parse( (int) $answer['status'], (string) $answer['body'], $key );
			}
			if ( $answer['aborted'] ) {
				return array(
					'content'     => '' === $text ? array() : array(
						array(
							'type' => 'text',
							'text' => $text,
						),
					),
					'stop_reason' => '',
					'usage'       => array(),
					'model'       => '',
					'streamed'    => true,
					'aborted'     => true,
				);
			}
			return array( 'streamed' => true ) + $adapter->stream_result( $state, $key );
		} catch ( ProviderError $e ) {
			$error = self::exception( $e );
			throw $error;
		}//end try
	}

	/**
	 * The store summary for the system prompt (filter `addsynapse_assistant_send_store_summary` = false to leave it out).
	 * Never blocks the chat: on any error the model falls back to describe_store.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function store_state(): ?array {
		if ( ! apply_filters( 'addsynapse_assistant_send_store_summary', true ) ) {
			return null;
		}
		try {
			return StoreSummary::current();
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}
}
