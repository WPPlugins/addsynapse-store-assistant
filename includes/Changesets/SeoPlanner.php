<?php
/**
 * SEO improvement plan: AI-written SEO title / meta description / focus keyword (through the
 * SEO plugin adapter) and short description / description, per product, as a DRAFT.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Changesets;

use Addsynapse\StoreAssistant\Adapters\Seo\AbstractSeoAdapter;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapterInterface;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The model writes the texts; the plugin trusts none of it: every text is
 * validated/sanitised by SeoText (caps, plain text for SEO fields, wp_kses without scripts/
 * iframes for descriptions, no links to other domains, known SEO variables only). Any invalid
 * text → error for the whole call (nothing planned) so the model can fix it in one retry.
 * ONE product per call (a variation id is skipped: SEO texts belong to the parent; a page or post gets the «part of
 * Pro» answer). Slugs are never changed.
 */
final class SeoPlanner extends Planner {

	public const OPERATION    = 'seo_update';
	public const MAX_PRODUCTS = 1;

	/**
	 * Input key → part.
	 */
	public const INPUT = array(
		'seo_title'         => 'title',
		'seo_description'   => 'description',
		'focus_keyword'     => 'focus_keyword',
		'short_description' => 'short_description',
		'description'       => 'description',
	);

	/**
	 * Builds the plan and stores a draft changeset.
	 *
	 * @param array<string,mixed> $input           Validated ability input.
	 * @param int                 $user_id         Owner.
	 * @param int                 $conversation_id Conversation (0 = none).
	 * @return array<string,mixed>|WP_Error
	 */
	public function plan( array $input, int $user_id, int $conversation_id = 0 ) {
		$entries = array_values( array_filter( (array) ( $input['products'] ?? array() ), 'is_array' ) );
		if ( ! $entries ) {
			return new WP_Error( 'addsynapse_assistant_invalid_plan', 'Give at least one product with the proposed texts.' );
		}
		if ( count( $entries ) > 1 ) {
			// One product per change.
			return ProductSelector::one_item_error();
		}
		$detected = SeoAdapters::detect();
		$adapter  = $detected['adapter'];
		$script   = SeoText::expected_script();

		$errors    = array();
		$skipped   = array();
		$warnings  = array();
		$rows      = array();
		$unchanged = 0;
		$seen      = array();
		$flags     = array(
			'title_length' => array(),
			'desc_length'  => array(),
			'kw_title'     => array(),
			'kw_desc'      => array(),
			'language'     => array(),
			'stripped'     => array(),
		);
		$stripped  = array();
		$titles    = array();
		$fields    = array();
		foreach ( $entries as $entry ) {
			$id = (int) ( $entry['id'] ?? 0 );
			if ( $id < 1 ) {
				$errors[] = 'Every product needs its id.';
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				$errors[] = sprintf( 'Product %d is listed more than once; give each product once with all its texts.', $id );
				continue;
			}
			$seen[ $id ] = true;
			$proposed    = array_intersect_key( $entry, self::INPUT );
			if ( ! $proposed ) {
				$errors[] = sprintf( 'Product %d: give at least one of seo_title, seo_description, focus_keyword, short_description, description.', $id );
				continue;
			}
			$post = get_post( $id );
			if ( $post && in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
				// SEO of pages and posts is site work (part of Store Assistant Pro); here only products.
				return self::one_item_only( 'pages' );
			}
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				$skipped[] = array(
					'id'     => $id,
					'name'   => '#' . $id,
					'sku'    => '',
					'reason' => 'Product not found.',
				);
				continue;
			}
			if ( $product instanceof \WC_Product_Variation ) {
				$skipped[] = ProductSelector::skip( $product, sprintf( 'SEO texts belong to the parent product (id %d), not to a variation.', $product->get_parent_id() ) );
				continue;
			}
			if ( 'trash' === $product->get_status( 'edit' ) ) {
				$skipped[] = ProductSelector::skip( $product, 'The product is in the trash.' );
				continue;
			}
			$seo_parts = array_intersect( array_keys( $proposed ), array( 'seo_title', 'seo_description', 'focus_keyword' ) );
			if ( $seo_parts && ! $adapter->supports_seo() ) {
				$errors[] = self::unsupported( $adapter );
				break;
			}

			$clean = self::validate( $proposed, $adapter, $product, $errors );
			if ( null === $clean ) {
				continue;
			}
			$changes = array();
			$problem = '';
			foreach ( $clean['values'] as $key => $value ) {
				$field   = in_array( $key, array( 'short_description', 'description' ), true ) ? $key : SeoFields::field( $adapter->key(), self::INPUT[ $key ] );
				$current = SeoFields::read( $product, $field );
				if ( '' !== SeoFields::unreadable( $current ) ) {
					$problem = SeoFields::unreadable( $current );
					break;
				}
				$after          = 'focus_keyword' === $key ? $adapter->focus_after( $current, $value ) : $value;
				$fields[ $key ] = true;
				if ( SeoFields::same( $field, $current, $after ) ) {
					++$unchanged;
					continue;
				}
				$changes[] = array(
					'field'  => $field,
					'before' => $current,
					'after'  => $after,
				);
			}
			if ( '' !== $problem ) {
				$skipped[] = ProductSelector::skip( $product, $problem . ' It is left unchanged.' );
				continue;
			}
			self::checks( $product, $adapter, $clean['values'], $script, $flags, $titles );
			if ( $clean['stripped'] ) {
				$flags['stripped'][] = $id;
				$stripped            = array_merge( $stripped, $clean['stripped'] );
			}
			if ( $changes ) {
				$rows[] = self::row( $product, $changes );
			}
		}//end foreach

