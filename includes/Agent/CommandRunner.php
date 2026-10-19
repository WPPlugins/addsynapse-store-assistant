<?php
/**
 * Runs slash commands without a model: same ability path, persisted as tool_use/tool_result.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Agent;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Data\MessageRepository;
use Addsynapse\StoreAssistant\Environment;
use Addsynapse\StoreAssistant\Media\ImageUploads;

defined( 'ABSPATH' ) || exit;

/**
 * A command turn is stored exactly like a model tool round-trip:
 *   assistant { tool_use(name = ability tool name, input) }  (model = "command")
 *   tool      { tool_result(content = ability JSON) }
 * so history reloads, the UI renders it like any tool result and a future model sees
 * a valid transcript. Execution goes through ToolRegistry::execute(), i.e. the
 * ability's own input validation and permission_callback.
 */
class CommandRunner {

	public const MODEL = 'command';

	/**
	 * Tools.
	 *
	 * @var ToolRegistry
	 */
	private ToolRegistry $tools;

	/**
	 * Messages.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Constructor.
	 *
	 * @param ToolRegistry|null      $tools    Tools.
	 * @param MessageRepository|null $messages Messages.
	 */
	public function __construct( ?ToolRegistry $tools = null, ?MessageRepository $messages = null ) {
		$this->tools    = $tools ?? new ToolRegistry();
		$this->messages = $messages ?? new MessageRepository();
	}

	/**
	 * Runs the command in $text, if it is one.
	 *
	 * @param int            $conversation_id Conversation.
	 * @param string         $text            The user's message.
	 * @param callable|null  $emit            Event listener (same events as AgentLoop).
	 * @param array<int,int> $selection       Products checked in the UI (`ids=@selection`).
	 * @param int            $image_id   Image uploaded with this message (0 = none; `=@upload`).
	 * @return array<int,int>|null Stored message ids, or null when $text is not a command.
	 */
	public function maybe_run( int $conversation_id, string $text, ?callable $emit = null, array $selection = array(), int $image_id = 0 ): ?array {
		$expanded = CommandParser::expand_selection( $text, $selection );
		if ( null === $expanded ) {
			return $this->reply( $conversation_id, __( 'No products are selected. Tick products in a result table first, then use `@selection`.', 'addsynapse-store-assistant' ), $emit );
		}
		// `image=@upload`: the image sent with the message, else the newest one of this conversation.
		$expanded = CommandParser::expand_image( $expanded, $image_id > 0 ? $image_id : ImageUploads::latest_in_conversation( $conversation_id, get_current_user_id() ) );
		if ( null === $expanded ) {
			return $this->reply( $conversation_id, __( 'Upload an image first (paperclip next to the message box), then use `=@upload`.', 'addsynapse-store-assistant' ), $emit );
		}
		$command = CommandParser::parse( $expanded );
		if ( null === $command ) {
			return null;
		}
		if ( 'help' === $command['type'] ) {
			return $this->reply( $conversation_id, self::help_text(), $emit );
		}
		if ( 'error' === $command['type'] ) {
			return $this->reply( $conversation_id, $command['message'] . "\n\n" . __( 'Type `/help` to see the commands and examples.', 'addsynapse-store-assistant' ), $emit );
		}

		// Plain WordPress site: product/order commands do not exist (their abilities are not registered).
		if ( in_array( (string) $command['ability'], AbilitiesRegistrar::WOOCOMMERCE_ONLY, true ) && ! Environment::has_woocommerce() ) {
			return $this->reply(
				$conversation_id,
				sprintf(
					/* translators: %s: command */
					__( '%s works with WooCommerce products and orders, and WooCommerce is not active on this site.', 'addsynapse-store-assistant' ),
					'`/' . (string) $command['command'] . '`'
				) . "\n\n" . __( 'Type `/help` to see the commands and examples.', 'addsynapse-store-assistant' ),
				$emit
			);
		}

		// No package check: commands run on the site without the AI service, so they work for everyone,
		// connected or not (owner decision 2026-10-07, docs/14 W-1). The same ToolRegistry::execute as the
		// model's tool calls still validates the input schema and the capability; writes stay drafts.
		$ids   = array();
		$name  = ToolRegistry::tool_name( (string) $command['ability'] );
		$id    = 'toolu_cmd_' . wp_generate_password( 16, false );
		$label = $this->tools->label( $name );
		$input = (array) $command['input'];

		self::emit(
			$emit,
			array(
				'type'  => 'tool_use_start',
				'id'    => $id,
				'name'  => $name,
				'label' => $label,
			)
		);
		self::emit(
			$emit,
			array(
				'type'  => 'tool_use',
				'id'    => $id,
				'name'  => $name,
				'label' => $label,
				'input' => $input ? $input : new \stdClass(),
			)
		);
		$assistant = $this->messages->add(
			$conversation_id,
			MessageRepository::ROLE_ASSISTANT,
			array(
				array(
					'type'  => 'tool_use',
					'id'    => $id,
					'name'  => $name,
					'input' => $input ? $input : new \stdClass(),
				),
			),
			array( 'model' => self::MODEL )
		);
		if ( $assistant ) {
			$ids[] = (int) $assistant['id'];
		}

		$exec  = $this->tools->execute( $name, $input, $conversation_id );
		$block = array(
			'type'        => 'tool_result',
			'tool_use_id' => $id,
			'content'     => $exec['content'],
		);
		if ( $exec['is_error'] ) {
			$block['is_error'] = true;
		}
		$tool = $this->messages->add( $conversation_id, MessageRepository::ROLE_TOOL, array( $block ) );
		if ( $tool ) {
			$ids[] = (int) $tool['id'];
		}
		self::emit(
			$emit,
			array(
				'type'        => 'tool_result',
				'tool_use_id' => $id,
				'is_error'    => $exec['is_error'],
				// Shaped by MessageFormatter::event() before it reaches the browser.
				'content'     => $exec['content'],
			)
		);
		return $ids;
	}

