<?php
/**
 * Remote units for the «product family» (1.3.9): the categories, tags, brand and global attributes (pa_*) of ONE product,
 * and the terms a change set creates for them.
 *
 *  - product_terms: id = product (never a variation: terms belong to the parent). Values are Changesets\TermFields fields:
 *                   `terms:product_cat`, `terms:product_tag`, `terms:<brand taxonomy>` (sorted term ids) and
 *                   `attribute:<pa_x>` for every global attribute (null when the product does not have it, else
 *                   {terms, visible, variation, position}). An attribute used for variations is never changed here.
 *                   In an approve, an id list may hold "@N": the term created by item N (kind term) of the same change set.
 *  - term:          a term of a product taxonomy. Created only by a change set (id 0 → new id, marked as ours); its Undo
 *                   deletes it only when it is ours and nothing uses it any more — otherwise it is kept (reported back).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Remote;

use Addsynapse\StoreAssistant\Adapters\Brand\BrandResolver;
use Addsynapse\StoreAssistant\Changesets\TermFields;
use Addsynapse\StoreAssistant\Changesets\TermsPlanner;
use Addsynapse\StoreAssistant\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Read / compare / validate / write of the product_terms and term units.
 */
final class ProductTerms {

	/** Term meta marking a term a change set created (value: the change set id). */
	public const CREATED_META = '_addsynapse_assistant_created';

	/** Longest term name a change set may create. */
	public const MAX_NAME = 200;

	/** A reference to a term created earlier in the same change set. */
	public const REF = '/^@(\d{1,3})$/';

	/**
	 * Kinds handled here.
	 *
	 * @param string $kind Kind.
	 */
	public static function handles( string $kind ): bool {
		return in_array( $kind, array( 'product_terms', 'term' ), true );
	}

