<?php
/**
 * Abilities: the site's look through AddSynapse — read the design map / the blocks of a document, and compute a design
 * change (site styles, block templates and template parts, page blocks, theme settings, Additional CSS, widgets,
 * Elementor pages and Site Settings). The plan abilities write nothing and store nothing: they return the units
 * (current and target values) AddSynapse keeps as a draft and, after the owner approved it, sends to apply-changes.
 * Only offered to the remote route; works with or without WooCommerce.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;
use Addsynapse\StoreAssistant\Adapters\Content\ElementorAdapter;
use Addsynapse\StoreAssistant\Design\BlocksEdit;
use Addsynapse\StoreAssistant\Design\DescribeBlocks;
use Addsynapse\StoreAssistant\Design\DescribeDesign;
use Addsynapse\StoreAssistant\Design\DesignUnits;
use Addsynapse\StoreAssistant\Design\ElementorEdit;
use Addsynapse\StoreAssistant\Design\ElementorKit;
use Addsynapse\StoreAssistant\Design\GlobalStyles;
use Addsynapse\StoreAssistant\Design\ThemeSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities describe-site-design, describe-blocks, plan-design and plan-elementor (offered only while Elementor is active).
 */
final class DesignAbilities {

	public const DESCRIBE_DESIGN = 'addsynapse-assistant/describe-site-design';
	public const DESCRIBE_BLOCKS = 'addsynapse-assistant/describe-blocks';
	public const PLAN_DESIGN     = 'addsynapse-assistant/plan-design';
	public const PLAN_ELEMENTOR  = 'addsynapse-assistant/plan-elementor';

	/** Planner of each tool of plan-design / plan-elementor. */
	public const PLANNERS = array(
		'global_styles'  => GlobalStyles::class,
		'blocks_edit'    => BlocksEdit::class,
		'theme_settings' => ThemeSettings::class,
		'elementor_edit' => ElementorEdit::class,
		'elementor_kit'  => ElementorKit::class,
	);

	/** Tools of plan-elementor (registered only while Elementor is active). */
	public const ELEMENTOR_TOOLS = array( 'elementor_edit', 'elementor_kit' );

	/**
	 * Registers the abilities.
	 */
	public function register(): void {
		$readonly = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => false,
		);
		$target   = array(
			'page_id'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'template'      => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'template_part' => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'front_page'    => array( 'type' => 'boolean' ),
		);
		wp_register_ability(
			self::DESCRIBE_DESIGN,
			array(
				'label'               => __( 'Describe the site design', 'addsynapse-store-assistant' ),
				'description'         => 'How the site looks and what can be changed: theme, block-theme styles / templates / template parts / patterns, classic Customizer settings / widgets / Additional CSS, Elementor, and which tool changes what. Read-only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'section' => array(
							'type' => 'string',
							'enum' => DescribeDesign::SECTIONS,
						),
					),
				),
				'execute_callback'    => array( $this, 'describe_design' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $readonly,
			)
		);
		wp_register_ability(
			self::DESCRIBE_BLOCKS,
			array(
				'label'               => __( 'Describe the blocks of a page or template', 'addsynapse-store-assistant' ),
				'description'         => 'The block (or Elementor element) tree of one page, post, template or template part, with ids for a design change. Read-only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $target + array(
						'block' => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
					),
				),
				'execute_callback'    => array( $this, 'describe_blocks' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $readonly,
			)
		);
		$this->register_plan( self::PLAN_DESIGN, array_values( array_diff( array_keys( self::PLANNERS ), self::ELEMENTOR_TOOLS ) ), __( 'Preview a design change', 'addsynapse-store-assistant' ), $readonly );
		// Always registered; offered (ping «tools») only while Elementor is active — RemoteAccess::available().
		$this->register_plan( self::PLAN_ELEMENTOR, self::ELEMENTOR_TOOLS, __( 'Preview an Elementor design change', 'addsynapse-store-assistant' ), $readonly );
	}

	/**
	 * One plan ability.
	 *
	 * @param string              $name  Name.
	 * @param array<int,string>   $tools Tools.
	 * @param string              $label Label.
	 * @param array<string,mixed> $meta  Meta.
	 */
	private function register_plan( string $name, array $tools, string $label, array $meta ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => 'Computes a design change (current and target values, with a before/after list) for ' . implode( ', ', $tools ) . '. Writes nothing.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'tool', 'args' ),
					'properties'           => array(
						'tool' => array(
							'type' => 'string',
							'enum' => $tools,
						),
						'args' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
				'execute_callback'    => array( $this, 'plan' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => $meta,
			)
		);
	}

	/**
	 * Execute callback of describe-site-design.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>
	 */
	public function describe_design( $input = null ) {
		return ( new DescribeDesign() )->execute( is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute callback of describe-blocks.
	 *
	 * @param array<string,mixed>|null $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function describe_blocks( $input = null ) {
		return ( new DescribeBlocks() )->execute( is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute callback of plan-design / plan-elementor: {status draft|nothing_to_change, summary, units[], changes, warnings…}.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function plan( $input ) {
		$tool  = (string) ( $input['tool'] ?? '' );
		$class = self::PLANNERS[ $tool ] ?? null;
		if ( null === $class ) {
			return new \WP_Error( 'addsynapse_assistant_design_tool', 'This design tool is not available on this site.' );
		}
		\Addsynapse\StoreAssistant\Links\OwnLinks::begin();
		$result = ( new $class() )->plan( (array) ( $input['args'] ?? array() ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['units'] = self::with_links( DesignUnits::from_lines( (array) ( $result['lines'] ?? array() ) ) );
		unset( $result['lines'] );
		return $result;
	}

	/**
	 * 1.3.20: the card shows the real address of every new link into the shop (checked: it exists).
	 *
	 * @param array<int,array<string,mixed>> $units Units.
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_links( array $units ): array {
		$used = \Addsynapse\StoreAssistant\Links\OwnLinks::used();
		if ( ! $units || ! $used ) {
			return $units;
		}
		$unverified = \Addsynapse\StoreAssistant\Links\OwnLinks::unverified();
		$drafts     = \Addsynapse\StoreAssistant\Links\OwnLinks::drafts();
		$rows       = (array) ( $units[0]['preview'] ?? array() );
		foreach ( array_slice( $used, 0, 20, true ) as $url => $label ) {
			$rows[] = array(
				'field'  => '' !== $label ? 'link: ' . $label : 'link',
				'before' => '',
				'after'  => (string) $url . ( in_array( (string) $url, $drafts, true ) ? ' (not published yet: visitors get «page not found» until it is)' : ( in_array( (string) $url, $unverified, true ) ? ' (not checked: the site did not answer itself)' : '' ) ),
			);
		}
		$units[0]['preview'] = $rows;
		return $units;
	}
}
