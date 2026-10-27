<?php
/**
 * The template of one page or post (1.3.7, plan_page_template): the `_wp_page_template` value WordPress uses to pick the
 * page's template (e.g. Twenty Twenty-Five «page-no-title»). Read-only ability `page-templates` lists the choices; the
 * change itself is a `template` unit of read-state / apply-changes (Undo restores the exact stored value).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Abilities\AbilitiesRegistrar;

defined( 'ABSPATH' ) || exit;

/**
 * Ability addsynapse-assistant/page-templates + the template unit helpers.
 */
final class PageTemplates {

	public const NAME = 'addsynapse-assistant/page-templates';

	/** Post meta WordPress keeps the template in. */
	public const META = '_wp_page_template';

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Templates a page can use', 'addsynapse-store-assistant' ),
				'description'         => 'The templates one page or post can be given (slug and name, e.g. a theme\'s «Page No Title»), and the one it uses now. Read-only.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Execute callback: {id, title, type, current, templates[{slug, name}]}.
	 *
	 * @param array<string,mixed> $input {id}.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post = StateUnits::editable_post( (int) ( $input['id'] ?? 0 ) );
		if ( null === $post ) {
			return new \WP_Error( 'addsynapse_assistant_not_found', 'Page or post not found (or not editable).' );
		}
		$list = array(
			array(
				'slug' => '',
				'name' => 'Default template',
			),
		);
		foreach ( self::choices( $post ) as $slug => $name ) {
			$list[] = array(
				'slug' => $slug,
				'name' => $name,
			);
		}
		return array(
			'id'        => (int) $post->ID,
			'title'     => html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES, 'UTF-8' ),
			'type'      => (string) $post->post_type,
			'current'   => self::stored( (int) $post->ID ),
			'templates' => $list,
		);
	}

	/**
	 * Templates this post can be given: slug → name (the theme's page templates, block-theme custom templates and the
	 * site's own custom templates).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,string>
	 */
	public static function choices( \WP_Post $post ): array {
		$out = array();
		foreach ( (array) wp_get_theme()->get_page_templates( $post, $post->post_type ) as $slug => $name ) {
			$out[ (string) $slug ] = html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' );
		}
		if ( wp_is_block_theme() && function_exists( 'get_block_templates' ) ) {
			foreach ( get_block_templates( array( 'post_type' => $post->post_type ), 'wp_template' ) as $template ) {
				if ( empty( $template->is_custom ) || isset( $out[ $template->slug ] ) ) {
					continue;
				}
				$out[ (string) $template->slug ] = html_entity_decode( wp_strip_all_tags( (string) ( $template->title ? $template->title : $template->slug ) ), ENT_QUOTES, 'UTF-8' );
			}
		}
		unset( $out['default'], $out[''] );
		return $out;
	}

	/**
	 * The stored value ('' = none stored).
	 *
	 * @param int $id Post id.
	 */
	public static function stored( int $id ): string {
		return (string) get_post_meta( $id, self::META, true );
	}

	/**
	 * Why a value may not be stored for this post ('' = it may): the default ('' / default) or one of its choices. The value
	 * it holds now (and, for an Undo, the value it held before) is always allowed back.
	 *
	 * @param int    $id    Post id.
	 * @param mixed  $value Value.
	 * @param string $was   A value the post held (Undo).
	 */
	public static function problem( int $id, $value, string $was = '' ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 200 ) {
			return 'Invalid template.';
		}
		if ( '' === $value || 'default' === $value || $value === $was ) {
			return '';
		}
		$post = StateUnits::editable_post( $id );
		if ( null === $post ) {
			return 'Page or post not found (or not editable).';
		}
		$choices = self::choices( $post );
		return isset( $choices[ $value ] ) ? '' : sprintf( 'This page cannot use the template "%1$s" (available: %2$s).', $value, implode( ', ', array_keys( $choices ) ) );
	}

	/**
	 * Stores a value ('' removes it).
	 *
	 * @param int    $id    Post id.
	 * @param string $value Value.
	 * @throws \RuntimeException When WordPress did not keep it.
	 */
	public static function write( int $id, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $id, self::META );
		} else {
			update_post_meta( $id, self::META, $value );
		}
		clean_post_cache( $id );
		if ( self::stored( $id ) !== $value ) {
			throw new \RuntimeException( 'WordPress did not store the template as planned.' );
		}
	}
}
