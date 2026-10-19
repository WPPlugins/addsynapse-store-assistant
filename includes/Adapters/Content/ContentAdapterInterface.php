<?php
/**
 * Content adapter contract: one implementation per page builder.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Adapters\Content;

defined( 'ABSPATH' ) || exit;

/**
 * A content adapter turns a builder's STORED representation of a page (Gutenberg post_content,
 * Elementor `_elementor_data`) into normalized nodes and applies node edits to it.
 *
 * Normalized node (what describe-page returns and the planner validates against):
 *   {
 *     id        string   stable node id (Elementor element id / Gutenberg path + attrs hash),
 *     type      string   heading | text | button | image | unsupported,
 *     label     string   human label (e.g. "Heading H2", "Button", "wd_title (unsupported)"),
 *     value     mixed    heading/text: the inner HTML; button: {text,url}; image: {image_id,url,alt,link};
 *                        unsupported: null,
 *     text      string   plain-text excerpt (≤ 160 chars) for lists / the picker,
 *     order     int      reading order inside the page (0-based),
 *     page_id   int      post id,
 *     path      string   structural path ("Container 2 › Container 1", "Group › Columns › Column 2"),
 *     editable  string[] the fields an edit may set: text | url | image_id | alt ([] = read-only),
 *     reason    string   why a node is read-only ('' when editable),
 *     hidden_on string[] (optional, Elementor) devices desktop|tablet|mobile where the element is not shown, incl. inherited,
 *   }
 *
 * "link" is not a node of its own: it is the `url` field of button nodes and of heading / image
 * nodes that already have a link. Byte rule: update_node() changes ONLY the bytes of the edited
 * node — every other node stays byte-identical, so an undo can restore the exact previous value.
 */
interface ContentAdapterInterface {

	/**
	 * Adapter key stored with changesets (gutenberg|elementor).
	 */
	public function kind(): string;

	/**
	 * Whether this adapter owns the post's content.
	 *
	 * @param int $post_id Post id.
	 */
	public function detect( int $post_id ): bool;

	/**
	 * The stored representation, exactly as in the database (null = none).
	 *
	 * @param int $post_id Post id.
	 */
	public function raw( int $post_id ): ?string;

	/**
	 * Normalized nodes of the post's CURRENT content.
	 *
	 * @param int $post_id Post id.
	 * @return array<int,array<string,mixed>>
	 */
	public function read_nodes( int $post_id ): array;

	/**
	 * Normalized nodes of a given stored representation.
	 *
	 * @param string $raw     Stored representation.
	 * @param int    $post_id Post id (for the nodes' page_id).
	 * @return array<int,array<string,mixed>>
	 * @throws \RuntimeException When the content cannot be read safely.
	 */
	public function nodes_from( string $raw, int $post_id ): array;

	/**
	 * Structural anchors of a stored representation, for placing NEW blocks: top-level sections
	 * and container elements (depth ≤ 2), in reading order:
	 *   {id, type (container|section|column / block name), label, title, path, depth, top_id,
	 *    contains (widget / block types inside, ≤ 6), excerpt (first text inside), can_contain
	 *    (inside_first / inside_last allowed), hidden_on?}
	 *
	 * @param string $raw     Stored representation.
	 * @param int    $post_id Post id.
	 * @return array<int,array<string,mixed>>
	 * @throws \RuntimeException When the content cannot be read safely.
	 */
	public function sections( string $raw, int $post_id ): array;
}
