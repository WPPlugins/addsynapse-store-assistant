<?php
/**
 * Extension point of the chat loop: tools, instructions and tool handlers that another plugin adds per chat request.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * A neutral seam (no licence checks, no remote calls, nothing to unlock): with no other plugin hooked in, every method
 * returns an empty result and the chat works exactly as before. Read ONCE per chat request (AgentLoop::run) and kept
 * for that request only.
 *
 * Filters (all receive the context `array{conversation_id:int,user_id:int}`):
 *  - `addsynapse_assistant_extension_tools` (array $tools, array $context): extra tool definitions for THIS request,
 *    each `array{name:string,description:string,input_schema:array,label?:string,group?:string}`; `name` = lower-case
 *    letters, digits and `_` (max 64). A definition with the name of a built-in tool replaces that tool in this request.
 *  - `addsynapse_assistant_extension_groups` (array $groups, array $context): what each tool `group` does
 *    (`array<string,string>` group → one line for the router, English). Lean requests (1.2.0): a tool of a DESCRIBED
 *    group reaches the AI only when the router picks its group for the conversation (or the AI loads it with
 *    `request_tools`, or the history already used it); a tool without a group, or of a group with no description, is
 *    always offered (as before).
 *  - `addsynapse_assistant_system_addendum` (string $text, array $context): extra instructions appended to the system
 *    prompt of this request (plain text, capped at MAX_ADDENDUM characters).
 *  - `addsynapse_assistant_extension_tool_result` (null $result, string $name, array $input, array $context): runs a call
 *    of an extension tool; return `array{content:mixed,is_error?:bool}` (content: a string, or data that is JSON-encoded
 *    within ToolRegistry::MAX_RESULT_CHARS). Null = not handled (the model gets «unknown tool»).
 * Extension tools appear to the model under their own name and are stored in the history as `ext__<name>`.
 * The handler runs inside the chat request of the signed-in manager (the same REST permission check as every tool) —
 * nothing here runs outside that request.
 */
final class Extensions {

	public const PREFIX        = 'ext__';
	public const MAX_TOOLS     = 64;
	public const MAX_ADDENDUM  = 60000;
	public const NAME_PATTERN  = '/^[a-z][a-z0-9_]{0,63}$/D';
	public const GROUP_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * Tools of this request, by name.
	 *
	 * @var array<string,array{name:string,description:string,input_schema:array<string,mixed>,label:string,group:string}>
	 */
	private array $tools = array();

	/**
	 * Instructions of this request.
	 *
	 * @var string
	 */
	private string $addendum = '';

	/**
	 * Described tool groups of this request (group → what it does).
	 *
	 * @var array<string,string>
	 */
	private array $groups = array();

	/**
	 * Context passed to the filters.
	 *
	 * @var array{conversation_id:int,user_id:int}
	 */
	private array $context;

	/**
	 * Collects the extensions of one chat request.
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $user_id         Manager.
	 */
	public static function collect( int $conversation_id, int $user_id ): self {
		$self          = new self();
		$self->context = array(
			'conversation_id' => $conversation_id,
			'user_id'         => $user_id,
		);
		/**
		 * Extra tool definitions for this chat request (see the class docblock).
		 *
		 * @param array<int,array<string,mixed>>         $tools   Definitions.
		 * @param array{conversation_id:int,user_id:int} $context Request.
		 */
		$tools = apply_filters( 'addsynapse_assistant_extension_tools', array(), $self->context );
		foreach ( is_array( $tools ) ? $tools : array() as $tool ) {
			$clean = self::clean_tool( $tool );
			if ( null !== $clean && ! isset( $self->tools[ $clean['name'] ] ) && count( $self->tools ) < self::MAX_TOOLS ) {
				$self->tools[ $clean['name'] ] = $clean;
			}
		}
		/**
		 * Extra instructions for this chat request (see the class docblock).
		 *
		 * @param string                                 $text    Instructions.
		 * @param array{conversation_id:int,user_id:int} $context Request.
		 */
		$addendum       = apply_filters( 'addsynapse_assistant_system_addendum', '', $self->context );
		$self->addendum = is_string( $addendum ) ? trim( wp_check_invalid_utf8( mb_substr( $addendum, 0, self::MAX_ADDENDUM ) ) ) : '';
		if ( $self->tools ) {
			/**
			 * What each extension tool group does (see the class docblock).
			 *
			 * @param array<string,string>                   $groups  Group → one line (English).
			 * @param array{conversation_id:int,user_id:int} $context Request.
			 */
			$groups = apply_filters( 'addsynapse_assistant_extension_groups', array(), $self->context );
			$used   = array_flip( array_filter( array_column( $self->tools, 'group' ) ) );
			foreach ( is_array( $groups ) ? $groups : array() as $name => $text ) {
				if ( is_string( $name ) && isset( $used[ $name ] ) && is_string( $text ) && '' !== trim( $text ) ) {
					$self->groups[ $name ] = sanitize_text_field( mb_substr( $text, 0, 300 ) );
				}
			}
		}
		return $self;
	}