		if ( $errors ) {
			return new WP_Error( 'addsynapse_assistant_seo_invalid', implode( "\n", array_slice( array_values( array_unique( $errors ) ), 0, 12 ) ) . "\nNothing was planned. Fix these texts and call plan_seo_update again with ALL the products." );
		}

		$warnings = array_merge( $warnings, self::warnings( $flags, $stripped, $titles, $script ) );
		foreach ( $detected['others'] as $other ) {
			$warnings[] = sprintf( 'Another SEO plugin is also active (%1$s); only the %2$s fields are changed.', $other, $adapter->label() );
		}
		if ( $skipped ) {
			$warnings[] = sprintf( '%d products/variations were skipped (see skipped).', count( $skipped ) );
		}

		$ids    = array_keys( $seen );
		$params = array(
			'seo_plugin'       => $adapter->key(),
			'seo_plugin_label' => $adapter->label(),
			'fields'           => array_keys( $fields ),
			'ids'              => array_slice( $ids, 0, 50 ),
		);

		return $this->finish(
			array(
				'operation'       => self::OPERATION,
				'params'          => $params,
				'summary'         => sprintf( 'SEO improvement (%1$s) — %2$d products: %3$s.', $adapter->supports_seo() ? $adapter->label() : 'no SEO plugin', count( $rows ), implode( ', ', array_keys( $fields ) ) ),
				'rows'            => $rows,
				'skipped'         => $skipped,
				'warnings'        => $warnings,
				'unchanged'       => $unchanged,
				'totals'          => array(
					'fields' => array_sum(
						array_map(
							static function ( $row ) {
								return count( $row['changes'] );
							},
							$rows
						)
					),
				),
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
			)
		);
	}

	/**
	 * Error text for pages/posts without a supported SEO plugin.
	 *
	 * @param SeoAdapterInterface $adapter Adapter.
	 */
	public static function unsupported_post( SeoAdapterInterface $adapter ): string {
		$plugin = method_exists( $adapter, 'unsupported_plugin' ) ? (string) $adapter->unsupported_plugin() : '';
		return '' !== $plugin
			? sprintf( 'The active SEO plugin (%s) is not supported: the SEO title, meta description and focus keyword of pages and posts cannot be changed from here.', $plugin )
			: 'No supported SEO plugin (Rank Math or Yoast SEO) is active: the SEO title, meta description and focus keyword of pages and posts cannot be stored. Install and activate one of them first.';
	}

	/**
	 * Error text when SEO fields are asked for without a supported SEO plugin.
	 *
	 * @param SeoAdapterInterface $adapter Adapter.
	 */
	public static function unsupported( SeoAdapterInterface $adapter ): string {
		$plugin = method_exists( $adapter, 'unsupported_plugin' ) ? (string) $adapter->unsupported_plugin() : '';
		return '' !== $plugin
			? sprintf( 'The active SEO plugin (%s) is not supported: SEO title, meta description and focus keyword cannot be changed from here. Only short_description / description can be planned.', $plugin )
			: 'No supported SEO plugin (Rank Math or Yoast SEO) is active: SEO title, meta description and focus keyword cannot be stored. Only short_description / description can be planned.';
	}

	/**
	 * Validates one product's proposed texts. Null (and $errors filled) when any is invalid.
	 *
	 * @param array<string,mixed>  $proposed Proposed texts (input keys).
	 * @param SeoAdapterInterface  $adapter  Adapter.
	 * @param \WC_Product|\WP_Post $product  Product, page or post.
	 * @param array<int,string>    $errors   Errors (by reference).
	 * @return array{values:array<string,string>,stripped:array<int,string>}|null
	 */
	private static function validate( array $proposed, SeoAdapterInterface $adapter, $product, array &$errors ): ?array {
		$values   = array();
		$stripped = array();
		$failed   = false;
		foreach ( self::INPUT as $key => $part ) {
			if ( ! array_key_exists( $key, $proposed ) ) {
				continue;
			}
			$text = is_string( $proposed[ $key ] ) ? $proposed[ $key ] : '';
			switch ( $key ) {
				case 'seo_title':
					$result = SeoText::plain( $text, SeoText::TITLE_MAX, $adapter );
					break;
				case 'seo_description':
					$result = SeoText::plain( $text, SeoText::DESCRIPTION_MAX, $adapter );
					break;
				case 'focus_keyword':
					$result = SeoText::keyword( $text, $adapter );
					break;
				default:
					$result = SeoText::html( $text, 'short_description' === $key ? SeoText::SHORT_MAX : SeoText::LONG_MAX );
					if ( ! is_wp_error( $result ) ) {
						// 1.3.20: a new link into the shop must lead to something that exists.
						$short   = 'short_description' === $key;
						$current = $product instanceof \WP_Post ? (string) ( $short ? $product->post_excerpt : $product->post_content ) : (string) ( $short ? $product->get_short_description( 'edit' ) : $product->get_description( 'edit' ) );
						$problem = \Addsynapse\StoreAssistant\Links\OwnLinks::html_problem( is_array( $result ) ? (string) $result['value'] : (string) $result, $current );
						if ( '' !== $problem ) {
							$result = new WP_Error( 'addsynapse_assistant_seo_link', '— ' . $problem );
						}
					}
			}//end switch
			if ( is_wp_error( $result ) ) {
				$errors[] = $product instanceof \WP_Post
					? sprintf( '%1$s %2$d (%3$s) %4$s %5$s.', ucfirst( $product->post_type ), $product->ID, mb_substr( html_entity_decode( (string) $product->post_title, ENT_QUOTES, 'UTF-8' ), 0, 60 ), $key, $result->get_error_message() )
					: sprintf( 'Product %1$d (%2$s) %3$s %4$s.', $product->get_id(), mb_substr( ProductSelector::label( $product ), 0, 60 ), $key, $result->get_error_message() );
				$failed   = true;
				continue;
			}
			if ( is_array( $result ) ) {
				$values[ $key ] = $result['value'];
				$stripped       = array_merge( $stripped, $result['stripped'] );
			} else {
				$values[ $key ] = $result;
			}
			unset( $part );
		}//end foreach
		return $failed ? null : array(
			'values'   => $values,
			'stripped' => $stripped,
		);
	}

	/**
	 * Simple SEO checks of the proposed texts (warnings only).
	 *
	 * @param \WC_Product|\WP_Post         $product Product, page or post.
	 * @param SeoAdapterInterface          $adapter Adapter.
	 * @param array<string,string>         $values  Clean values (input keys).
	 * @param string                       $script  Expected script.
	 * @param array<string,array<int,int>> $flags   Flags (by reference).
	 * @param array<string,array<int,int>> $titles  Rendered title → ids (by reference).
	 */
	private static function checks( $product, SeoAdapterInterface $adapter, array $values, string $script, array &$flags, array &$titles ): void {
		$id    = $product instanceof \WP_Post ? (int) $product->ID : (int) $product->get_id();
		$title = null;
		$desc  = null;
		if ( isset( $values['seo_title'] ) ) {
			$title = $adapter->render( $values['seo_title'], $product );
			if ( 'ok' !== SeoText::status( 'title', mb_strlen( $title ) ) ) {
				$flags['title_length'][] = $id;
			}
			$titles[ SeoText::fold( $title ) ][] = $id;
		}
		if ( isset( $values['seo_description'] ) ) {
			$desc = $adapter->render( $values['seo_description'], $product );
			if ( 'ok' !== SeoText::status( 'description', mb_strlen( $desc ) ) ) {
				$flags['desc_length'][] = $id;
			}
		}
		if ( isset( $values['focus_keyword'] ) ) {
			$keyword = $values['focus_keyword'];
			if ( null !== $title && ! SeoText::contains( $keyword, $title ) ) {
				$flags['kw_title'][] = $id;
			}
			if ( null !== $desc && ! SeoText::contains( $keyword, $desc ) ) {
				$flags['kw_desc'][] = $id;
			}
		}
		foreach ( $values as $key => $value ) {
			if ( 'focus_keyword' !== $key && SeoText::wrong_language( AbstractSeoAdapter::clean( $value ), $script ) ) {
				$flags['language'][] = $id;
				break;
			}
		}
	}

	/**
	 * Aggregated warnings (English; the card translates the known ones).
	 *
	 * @param array<string,array<int,int>> $flags    Flags.
	 * @param array<int,string>            $stripped Removed HTML.
	 * @param array<string,array<int,int>> $titles   Rendered titles → ids.
	 * @param string                       $script   Expected script.
	 * @return array<int,string>
	 */
	private static function warnings( array $flags, array $stripped, array $titles, string $script ): array {
		$ids = static function ( array $items ): string {
			return implode( ', ', array_slice( array_values( array_unique( $items ) ), 0, 10 ) );
		};
		$out = array();
		if ( $flags['title_length'] ) {
			$out[] = sprintf( 'SEO title outside the recommended %1$d–%2$d characters: products %3$s.', SeoText::GUIDE['title'][0], SeoText::GUIDE['title'][1], $ids( $flags['title_length'] ) );
		}
		if ( $flags['desc_length'] ) {
			$out[] = sprintf( 'Meta description outside the recommended %1$d–%2$d characters: products %3$s.', SeoText::GUIDE['description'][0], SeoText::GUIDE['description'][1], $ids( $flags['desc_length'] ) );
		}
		if ( $flags['kw_title'] ) {
			$out[] = sprintf( 'The focus keyword is not in the SEO title: products %s.', $ids( $flags['kw_title'] ) );
		}
		if ( $flags['kw_desc'] ) {
			$out[] = sprintf( 'The focus keyword is not in the meta description: products %s.', $ids( $flags['kw_desc'] ) );
		}
		foreach ( $titles as $list ) {
			if ( count( $list ) > 1 ) {
				$out[] = sprintf( 'Duplicate SEO titles: products %s have the same title.', $ids( $list ) );
			}
		}
		if ( $flags['language'] ) {
			$out[] = sprintf( 'Text does not look written in the store language (%1$s): products %2$s.', $script, $ids( $flags['language'] ) );
		}
		if ( $flags['stripped'] ) {
			$out[] = sprintf( 'Disallowed HTML (%1$s) was removed from the descriptions of products %2$s.', implode( ', ', array_values( array_unique( $stripped ) ) ), $ids( $flags['stripped'] ) );
		}
		return $out;
	}
}
