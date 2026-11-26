<?php
/**
 * Links to the shop's own content in a draft (1.3.20): every NEW link that points into this site must lead to something that
 * exists, before the draft is shown. 25/11/2026 a menu item and a header link got the guessed pretty address
 * /mystore/product-category/test/ on a shop with plain permalinks (the real one is /mystore/?product_cat=test) and gave 404.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Resolver of own-site links. Order: anchor / home / search → a published post (url_to_postid) → a term or a post type archive
 * (query var or the canonical link of the term the last path segment names) → a file under the site → (pretty permalinks only)
 * one GET to the site itself without following redirects. Only a sure «does not exist» refuses; a site that cannot reach itself
 * (loopback blocked) is let through with a note. Links already in the content being edited are not checked again (an old broken
 * link must not block an unrelated edit).
 */
final class OwnLinks {

	/**
	 * Normalised links already present in the content being edited.
	 *
	 * @var array<string,bool>
	 */
	private static array $known = array();

	/**
	 * Own links checked in this draft: final URL → label.
	 *
	 * @var array<string,string>
	 */
	private static array $used = array();

	/**
	 * Own links that could not be verified (the site did not answer itself).
	 *
	 * @var array<int,string>
	 */
	private static array $unverified = array();

	/**
	 * Own links to items that exist but are not public yet (drafts): allowed, the card says so.
	 *
	 * @var array<int,string>
	 */
	private static array $drafts = array();

	/**
	 * Requests to the site itself in this request (cap: MAX_GETS, MAX_SECONDS).
	 *
	 * @var int
	 */
	private static int $gets = 0;

	/**
	 * Seconds spent on requests to the site itself.
	 *
	 * @var float
	 */
	private static float $spent = 0.0;

	public const MAX_GETS    = 10;
	public const MAX_SECONDS = 10.0;

	/**
	 * Results of this request.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $cache = array();

	/**
	 * Off for the rest of the request (apply-changes: Approve / Undo never refuse because of a link).
	 *
	 * @var bool
	 */
	private static bool $off = false;

	/**
	 * Turns the check off for the rest of this request.
	 */
	public static function off(): void {
		self::$off = true;
	}

	/**
	 * Starts a draft: forgets the previous one and remembers the links already in the content being edited.
	 *
	 * @param string $existing Current content (block markup, HTML, Elementor JSON…).
	 */
	public static function begin( string $existing = '' ): void {
		self::$known      = array();
		self::$used       = array();
		self::$unverified = array();
		self::$drafts     = array();
		self::$off        = false;
		self::remember( $existing );
	}

	/**
	 * Adds the links of a content to the «already there» set.
	 *
	 * @param string $content Content.
	 */
	public static function remember( string $content ): void {
		foreach ( self::extract( $content ) as $url ) {
			self::$known[ self::key( $url ) ] = true;
		}
	}

