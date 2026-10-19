<?php
/**
 * Maps registered abilities to tool definitions and executes them.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Agent tools = abilities whose meta has `addsynapse_assistant_agent => true`.
 *
 * Ability names contain "/" which tool names may not, so "addsynapse-assistant/describe-store"
 * becomes the tool name "addsynapse-assistant__describe-store".
 */
class ToolRegistry {

	public const MAX_RESULT_CHARS = 30000;

	/**
	 * Plan abilities: the ONLY non-read-only abilities the agent may run. They write nothing
	 * to the store — they only record a DRAFT changeset of ONE item that a human must approve in the UI.
	 * Fixed list (+ the `addsynapse_assistant_plan_abilities` extension point, see plan_abilities()):
	 * an ability cannot make itself a "plan" through its own meta.
	 */
	public const PLAN_ABILITIES = array( 'addsynapse-assistant/plan-price-update', 'addsynapse-assistant/plan-stock-update', 'addsynapse-assistant/plan-taxonomy-terms', 'addsynapse-assistant/plan-brand-assign', 'addsynapse-assistant/plan-seo-update', 'addsynapse-assistant/plan-product-update' );

	/**
	 * Input keys that select products by a rule (a category, a brand, a search, the whole catalogue) instead of by
	 * id. The change tools of this plugin take one product id; a call that still carries one of these keys gets the
	 * friendly «one item per change» answer (Planner::one_item_only()) instead of a schema error.
	 */
	public const RULE_SELECTOR_KEYS = array( 'query', 'category', 'tag', 'brand', 'stock_status', 'type', 'all', 'filter', 'filters', 'category_id', 'tag_id', 'brand_id', 'search' );

	/**
	 * Context of the tool call being executed (conversation id, ability + input, replanned
	 * changeset), for plan abilities.
	 *
	 * @var array<string,mixed>
	 */
	private static array $context = array();

	/**
	 * Extension tools of the current chat request (Extensions::collect in AgentLoop::run; none elsewhere).
	 *
	 * @var Extensions|null
	 */
	private ?Extensions $extensions = null;

	/**
	 * Sets the extension tools of the current chat request.
	 *
	 * @param Extensions $extensions Extensions.
	 */
	public function with_extensions( Extensions $extensions ): self {
		$this->extensions = $extensions;
		return $this;
	}

	/**
	 * Extension tools of the current chat request.
	 */
	public function extensions(): Extensions {
		return $this->extensions ?? Extensions::none();
	}

	/**
	 * Source of the plan call being executed: the ability and its validated input, stored with
	 * the draft so the manager can re-run the SAME plan later (Επανάληψη), plus the changeset it
	 * repeats (0 = none).
	 *
	 * @return array{source:array<string,mixed>|null,replanned_from:int}
	 */
	public static function current_call(): array {
		$ability = (string) ( self::$context['ability'] ?? '' );
		return array(
			'source'         => in_array( $ability, self::plan_abilities(), true ) ? array(
				'ability' => $ability,
				'input'   => is_array( self::$context['input'] ?? null ) ? self::$context['input'] : array(),
			) : null,
			'replanned_from' => (int) ( self::$context['replanned_from'] ?? 0 ),
		);
	}

	/**
	 * Conversation id of the tool call being executed (0 outside a chat).
	 */
	public static function current_conversation_id(): int {
		return (int) ( self::$context['conversation_id'] ?? 0 );
	}

	/**
	 * Converts an ability name to a tool name.
	 *
	 * @param string $ability Ability name.
	 */
	public static function tool_name( string $ability ): string {
		return str_replace( '/', '__', $ability );
	}

	/**
	 * Converts a tool name back to an ability name.
	 *
	 * @param string $tool Tool name.
	 */
	public static function ability_name( string $tool ): string {
		return str_replace( '__', '/', $tool );
	}

