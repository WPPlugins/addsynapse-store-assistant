<?php
/**
 * Finds the page the manager means: id, title/slug, front page, shop page.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Content;

use Addsynapse\StoreAssistant\Adapters\Content\ContentAdapters;
use Addsynapse\StoreAssistant\Adapters\Content\GutenbergAdapter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Only pages/posts (and, for block themes, templates/template parts/synced patterns stored in the
 * database) that the current user may edit. Several title matches → candidates, never a guess.
 */
final class PageFinder {

	public const MAX_CANDIDATES = 10;

	/**
	 * Resolves the input.
	 *
	 * @param array<string,mixed> $input page_id | front_page | shop_page | query.
	 * @return \WP_Post|array<int,array<string,mixed>>|WP_Error Post, candidates, or error.
	 */
	public static function find( array $input ) {
		if ( ! empty( $input['page_id'] ) ) {
			return self::editable( (int) $input['page_id'] );
		}
		if ( ! empty( $input['front_page'] ) ) {
			$id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			if ( $id < 1 ) {
				return new WP_Error( 'addsynapse_assistant_page_not_found', 'The front page shows the latest posts (no page is set as the homepage in Settings → Reading), so there is no page content to edit.' );
			}
			return self::editable( $id );
		}
		if ( ! empty( $input['shop_page'] ) ) {
			$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $id < 1 ) {
				return new WP_Error( 'addsynapse_assistant_page_not_found', 'No shop page is set in WooCommerce.' );
			}
			return self::editable( $id );
		}
		$query = trim( (string) ( $input['query'] ?? '' ) );
		if ( '' === $query ) {
			return new WP_Error( 'addsynapse_assistant_page_query', 'Say which page: page_id, query (title or slug), front_page or shop_page.' );
		}
		return self::search( $query );
	}

	/**
	 * A post the current user may edit.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|WP_Error
	 */
	public static function editable( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, GutenbergAdapter::POST_TYPES, true ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return new WP_Error( 'addsynapse_assistant_page_not_found', 'Page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'addsynapse_assistant_page_forbidden', 'You are not allowed to edit this page.' );
		}
		return $post;
	}

	/**
	 * Title / slug search among pages and posts.
	 *
	 * @param string $query Text.
	 * @return \WP_Post|array<int,array<string,mixed>>|WP_Error
	 */
	private static function search( string $query ) {
		$types  = array( 'page', 'post' );
		$status = array( 'publish', 'private', 'draft', 'pending', 'future' );
		$slug   = sanitize_title( $query );
		$ids    = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => $status,
				'name'           => $slug,
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_CANDIDATES,
			)
		);
		$found  = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => $status,
				's'              => $query,
				'search_columns' => array( 'post_title' ),
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_CANDIDATES,
				'orderby'        => 'relevance',
			)
		);
		$ids    = array_values( array_unique( array_map( 'intval', array_merge( $ids, $found ) ) ) );
		$ids    = array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return current_user_can( 'edit_post', $id );
				}
			)
		);
		if ( ! $ids ) {
			return new WP_Error( 'addsynapse_assistant_page_not_found', sprintf( 'No page matches "%s".', mb_substr( $query, 0, 80 ) ) );
		}
		$needle = mb_strtolower( $query );
		$exact  = array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $needle, $slug ) {
					$post = get_post( $id );
					return $post && ( mb_strtolower( html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) ) === $needle || $post->post_name === $slug );
				}
			)
		);
		if ( 1 === count( $exact ) ) {
			return get_post( $exact[0] );
		}
		if ( 1 === count( $ids ) ) {
			return get_post( $ids[0] );
		}
		return array_map( array( self::class, 'summary' ), array_slice( $ids, 0, self::MAX_CANDIDATES ) );
	}

	/**
	 * Short description of a page (candidates / describe-page header).
	 *
	 * @param int $id Post id.
	 * @return array<string,mixed>
	 */
	public static function summary( int $id ): array {
		$post     = get_post( $id );
		$detected = $post ? ContentAdapters::detect( $post ) : array( 'builder' => '' );
		return array(
			'id'         => $id,
			'title'      => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '',
			'type'       => $post ? $post->post_type : '',
			'status'     => $post ? $post->post_status : '',
			'slug'       => $post ? $post->post_name : '',
			'url'        => $post ? (string) get_permalink( $post ) : '',
			'edit_url'   => $post ? (string) get_edit_post_link( $post, 'raw' ) : '',
			'builder'    => $detected['builder'],
			'front_page' => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $id,
			'shop_page'  => function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) === $id,
		);
	}
}
