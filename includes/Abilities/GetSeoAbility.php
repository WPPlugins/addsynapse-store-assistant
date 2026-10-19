<?php
/**
 * Ability addsynapse-assistant/get-seo — current SEO texts of products + simple checks (read-only).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Abilities;

use Addsynapse\StoreAssistant\Adapters\Brand\BrandResolver;
use Addsynapse\StoreAssistant\Adapters\Seo\AbstractSeoAdapter;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapterInterface;
use Addsynapse\StoreAssistant\Adapters\Seo\SeoAdapters;
use Addsynapse\StoreAssistant\Changesets\SeoFields;
use Addsynapse\StoreAssistant\Changesets\SeoPlanner;
use Addsynapse\StoreAssistant\Changesets\SeoText;
use Addsynapse\StoreAssistant\Environment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * For each product: the stored SEO title / meta description / focus keyword of the active
 * SEO plugin (Rank Math / Yoast) and what search engines actually get (the plugin's product
 * template when a field is empty), lengths, simple checks, and the product's own facts
 * (name, categories, brand, short description, description excerpt) as context. Everything
 * from the store is DATA. Writes nothing.
 */
final class GetSeoAbility {

	public const NAME       = 'addsynapse-assistant/get-seo';
	public const MAX_IDS    = 20;
	public const SHORT_TEXT = 600;
	public const LONG_TEXT  = 800;