	/**
	 * Links (href / src / "url" values) of a content.
	 *
	 * @param string $content Content.
	 * @return array<int,string>
	 */
	public static function extract( string $content ): array {
		if ( '' === $content ) {
			return array();
		}
		$out = array();
		// JSON inside JSON (Elementor data): unescape slashes and quotes until stable (at most 3 levels).
		$text = $content;
		for ( $i = 0; $i < 3; $i++ ) {
			$next = str_replace( array( '\\/', '\\u002f', '\\u002F', '\\"', '\\u0022' ), array( '/', '/', '/', '"', '"' ), $text );
			if ( $next === $text ) {
				break;
			}
			$text = $next;
		}
		if ( preg_match_all( '/\b(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$out[] = html_entity_decode( (string) ( $match[1] ?? '' ) . (string) ( $match[2] ?? '' ), ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( preg_match_all( '/"(?:url|href|link)"\s*:\s*"([^"]*)"/i', $text, $m ) ) {
			foreach ( $m[1] as $url ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Own links checked in this draft (final URL → label), for the card.
	 *
	 * @return array<string,string>
	 */
	public static function used(): array {
		return self::$used;
	}

	/**
	 * Own links to items that are not public yet.
	 *
	 * @return array<int,string>
	 */
	public static function drafts(): array {
		return array_values( array_unique( self::$drafts ) );
	}

	/**
	 * Own links the site could not verify.
	 *
	 * @return array<int,string>
	 */
	public static function unverified(): array {
		return array_values( array_unique( self::$unverified ) );
	}

	/**
	 * Is this URL (absolute or relative) on this site?
	 *
	 * @param string $url URL.
	 */
	public static function is_own( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || '#' === $url[0] ) {
			return false;
		}
		if ( preg_match( '#^(/(?!/)|\?)#', $url ) ) {
			return true;
		}
		$host = (string) wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
		return '' !== $host && self::host( $host ) === self::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Checks one link of a draft. Links to other sites, anchors and links already in the content pass unchanged.
	 *
	 * @param string $url   URL as given.
	 * @param string $label What the link is (for the card).
	 * @return array{url:string,problem:string,object:array<string,mixed>|null}
	 */
	public static function check( string $url, string $label = '' ): array {
		$url = trim( $url );
		$ok  = array(
			'url'     => $url,
			'problem' => '',
			'object'  => null,
		);
		if ( self::$off || ! self::is_own( $url ) || isset( self::$known[ self::key( $url ) ] ) ) {
			return $ok;
		}
		$result = self::resolve( $url );
		if ( '' === $result['problem'] ) {
			self::$used[ $result['url'] ] = '' !== $label ? $label : ( self::$used[ $result['url'] ] ?? '' );
			if ( ! empty( $result['draft'] ) ) {
				self::$drafts[] = $result['url'];
			} elseif ( ! empty( $result['unverified'] ) ) {
				self::$unverified[] = $result['url'];
			}
		}
		unset( $result['unverified'], $result['draft'] );
		return $result;
	}

	/**
	 * Resolves an own link (no «known» shortcut, no bookkeeping).
	 *
	 * @param string $url URL.
	 * @return array{url:string,problem:string,object:array<string,mixed>|null,unverified?:bool}
	 */
	public static function resolve( string $url ): array {
		$url = trim( $url );
		if ( isset( self::$cache[ $url ] ) ) {
			return self::$cache[ $url ];
		}
		$abs    = self::absolute( $url );
		$result = self::lookup( $abs );
		if ( null === $result['object'] && '' === $result['problem'] && empty( $result['unverified'] ) ) {
			$result['url'] = $url;
		}
		self::$cache[ $url ] = $result;
		return $result;
	}

	/**
	 * The real link of an object (term / post), or '' when it does not exist or is not public.
	 *
	 * @param string $kind     taxonomy|post-type.
	 * @param string $type     Taxonomy or post type.
	 * @param int    $id       Id.
	 */
	public static function object_link( string $kind, string $type, int $id ): string {
		if ( $id < 1 ) {
			return '';
		}
		if ( 'taxonomy' === $kind ) {
			$term = get_term( $id, '' !== $type ? $type : '' );
			if ( ! $term instanceof \WP_Term || ( '' !== $type && $term->taxonomy !== $type ) ) {
				return '';
			}
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		$post = get_post( $id );
		if ( ! $post || ( '' !== $type && $post->post_type !== $type ) || 'publish' !== $post->post_status ) {
			return '';
		}
		return (string) get_permalink( $post );
	}

	/**
	 * Navigation links of block markup (core/navigation-link, core/navigation-submenu): the address lives in the block comment,
	 * where no href scan sees it, and WordPress prints that stored address as it is (it does not rebuild it from the id). Every
	 * NEW one: linked to an item (kind + id) → its address is set to the item's real link now; a typed address into the shop →
	 * must exist, and becomes a link to the item when it names one.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 * @throws \InvalidArgumentException When a new link leads nowhere.
	 */
	public static function fix_nav_links( string $markup ): string {
		if ( self::$off ) {
			return $markup;
		}
		$out = preg_replace_callback(
			'#<!--\s+wp:(navigation-link|navigation-submenu)\s+(\{.*?\})\s+(/?)-->#s',
			static function ( array $m ): string {
				$attrs = json_decode( $m[2], true );
				if ( ! is_array( $attrs ) ) {
					return $m[0];
				}
				$url   = is_string( $attrs['url'] ?? null ) ? trim( $attrs['url'] ) : '';
				$kind  = is_string( $attrs['kind'] ?? null ) ? $attrs['kind'] : '';
				$id    = is_numeric( $attrs['id'] ?? null ) ? (int) $attrs['id'] : 0;
				$label = is_string( $attrs['label'] ?? null ) ? trim( wp_strip_all_tags( $attrs['label'] ) ) : '';
				if ( '' !== $url && isset( self::$known[ self::key( $url ) ] ) ) {
					return $m[0];
				}
				if ( in_array( $kind, array( 'taxonomy', 'post-type' ), true ) && $id > 0 ) {
					$type = is_string( $attrs['type'] ?? null ) ? $attrs['type'] : '';
					$type = 'tag' === $type ? 'post_tag' : $type;
					$link = self::object_link( $kind, $type, $id );
					if ( '' === $link ) {
						throw new \InvalidArgumentException( esc_html( sprintf( 'The menu link "%1$s" points to %2$s #%3$d, which does not exist or is not published. Nothing was changed.', $label, '' !== $type ? $type : $kind, $id ) ) );
					}
					$attrs['url'] = $link;
				} elseif ( '' !== $url && self::is_own( $url ) ) {
					$result = self::resolve( $url );
					if ( '' !== $result['problem'] ) {
						throw new \InvalidArgumentException( esc_html( $result['problem'] ) );
					}
					if ( is_array( $result['object'] ) ) {
						$attrs['kind'] = $result['object']['kind'];
						$attrs['type'] = 'post_tag' === $result['object']['type'] ? 'tag' : $result['object']['type'];
						$attrs['id']   = (int) $result['object']['id'];
					}
					$attrs['url'] = $result['url'];
					if ( ! empty( $result['draft'] ) ) {
						self::$drafts[] = $result['url'];
					} elseif ( ! empty( $result['unverified'] ) ) {
						self::$unverified[] = $result['url'];
					}
				} else {
					return $m[0];
				}//end if
				self::$used[ (string) $attrs['url'] ]               = $label;
				self::$known[ self::key( (string) $attrs['url'] ) ] = true;
				return '<!-- wp:' . $m[1] . ' ' . serialize_block_attributes( $attrs ) . ' ' . $m[3] . '-->';
			},
			$markup
		);
		return is_string( $out ) ? $out : $markup;
	}

	/**
	 * The first new own link of an HTML text that leads nowhere ('' = all fine). Links already in $existing pass.
	 *
	 * @param string $html     New text.
	 * @param string $existing Current text.
	 */
	public static function html_problem( string $html, string $existing ): string {
		$before = array();
		foreach ( self::extract( $existing ) as $url ) {
			$before[ self::key( $url ) ] = true;
		}
		foreach ( self::extract( $html ) as $url ) {
			if ( ! self::is_own( $url ) || isset( $before[ self::key( $url ) ] ) ) {
				continue;
			}
			$result = self::resolve( $url );
			if ( '' !== $result['problem'] ) {
				return $result['problem'];
			}
		}
		return '';
	}

	/**
	 * Clears the per-request cache (tests).
	 */
	public static function flush(): void {
		self::$cache = array();
		self::$gets  = 0;
		self::$spent = 0.0;
		self::$off   = false;
		self::begin();
	}

	/**
	 * The message when a link does not exist.
	 *
	 * @param string $url     URL.
	 * @param string $suggest Real link of the closest match ('' = none).
	 */
	public static function message( string $url, string $suggest ): string {
		return sprintf( 'This address does not exist on your shop: %s.', $url )
			. ( '' !== $suggest ? sprintf( ' Did you mean %s?', $suggest ) : '' )
			. ' Never type or guess an address of the shop\'s own pages, categories or products: link to the item itself (its id), so WordPress makes the address. Nothing was changed.';
	}

	// ── Internals ─────────────────────────────────────────────────────────────────────────

	/**
	 * Lookup of an absolute own URL.
	 *
	 * @param string $abs Absolute URL.
	 * @return array{url:string,problem:string,object:array<string,mixed>|null,unverified?:bool}
	 */
	private static function lookup( string $abs ): array {
		$parts = wp_parse_url( $abs );
		$path  = (string) ( $parts['path'] ?? '/' );
		$query = array();
		wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/';
		$found     = static function ( string $kind, string $type, int $id, string $link ) {
			return array(
				'url'     => $link,
				'problem' => '',
				'object'  => array(
					'kind' => $kind,
					'type' => $type,
					'id'   => $id,
				),
			);
		};
		$plain_ok  = array(
			'url'     => $abs,
			'problem' => '',
			'object'  => null,
		);

		// A file of the site (media, sitemap…).
		$file = self::file_for( $path );
		if ( '' !== $file ) {
			return $plain_ok;
		}
		$inside = 0 === strpos( rtrim( $path, '/' ) . '/', $home_path );
		$rest   = $inside ? trim( substr( rtrim( $path, '/' ) . '/', strlen( $home_path ) ), '/' ) : trim( $path, '/' );

		// Query vars: a term or a post type archive.
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$var = is_string( $tax->query_var ) && '' !== $tax->query_var ? $tax->query_var : '';
			if ( '' !== $var && isset( $query[ $var ] ) && is_string( $query[ $var ] ) ) {
				$term = get_term_by( 'slug', sanitize_title( $query[ $var ] ), $tax->name );
				if ( $term instanceof \WP_Term ) {
					$link = get_term_link( $term );
					return $found( 'taxonomy', $tax->name, (int) $term->term_id, is_wp_error( $link ) ? $abs : (string) $link );
				}
			}
		}
		if ( isset( $query['cat'] ) && is_numeric( $query['cat'] ) ) {
			$link = self::object_link( 'taxonomy', 'category', (int) $query['cat'] );
			if ( '' !== $link ) {
				return $found( 'taxonomy', 'category', (int) $query['cat'], $link );
			}
		}
		foreach ( array( 'p', 'page_id' ) as $var ) {
			if ( isset( $query[ $var ] ) && is_numeric( $query[ $var ] ) ) {
				$link = self::object_link( 'post-type', '', (int) $query[ $var ] );
				if ( '' === $link ) {
					$draft = get_post( (int) $query[ $var ] );
					if ( $draft && in_array( $draft->post_status, array( 'draft', 'pending', 'future', 'private' ), true ) ) {
						// It exists, not public yet (e.g. several new articles that link to each other): allowed, the card says so.
						return $plain_ok + array( 'draft' => true );
					}
					return array(
						'url'     => $abs,
						'problem' => self::message( $abs, '' ),
						'object'  => null,
					);
				}
				$post = get_post( (int) $query[ $var ] );
				return $found( 'post-type', (string) $post->post_type, (int) $post->ID, $link );
			}
		}
		if ( $inside && '' === $rest && isset( $query['post_type'] ) && is_string( $query['post_type'] ) && post_type_exists( $query['post_type'] ) && get_post_type_archive_link( $query['post_type'] ) ) {
			return $plain_ok;
		}

		// A post / page / product.
		$post_id = url_to_postid( $abs );
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				return $found( 'post-type', (string) $post->post_type, (int) $post->ID, (string) get_permalink( $post ) );
			}
			if ( $post && in_array( $post->post_status, array( 'draft', 'pending', 'future', 'private' ), true ) ) {
				return $plain_ok + array( 'draft' => true );
			}
		}

		// The home page, a search.
		$known_vars = array_diff( array_keys( $query ), array( 's', 'post_type', 'orderby', 'order', 'paged', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'add-to-cart', 'lang' ) );
		if ( $inside && '' === $rest && ! $known_vars ) {
			return $plain_ok;
		}

		// The canonical link of a term / post / archive the URL names (also the «did you mean»).
		$slug    = '';
		$segment = '' !== $rest ? (string) basename( $rest ) : '';
		foreach ( $query as $value ) {
			if ( is_string( $value ) && '' !== $value && '' === $slug ) {
				$slug = sanitize_title( $value );
			}
		}
		if ( '' !== $segment ) {
			$slug = sanitize_title( rawurldecode( $segment ) );
		}
		$suggest = '';
		$key     = self::key( $abs );
		foreach ( self::candidates( $slug ) as $candidate ) {
			if ( self::key( $candidate['url'] ) === $key ) {
				return $found( $candidate['kind'], $candidate['type'], $candidate['id'], $candidate['url'] );
			}
			if ( '' === $suggest ) {
				$suggest = $candidate['url'];
			}
		}
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$archive = get_post_type_archive_link( $type );
			if ( $archive && self::key( $archive ) === $key ) {
				return $plain_ok;
			}
		}

