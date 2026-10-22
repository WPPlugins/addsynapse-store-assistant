<?php
/**
 * Text, link and image changes of one block (its saved HTML + the attributes that mirror it).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Operations set_text: paragraph, heading, button, list item, verse, preformatted, pullquote / quote citation-less text.
 * set_link: button, image with a link. set_image: image, cover (with an image), media & text.
 * Site title / tagline / logo are site settings (plan_theme_settings), not block content.
 */
final class BlockText {

	/**
	 * Blocks whose text is the inner HTML of one element → [element, kind for ContentText].
	 */
	private const TEXT = array(
		'core/paragraph'    => array( 'p', 'text' ),
		'core/heading'      => array( 'h[1-6]', 'heading' ),
		'core/list-item'    => array( 'li', 'text' ),
		'core/verse'        => array( 'pre', 'text' ),
		'core/preformatted' => array( 'pre', 'text' ),
		'core/button'       => array( 'a', 'button' ),
	);

	/**
	 * Sets the text of a block.
	 *
	 * @param BlockDoc $doc  Document.
	 * @param string   $id   Block.
	 * @param string   $text New text (HTML with inline tags; plain for buttons).
	 * @return array{before:string,after:string}
	 * @throws \InvalidArgumentException When the block has no text of its own.
	 */
	public static function set_text( BlockDoc $doc, string $id, string $text ): array {
		$node = self::node( $doc, $id );
		$name = (string) $node['name'];
		if ( in_array( $name, array( 'core/site-title', 'core/site-tagline' ), true ) ) {
			throw new \InvalidArgumentException( 'The site title / tagline is a site setting: change it with plan_theme_settings (blogname / blogdescription).' );
		}
		if ( ! isset( self::TEXT[ $name ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'A %s block has no text of its own to set: change the blocks inside it, or replace it with complete markup.', esc_html( $name ) ) );
		}
		$parts = $doc->html_parts( $id );
		if ( 1 !== count( $parts ) ) {
			throw new \InvalidArgumentException( 'This block has inner blocks: change those instead.' );
		}
		list( $tag, $kind ) = self::TEXT[ $name ];
		$html               = $parts[0];
		if ( 'core/button' === $name ) {
			if ( ! preg_match( '/^(.*?<a\b[^>]*>)(.*?)(<\/a>.*)$/s', $html, $m ) && ! preg_match( '/^(.*?<button\b[^>]*>)(.*?)(<\/button>.*)$/s', $html, $m ) ) {
				throw new \InvalidArgumentException( 'Unexpected button markup.' );
			}
			$clean = DesignText::plain( $text, 'button' );
			if ( is_wp_error( $clean ) ) {
				throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
			}
			$doc->update( $id, (array) $node['attrs'], array( $m[1] . esc_html( $clean ) . $m[3] ) );
			return array(
				'before' => html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES, 'UTF-8' ),
				'after'  => $clean,
			);
		}
		if ( ! preg_match( '/^(\s*<(' . $tag . ')\b[^>]*>)(.*)(<\/\2>\s*)$/s', $html, $m ) ) {
			throw new \InvalidArgumentException( 'Unexpected block markup: replace the block with complete markup instead.' );
		}
		$clean = DesignText::rich( $text, $kind );
		if ( is_wp_error( $clean ) ) {
			throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
		}
		$doc->update( $id, (array) $node['attrs'], array( $m[1] . $clean['html'] . $m[4] ) );
		return array(
			'before'   => DesignText::excerpt( $m[3], 300 ),
			'after'    => DesignText::excerpt( $clean['html'], 300 ),
			'external' => $clean['external'],
		);
	}

	/**
	 * Sets the link of a button / linked image.
	 *
	 * @param BlockDoc $doc Document.
	 * @param string   $id  Block.
	 * @param string   $url URL.
	 * @return array{before:string,after:string}
	 * @throws \InvalidArgumentException When the block has no link.
	 */
	public static function set_link( BlockDoc $doc, string $id, string $url ): array {
		$node  = self::node( $doc, $id );
		$clean = DesignText::url( $url );
		if ( is_wp_error( $clean ) ) {
			throw new \InvalidArgumentException( esc_html( $clean->get_error_message() ) );
		}
		$parts = $doc->html_parts( $id );
		if ( ! in_array( $node['name'], array( 'core/button', 'core/image' ), true ) || 1 !== count( $parts ) ) {
			throw new \InvalidArgumentException( 'Only buttons and linked images have a link to set (put links inside a text with set_text).' );
		}
		$tags = new \WP_HTML_Tag_Processor( $parts[0] );
		if ( ! $tags->next_tag( 'a' ) ) {
			throw new \InvalidArgumentException( 'This block has no link.' );
		}
		$before = (string) $tags->get_attribute( 'href' );
		$tags->set_attribute( 'href', $clean['url'] );
		$attrs = (array) $node['attrs'];
		if ( 'core/image' === $node['name'] && isset( $attrs['href'] ) ) {
			$attrs['href'] = $clean['url'];
		}
		$doc->update( $id, $attrs, array( $tags->get_updated_html() ) );
		return array(
			'before'   => $before,
			'after'    => $clean['url'],
			'external' => $clean['external'] ? array( $clean['url'] ) : array(),
		);
	}

	/**
	 * Sets the image of an image / cover / media & text block (an image of the media library).
	 *
	 * @param BlockDoc    $doc      Document.
	 * @param string      $id       Block.
	 * @param int         $image_id Attachment.
	 * @param string|null $alt      Alt text (null = keep / the image's own).
	 * @return array{before:string,after:string}
	 * @throws \InvalidArgumentException When the block has no image to replace.
	 */
	public static function set_image( BlockDoc $doc, string $id, int $image_id, ?string $alt = null ): array {
		$node  = self::node( $doc, $id );
		$name  = (string) $node['name'];
		$attrs = (array) $node['attrs'];
		$image = DesignText::image( $image_id, (string) ( $attrs['sizeSlug'] ?? 'full' ) );
		if ( is_wp_error( $image ) ) {
			throw new \InvalidArgumentException( esc_html( $image->get_error_message() ) );
		}
		$url = (string) $image['image_url'];
		if ( '' === $url ) {
			throw new \InvalidArgumentException( 'This image has no file.' );
		}
		if ( null !== $alt ) {
			$alt = DesignText::plain( $alt, 'alt' );
			if ( is_wp_error( $alt ) ) {
				throw new \InvalidArgumentException( esc_html( $alt->get_error_message() ) );
			}
		}
		$parts = $doc->html_parts( $id );
		$all   = implode( "\x01", $parts );
		$class = array(
			'core/image'      => '',
			'core/cover'      => 'wp-block-cover__image-background',
			'core/media-text' => '',
		);
		if ( ! array_key_exists( $name, $class ) ) {
			throw new \InvalidArgumentException( sprintf( 'A %s block has no image to set.', esc_html( $name ) ) );
		}
		$tags = new \WP_HTML_Tag_Processor( $all );
		$find = '' !== $class[ $name ] ? array( 'class_name' => $class[ $name ] ) : 'img';
		if ( ! $tags->next_tag( $find ) || 'IMG' !== $tags->get_tag() ) {
			throw new \InvalidArgumentException( 'This block has no image yet (e.g. a cover with only a color): replace it with complete markup that includes the image.' );
		}
		$before = (string) $tags->get_attribute( 'src' );
		$old_id = (int) ( 'core/media-text' === $name ? ( $attrs['mediaId'] ?? 0 ) : ( $attrs['id'] ?? 0 ) );
		$tags->set_attribute( 'src', $url );
		$css = (string) $tags->get_attribute( 'class' );
		$css = preg_match( '/\bwp-image-\d+\b/', $css ) ? (string) preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $image_id, $css ) : trim( $css . ' wp-image-' . $image_id );
		$tags->set_attribute( 'class', $css );
		$tags->remove_attribute( 'srcset' );
		$tags->remove_attribute( 'sizes' );
		$tags->remove_attribute( 'width' );
		$tags->remove_attribute( 'height' );
		if ( null !== $alt ) {
			$tags->set_attribute( 'alt', $alt );
			if ( 'core/cover' === $name ) {
				$attrs['alt'] = $alt;
			}
			if ( 'core/media-text' === $name ) {
				$attrs['mediaAlt'] = $alt;
			}
		}
		switch ( $name ) {
			case 'core/image':
				$attrs['id'] = $image_id;
				unset( $attrs['width'], $attrs['height'] );
				break;
			case 'core/cover':
				$attrs['id']  = $image_id;
				$attrs['url'] = $url;
				break;
			case 'core/media-text':
				$attrs['mediaId']   = $image_id;
				$attrs['mediaLink'] = (string) get_attachment_link( $image_id );
				$attrs['mediaType'] = 'image';
				break;
		}
		unset( $old_id );
		$doc->update( $id, $attrs, explode( "\x01", $tags->get_updated_html() ) );
		return array(
			'before' => $before,
			'after'  => $url,
		);
	}

	/**
	 * A node or an exception.
	 *
	 * @param BlockDoc $doc Document.
	 * @param string   $id  Id.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When missing.
	 */
	private static function node( BlockDoc $doc, string $id ): array {
		$node = $doc->get( $id );
		if ( null === $node ) {
			throw new \InvalidArgumentException( sprintf( 'Block %s was not found.', esc_html( $id ) ) );
		}
		return $node;
	}
}