	/**
	 * Stores (and streams) a plain assistant text reply.
	 *
	 * @param int           $conversation_id Conversation.
	 * @param string        $text            Markdown.
	 * @param callable|null $emit            Listener.
	 * @return array<int,int>
	 */
	private function reply( int $conversation_id, string $text, ?callable $emit ): array {
		self::emit(
			$emit,
			array(
				'type' => 'text_delta',
				'text' => $text,
			)
		);
		$message = $this->messages->add(
			$conversation_id,
			MessageRepository::ROLE_ASSISTANT,
			array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			array( 'model' => self::MODEL )
		);
		return $message ? array( (int) $message['id'] ) : array();
	}

	/**
	 * Emits when a listener exists.
	 *
	 * @param callable|null       $emit  Listener.
	 * @param array<string,mixed> $event Event.
	 */
	private static function emit( ?callable $emit, array $event ): void {
		if ( $emit ) {
			$emit( $event );
		}
	}

	/**
	 * /help options of a plain WordPress site (no product/order commands).
	 */
	private static function site_options(): string {
		$text  = '- ' . __( 'On a site without WooCommerce the commands read the site: its map, menus, pages and media library.', 'addsynapse-store-assistant' ) . "\n";
		$text .= '- ' . __( 'Values with spaces go in quotes:', 'addsynapse-store-assistant' ) . ' `/page "About us"`' . "\n";
		return $text;
	}

	/**
	 * Ability of each /help row (a plain WordPress site lists only the commands whose abilities exist there).
	 */
	private const HELP_ABILITIES = array(
		'/search'      => 'addsynapse-assistant/search-products',
		'/product'     => 'addsynapse-assistant/get-product',
		'/report'      => 'addsynapse-assistant/product-report',
		'/orders'      => 'addsynapse-assistant/list-orders',
		'/customers'   => 'addsynapse-assistant/customer-report',
		'/store'       => 'addsynapse-assistant/describe-store',
		'/raw'         => 'addsynapse-assistant/get-product-raw',
		'/terms'       => 'addsynapse-assistant/find-terms',
		'/price'       => 'addsynapse-assistant/plan-price-update',
		'/stock'       => 'addsynapse-assistant/plan-stock-update',
		'/terms-set'   => 'addsynapse-assistant/plan-taxonomy-terms',
		'/brand'       => 'addsynapse-assistant/plan-brand-assign',
		'/product-set' => 'addsynapse-assistant/plan-product-update',
		'/menus'       => 'addsynapse-assistant/describe-menus',
		'/page'        => 'addsynapse-assistant/describe-page',
		'/media'       => 'addsynapse-assistant/find-media',
	);

