<?php
/**
 * The agent loop: model call → tool calls → model call … until a final answer.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Media\ImageUploads;
use Addsynapse\StoreAssistant\Data\ConversationRepository;
use Addsynapse\StoreAssistant\Data\MessageRepository;
use Addsynapse\StoreAssistant\Data\UsageRepository;
use Addsynapse\StoreAssistant\Llm\LlmClient;
use Addsynapse\StoreAssistant\Llm\LlmException;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one assistant turn for a conversation whose last stored message is the user's.
 *
 * Emitted events (optional listener): text_delta{text}, thinking, tool_use_start{id,name,label},
 * tool_use{id,name,label,input}, tool_result{tool_use_id,is_error}, notice{code}, error{code,message,retryable}.
 */
class AgentLoop {

	public const STATUS_COMPLETE = 'complete';
	public const STATUS_STOPPED  = 'stopped';
	public const STATUS_ERROR    = 'error';
	/**
	 * The time budget of this request ended between two model calls (own AI key on a host with a short time limit, or
	 * compatibility mode): the browser sends the same turn again (retry) and the loop continues from the stored history.
	 */
	public const STATUS_CONTINUE = 'continue';

	/**
	 * Unix time after which no new model call starts in this request (null = none).
	 *
	 * @var float|null
	 */
	private ?float $deadline = null;

	/**
	 * LLM client.
	 *
	 * @var LlmClient
	 */
	private LlmClient $client;

	/**
	 * Tools.
	 *
	 * @var ToolRegistry
	 */
	private ToolRegistry $tools;

	/**
	 * Conversations.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Messages.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Usage.
	 *
	 * @var UsageRepository
	 */
	private UsageRepository $usage;

	/**
	 * Constructor.
	 *
	 * @param LlmClient                   $client        Client.
	 * @param ToolRegistry|null           $tools         Tools.
	 * @param ConversationRepository|null $conversations Conversations.
	 * @param MessageRepository|null      $messages      Messages.
	 * @param UsageRepository|null        $usage         Usage.
	 */
	public function __construct(
		LlmClient $client,
		?ToolRegistry $tools = null,
		?ConversationRepository $conversations = null,
		?MessageRepository $messages = null,
		?UsageRepository $usage = null
	) {
		$this->client        = $client;
		$this->tools         = $tools ?? new ToolRegistry();
		$this->conversations = $conversations ?? new ConversationRepository();
		$this->messages      = $messages ?? new MessageRepository();
		$this->usage         = $usage ?? new UsageRepository();
	}

	/**
	 * Sets the time budget of this request (null = none). With a budget, the loop stops before a new model call once
	 * it is spent and returns STATUS_CONTINUE (the history is already stored).
	 *
	 * @param float|null $deadline Unix time.
	 */
	public function set_deadline( ?float $deadline ): self {
		$this->deadline = $deadline;
		return $this;
	}

	/**
	 * Base request: which abilities this site can run (`addsynapse_assistant_abilities`, names + versions). The
	 * client (Byok\DirectClient) adds the prompt, the model and the tool definitions.
	 *
	 * @return array<string,mixed>
	 */
	public function base_request(): array {
		return array( 'addsynapse_assistant_abilities' => $this->tools->advertised() );
	}

