<?php
/**
 * Checks of NEW block markup (insert / replace) and of where a block may go.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * New markup: blocks only, registered block types only, no script / style / iframe / event handlers / javascript: URLs,
 * images only from this site, and — for a manager without the «unfiltered_html» capability — WordPress's own kses of
 * block content. Theme / plugin patterns are taken from the pattern registry (their markup is the theme's own).
 */
final class BlockMarkup {

	public const MAX_BYTES = 60000;

	/**
	 * Tags never allowed in new markup.
	 */
	private const DANGEROUS = '/<\s*\/?\s*(script|style|iframe|frame|frameset|object|embed|applet|form|input|textarea|select|link|meta|base|template|noscript)\b/i';

	/**
	 * Checks and cleans new markup.
	 *
	 * @param string $markup Markup.
	 * @return array{markup:string,external:array<int,string>,warnings:array<int,string>}
	 * @throws \InvalidArgumentException When it is not allowed.
	 */
	public static function check( string $markup ): array {
		$markup = trim( str_replace( "\r\n", "\n", $markup ) );
		if ( '' === $markup ) {
			throw new \InvalidArgumentException( 'The new markup is empty.' );
		}
		if ( strlen( $markup ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'The new markup is too long (max %d bytes): split it into several operations.', self::MAX_BYTES ) ) );
		}
		$decoded = html_entity_decode( $markup, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( array( $markup, $decoded ) as $candidate ) {
			if ( preg_match( self::DANGEROUS, $candidate ) ) {
				throw new \InvalidArgumentException( 'Scripts, styles, iframes, forms and embeds are not allowed in new blocks (use a core/embed block with a URL for videos; CSS goes to the site styles).' );
			}
			if ( preg_match( '/[\s"\'\/]on[a-z]+\s*=|\bsrcdoc\s*=|\bformaction\s*=/i', $candidate ) ) {
				throw new \InvalidArgumentException( 'Event-handler attributes (on…=) are not allowed.' );
			}
			if ( preg_match( '/(?:java|vb)script\s*:|\bdata\s*:\s*text\/html/i', $candidate ) ) {
				throw new \InvalidArgumentException( 'Only http(s) or relative links are allowed.' );
			}
		}
		$warnings = array();
		$names    = BlockDoc::names( $markup );
		if ( ! $names ) {
			throw new \InvalidArgumentException( 'The new markup has no block.' );
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array_unique( $names ) as $name ) {
			if ( 'core/freeform' === $name || 'core/missing' === $name ) {
				throw new \InvalidArgumentException( 'Classic (freeform) content is not allowed: use blocks.' );
			}
			if ( ! $registry->is_registered( $name ) ) {
				throw new \InvalidArgumentException( sprintf( 'The block type %s is not available on this site.', esc_html( $name ) ) );
			}
			if ( 'core/html' === $name && ! current_user_can( 'unfiltered_html' ) ) {
				throw new \InvalidArgumentException( 'Custom HTML blocks need the «unfiltered_html» permission (an administrator).' );
			}
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$filtered = filter_block_content( $markup, 'post' );
			if ( $filtered !== $markup ) {
				$warnings[] = 'unsupported_markup_removed';
				$markup     = $filtered;
			}
		}
		$external = array();
		$home     = self::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$tags     = new \WP_HTML_Tag_Processor( $markup );
		while ( $tags->next_tag() ) {
			$tag = $tags->get_tag();
			foreach ( array( 'href', 'src' ) as $attr ) {
				$value = $tags->get_attribute( $attr );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$url = DesignText::url( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
				if ( is_wp_error( $url ) ) {
					throw new \InvalidArgumentException( esc_html( $url->get_error_message() ) );
				}
				if ( $url['external'] ) {
					if ( 'src' === $attr && in_array( $tag, array( 'IMG', 'VIDEO', 'AUDIO', 'SOURCE' ), true ) ) {
						throw new \InvalidArgumentException( 'Images and media must come from this site\'s media library (find_media), not from another site.' );
					}
					$external[] = $url['url'];
				}
			}
			if ( 'IMG' === $tag ) {
				$class = (string) $tags->get_attribute( 'class' );
				if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) && ! wp_attachment_is_image( (int) $m[1] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Image #%d is not in the media library.', (int) $m[1] ) );
				}
			}
		}//end while
		unset( $home );
		return array(
			'markup'   => $markup,
			'external' => array_values( array_unique( $external ) ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Markup of a registered (theme / plugin / core) pattern.
	 *
	 * @param string $name Pattern name (e.g. twentytwentyfive/banner-hero).
	 * @throws \InvalidArgumentException When unknown.
	 */
	public static function pattern( string $name ): string {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		if ( ! $registry->is_registered( $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'The pattern "%s" does not exist: use a name from describe_site_design (patterns).', esc_html( $name ) ) );
		}
		$pattern = $registry->get_registered( $name );
		$content = (string) ( $pattern['content'] ?? '' );
		if ( '' === trim( $content ) ) {
			throw new \InvalidArgumentException( 'This pattern is empty.' );
		}
		return $content;
	}

	/**
	 * Markup of a synced pattern reference.
	 *
	 * @param int $id wp_block post.
	 * @throws \InvalidArgumentException When it is not a published synced pattern.
	 */
	public static function synced( int $id ): string {
		$post = get_post( $id );
		if ( ! $post || 'wp_block' !== $post->post_type || 'publish' !== $post->post_status ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Synced pattern #%d was not found.', $id ) ) );
		}
		return '<!-- wp:block {"ref":' . (int) $id . '} /-->';
	}

	/**
	 * Why a block may not sit in a place ('' = fine): block.json `parent` / `ancestor` / the container's `allowedBlocks`.
	 *
	 * @param string            $name      Block.
	 * @param string            $parent    Parent block ('' = top level).
	 * @param array<int,string> $ancestors Ancestor block names.
	 */
	public static function placement_problem( string $name, string $parent, array $ancestors ): string {
		$registry = \WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( $name );
		if ( $type && ! empty( $type->parent ) && ! in_array( $parent, (array) $type->parent, true ) ) {
			return sprintf( 'A %1$s block must be directly inside %2$s.', $name, implode( ' or ', (array) $type->parent ) );
		}
		if ( $type && ! empty( $type->ancestor ) && ! array_intersect( (array) $type->ancestor, $ancestors ) ) {
			return sprintf( 'A %1$s block must be inside %2$s.', $name, implode( ' or ', (array) $type->ancestor ) );
		}
		$container = '' !== $parent ? $registry->get_registered( $parent ) : null;
		$allowed   = $container && is_array( $container->allowed_blocks ?? null ) ? $container->allowed_blocks : null;
		if ( $allowed && ! in_array( $name, $allowed, true ) ) {
			return sprintf( 'A %1$s block cannot contain %2$s (allowed: %3$s).', $parent, $name, implode( ', ', $allowed ) );
		}
		return '';
	}

	/**
	 * Expands pattern references (<!-- wp:pattern {"slug":…} /-->) into the pattern's blocks, as the block editor does
	 * when it opens a template (WordPress resolve_pattern_blocks: a single-root pattern keeps its name in
	 * metadata.patternName). Saving the expanded blocks is what the Site Editor stores too.
	 *
	 * @param string $raw   Markup.
	 * @param int    $depth Nesting guard.
	 */
	public static function expand( string $raw, int $depth = 0 ): string {
		if ( $depth > 4 || false === strpos( $raw, '<!-- wp:pattern ' ) ) {
			return $raw;
		}
		try {
			$doc = new BlockDoc( $raw );
		} catch ( \RuntimeException $e ) {
			return $raw;
		}
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$changed  = false;
		foreach ( $doc->nodes() as $node ) {
			if ( 'core/pattern' !== $node['name'] || empty( $node['attrs']['slug'] ) || ! $doc->has( $node['id'] ) ) {
				continue;
			}
			$pattern = $registry->get_registered( (string) $node['attrs']['slug'] );
			if ( ! $pattern || '' === trim( (string) ( $pattern['content'] ?? '' ) ) ) {
				continue;
			}
			try {
				$nodes = $doc->parse_new( trim( (string) $pattern['content'] ) );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
			if ( 1 === count( $nodes ) ) {
				$meta                = (array) ( $nodes[0]['attrs']['metadata'] ?? array() );
				$meta['patternName'] = (string) $node['attrs']['slug'];
				foreach ( array(
					'name'        => 'title',
					'description' => 'description',
					'categories'  => 'categories',
				) as $key => $field ) {
					$value = $pattern[ $field ] ?? $meta[ $key ] ?? null;
					if ( $value ) {
						$meta[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
					}
				}
				$nodes[0]['attrs']['metadata'] = $meta;
				$nodes[0]['attrs_dirty']       = true;
				$nodes[0]['dirty']             = true;
			}
			$doc->replace( $node['id'], $nodes );
			$changed = true;
		}//end foreach
		return $changed ? self::expand( $doc->serialize(), $depth + 1 ) : $raw;
	}

	/**
	 * Host without www.
	 *
	 * @param string $host Host.
	 */
	private static function host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}
}