	/**
	 * The /help text (Markdown).
	 */
	public static function help_text(): string {
		$rows = array(
			array( '/search <text> [options]', __( 'Search products by name or SKU', 'addsynapse-store-assistant' ), '/search shoes stock=outofstock' ),
			array( '/product <id|SKU>', __( 'Product card with variations', 'addsynapse-store-assistant' ), '/product 123 · /product SKU-1' ),
			array( '/report <type> [period] [filters]', __( 'Stock, catalogue and sales reports', 'addsynapse-store-assistant' ), '/report low_stock · /report sales 30d · /report best_sellers 30d brand="Fieldnote Gifts"' ),
			array( '/orders [status] [period]', __( 'Recent orders (no customer data)', 'addsynapse-store-assistant' ), '/orders status=processing 7d' ),
			array( '/customers [period] [sort=spent|orders]', __( 'Top customers by total spent or number of orders', 'addsynapse-store-assistant' ), '/customers 30d · /customers month sort=orders' ),
			array( '/store [refresh]', __( 'Store map: plugins, taxonomies, attributes, brands, meta keys', 'addsynapse-store-assistant' ), '/store · /store refresh' ),
			array( '/raw <id|SKU>', __( 'Full raw product data (meta, taxonomies, attributes)', 'addsynapse-store-assistant' ), '/raw 123' ),
			array( '/terms <taxonomy> <name>', __( 'Find a term, also approximately', 'addsynapse-store-assistant' ), '/terms brand adidas · /terms pa_color red' ),
			array( '/price <change> <product>', __( 'Prepare a price change of one product (preview to approve)', 'addsynapse-store-assistant' ), '/price sku=NUM-07 set 19.90 · /price id=123 -10% target=sale from=2026-10-01 to=2026-10-15' ),
			array( '/stock <set|adjust> <qty> <product>', __( 'Prepare a stock change of one product (preview to approve)', 'addsynapse-store-assistant' ), '/stock sku=NUM-07 set 5 · /stock id=123 adjust -2' ),
			array( '/terms-set <product> <add|remove|replace> <taxonomy>=<terms>', __( 'Prepare a category or tag change of one product (preview to approve)', 'addsynapse-store-assistant' ), '/terms-set id=123 add tag=vegan · /terms-set sku=A remove cat=Offers' ),
			array( '/brand <product> <set|add|remove> <brand>', __( 'Prepare a brand change of one product (preview to approve)', 'addsynapse-store-assistant' ), '/brand id=123 set Adidas' ),
			array( '/product-set <product> field=value …', __( 'Prepare a change of one product\'s name, texts or images (preview to approve)', 'addsynapse-store-assistant' ), '/product-set id=423 name="New name" · /product-set id=423 image=12 gallery_add=13,14' ),
			array( '/menus [menu]', __( 'Menus, theme locations and their items', 'addsynapse-store-assistant' ), '/menus · /menus primary' ),
			array( '/page <id|front|shop|"title">', __( 'The elements of a page (headings, texts, buttons, images) with their ids', 'addsynapse-store-assistant' ), '/page front · /page "About us"' ),
			array( '/media [words] [product=ID] [recent]', __( 'Images of the media library', 'addsynapse-store-assistant' ), '/media recent · /media lego · /media product=123' ),
			array( '/help', __( 'This list', 'addsynapse-store-assistant' ), '/help' ),
		);
		$shop = Environment::has_woocommerce();
		if ( ! $shop ) {
			// Plain WordPress site: only the commands whose abilities exist here.
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						$command = strtok( $row[0], ' ' );
						return ! isset( self::HELP_ABILITIES[ $command ] ) || ! in_array( self::HELP_ABILITIES[ $command ], AbilitiesRegistrar::WOOCOMMERCE_ONLY, true );
					}
				)
			);
			foreach ( $rows as $index => $row ) {
				if ( 0 === strpos( $row[0], '/store' ) ) {
					$rows[ $index ] = array( '/store', __( 'Site map: pages, builders, menus, SEO plugin, languages', 'addsynapse-store-assistant' ), '/store' );
				} elseif ( 0 === strpos( $row[0], '/page' ) ) {
					$rows[ $index ] = array( '/page <id|front|"title">', $row[1], '/page front · /page "About us"' );
				}
			}
		}//end if
		$text  = '**' . __( 'Commands', 'addsynapse-store-assistant' ) . '** — ' . __( 'they run the same tools the assistant uses, without AI.', 'addsynapse-store-assistant' ) . ' ' . __( 'They work on your site even before an AI key is set up.', 'addsynapse-store-assistant' ) . "\n\n";
		$text .= '| ' . __( 'Command', 'addsynapse-store-assistant' ) . ' | ' . __( 'What it does', 'addsynapse-store-assistant' ) . ' | ' . __( 'Example', 'addsynapse-store-assistant' ) . " |\n|---|---|---|\n";
		foreach ( $rows as $row ) {
			$examples = array_map(
				static function ( $e ) {
					return '`' . trim( $e ) . '`';
				},
				explode( '·', $row[2] )
			);
			$text    .= '| `' . str_replace( '|', '\\|', $row[0] ) . '` | ' . $row[1] . ' | ' . implode( ' ', $examples ) . " |\n";
		}
		$text .= "\n**" . __( 'Options', 'addsynapse-store-assistant' ) . "**\n\n";
		if ( ! $shop ) {
			return $text . self::site_options() . self::pro_text( false );
		}
		$text .= '- `/search`: `cat=` `tag=` `brand=` `attr=pa_color:red` `min=` `max=` `stock=instock|outofstock|onbackorder` `type=simple|variable|grouped|external` `status=publish|draft|pending|private` `limit=` `page=`' . "\n";
		$text .= '- `/report`: `low_stock [threshold]` · `out_of_stock` · `missing_image` · `missing_description` · `missing_price` · `best_sellers [period]` · `sales [period]` — `limit=` `from=YYYY-MM-DD` `to=YYYY-MM-DD` · ' . __( 'filters:', 'addsynapse-store-assistant' ) . ' `cat=` `tag=` `brand=` `attr=pa_color:red` `ids=1,2`' . "\n";
		$text .= '- `/customers`: `today` `7d` `30d` `month` `sort=spent|orders` `limit=` `from=` `to=` — ' . __( 'names are shown only to you; the assistant sees customer references (c:12, g:…)', 'addsynapse-store-assistant' ) . "\n";
		$text .= '- `/orders`: `status=` `min=` `max=` `limit=` `page=` `from=` `to=`' . "\n";
		$text .= '- `/search`: `sort=date|modified|title|price|sales|stock` `order=asc|desc` — ' . __( 'e.g. the 20 newest:', 'addsynapse-store-assistant' ) . ' `/search sort=date limit=20`' . "\n";
		$text .= '- ' . __( 'Changes cover one product: give it with', 'addsynapse-store-assistant' ) . ' `id=` ' . __( 'or', 'addsynapse-store-assistant' ) . ' `sku=`' . "\n";
		$text .= '- `/price`: `+10%` `-5%` `+2` `-2` `set 19.90` — `target=regular|sale` `from=` `to=` `round=none|2dp|x.90|x.99`' . "\n";
		$text .= '- `ids=@selection`: ' . __( 'the product ticked in a result table, e.g.', 'addsynapse-store-assistant' ) . ' `/price ids=@selection -5% target=sale`' . "\n";
		$text .= '- `=@upload`: ' . __( 'the image uploaded with the paperclip (JPG/PNG/WebP), e.g.', 'addsynapse-store-assistant' ) . ' `/product-set id=423 image=@upload` · `gallery_add=@upload`' . "\n";
		$text .= '- `/stock`: `set 5` `adjust -2` — `enable=yes` (' . __( 'start tracking stock', 'addsynapse-store-assistant' ) . ')' . "\n";
		$text .= '- `/terms-set`, `/brand`: ' . __( 'existing terms by name or id, comma-separated;', 'addsynapse-store-assistant' ) . ' `tax=product_brand terms="A,B"`' . "\n";
		$text .= '- `/product-set`: `name=` `short=` `desc=` `image=ID` `gallery_add=ID,ID` — ' . __( 'prices and stock have their own commands', 'addsynapse-store-assistant' ) . "\n";
		$text .= '- ' . __( '/price, /stock, /terms-set, /brand and /product-set only prepare a preview: nothing changes until you press Approve.', 'addsynapse-store-assistant' ) . "\n";
		$text .= '- ' . __( 'Periods:', 'addsynapse-store-assistant' ) . ' `today` `7d` `30d` `month` ' . __( 'or', 'addsynapse-store-assistant' ) . ' `from=… to=…`' . "\n";
		$text .= '- ' . __( 'Values with spaces go in quotes:', 'addsynapse-store-assistant' ) . ' `cat="Training accessories"`' . "\n";
		$text .= '- ' . __( 'Lists show at most 100 rows (default 20).', 'addsynapse-store-assistant' ) . "\n";
		return $text . self::pro_text( true );
	}

	/**
	 * «Coming soon in Store Assistant Pro» (information only: these tools are not part of this plugin).
	 *
	 * @param bool $shop WooCommerce is active.
	 */
	private static function pro_text( bool $shop ): string {
		$items = array();
		if ( $shop ) {
			$items[] = __( 'bulk changes for a whole category, a brand or the whole catalogue', 'addsynapse-store-assistant' );
			$items[] = __( 'price and stock import from a CSV file', 'addsynapse-store-assistant' );
			$items[] = __( 'creating, renaming and merging categories, tags and brands', 'addsynapse-store-assistant' );
			$items[] = __( 'product attributes and variations, custom fields, other product settings', 'addsynapse-store-assistant' );
			$items[] = __( 'coupons and orders', 'addsynapse-store-assistant' );
		}
		$items[] = __( 'menus, page texts, sections and banners, posts and blog categories', 'addsynapse-store-assistant' );
		$items[] = __( 'SEO and image alt texts at scale, content writing', 'addsynapse-store-assistant' );
		$items[] = __( 'a full change history', 'addsynapse-store-assistant' );
		$text    = "\n**" . __( 'Coming soon in Store Assistant Pro', 'addsynapse-store-assistant' ) . '** — ' . __( 'a separate add-on:', 'addsynapse-store-assistant' ) . ' ' . implode( '; ', $items ) . ".\n";
		return $text;
	}
}