	/**
	 * Runs the loop.
	 *
	 * @param array<string,mixed> $conversation Conversation row (already ownership-checked).
	 * @param callable|null       $emit         Event listener.
	 * @param callable|null       $should_abort Cancel poll.
	 * @return array{status:string,error:?LlmException,message_ids:array<int,int>}
	 */
	public function run( array $conversation, ?callable $emit = null, ?callable $should_abort = null ): array {
		$conversation_id = (int) $conversation['id'];
		$user_id         = (int) $conversation['user_id'];
		$rows            = $this->messages->list_for_conversation( $conversation_id );
		$history         = HistoryBuilder::build( $rows );
		$result          = array(
			'status'      => self::STATUS_COMPLETE,
			'error'       => null,
			'message_ids' => array(),
		);

		if ( ! $history || 'user' !== end( $history )['role'] ) {
			$result['status'] = self::STATUS_ERROR;
			$result['error']  = new LlmException( 'nothing_to_answer', 'There is no user message to answer.' );
			$this->emit( $emit, self::error_event( $result['error'] ) );
			return $result;
		}

		// Slash commands run the ability directly — no model call, no tokens.
		$last_row  = $rows ? end( $rows ) : null;
		$selection = array();
		$image     = array();
		if ( $last_row && MessageRepository::ROLE_USER === $last_row['role'] ) {
			$selection   = Selection::from_blocks( (array) $last_row['content'] );
			$image       = ImageUploads::from_blocks( (array) $last_row['content'] );
			$command_ids = ( new CommandRunner( $this->tools, $this->messages ) )->maybe_run(
				$conversation_id,
				(string) ( $last_row['content'][0]['text'] ?? '' ),
				$emit,
				$selection,
				(int) ( $image['attachment_id'] ?? 0 )
			);
			if ( null !== $command_ids ) {
				$result['message_ids'] = $command_ids;
				$this->conversations->touch( $conversation_id );
				return $result;
			}
		}

		// Not connected: the AI does not answer (there is no local AI — docs/13 → Α3); the UI shows «Connect».
		// Slash commands already ran above: they work without the AI service (docs/14 W-1).
		if ( ! $this->client->is_configured() ) {
			$result['status'] = self::STATUS_ERROR;
			$result['error']  = new LlmException( 'not_configured', 'The AI connection is not configured.' );
			$this->emit( $emit, self::error_event( $result['error'] ) );
			return $result;
		}

		// Tools/instructions another plugin adds to THIS request (Extensions; nothing when none is hooked in).
		$extensions = Extensions::collect( $conversation_id, $user_id );
		$this->tools->with_extensions( $extensions );

		$base = $this->base_request();
		if ( ! $extensions->is_empty() ) {
			$base['addsynapse_assistant_extension'] = array(
				'tools'    => $extensions->definitions(),
				'addendum' => $extensions->addendum(),
			);
		}
		$max_iter  = Config::max_iterations();
		$listener  = null === $emit ? null : function ( array $event ) use ( $emit ) {
			if ( 'tool_use_start' === $event['type'] ) {
				$event['label'] = $this->tools->label( (string) $event['name'] );
			}
			$emit( $event );
		};
		$last_stop = '';

		for ( $iteration = 0; $iteration < $max_iter; $iteration++ ) {
			if ( $iteration > 0 && null !== $this->deadline && microtime( true ) >= $this->deadline ) {
				$result['status'] = self::STATUS_CONTINUE;
				break;
			}
			$request             = $base;
			$request['messages'] = $history;
			$request['addsynapse_assistant_conversation_id'] = $conversation_id;
			// Checked products of the user's message (structured; the client adds a DATA block).
			$request['addsynapse_assistant_selection'] = $selection;
			// Image uploaded with the user's message (facts; the pixels only with the vision setting).
			$request['addsynapse_assistant_image'] = $image;

			try {
				$response = $this->client->create_message( $request, $listener, $should_abort );
			} catch ( LlmException $e ) {
				$result['status'] = self::STATUS_ERROR;
				$result['error']  = $e;
				$this->emit( $emit, self::error_event( $e ) );
				break;
			}

			$content = $response->content;
			if ( $response->aborted ) {
				// Unfinished tool calls cannot be answered — keep only what was said.
				$content = array_values(
					array_filter(
						$content,
						static function ( $b ) {
							return 'text' === ( $b['type'] ?? '' );
						}
					)
				);
			}

			$status = MessageRepository::STATUS_COMPLETE;
			if ( $response->aborted ) {
				$status = MessageRepository::STATUS_STOPPED;
			} elseif ( 'refusal' === $response->stop_reason ) {
				$status = 'refusal';
			} elseif ( 'max_tokens' === $response->stop_reason ) {
				$status = 'truncated';
			}

			$usage   = $response->usage;
			$in      = (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['cache_read_input_tokens'] ?? 0 ) + (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
			$message = $this->messages->add(
				$conversation_id,
				MessageRepository::ROLE_ASSISTANT,
				$content,
				array(
					'status'     => $status,
					'model'      => $response->model,
					'tokens_in'  => $in,
					'tokens_out' => (int) ( $usage['output_tokens'] ?? 0 ),
				)
			);
			$this->usage->log(
				array(
					'user_id'         => $user_id,
					'conversation_id' => $conversation_id,
					'message_id'      => $message ? (int) $message['id'] : 0,
					'purpose'         => $this->client instanceof \Addsynapse\StoreAssistant\Byok\DirectClient ? \Addsynapse\StoreAssistant\Byok\Usage::PURPOSE : 'chat',
					'model'           => '' !== $response->model ? $response->model : 'unknown',
					'usage'           => $usage,
				)
			);
			if ( $message ) {
				$result['message_ids'][] = (int) $message['id'];
			}
			if ( '' !== $response->title ) {
				$this->conversations->set_auto_title( $conversation_id, $response->title );
			}

			if ( $response->aborted ) {
				$result['status'] = self::STATUS_STOPPED;
				break;
			}

			$last_stop = $response->stop_reason;
			if ( 'refusal' === $last_stop || 'max_tokens' === $last_stop ) {
				$this->emit(
					$emit,
					array(
						'type' => 'notice',
						'code' => $last_stop,
					)
				);
			}
			if ( 'tool_use' !== $last_stop ) {
				break;
			}

			$tool_uses = $response->tool_uses();
			if ( ! $tool_uses ) {
				break;
			}
			// All results go back in ONE user message.
			$results = array();
			foreach ( $tool_uses as $tool_use ) {
				$name  = (string) ( $tool_use['name'] ?? '' );
				$label = $this->tools->label( $name );
				$this->emit(
					$emit,
					array(
						'type'  => 'tool_use',
						'id'    => (string) $tool_use['id'],
						'name'  => $name,
						'label' => $label,
						'input' => $tool_use['input'] ?? array(),
					)
				);
				$exec  = $this->tools->execute( $name, $tool_use['input'] ?? array(), $conversation_id );
				$block = array(
					'type'        => 'tool_result',
					'tool_use_id' => (string) $tool_use['id'],
					'content'     => $exec['content'],
				);
				if ( $exec['is_error'] ) {
					$block['is_error'] = true;
				}
				$results[] = $block;
				$this->emit(
					$emit,
					array(
						'type'        => 'tool_result',
						'tool_use_id' => (string) $tool_use['id'],
						'is_error'    => $exec['is_error'],
						// Shaped by MessageFormatter::event() before it reaches the browser.
						'content'     => $exec['content'],
					)
				);
			}//end foreach
			$tool_message = $this->messages->add( $conversation_id, MessageRepository::ROLE_TOOL, $results );
			if ( $tool_message ) {
				$result['message_ids'][] = (int) $tool_message['id'];
			}

			$history[] = array(
				'role'    => 'assistant',
				'content' => $content,
			);
			$history[] = array(
				'role'    => 'user',
				'content' => $results,
			);

			if ( $should_abort && $should_abort() ) {
				$result['status'] = self::STATUS_STOPPED;
				break;
			}
		}//end for

		if ( self::STATUS_COMPLETE === $result['status'] && 'tool_use' === $last_stop ) {
			$this->emit(
				$emit,
				array(
					'type' => 'notice',
					'code' => 'max_iterations',
				)
			);
		}

		$this->conversations->touch( $conversation_id );
		return $result;
	}

	/**
	 * Emits when a listener exists.
	 *
	 * @param callable|null       $emit  Listener.
	 * @param array<string,mixed> $event Event.
	 */
	private function emit( ?callable $emit, array $event ): void {
		if ( $emit ) {
			$emit( $event );
		}
	}

	/**
	 * Error event payload.
	 *
	 * @param LlmException $e Error.
	 * @return array<string,mixed>
	 */
	public static function error_event( LlmException $e ): array {
		$event = array(
			'type'      => 'error',
			'code'      => $e->get_error_code(),
			'message'   => ErrorMessages::for_code( $e->get_error_code(), $e->getMessage() ),
			'retryable' => $e->is_retryable(),
		);
		return $event;
	}
}