	/**
	 * Registers the ability.
	 */
	public function register(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'SEO of products and pages', 'addsynapse-store-assistant' ),
				'description'         => 'Returns the current SEO title, meta description and focus keyword of up to 20 products (with WooCommerce), pages or posts (read-only), with lengths, simple checks and the facts to write from.',
				'category'            => AbilitiesRegistrar::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type'     => 'object',
					'required' => array( 'seo_plugin', 'products' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( AbilitiesRegistrar::class, 'can_manage' ),
				'meta'                => Shared::readonly_meta(),
			)
		);
	}

	/**
	 * Input schema (also the tool definition the AI provider receives).
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'ids' ),
			'properties'           => array(
				'ids' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::MAX_IDS,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$ids   = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) ( $input['ids'] ?? array() ) ) ) ) ), 0, self::MAX_IDS );
		if ( ! $ids ) {
			return new WP_Error( 'addsynapse_assistant_missing_product', 'Give the product, page or post ids.' );
		}
		$detected = SeoAdapters::detect();
		$adapter  = $detected['adapter'];
		$brand    = Environment::has_woocommerce() ? self::brand_taxonomy() : '';
		$products = array();
		$skipped  = array();
		$titles   = array();
		$has_wc   = Environment::has_woocommerce();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
				if ( 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) {
					$skipped[] = array(
						'id'     => $id,
						'reason' => 'trash' === $post->post_status ? 'In the trash.' : 'You are not allowed to edit it.',
					);
					continue;
				}
				$row        = self::post( $post, $adapter );
				$products[] = $row;
				if ( isset( $row['seo']['title']['effective'] ) && '' !== $row['seo']['title']['effective'] ) {
					$titles[ SeoText::fold( $row['seo']['title']['effective'] ) ][] = $id;
				}
				continue;
			}
			$product = $has_wc ? wc_get_product( $id ) : null;
			if ( ! $product || 'trash' === $product->get_status( 'edit' ) ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => $has_wc ? 'Product, page or post not found.' : 'Page or post not found (this site has no products).',
				);
				continue;
			}
			if ( $product instanceof \WC_Product_Variation ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => sprintf( 'A variation: SEO texts belong to the parent product (id %d).', $product->get_parent_id() ),
				);
				continue;
			}
			$row        = self::product( $product, $adapter, $brand );
			$products[] = $row;
			if ( isset( $row['seo']['title']['effective'] ) && '' !== $row['seo']['title']['effective'] ) {
				$titles[ SeoText::fold( $row['seo']['title']['effective'] ) ][] = $id;
			}
		}//end foreach
		$duplicates = array();
		foreach ( $titles as $list ) {
			if ( count( $list ) > 1 ) {
				$duplicates[] = $list;
				foreach ( $products as $index => $row ) {
					if ( in_array( $row['id'], $list, true ) ) {
						$products[ $index ]['issues'][] = 'duplicate_title';
					}
				}
			}
		}
		return array(
			'seo_plugin'        => array(
				'key'               => $adapter->key(),
				'label'             => $adapter->label(),
				'supported'         => $adapter->supports_seo(),
				'templates'         => $adapter->supports_seo() ? array(
					'title'       => $adapter->template( 'title', $has_wc ? 'product' : 'page' ),
					'description' => $adapter->template( 'description', $has_wc ? 'product' : 'page' ),
				) : null,
				// Default templates of pages/posts (products: `templates` above on a shop).
				'templates_by_type' => $adapter->supports_seo() ? array(
					'page' => array(
						'title'       => $adapter->template( 'title', 'page' ),
						'description' => $adapter->template( 'description', 'page' ),
					),
					'post' => array(
						'title'       => $adapter->template( 'title', 'post' ),
						'description' => $adapter->template( 'description', 'post' ),
					),
				) : null,
			),
			'site_type'         => Environment::site_type(),
			'other_seo_plugins' => $detected['others'],
			'store_language'    => (string) get_locale(),
			'guidance'          => array(
				'title_chars'       => SeoText::GUIDE['title'],
				'description_chars' => SeoText::GUIDE['description'],
				'max_per_plan'      => SeoPlanner::MAX_PRODUCTS,
			),
			'products'          => $products,
			'duplicate_titles'  => $duplicates,
			'skipped'           => $skipped,
			'notes'             => array_values(
				array_filter(
					array(
						'Slugs/URLs are never changed from here (risk of broken links).',
						'Pages and posts: their SEO is shown here; changing it is coming in Store Assistant Pro.',
						$adapter->supports_seo() ? '' : ( $has_wc ? SeoPlanner::unsupported( $adapter ) : SeoPlanner::unsupported_post( $adapter ) ),
					)
				)
			),
			'_data'             => Shared::DATA_NOTICE,
		);
	}

	/**
	 * One product.
	 *
	 * @param \WC_Product         $product Product.
	 * @param SeoAdapterInterface $adapter Adapter.
	 * @param string              $brand   Brand taxonomy ('' = none).
	 * @return array<string,mixed>
	 */
	public static function product( \WC_Product $product, SeoAdapterInterface $adapter, string $brand ): array {
		$id     = (int) $product->get_id();
		$short  = AbstractSeoAdapter::clean( (string) $product->get_short_description( 'edit' ) );
		$long   = AbstractSeoAdapter::clean( (string) $product->get_description( 'edit' ) );
		$issues = array();
		$row    = array(
			'id'                      => $id,
			'object_type'             => 'product',
			'name'                    => html_entity_decode( wp_strip_all_tags( (string) $product->get_name( 'edit' ) ), ENT_QUOTES, 'UTF-8' ),
			'sku'                     => (string) $product->get_sku( 'edit' ),
			'type'                    => (string) $product->get_type(),
			'status'                  => (string) $product->get_status( 'edit' ),
			'edit_link'               => Shared::product_edit_link( $id ),
			'categories'              => self::terms( $id, 'product_cat' ),
			'brand'                   => '' !== $brand ? self::terms( $id, $brand ) : array(),
			'short_description'       => mb_strlen( $short ) > self::SHORT_TEXT ? mb_substr( $short, 0, self::SHORT_TEXT ) . '…' : $short,
			'short_description_chars' => mb_strlen( $short ),
			'description_excerpt'     => mb_strlen( $long ) > self::LONG_TEXT ? mb_substr( $long, 0, self::LONG_TEXT ) . '…' : $long,
			'description_chars'       => mb_strlen( $long ),
		);
		if ( '' === $short ) {
			$issues[] = 'short_description_missing';
		}
		if ( '' === $long ) {
			$issues[] = 'description_missing';
		}
		if ( $adapter->supports_seo() ) {
			$seo = array();
			foreach ( array( 'title', 'description' ) as $part ) {
				$stored       = SeoFields::read( $product, SeoFields::field( $adapter->key(), $part ) );
				$stored       = is_string( $stored ) ? $stored : null;
				$custom       = null !== $stored && '' !== trim( $stored );
				$effective    = $adapter->render( $custom ? (string) $stored : $adapter->template( $part ), $product );
				$status       = SeoText::status( $part, mb_strlen( $effective ) );
				$seo[ $part ] = array(
					'stored'    => $stored,
					'source'    => $custom ? 'custom' : 'default_template',
					'effective' => $effective,
					'chars'     => mb_strlen( $effective ),
					'status'    => $status,
				);
				if ( ! $custom ) {
					$issues[] = 'seo_' . $part . '_not_set';
				}
				if ( 'ok' !== $status ) {
					$issues[] = 'seo_' . $part . '_' . $status;
				}
			}
			$stored               = SeoFields::read( $product, SeoFields::field( $adapter->key(), 'focus_keyword' ) );
			$primary              = $adapter->focus_primary( is_string( $stored ) ? $stored : null );
			$seo['focus_keyword'] = array(
				'stored'         => is_string( $stored ) ? $stored : null,
				'primary'        => $primary,
				'in_title'       => '' !== $primary && SeoText::contains( $primary, $seo['title']['effective'] ),
				'in_description' => '' !== $primary && SeoText::contains( $primary, $seo['description']['effective'] ),
			);
			if ( '' === $primary ) {
				$issues[] = 'focus_keyword_missing';
			} else {
				if ( ! $seo['focus_keyword']['in_title'] ) {
					$issues[] = 'focus_keyword_not_in_title';
				}
				if ( ! $seo['focus_keyword']['in_description'] ) {
					$issues[] = 'focus_keyword_not_in_description';
				}
			}
			$row['seo'] = $seo;
		}//end if
		$row['issues'] = $issues;
		return $row;
	}

	/**
	 * One page or post: SEO keys only (its content is edited with the page tools).
	 *
	 * @param \WP_Post            $post    Page or post.
	 * @param SeoAdapterInterface $adapter Adapter.
	 * @return array<string,mixed>
	 */
	public static function post( \WP_Post $post, SeoAdapterInterface $adapter ): array {
		$content = AbstractSeoAdapter::clean( (string) $post->post_content );
		$excerpt = AbstractSeoAdapter::clean( (string) $post->post_excerpt );
		$issues  = array();
		$row     = array(
			'id'              => (int) $post->ID,
			'object_type'     => (string) $post->post_type,
			'name'            => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'status'          => (string) $post->post_status,
			'edit_link'       => (string) get_edit_post_link( $post, 'raw' ),
			'url'             => (string) get_permalink( $post ),
			'categories'      => 'post' === $post->post_type ? self::terms( (int) $post->ID, 'category' ) : array(),
			'excerpt'         => mb_strlen( $excerpt ) > self::SHORT_TEXT ? mb_substr( $excerpt, 0, self::SHORT_TEXT ) . '…' : $excerpt,
			// Page-builder pages keep their text elsewhere (describe_page): this may be short/empty.
			'content_excerpt' => mb_strlen( $content ) > self::LONG_TEXT ? mb_substr( $content, 0, self::LONG_TEXT ) . '…' : $content,
			'content_chars'   => mb_strlen( $content ),
			'writable_fields' => $adapter->supports_seo() ? array( 'seo_title', 'seo_description', 'focus_keyword' ) : array(),
		);
		if ( $adapter->supports_seo() ) {
			$seo = array();
			foreach ( array( 'title', 'description' ) as $part ) {
				$stored       = SeoFields::read_post( $post, SeoFields::field( $adapter->key(), $part ) );
				$stored       = is_string( $stored ) ? $stored : null;
				$custom       = null !== $stored && '' !== trim( $stored );
				$effective    = $adapter->render( $custom ? (string) $stored : $adapter->template( $part, $post->post_type ), $post );
				$status       = SeoText::status( $part, mb_strlen( $effective ) );
				$seo[ $part ] = array(
					'stored'    => $stored,
					'source'    => $custom ? 'custom' : 'default_template',
					'effective' => $effective,
					'chars'     => mb_strlen( $effective ),
					'status'    => $status,
				);
				if ( ! $custom ) {
					$issues[] = 'seo_' . $part . '_not_set';
				}
				if ( 'ok' !== $status ) {
					$issues[] = 'seo_' . $part . '_' . $status;
				}
			}
			$stored               = SeoFields::read_post( $post, SeoFields::field( $adapter->key(), 'focus_keyword' ) );
			$primary              = $adapter->focus_primary( is_string( $stored ) ? $stored : null );
			$seo['focus_keyword'] = array(
				'stored'         => is_string( $stored ) ? $stored : null,
				'primary'        => $primary,
				'in_title'       => '' !== $primary && SeoText::contains( $primary, $seo['title']['effective'] ),
				'in_description' => '' !== $primary && SeoText::contains( $primary, $seo['description']['effective'] ),
			);
			if ( '' === $primary ) {
				$issues[] = 'focus_keyword_missing';
			}
			$row['seo'] = $seo;
		}//end if
		$row['issues'] = $issues;
		return $row;
	}

	/**
	 * Term names of a product (≤5).
	 *
	 * @param int    $id       Product.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int,string>
	 */
	private static function terms( int $id, string $taxonomy ): array {
		$terms = get_the_terms( $id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $terms, 0, 5 ) as $term ) {
			$out[] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * Taxonomy where this store keeps brands ('' = none/unsupported).
	 */
	private static function brand_taxonomy(): string {
		$resolved = BrandResolver::resolve();
		return is_array( $resolved ) ? (string) $resolved['adapter']->taxonomy() : '';
	}
}