	/**
	 * Nothing hooked in.
	 */
	public static function none(): self {
		$self          = new self();
		$self->context = array(
			'conversation_id' => 0,
			'user_id'         => 0,
		);
		return $self;
	}

	/**
	 * Whether the request has no extension.
	 */
	public function is_empty(): bool {
		return ! $this->tools && '' === $this->addendum;
	}

	/**
	 * Tool definitions in the shape of ToolRegistry::definitions(), names prefixed with PREFIX, plus `group` (a
	 * described group, or '' = always offered).
	 *
	 * @return array<int,array{name:string,description:string,input_schema:array<string,mixed>,group:string}>
	 */
	public function definitions(): array {
		$out = array();
		foreach ( $this->tools as $name => $tool ) {
			$out[] = array(
				'name'         => self::PREFIX . $name,
				'description'  => $tool['description'],
				'input_schema' => $tool['input_schema'],
				'group'        => isset( $this->groups[ $tool['group'] ] ) ? $tool['group'] : '',
			);
		}
		return $out;
	}

	/**
	 * Described tool groups of this request (group → what it does).
	 *
	 * @return array<string,string>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Names of the extension tools (without the prefix).
	 *
	 * @return array<int,string>
	 */
	public function names(): array {
		return array_keys( $this->tools );
	}

	/**
	 * Instructions of this request ('' = none).
	 */
	public function addendum(): string {
		return $this->addendum;
	}

	/**
	 * Whether a stored/model tool name belongs to an extension.
	 *
	 * @param string $tool Tool name.
	 */
	public static function is_extension_tool( string $tool ): bool {
		return 0 === strpos( $tool, self::PREFIX );
	}

	/**
	 * Label of an extension tool (UI chip).
	 *
	 * @param string $tool Tool name (with the prefix).
	 */
	public function label( string $tool ): string {
		$name = substr( $tool, strlen( self::PREFIX ) );
		if ( isset( $this->tools[ $name ] ) && '' !== $this->tools[ $name ]['label'] ) {
			return $this->tools[ $name ]['label'];
		}
		return ucfirst( str_replace( '_', ' ', $name ) );
	}

	/**
	 * Runs a call of an extension tool. Never throws.
	 *
	 * @param string $tool  Tool name (with the prefix).
	 * @param mixed  $input Decoded input.
	 * @return array{content:string,is_error:bool}
	 */
	public function execute( string $tool, $input ): array {
		$name = substr( $tool, strlen( self::PREFIX ) );
		if ( ! isset( $this->tools[ $name ] ) ) {
			return array(
				'content'  => sprintf( 'The tool "%s" is not available in this request.', $name ),
				'is_error' => true,
			);
		}
		if ( null !== $input && ! is_array( $input ) ) {
			return array(
				'content'  => 'The tool input must be a JSON object.',
				'is_error' => true,
			);
		}
		try {
			/**
			 * Runs a call of an extension tool (see the class docblock).
			 *
			 * @param array|null                             $result  Null = not handled.
			 * @param string                                 $name    Tool name (as offered).
			 * @param array<string,mixed>                    $input   Input.
			 * @param array{conversation_id:int,user_id:int} $context Request.
			 */
			$result = apply_filters( 'addsynapse_assistant_extension_tool_result', null, $name, is_array( $input ) ? $input : array(), $this->context );
		} catch ( \Throwable $e ) {
			return array(
				'content'  => 'The tool failed: ' . $e->getMessage(),
				'is_error' => true,
			);
		}
		if ( ! is_array( $result ) || ! array_key_exists( 'content', $result ) ) {
			return array(
				'content'  => sprintf( 'The tool "%s" is not available in this request.', $name ),
				'is_error' => true,
			);
		}
		$content = $result['content'];
		return array(
			'content'  => is_string( $content ) ? mb_substr( $content, 0, ToolRegistry::MAX_RESULT_CHARS ) : ToolRegistry::encode( $content ),
			'is_error' => ! empty( $result['is_error'] ),
		);
	}

	/**
	 * Validates one definition.
	 *
	 * @param mixed $tool Definition.
	 * @return array{name:string,description:string,input_schema:array<string,mixed>,label:string,group:string}|null
	 */
	private static function clean_tool( $tool ): ?array {
		if ( ! is_array( $tool ) || ! is_string( $tool['name'] ?? null ) || ! preg_match( self::NAME_PATTERN, $tool['name'] ) ) {
			return null;
		}
		$schema = $tool['input_schema'] ?? null;
		if ( ! is_array( $schema ) || 'object' !== ( $schema['type'] ?? '' ) ) {
			$schema = array( 'type' => 'object' );
		}
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			$schema['properties'] = array();
		}
		return array(
			'name'         => $tool['name'],
			'description'  => is_string( $tool['description'] ?? null ) ? mb_substr( wp_check_invalid_utf8( $tool['description'] ), 0, 4000 ) : '',
			'input_schema' => $schema,
			'label'        => is_string( $tool['label'] ?? null ) ? mb_substr( sanitize_text_field( $tool['label'] ), 0, 80 ) : '',
			'group'        => is_string( $tool['group'] ?? null ) && preg_match( self::GROUP_PATTERN, $tool['group'] ) ? $tool['group'] : '',
		);
	}
}