	/**
	 * Abilities exposed to the agent.
	 *
	 * @return array<string,\WP_Ability>
	 */
	public function abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$out = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( true === $ability->get_meta_item( 'addsynapse_assistant_agent', false ) ) {
				$out[ $ability->get_name() ] = $ability;
			}
		}
		// No filter here: abilities of other plugins never become agent tools (docs/13 → Α3). Another plugin's tools come
		// only through Extensions (one chat request, its own handler, its own definitions).
		return $out;
	}

	/**
	 * Tool definitions (name, description, input schema) of the runnable abilities, for the own-key brain
	 * (Byok\DirectClient). The AddSynapse service never gets them: it holds its own definitions (advertised()).
	 *
	 * @return array<int,array{name:string,description:string,input_schema:array<string,mixed>}>
	 */
	public function definitions(): array {
		$abilities = $this->abilities();
		ksort( $abilities );
		$tools = array();
		foreach ( $abilities as $name => $ability ) {
			if ( ! self::is_runnable( $ability ) ) {
				continue;
			}
			$schema = $ability->get_input_schema();
			if ( empty( $schema ) || 'object' !== ( $schema['type'] ?? '' ) ) {
				$schema = array(
					'type'       => 'object',
					'properties' => array(),
				);
			}
			if ( ! array_key_exists( 'properties', $schema ) ) {
				$schema['properties'] = array();
			}
			$tools[] = array(
				'name'         => self::tool_name( $name ),
				'description'  => (string) $ability->get_description(),
				'input_schema' => $schema,
			);
		}
		return $tools;
	}

	/**
	 * Runnable agent abilities: names + versions (stored with each request for diagnostics).
	 *
	 * @return array<int,array{name:string,version:int}>
	 */
	public function advertised(): array {
		$out = array();
		foreach ( $this->abilities() as $name => $ability ) {
			if ( self::is_runnable( $ability ) ) {
				$out[] = array(
					'name'    => (string) $name,
					'version' => max( 1, (int) $ability->get_meta_item( 'addsynapse_assistant_version', 1 ) ),
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Whether an ability declares itself read-only (annotations.readonly === true).
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public static function is_readonly( $ability ): bool {
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		return true === ( $annotations['readonly'] ?? false );
	}

	/**
	 * Whether the agent may run an ability: read-only, or one of the fixed plan abilities
	 * (draft changesets only).
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public static function is_runnable( $ability ): bool {
		if ( self::is_readonly( $ability ) ) {
			return true;
		}
		return in_array( $ability->get_name(), self::plan_abilities(), true ) && true === $ability->get_meta_item( 'addsynapse_assistant_plan', false );
	}

	/**
	 * The plan abilities: the plugin's own + those other plugins register through the extension point. An added
	 * ability still needs the meta `addsynapse_assistant_plan => true` and its own permission callback.
	 *
	 * @return array<int,string>
	 */
	public static function plan_abilities(): array {
		/**
		 * Extension point: names of other plugins' abilities that prepare DRAFT changesets.
		 *
		 * @param array<int,string> $names Ability names.
		 */
		$extra = apply_filters( 'addsynapse_assistant_plan_abilities', array() );
		$extra = is_array( $extra ) ? array_values( array_filter( $extra, 'is_string' ) ) : array();
		return array_values( array_unique( array_merge( self::PLAN_ABILITIES, $extra ) ) );
	}

	/**
	 * Whether a plan call selects products by a rule (a key of RULE_SELECTOR_KEYS that its schema does not have).
	 *
	 * @param array<string,mixed> $input  Input.
	 * @param array<string,mixed> $schema Input schema.
	 */
	private static function selects_by_rule( array $input, array $schema ): bool {
		$known = (array) ( $schema['properties'] ?? array() );
		foreach ( self::RULE_SELECTOR_KEYS as $key ) {
			if ( array_key_exists( $key, $input ) && ! array_key_exists( $key, $known ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human label for a tool (for UI chips).
	 *
	 * @param string $tool Tool name.
	 */
	public function label( string $tool ): string {
		if ( Extensions::is_extension_tool( $tool ) ) {
			return $this->extensions()->label( $tool );
		}
		$abilities = $this->abilities();
		$name      = self::ability_name( $tool );
		return isset( $abilities[ $name ] ) ? $abilities[ $name ]->get_label() : $tool;
	}

	/**
	 * Executes a tool call. Never throws.
	 *
	 * @param string $tool            Tool name.
	 * @param mixed  $input           Decoded input.
	 * @param int    $conversation_id Conversation the call belongs to (plan abilities link drafts to it).
	 * @param int    $replanned_from  Changeset this plan call repeats (UI «Επανάληψη» only).
	 * @return array{content:string,is_error:bool}
	 */
	public function execute( string $tool, $input, int $conversation_id = 0, int $replanned_from = 0 ): array {
		if ( Extensions::is_extension_tool( $tool ) ) {
			if ( is_array( $input ) && isset( $input['__invalid_json'] ) ) {
				return self::error( 'The tool input was not valid JSON. Please retry with valid arguments.' );
			}
			return $this->extensions()->execute( $tool, $input );
		}
		$abilities = $this->abilities();
		$name      = self::ability_name( $tool );
		if ( ! isset( $abilities[ $name ] ) ) {
			return self::error( sprintf( 'Unknown tool "%s".', $tool ) );
		}
		if ( is_array( $input ) && isset( $input['__invalid_json'] ) ) {
			return self::error( 'The tool input was not valid JSON. Please retry with valid arguments.' );
		}
		$ability = $abilities[ $name ];
		// Invariant: tools run here are READ-ONLY, or plan abilities that only record a DRAFT
		// changeset. Changes happen only when the manager approves it with a button in the UI
		// (REST) — never from a model tool call. Apply/revert are not abilities.
		if ( ! self::is_runnable( $ability ) ) {
			return self::error( sprintf( 'The tool "%s" changes data and cannot run from the chat; changes need the manager\'s approval in the UI.', $tool ) );
		}
		if ( null !== $input && ! is_array( $input ) ) {
			return self::error( 'The tool input must be a JSON object.' );
		}
		$schema = $ability->get_input_schema();
		if ( is_array( $input ) && in_array( $name, self::PLAN_ABILITIES, true ) ) {
			// A request for many products is answered conversationally (the tool edits one product at a time).
			$many    = count( (array) ( $input['ids'] ?? array() ) ) + count( (array) ( $input['skus'] ?? array() ) ) > 1
				|| count( (array) ( $input['items'] ?? array() ) ) > 1 || count( (array) ( $input['products'] ?? array() ) ) > 1;
			$feature = ! empty( $input['create_missing'] ) ? 'terms' : ( $many || self::selects_by_rule( $input, (array) $schema ) ? 'bulk' : '' );
			if ( '' !== $feature ) {
				return array(
					'content'  => self::encode( \Addsynapse\StoreAssistant\Changesets\Planner::one_item_only( $feature ) ),
					'is_error' => false,
				);
			}
		}
		// Abilities without an input schema must receive null.
		$arg           = empty( $schema ) ? null : ( is_array( $input ) ? $input : array() );
		self::$context = array(
			'conversation_id' => $conversation_id,
			'ability'         => $name,
			'input'           => $arg,
			'replanned_from'  => $replanned_from,
		);
		try {
			$result = $ability->execute( $arg );
		} catch ( \Throwable $e ) {
			return self::error( 'The tool failed: ' . $e->getMessage() );
		} finally {
			self::$context = array();
		}
		if ( is_wp_error( $result ) && \Addsynapse\StoreAssistant\Changesets\ProductSelector::ONE_ITEM_ERROR === $result->get_error_code() ) {
			$result = \Addsynapse\StoreAssistant\Changesets\Planner::one_item_only();
		}
		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message() );
		}
		return array(
			'content'  => self::encode( $result ),
			'is_error' => false,
		);
	}

	/**
	 * JSON-encodes a result within MAX_RESULT_CHARS. Oversized results first drop rows
	 * from their longest top-level list (keeping valid JSON, flagged `_truncated`),
	 * and only as a last resort are cut as text.
	 *
	 * @param mixed $result Ability result.
	 */
	public static function encode( $result ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		$json  = (string) wp_json_encode( $result, $flags );
		$size  = strlen( $json );
		while ( $size > self::MAX_RESULT_CHARS && is_array( $result ) ) {
			$longest = null;
			foreach ( $result as $key => $value ) {
				if ( is_array( $value ) && array_is_list( $value ) && $value && ( null === $longest || count( $value ) > count( $result[ $longest ] ) ) ) {
					$longest = $key;
				}
			}
			if ( null === $longest ) {
				break;
			}
			$keep                 = (int) floor( count( $result[ $longest ] ) * 0.8 );
			$result[ $longest ]   = array_slice( $result[ $longest ], 0, $keep );
			$result['_truncated'] = 'Result too large: some rows were dropped. Ask for a narrower query or a smaller limit.';
			$json                 = (string) wp_json_encode( $result, $flags );
			$size                 = strlen( $json );
		}
		if ( strlen( $json ) > self::MAX_RESULT_CHARS ) {
			$json = substr( $json, 0, self::MAX_RESULT_CHARS ) . '… [truncated: result too large, ask for a narrower query]';
		}
		return $json;
	}

	/**
	 * Error result.
	 *
	 * @param string $message Message.
	 * @return array{content:string,is_error:bool}
	 */
	private static function error( string $message ): array {
		return array(
			'content'  => $message,
			'is_error' => true,
		);
	}
}
