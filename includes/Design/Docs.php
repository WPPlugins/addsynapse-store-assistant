<?php
/**
 * The design documents the assistant can edit: pages/posts/synced patterns, block templates and template parts (also the
 * theme's or a plugin's own files, customised on Approve), Elementor documents.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

use Addsynapse\StoreAssistant\Adapters\Content\ElementorAdapter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A document reference (stored in the draft line, re-read on Approve / Undo):
 *   {kind: blocks|template|elementor, post_id, title, url (where it shows on the site), edit_url,
 *    template: {id, type wp_template|wp_template_part, slug, theme, source theme|plugin|custom, area, title, description}}
 * Templates that only exist as theme / plugin files have post_id 0: Approve stores the customised copy exactly like the
 * Site Editor does (a wp_template / wp_template_part post); Undo deletes that copy (= «revert to the theme's version»).
 */
final class Docs {

	/**
	 * Post types whose content is block markup that may be edited (navigation menus: plan_menu_update).
	 */
	public const BLOCK_TYPES = array( 'page', 'post', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Resolves a target of a tool input.
	 *
	 * @param array<string,mixed> $input {page_id | template | template_part | front_page}.
	 * @return array<string,mixed>|WP_Error Reference + `raw`.
	 */
	public static function resolve( array $input ) {
		if ( ! empty( $input['template_part'] ) ) {
			return self::template( (string) $input['template_part'], 'wp_template_part' );
		}
		if ( ! empty( $input['template'] ) ) {
			return self::template( (string) $input['template'], 'wp_template' );
		}
		if ( ! empty( $input['front_page'] ) ) {
			$id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			if ( $id > 0 ) {
				return self::post( $id );
			}
			if ( wp_is_block_theme() ) {
				foreach ( array( 'front-page', 'home', 'index' ) as $slug ) {
					$doc = self::template( $slug, 'wp_template' );
					if ( ! is_wp_error( $doc ) ) {
						return $doc;
					}
				}
			}
			return new WP_Error( 'addsynapse_assistant_design_target', 'The homepage shows the latest posts and is drawn by the theme (no page to edit): change the theme\'s settings or templates instead.' );
		}
		$id = (int) ( $input['page_id'] ?? 0 );
		if ( $id > 0 ) {
			return self::post( $id );
		}
		return new WP_Error( 'addsynapse_assistant_design_target', 'Say what to edit: page_id (a page, post, synced pattern, Elementor template…), template (slug, e.g. single-product), template_part (slug, e.g. header) or front_page.' );
	}

	/**
	 * A post (page, post, synced pattern, customised template, Elementor document).
	 *
	 * @param int $id Post.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function post( int $id ) {
		$post = get_post( $id );
		if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return new WP_Error( 'addsynapse_assistant_design_target', sprintf( 'Content #%d was not found.', $id ) );
		}
		if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
			$template = get_block_template( get_stylesheet() . '//' . $post->post_name, $post->post_type );
			if ( $template && (int) $template->wp_id === (int) $post->ID ) {
				return self::from_template( $template );
			}
		}
		if ( ! self::can_edit_post( $post ) ) {
			return new WP_Error( 'addsynapse_assistant_design_forbidden', 'You are not allowed to edit this content.' );
		}
		$elementor = ElementorAdapter::built_with( (int) $post->ID );
		if ( ! $elementor && ! in_array( $post->post_type, self::BLOCK_TYPES, true ) ) {
			return new WP_Error( 'addsynapse_assistant_design_target', sprintf( 'Content #%1$d is a %2$s, which is not a page, post, template or pattern.', $id, $post->post_type ) );
		}
		$ref = array(
			'kind'     => $elementor ? 'elementor' : 'blocks',
			'post_id'  => (int) $post->ID,
			'type'     => (string) $post->post_type,
			'title'    => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'      => self::view_url( $post ),
			'edit_url' => (string) get_edit_post_link( (int) $post->ID, 'raw' ),
		);
		if ( $elementor ) {
			$ref['elementor_type'] = (string) get_post_meta( (int) $post->ID, '_elementor_template_type', true );
		}
		$ref['raw'] = self::read( $ref );
		return $ref;
	}

	/**
	 * Where a post shows on the site.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function view_url( \WP_Post $post ): string {
		if ( in_array( $post->post_type, array( 'page', 'post', 'product' ), true ) ) {
			$link = get_permalink( $post );
			return is_string( $link ) ? $link : '';
		}
		return home_url( '/' );
	}

	/**
	 * The current user may edit a post.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function can_edit_post( \WP_Post $post ): bool {
		if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ), true ) ) {
			return current_user_can( 'edit_theme_options' );
		}
		return current_user_can( 'edit_post', (int) $post->ID );
	}

	/**
	 * A block template / template part by slug (customised copy, theme file or plugin template — the one WordPress uses).
	 *
	 * @param string $slug Slug.
	 * @param string $type wp_template|wp_template_part.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function template( string $slug, string $type ) {
		if ( ! wp_is_block_theme() && 'wp_template_part' === $type && ! current_theme_supports( 'block-template-parts' ) ) {
			return new WP_Error( 'addsynapse_assistant_design_target', 'This theme is not a block theme: its header, footer and templates are PHP files. Use describe_site_design for what can be changed (Customizer settings, widgets, menus, Additional CSS).' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'addsynapse_assistant_design_forbidden', 'Changing the site\'s templates needs the «edit theme options» permission (an administrator).' );
		}
		$slug = sanitize_title( $slug );
		$tpl  = get_block_template( get_stylesheet() . '//' . $slug, $type );
		if ( ! $tpl ) {
			$found = get_block_templates( array( 'slug__in' => array( $slug ) ), $type );
			$tpl   = $found ? $found[0] : null;
		}
		if ( ! $tpl ) {
			$all = array_map( static fn( $t ) => $t->slug, get_block_templates( array(), $type ) );
			return new WP_Error( 'addsynapse_assistant_design_target', sprintf( 'The %1$s "%2$s" does not exist. Available: %3$s.', 'wp_template' === $type ? 'template' : 'template part', $slug, implode( ', ', array_slice( array_unique( $all ), 0, 40 ) ) ) );
		}
		return self::from_template( $tpl );
	}

	/**
	 * Reference of a block template object.
	 *
	 * @param \WP_Block_Template $tpl Template.
	 * @return array<string,mixed>
	 */
	public static function from_template( \WP_Block_Template $tpl ): array {
		$ref        = array(
			'kind'     => 'template',
			'post_id'  => (int) $tpl->wp_id,
			'type'     => (string) $tpl->type,
			'title'    => self::template_title( $tpl ),
			'url'      => self::template_url( $tpl ),
			'edit_url' => admin_url( 'site-editor.php?p=' . rawurlencode( '/' . $tpl->type . '/' . $tpl->id ) . '&canvas=edit' ),
			'template' => array(
				'id'          => (string) $tpl->id,
				'type'        => (string) $tpl->type,
				'slug'        => (string) $tpl->slug,
				'theme'       => (string) $tpl->theme,
				'source'      => (string) $tpl->source,
				'origin'      => (string) ( $tpl->origin ?? '' ),
				'area'        => (string) ( $tpl->area ?? '' ),
				'title'       => (string) ( is_string( $tpl->title ) ? $tpl->title : '' ),
				'description' => (string) $tpl->description,
			),
		);
		$ref['raw'] = (string) $tpl->content;
		return $ref;
	}

	/**
	 * Human title of a template.
	 *
	 * @param \WP_Block_Template $tpl Template.
	 */
	private static function template_title( \WP_Block_Template $tpl ): string {
		$title = is_string( $tpl->title ) && '' !== $tpl->title ? $tpl->title : $tpl->slug;
		return ( 'wp_template_part' === $tpl->type ? 'Template part: ' : 'Template: ' ) . html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A front-end URL where a template is visible (best effort).
	 *
	 * @param \WP_Block_Template $tpl Template.
	 */
	public static function template_url( \WP_Block_Template $tpl ): string {
		$slug = (string) $tpl->slug;
		if ( 'wp_template_part' === $tpl->type ) {
			return home_url( '/' );
		}
		$first = static function ( array $args ): string {
			$ids = get_posts(
				$args + array(
					'numberposts' => 1,
					'fields'      => 'ids',
					'post_status' => 'publish',
				)
			);
			if ( ! $ids ) {
				return '';
			}
			$link = get_permalink( (int) $ids[0] );
			return is_string( $link ) ? $link : '';
		};
		$url   = '';
		if ( 'single-product' === $slug || 0 === strpos( $slug, 'single-product-' ) ) {
			$url = $first( array( 'post_type' => 'product' ) );
		} elseif ( in_array( $slug, array( 'archive-product', 'product-search-results' ), true ) ) {
			$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		} elseif ( 0 === strpos( $slug, 'taxonomy-product_cat' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'number'     => 1,
					'hide_empty' => true,
				)
			);
			$url   = $terms && ! is_wp_error( $terms ) ? (string) get_term_link( $terms[0] ) : '';
		} elseif ( in_array( $slug, array( 'single', 'single-post' ), true ) ) {
			$url = $first( array( 'post_type' => 'post' ) );
		} elseif ( 'page' === $slug || 0 === strpos( $slug, 'page-' ) ) {
			$url = $first( array( 'post_type' => 'page' ) );
		} elseif ( in_array( $slug, array( 'cart', 'page-cart' ), true ) && function_exists( 'wc_get_cart_url' ) ) {
			$url = (string) wc_get_cart_url();
		} elseif ( in_array( $slug, array( 'checkout', 'page-checkout' ), true ) && function_exists( 'wc_get_checkout_url' ) ) {
			$url = (string) wc_get_checkout_url();
		} elseif ( '404' === $slug ) {
			$url = home_url( '/addsynapse-preview-404-' . wp_generate_password( 6, false ) );
		} elseif ( in_array( $slug, array( 'archive', 'category', 'home', 'index' ), true ) ) {
			$url = 'home' === $slug && 'page' === get_option( 'show_on_front' ) && get_option( 'page_for_posts' ) ? (string) get_permalink( (int) get_option( 'page_for_posts' ) ) : home_url( '/' );
		}//end if
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * The stored content of a reference, read now (null = gone).
	 *
	 * @param array<string,mixed> $ref Reference.
	 */
	public static function read( array $ref ): ?string {
		if ( 'template' === ( $ref['kind'] ?? '' ) ) {
			$tpl = self::current_template( $ref );
			return $tpl ? (string) $tpl->content : null;
		}
		$post_id = (int) ( $ref['post_id'] ?? 0 );
		if ( 'elementor' === ( $ref['kind'] ?? '' ) ) {
			return ( new ElementorAdapter() )->raw( $post_id );
		}
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		return $post ? (string) $post->post_content : null;
	}

	/**
	 * The template object WordPress uses now for a template reference.
	 *
	 * @param array<string,mixed> $ref Reference.
	 */
	public static function current_template( array $ref ): ?\WP_Block_Template {
		$t   = (array) ( $ref['template'] ?? array() );
		$tpl = get_block_template( (string) ( $t['id'] ?? '' ), (string) ( $t['type'] ?? 'wp_template' ) );
		if ( ! $tpl && ! empty( $t['slug'] ) ) {
			$found = get_block_templates( array( 'slug__in' => array( (string) $t['slug'] ) ), (string) ( $t['type'] ?? 'wp_template' ) );
			$tpl   = $found ? $found[0] : null;
		}
		return $tpl;
	}

	/**
	 * Writes the exact markup / JSON of a reference (customising a file template on the first write).
	 *
	 * @param array<string,mixed> $ref Reference.
	 * @param string              $raw Content.
	 * @throws \RuntimeException When WordPress refuses the write or changes the content.
	 */
	public static function write( array $ref, string $raw ): void {
		if ( 'elementor' === ( $ref['kind'] ?? '' ) ) {
			( new ElementorAdapter() )->write( (int) $ref['post_id'], $raw );
			return;
		}
		if ( 'template' === ( $ref['kind'] ?? '' ) ) {
			$tpl = self::current_template( $ref );
			if ( ! $tpl ) {
				throw new \RuntimeException( 'The template is gone.' );
			}
			if ( (int) $tpl->wp_id > 0 ) {
				self::write_post( (int) $tpl->wp_id, $raw );
			} else {
				self::customise( $tpl, $raw );
			}
			wp_cache_delete( 'wp_get_global_stylesheet', 'theme_json' );
			return;
		}
		self::write_post( (int) $ref['post_id'], $raw );
	}

	/**
	 * Stores post_content exactly (kses off: the content was checked when it was planned).
	 *
	 * @param int    $post_id Post.
	 * @param string $raw     Markup.
	 * @throws \RuntimeException When WordPress refuses or rewrites it.
	 */
	private static function write_post( int $post_id, string $raw ): void {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $raw ),
				),
				true
			);
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post || (string) $post->post_content !== $raw ) {
			throw new \RuntimeException( 'WordPress changed the content while saving (a filter rewrote it).' );
		}
	}

	/**
	 * First customisation of a theme / plugin template: the same post the Site Editor stores.
	 *
	 * @param \WP_Block_Template $tpl Template.
	 * @param string             $raw Markup.
	 * @throws \RuntimeException When WordPress refuses it.
	 */
	private static function customise( \WP_Block_Template $tpl, string $raw ): void {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => $tpl->type,
						'post_status'  => 'publish',
						'post_name'    => $tpl->slug,
						'post_title'   => is_string( $tpl->title ) && '' !== $tpl->title ? $tpl->title : $tpl->slug,
						'post_excerpt' => (string) $tpl->description,
						'post_content' => $raw,
						'meta_input'   => in_array( $tpl->source, array( 'theme', 'plugin' ), true ) ? array( 'origin' => $tpl->source ) : array(),
					)
				),
				true
			);
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( esc_html( $id->get_error_message() ) );
		}
		wp_set_post_terms( (int) $id, array( (string) $tpl->theme ), 'wp_theme' );
		if ( 'wp_template_part' === $tpl->type ) {
			wp_set_post_terms( (int) $id, array( $tpl->area ? (string) $tpl->area : WP_TEMPLATE_PART_AREA_UNCATEGORIZED ), 'wp_template_part_area' );
		}
		clean_post_cache( (int) $id );
		$post = get_post( (int) $id );
		if ( ! $post || (string) $post->post_content !== $raw ) {
			wp_delete_post( (int) $id, true );
			throw new \RuntimeException( 'WordPress changed the template while saving.' );
		}
	}

	/**
	 * Undo of a first customisation: deletes the customised copy (WordPress uses the theme / plugin file again).
	 *
	 * @param array<string,mixed> $ref Reference.
	 * @throws \RuntimeException When there is nothing to delete.
	 */
	public static function revert_customisation( array $ref ): void {
		$tpl = self::current_template( $ref );
		if ( ! $tpl || (int) $tpl->wp_id < 1 ) {
			throw new \RuntimeException( 'The customised template is gone.' );
		}
		wp_delete_post( (int) $tpl->wp_id, true );
		wp_cache_delete( 'wp_get_global_stylesheet', 'theme_json' );
	}

	/**
	 * Whether a template reference is now a database copy.
	 *
	 * @param array<string,mixed> $ref Reference.
	 */
	public static function is_customised( array $ref ): bool {
		$tpl = self::current_template( $ref );
		return $tpl && (int) $tpl->wp_id > 0;
	}

	/**
	 * The reference without the content (stored in the line).
	 *
	 * @param array<string,mixed> $doc Document.
	 * @return array<string,mixed>
	 */
	public static function ref( array $doc ): array {
		unset( $doc['raw'] );
		return $doc;
	}
}