		$bad = array(
			'url'     => $abs,
			'problem' => self::message( $abs, $suggest ),
			'object'  => null,
		);
		if ( ! $inside ) {
			// Outside the WordPress folder (e.g. /product-category/… on a shop in /mystore): only a real file would answer.
			return self::http( $abs, $bad, $plain_ok );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) && '' !== $rest ) {
			// Plain permalinks: WordPress answers no path below its folder except files (checked above).
			return $bad;
		}
		return self::http( $abs, $bad, $plain_ok );
	}

	/**
	 * Last resort: one GET to the site itself (no redirects followed). 404/410 → $bad; an answer → $ok; no answer → $ok, unverified.
	 *
	 * @param string              $abs URL.
	 * @param array<string,mixed> $bad Result when it does not exist.
	 * @param array<string,mixed> $ok  Result when it does.
	 * @return array<string,mixed>
	 */
	private static function http( string $abs, array $bad, array $ok ): array {
		// Only the site's own address (same port), at most MAX_GETS requests / MAX_SECONDS per request; the rest «not checked».
		$port = (int) wp_parse_url( $abs, PHP_URL_PORT );
		$home = (int) wp_parse_url( home_url(), PHP_URL_PORT );
		if ( $port !== $home || self::$gets >= self::MAX_GETS || self::$spent >= self::MAX_SECONDS ) {
			return $ok + array( 'unverified' => true );
		}
		++self::$gets;
		$start        = microtime( true );
		$response     = wp_remote_get(
			$abs,
			array(
				'timeout'             => (float) min( 5, max( 1, self::MAX_SECONDS - self::$spent ) ),
				'redirection'         => 0,
				'limit_response_size' => 2048,
				'headers'             => array( 'X-Addsynapse-Link-Check' => '1' ),
			)
		);
		self::$spent += microtime( true ) - $start;
		if ( is_wp_error( $response ) ) {
			return $ok + array( 'unverified' => true );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code || 410 === $code ) {
			return $bad;
		}
		if ( $code >= 200 && $code < 400 ) {
			return $ok;
		}
		return $ok + array( 'unverified' => true );
	}

	/**
	 * Terms / posts whose slug is $slug, with their real links.
	 *
	 * @param string $slug Slug.
	 * @return array<int,array{kind:string,type:string,id:int,url:string}>
	 */
	private static function candidates( string $slug ): array {
		if ( '' === $slug ) {
			return array();
		}
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$term = get_term_by( 'slug', $slug, $tax );
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$out[] = array(
						'kind' => 'taxonomy',
						'type' => (string) $tax,
						'id'   => (int) $term->term_id,
						'url'  => (string) $link,
					);
				}
			}
		}
		$posts = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
				'post_status' => 'publish',
				'numberposts' => 5,
			)
		);
		foreach ( $posts as $post ) {
			$out[] = array(
				'kind' => 'post-type',
				'type' => (string) $post->post_type,
				'id'   => (int) $post->ID,
				'url'  => (string) get_permalink( $post ),
			);
		}
		return $out;
	}

	/**
	 * Absolute form of an own URL (relative paths are relative to the domain, as a browser reads them).
	 *
	 * @param string $url URL.
	 */
	private static function absolute( string $url ): string {
		if ( '?' === $url[0] ) {
			return home_url( '/' ) . $url;
		}
		if ( '/' === $url[0] && 0 !== strpos( $url, '//' ) ) {
			$home = wp_parse_url( home_url() );
			return (string) ( $home['scheme'] ?? 'https' ) . '://' . (string) ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' ) . $url;
		}
		return 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
	}

	/**
	 * A real file of the site the path names ('' = none).
	 *
	 * @param string $path URL path.
	 */
	private static function file_for( string $path ): string {
		$path = rawurldecode( $path );
		if ( '' === $path || '/' === substr( $path, -1 ) || false !== strpos( $path, '..' ) || ! preg_match( '/\.[A-Za-z0-9]{1,5}$/', $path ) || preg_match( '/\.php$/i', $path ) ) {
			return '';
		}
		$roots = array(
			(string) wp_parse_url( content_url( '/' ), PHP_URL_PATH ) => trailingslashit( WP_CONTENT_DIR ),
			(string) wp_parse_url( site_url( '/' ), PHP_URL_PATH )    => trailingslashit( ABSPATH ),
		);
		foreach ( $roots as $prefix => $dir ) {
			if ( '' !== $prefix && 0 === strpos( $path, $prefix ) ) {
				$file = $dir . ltrim( substr( $path, strlen( $prefix ) ), '/' );
				if ( is_file( $file ) ) {
					return $file;
				}
			}
		}
		return '';
	}

	/**
	 * Comparison key: host without www, no scheme, no trailing slash, sorted query, no fragment.
	 *
	 * @param string $url URL.
	 */
	public static function key( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url ) {
			return '';
		}
		if ( '#' !== $url[0] && ( '/' === $url[0] || '?' === $url[0] ) ) {
			$url = self::absolute( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$query = array();
		wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
		ksort( $query );
		return self::host( (string) ( $parts['host'] ?? '' ) ) . rtrim( (string) ( $parts['path'] ?? '' ), '/' ) . ( $query ? '?' . http_build_query( $query ) : '' );
	}

	/**
	 * Lower-case host without www.
	 *
	 * @param string $host Host.
	 */
	private static function host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}
}
