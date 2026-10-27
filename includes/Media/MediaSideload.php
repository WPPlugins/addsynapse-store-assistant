<?php
/**
 * An image from a public web address into the media library (1.3.7, plan_media_upload): only after the owner approved the
 * draft, only https, only public addresses (no private, loopback or link-local hosts), only JPG / PNG / WebP / GIF up to
 * MAX_BYTES. Undo deletes exactly that attachment (marked with SOURCE_META) while nothing on the site uses it.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Media;

use Addsynapse\StoreAssistant\Seams;

defined( 'ABSPATH' ) || exit;

/**
 * Checks and downloads one image.
 */
final class MediaSideload {

	/** Largest image accepted (bytes). */
	public const MAX_BYTES = 10485760;

	/** Seconds the download may take (the approval answers within the relay's time budget). */
	public const TIMEOUT = 15;

	/** The address the image came from: marks an image AddSynapse added (Undo deletes only those). */
	public const SOURCE_META = '_addsynapse_assistant_source_url';

	/** Accepted types → extension. */
	public const TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	);

	/** Longest alt text / title. */
	public const MAX_ALT   = 250;
	public const MAX_TITLE = 200;

	/**
	 * Why a web address may not be downloaded ('' = it may). With $resolve the host must resolve to public addresses only.
	 *
	 * @param mixed $url     Address.
	 * @param bool  $resolve Look the host up.
	 */
	public static function url_problem( $url, bool $resolve = true ): string {
		if ( ! is_string( $url ) || '' === trim( $url ) || strlen( $url ) > 2000 || preg_match( '/[\s<>"\'`\x00-\x1f\\\\]/', $url ) ) {
			return 'Give the full https address of the image.';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return 'Only https addresses can be used.';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return 'The address must not contain a user name or password.';
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return 'Only the standard https port is allowed.';
		}
		$host = strtolower( trim( (string) $parts['host'], '[]' ) );
		if ( 'localhost' === $host || preg_match( '/\.(localhost|local|internal|lan|home|corp|intranet)$/', $host ) ) {
			return 'The address points at a private network, not a public website.';
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::public_ip( $host ) ? '' : 'The address points at a private network, not a public website.';
		}
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]{0,62}\.)+[a-z]{2,63}$/', $host ) && ! preg_match( '/^xn--/', $host ) ) {
			return 'The address has no valid host name.';
		}
		if ( ! $resolve ) {
			return '';
		}
		$ips = self::resolve( $host );
		if ( ! $ips ) {
			return 'The host of this address could not be found.';
		}
		foreach ( $ips as $ip ) {
			if ( ! self::public_ip( $ip ) ) {
				return 'The address points at a private network, not a public website.';
			}
		}
		return '';
	}

	/**
	 * Whether an IP address is public (not private, loopback, link-local or reserved).
	 *
	 * @param string $ip Address.
	 */
	public static function public_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
			&& ! preg_match( '/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|::ffff:|fe80:|f[cd])/i', $ip );
	}

	/**
	 * IPv4 addresses of a host (tests: seam `media_dns` = [host => [ips]]).
	 *
	 * @param string $host Host.
	 * @return array<int,string>
	 */
	private static function resolve( string $host ): array {
		$fake = Seams::get( 'media_dns', 'array' );
		if ( null !== $fake ) {
			return array_values( array_map( 'strval', (array) ( $fake[ $host ] ?? array() ) ) );
		}
		$ips = gethostbynamel( $host );
		return is_array( $ips ) ? $ips : array();
	}

	/**
	 * Why an alt text / title may not be stored as it is ('' = fine). Plain text, already trimmed, single spaces.
	 *
	 * @param mixed  $text Text.
	 * @param string $what alt|title.
	 */
	public static function text_problem( $text, string $what ): string {
		$max = 'alt' === $what ? self::MAX_ALT : self::MAX_TITLE;
		if ( ! is_string( $text ) || mb_strlen( $text ) > $max ) {
			return sprintf( '%1$s is plain text of at most %2$d characters.', $what, $max );
		}
		if ( sanitize_text_field( $text ) !== $text || preg_match( '/[<>&"]/', $text ) ) {
			return sprintf( '%s is plain text: no HTML, no line breaks, no < > & " characters, single spaces.', $what );
		}
		return '';
	}

	/**
	 * Downloads the image and adds it to the media library.
	 *
	 * @param array<string,mixed> $set {url, alt, title}.
	 * @return int Attachment id.
	 * @throws \RuntimeException When anything is not right (nothing stays behind).
	 */
	public static function sideload( array $set ): int {
		$url     = (string) $set['url'];
		$problem = self::url_problem( $url );
		if ( '' !== $problem ) {
			throw new \RuntimeException( esc_html( $problem ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new \RuntimeException( 'This user may not upload files.' );
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$tmp = wp_tempnam( 'addsynapse-assistant-image' );
		if ( ! $tmp ) {
			throw new \RuntimeException( 'No temporary file could be created.' );
		}
		try {
			// wp_safe_remote_get: WordPress refuses private / loopback addresses again, on every redirect too.
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 3,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => self::MAX_BYTES + 1,
					'headers'             => array( 'Accept' => 'image/jpeg, image/png, image/webp, image/gif' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( esc_html( 'The image could not be downloaded: ' . mb_substr( $response->get_error_message(), 0, 160 ) ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				throw new \RuntimeException( esc_html( sprintf( 'The image could not be downloaded (HTTP %d).', $code ) ) );
			}
			clearstatcache( true, $tmp );
			$size = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
			if ( $size < 1 ) {
				throw new \RuntimeException( 'The address gave an empty file.' );
			}
			if ( $size > self::MAX_BYTES ) {
				throw new \RuntimeException( 'The image is larger than 10 MB.' );
			}
			$mime = self::mime( $tmp );
			if ( '' === $mime ) {
				throw new \RuntimeException( 'The address does not give a JPG, PNG, WebP or GIF image.' );
			}
			$name = self::file_name( $url, $mime );
			$file = array(
				'name'     => $name,
				'tmp_name' => $tmp,
			);
			$id   = media_handle_sideload( $file, 0, (string) $set['title'] );
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( esc_html( 'WordPress did not accept the image: ' . mb_substr( $id->get_error_message(), 0, 160 ) ) );
			}
		} finally {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}//end try
		$id = (int) $id;
		update_post_meta( $id, self::SOURCE_META, $url );
		if ( '' !== (string) $set['alt'] ) {
			update_post_meta( $id, '_wp_attachment_image_alt', (string) $set['alt'] );
		}
		return $id;
	}

	/**
	 * Values of an image AddSynapse added ({url, alt, title}), or null.
	 *
	 * @param int $id Attachment id.
	 * @return array{url:string,alt:string,title:string}|null
	 */
	public static function values( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}
		$url = (string) get_post_meta( $id, self::SOURCE_META, true );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'url'   => $url,
			'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title' => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
		);
	}

	/**
	 * The real type of the downloaded bytes ('' = not an accepted image).
	 *
	 * @param string $path File.
	 */
	private static function mime( string $path ): string {
		$head  = (string) file_get_contents( $path, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary file.
		$magic = array(
			'image/jpeg' => 0 === strpos( $head, "\xFF\xD8\xFF" ),
			'image/png'  => 0 === strpos( $head, "\x89PNG\r\n\x1A\n" ),
			'image/webp' => 0 === strpos( $head, 'RIFF' ) && 'WEBP' === substr( $head, 8, 4 ),
			'image/gif'  => 0 === strpos( $head, 'GIF87a' ) || 0 === strpos( $head, 'GIF89a' ),
		);
		$type  = (string) array_search( true, $magic, true );
		if ( '' === $type ) {
			return '';
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid images are expected input.
		return is_array( $info ) && ( $info['mime'] ?? '' ) === $type && (int) $info[0] > 0 && (int) $info[1] > 0 ? $type : '';
	}

	/**
	 * A safe file name from the address, with the extension of the real type.
	 *
	 * @param string $url  Address.
	 * @param string $mime Type.
	 */
	private static function file_name( string $url, string $mime ): string {
		$base = (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME );
		$base = sanitize_file_name( mb_substr( rawurldecode( $base ), 0, 80 ) );
		$base = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $base ), '-' );
		return ( '' !== $base ? $base : 'image' ) . '.' . self::TYPES[ $mime ];
	}
}