	/**
	 * Taxonomies whose terms the remote route may set on a product: categories, tags, the brand taxonomies of the
	 * brand plugins we know, and every global attribute.
	 *
	 * @return array<string,string> field => taxonomy
	 */
	public static function fields(): array {
		$out = array();
		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$out[ TermFields::TERMS . $taxonomy ] = $taxonomy;
			}
		}
		foreach ( BrandResolver::taxonomy_adapters() as $taxonomy => $adapter ) {
			if ( $adapter->available() && TermFields::writable_taxonomy( (string) $taxonomy ) ) {
				$out[ TermFields::TERMS . $taxonomy ] = (string) $taxonomy;
			}
		}
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			foreach ( wc_get_attribute_taxonomy_names() as $taxonomy ) {
				if ( TermFields::is_attribute_taxonomy( (string) $taxonomy ) ) {
					$out[ TermFields::ATTRIBUTE . $taxonomy ] = (string) $taxonomy;
				}
			}
		}
		return $out;
	}

	/**
	 * Where this store keeps the brand: a TermFields field (`terms:product_brand`, `attribute:pa_brand`…) or null.
	 */
	public static function brand_field(): ?string {
		$resolved = BrandResolver::resolve();
		if ( is_wp_error( $resolved ) || ! isset( $resolved['adapter'] ) ) {
			return null;
		}
		$field = $resolved['adapter']->field();
		return array_key_exists( $field, self::fields() ) ? $field : null;
	}

	/**
	 * Current state of a unit.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   Id.
	 * @return array<string,mixed>
	 */
	public static function read( string $kind, int $id ): array {
		if ( 'term' === $kind ) {
			$term = $id > 0 ? get_term( $id ) : null;
			if ( ! $term instanceof \WP_Term || ! TermFields::writable_taxonomy( $term->taxonomy ) ) {
				return self::missing( $kind, $id, 'Term not found.' );
			}
			return array(
				'kind'                  => $kind,
				'id'                    => $id,
				'exists'                => true,
				'name'                  => self::text( $term->name ),
				'type'                  => $term->taxonomy,
				'created_by_addsynapse' => '' !== (string) get_term_meta( $id, self::CREATED_META, true ),
				'values'                => self::term_values( $term ),
			);
		}
		if ( ! Environment::has_woocommerce() ) {
			return self::missing( $kind, $id, 'WooCommerce is not active on this site.' );
		}
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product || 'trash' === $product->get_status( 'edit' ) ) {
			return self::missing( $kind, $id, 'Product not found.' );
		}
		if ( $product instanceof \WC_Product_Variation ) {
			return self::missing( $kind, $id, sprintf( 'This is a variation: categories, tags, brand and attributes belong to the parent product #%d.', (int) $product->get_parent_id() ) );
		}
		$values = array();
		foreach ( array_keys( self::fields() ) as $field ) {
			$values[ $field ] = TermFields::read( $product, $field );
		}
		return array(
			'kind'        => $kind,
			'id'          => $id,
			'exists'      => true,
			'name'        => self::text( $product->get_name() ),
			'type'        => (string) $product->get_type(),
			'brand_field' => self::brand_field(),
			'values'      => $values,
		);
	}

	/**
	 * The unit holds the expected values.
	 *
	 * @param string              $kind   Kind.
	 * @param array<string,mixed> $now    Current values.
	 * @param array<string,mixed> $expect Expected values.
	 */
	public static function holds( string $kind, array $now, array $expect ): bool {
		foreach ( $expect as $field => $value ) {
			if ( ! array_key_exists( $field, $now ) ) {
				return false;
			}
			if ( 'term' === $kind ) {
				if ( 'parent' === $field ? (int) $now[ $field ] !== (int) $value : (string) $now[ $field ] !== (string) $value ) {
					return false;
				}
				continue;
			}
			if ( ! TermFields::same( (string) $field, $now[ $field ], $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Why a set of values cannot be written ('' = fine).
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $set  New values.
	 * @param int[]               $refs Indexes of the items that create a term in this change set ("@N" may point there).
	 */
	public static function invalid( string $kind, array $set, array $refs = array() ): string {
		if ( 'term' === $kind ) {
			foreach ( array_keys( $set ) as $field ) {
				if ( ! in_array( $field, array( 'taxonomy', 'name', 'parent' ), true ) ) {
					return 'Field not allowed: ' . $field;
				}
			}
			$taxonomy = (string) ( $set['taxonomy'] ?? '' );
			if ( ! in_array( TermFields::field_for( $taxonomy ), array_keys( self::fields() ), true ) ) {
				return 'Terms of this taxonomy cannot be created here.';
			}
			$name = is_string( $set['name'] ?? null ) ? trim( $set['name'] ) : '';
			if ( '' === $name || mb_strlen( $name ) > self::MAX_NAME ) {
				return 'A new term needs a name (at most 200 characters).';
			}
			$parent = $set['parent'] ?? 0;
			if ( ! is_int( $parent ) || $parent < 0 || ( $parent > 0 && ( ! is_taxonomy_hierarchical( $taxonomy ) || ! get_term( $parent, $taxonomy ) instanceof \WP_Term ) ) ) {
				return 'Invalid parent.';
			}
			return '';
		}
		$fields = self::fields();
		foreach ( $set as $field => $value ) {
			if ( ! isset( $fields[ $field ] ) ) {
				return 'Field not allowed: ' . $field;
			}
			if ( ! TermFields::valid_value( (string) $field, self::resolve( (string) $field, $value, array_fill_keys( $refs, PHP_INT_MAX ) ), array() ) ) {
				return 'Invalid ' . $field . ' (a list of term ids, or for an attribute {terms, visible, variation, position} or null).';
			}
			if ( 'attribute' === TermFields::kind( (string) $field ) && is_array( $value ) && ! empty( $value['variation'] ) ) {
				return 'An attribute used for variations is changed in the product editor only.';
			}
		}
		return '';
	}

	/**
	 * Extra checks against the current state (after `holds`): an attribute the product uses for variations stays as it is.
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $now  Current values.
	 * @param array<string,mixed> $set  New values.
	 */
	public static function problem( string $kind, array $now, array $set ): string {
		if ( 'product_terms' !== $kind ) {
			return '';
		}
		foreach ( $set as $field => $value ) {
			$current = $now[ $field ] ?? null;
			if ( 'attribute' === TermFields::kind( (string) $field ) && is_array( $current ) && ! empty( $current['variation'] ) && ! TermFields::same( (string) $field, $current, $value ) ) {
				return 'This attribute is used for variations; change it in the product editor.';
			}
		}
		return '';
	}

	/**
	 * "@N" references → the ids of the terms created by items N.
	 *
	 * @param string         $field   Field.
	 * @param mixed          $value   Value.
	 * @param array<int,int> $created Item index => created term id.
	 * @return mixed
	 */
	public static function resolve( string $field, $value, array $created ) {
		$map = static function ( $ids ) use ( $created ) {
			$out = array();
			foreach ( (array) $ids as $id ) {
				if ( is_string( $id ) && preg_match( self::REF, $id, $m ) ) {
					$out[] = isset( $created[ (int) $m[1] ] ) ? (int) $created[ (int) $m[1] ] : 'unresolved ' . $id;
					continue;
				}
				$out[] = $id;
			}
			return $out;
		};
		if ( 'attribute' === TermFields::kind( $field ) ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			if ( isset( $value['terms'] ) ) {
				$value['terms'] = $map( $value['terms'] );
			}
			return $value;
		}
		return is_array( $value ) ? $map( $value ) : $value;
	}

	/**
	 * Writes a product_terms unit (references already resolved) or creates a term. Term deletion: see delete_created().
	 *
	 * @param string              $kind      Kind.
	 * @param int                 $id        Id.
	 * @param array<string,mixed> $set       New values.
	 * @param string              $changeset Change set id (marks a created term).
	 * @return int The unit id (new term id for a created term).
	 */
	public static function write( string $kind, int $id, array $set, string $changeset ): int {
		if ( 'term' === $kind ) {
			$taxonomy = (string) $set['taxonomy'];
			$name     = trim( (string) $set['name'] );
			if ( TermsPlanner::find_exact( $name, $taxonomy ) instanceof \WP_Term ) {
				throw new \RuntimeException( esc_html( sprintf( 'A term named "%s" already exists.', $name ) ) );
			}
			$result = wp_insert_term( $name, $taxonomy, array( 'parent' => (int) ( $set['parent'] ?? 0 ) ) );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( esc_html( $result->get_error_message() ) );
			}
			$term_id = (int) $result['term_id'];
			update_term_meta( $term_id, self::CREATED_META, '' !== $changeset ? $changeset : '1' );
			return $term_id;
		}
		$product = wc_get_product( $id );
		foreach ( $set as $field => $value ) {
			TermFields::write( $product, (string) $field, $value );
		}
		$product->save();
		// set_category_ids / set_tag_ids are stored by save(); wp_set_object_terms ones already are.
		clean_post_cache( $id );
		return $id;
	}

	/**
	 * Undo of a created term: deleted only when this change set (any AddSynapse change set) created it and no product or
	 * post uses it now. Otherwise it stays.
	 *
	 * @param int $id Term id.
	 * @return bool Deleted.
	 */
	public static function delete_created( int $id ): bool {
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term || '' === (string) get_term_meta( $id, self::CREATED_META, true ) ) {
			return false;
		}
		$objects = get_objects_in_term( $id, $term->taxonomy );
		if ( is_wp_error( $objects ) || ! empty( $objects ) ) {
			return false;
		}
		if ( 0 < (int) get_terms(
			array(
				'taxonomy'   => $term->taxonomy,
				'parent'     => $id,
				'hide_empty' => false,
				'fields'     => 'count',
			)
		) ) {
			return false;
		}
		$deleted = wp_delete_term( $id, $term->taxonomy );
		return true === $deleted;
	}

	/**
	 * The values of a term unit.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string,mixed>
	 */
	public static function term_values( \WP_Term $term ): array {
		return array(
			'taxonomy' => (string) $term->taxonomy,
			'name'     => self::text( $term->name ),
			'parent'   => (int) $term->parent,
		);
	}

	/**
	 * Short form for the approval log: term names instead of ids.
	 *
	 * @param mixed $values Values.
	 * @return mixed
	 */
	public static function for_log( $values ) {
		if ( ! is_array( $values ) ) {
			return $values;
		}
		$out = array();
		foreach ( $values as $field => $value ) {
			$out[ $field ] = 0 === strpos( (string) $field, TermFields::TERMS ) || 0 === strpos( (string) $field, TermFields::ATTRIBUTE ) ? self::show( (string) $field, $value ) : $value;
		}
		return $out;
	}

	/**
	 * Names of the terms of a value («@N» shown as «new term»).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	private static function show( string $field, $value ): string {
		$names = array();
		foreach ( TermFields::term_list( $field, $value ) as $id ) {
			$term    = is_int( $id ) ? get_term( $id ) : null;
			$names[] = $term instanceof \WP_Term ? self::text( $term->name ) : ( is_string( $id ) ? 'new term' : (string) $id );
		}
		return $names ? mb_substr( implode( ', ', $names ), 0, 120 ) : 'empty';
	}

	/**
	 * Not-found unit.
	 *
	 * @param string $kind   Kind.
	 * @param int    $id     Id.
	 * @param string $reason Reason.
	 * @return array<string,mixed>
	 */
	private static function missing( string $kind, int $id, string $reason ): array {
		return array(
			'kind'   => $kind,
			'id'     => $id,
			'exists' => false,
			'reason' => $reason,
			'values' => null,
		);
	}

	/**
	 * Plain text.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}
}
