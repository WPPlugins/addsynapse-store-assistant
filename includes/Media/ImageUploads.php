<?php
/**
 * Images uploaded in the chat (📎 / drag & drop): validation, re-encoding into the media
 * library, the «pending» lifecycle and its cleanup.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Media;

use Addsynapse\StoreAssistant\Config;
use Addsynapse\StoreAssistant\Install\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Security model (docs/03-ασφάλεια.md → «Εικόνες από το chat»):
 *  - Only JPEG / PNG / WebP (no SVG, GIF, HEIC): image extension AND real content type
 *    (`wp_check_filetype_and_ext`, which corrects jpg↔png↔webp) AND `getimagesize()` AND magic
 *    bytes must agree; at most
 *    Config::image_max_bytes(), Config::image_max_side() and Config::image_max_pixels() (read from
 *    the header before anything is decoded).
 *  - The bytes are NEVER stored as received: the image is decoded and re-encoded with the GD editor
 *    (pixels only → EXIF/GPS/XMP/IPTC/comments and anything appended to the file are gone; EXIF
 *    orientation is applied first), scaled to at most MAX_STORED_SIDE, saved under a random name
 *    `addsynapse-assistant-<16 hex>.<ext>` and inserted into the media library (uploader = the current user).
 *  - A new image is «pending» (meta PENDING_META: uploader, conversation, time). It becomes «used»
 *    when a changeset that references it is APPLIED and the site really uses it. Pending images are
 *    deleted completely (file + every size) by the cleanup job when nobody uses them: after
 *    Config::image_pending_hours(), or Config::image_release_minutes() after their plan was
 *    rejected / undone — never while a live draft references them, never when referenced
 *    anywhere (ImageUsage).
 *  - Another manager's pending image is invisible to plans and find-media («not in the library»).
 *  - The model sees only name/size/dimensions — the pixels only with the «AI sees images» setting.
 */
final class ImageUploads {

	public const BLOCK             = 'image_upload';
	public const PENDING_META      = '_addsynapse_assistant_pending';
	public const UPLOADED_META     = '_addsynapse_assistant_uploaded';
	public const CONVERSATION_META = '_addsynapse_assistant_conversation';
	public const CHANGESET_META    = '_addsynapse_assistant_changeset';
	public const CLEANUP_HOOK      = 'addsynapse_assistant_images_cleanup';
	public const GROUP             = 'addsynapse-assistant';
	public const VISION_OPTION     = 'addsynapse_assistant_image_vision';
	public const MAX_STORED_SIDE   = 2560;
	public const VISION_SIDE       = 1024;

	/**
	 * Accepted types: extension regex => mime (WordPress `mimes` format).
	 */
	public const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/**
	 * Hooks: cleanup job + changeset lifecycle.
	 */
	public static function register_hooks(): void {
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		add_action( 'init', array( self::class, 'schedule' ), 20 );
		add_action( 'addsynapse_assistant_changeset_created', array( self::class, 'on_created' ), 10, 3 );
		add_action( 'addsynapse_assistant_changeset_applied', array( self::class, 'on_applied' ), 10, 1 );
		add_action( 'addsynapse_assistant_changeset_rejected', array( self::class, 'on_released' ), 10, 1 );
		add_action( 'addsynapse_assistant_changeset_reverted', array( self::class, 'on_reverted' ), 10, 1 );
	}

	/**
	 * Schedules the hourly cleanup once.
	 */
	public static function schedule(): void {
		// Nothing is scheduled before the site is connected (Guideline 7, Install\Lifecycle).
		if ( ! \Addsynapse\StoreAssistant\Install\Lifecycle::ready_now() ) {
			return;
		}
		\Addsynapse\StoreAssistant\Jobs::recurring( self::CLEANUP_HOOK, HOUR_IN_SECONDS, self::GROUP );
	}

	/**
	 * «The AI sees the images you upload»: ON unless a manager turned it off (owner decision 19/10/2026). An image is
	 * sent only when the manager attaches it, only to the chosen AI provider. An explicitly saved value always wins.
	 */
	public static function vision_enabled(): bool {
		return '1' === (string) get_option( self::VISION_OPTION, '1' );
	}

	/**
	 * Validates, re-encodes and stores an uploaded image as a pending media-library attachment.
	 *
	 * @param string $tmp      Temporary file (already checked to be a PHP upload).
	 * @param string $filename Name the browser sent (a label only).
	 * @param int    $user_id  Uploader.
	 * @return array<string,mixed>|WP_Error Summary (see summary()).
	 */
	public static function store( string $tmp, string $filename, int $user_id ) {
		if ( $user_id < 1 || ! user_can( $user_id, 'upload_files' ) ) {
			return self::error( 'addsynapse_assistant_image_forbidden', __( 'You are not allowed to upload files.', 'addsynapse-store-assistant' ), 403 );
		}
		$type_error = self::error( 'addsynapse_assistant_image_type', __( 'Only JPG, PNG or WebP images can be uploaded.', 'addsynapse-store-assistant' ), 415 );
		$name       = self::clean_name( $filename );
		$ext        = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$raw        = wp_basename( str_replace( '\\', '/', $filename ) );
		if ( '' === $name || ! preg_match( '/^(jpe?g|jpe|png|webp)$/', $ext ) || preg_match( '/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|exe|js|html?|svgz?)_?\./i', $name . ' ' . $raw ) ) {
			return $type_error;
		}
		$size = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( $size < 1 ) {
			return self::error( 'addsynapse_assistant_image_empty', __( 'The file is empty.', 'addsynapse-store-assistant' ), 422 );
		}
		if ( $size > Config::image_max_bytes() ) {
			/* translators: %s: size like 8 MB */
			return self::error( 'addsynapse_assistant_image_size', sprintf( __( 'The image is larger than %s.', 'addsynapse-store-assistant' ), size_format( Config::image_max_bytes() ) ), 413 );
		}
		$mime = self::detect_mime( $tmp, $name );
		if ( '' === $mime ) {
			return $type_error;
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid images are expected input.
		if ( ! is_array( $info ) || ( $info['mime'] ?? '' ) !== $mime ) {
			return $type_error;
		}
		$width  = (int) $info[0];
		$height = (int) $info[1];
		if ( $width < 1 || $height < 1 ) {
			return $type_error;
		}
		if ( max( $width, $height ) > Config::image_max_side() || $width * $height > Config::image_max_pixels() ) {
			return self::error(
				'addsynapse_assistant_image_dimensions',
				/* translators: 1: max side in px, 2: max megapixels */
				sprintf( __( 'The image is too large (max %1$d px per side, %2$d megapixels).', 'addsynapse-store-assistant' ), Config::image_max_side(), (int) floor( Config::image_max_pixels() / 1000000 ) ),
				413
			);
		}

		$stored = self::reencode( $tmp, $mime, $width, $height );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$title = trim( (string) preg_replace( '/[\x00-\x1F\x7F<>]+/u', ' ', (string) pathinfo( $name, PATHINFO_FILENAME ) ) );
		$id    = wp_insert_attachment(
			array(
				'post_mime_type' => $stored['mime'],
				'post_title'     => '' !== $title ? mb_substr( sanitize_text_field( $title ), 0, 80 ) : 'image',
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
			),
			$stored['path'],
			0,
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_delete_file( $stored['path'] );
			return self::error( 'addsynapse_assistant_db', __( 'Could not store the image.', 'addsynapse-store-assistant' ), 500 );
		}
		$id = (int) $id;
		update_post_meta( $id, self::UPLOADED_META, time() );
		update_post_meta(
			$id,
			self::PENDING_META,
			array(
				'user_id'    => $user_id,
				'created_at' => time(),
				'name'       => $name,
			)
		);
		$meta = wp_generate_attachment_metadata( $id, $stored['path'] );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $id, $meta );
		}
		// Not connected (slash commands work offline — docs/14 W-1): the recurring cleanup is not scheduled, so this
		// upload gets one local cleanup run when it would expire (nothing leaves the site).
		if ( ! \Addsynapse\StoreAssistant\Install\Lifecycle::ready_now() ) {
			\Addsynapse\StoreAssistant\Jobs::single( time() + Config::image_pending_hours() * HOUR_IN_SECONDS + 60, self::CLEANUP_HOOK, self::GROUP );
		}
		return self::summary( $id );
	}

	/**
	 * The real content type of an accepted image ('' = refused). Extension and bytes must agree.
	 *
	 * @param string $path File.
	 * @param string $name Cleaned name (for the extension).
	 */
	private static function detect_mime( string $path, string $name ): string {
		$check = wp_check_filetype_and_ext( $path, $name, self::MIMES );
		$type  = (string) ( $check['type'] ?? '' );
		if ( '' === $type || empty( $check['ext'] ) || ! in_array( $type, self::MIMES, true ) ) {
			return '';
		}
		$head  = (string) file_get_contents( $path, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$magic = array(
			'image/jpeg' => 0 === strpos( $head, "\xFF\xD8\xFF" ),
			'image/png'  => 0 === strpos( $head, "\x89PNG\r\n\x1A\n" ),
			'image/webp' => 0 === strpos( $head, 'RIFF' ) && 'WEBP' === substr( $head, 8, 4 ),
		);
		return ! empty( $magic[ $type ] ) ? $type : '';
	}

	/**
	 * Decodes and re-encodes with GD (pixels only → no metadata survives), EXIF orientation applied,
	 * scaled to MAX_STORED_SIDE, into the uploads folder under a random name.
	 *
	 * @param string $tmp    Source.
	 * @param string $mime   Detected type.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @return array{path:string,mime:string}|WP_Error
	 */
	private static function reencode( string $tmp, string $mime, int $width, int $height ) {
		$fail = self::error( 'addsynapse_assistant_image_process', __( 'The image could not be processed. Try another JPG or PNG file.', 'addsynapse-store-assistant' ), 422 );
		if ( ! class_exists( 'WP_Image_Editor_GD' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
			require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		}
		// GD on purpose: Imagick keeps EXIF (with GPS) even through WordPress' strip_meta().
		$only_gd = static function () {
			return array( 'WP_Image_Editor_GD' );
		};
		add_filter( 'wp_image_editors', $only_gd, PHP_INT_MAX );
		wp_raise_memory_limit( 'image' );
		$editor = wp_get_image_editor( $tmp, array( 'mime_type' => $mime ) );
		remove_filter( 'wp_image_editors', $only_gd, PHP_INT_MAX );
		if ( is_wp_error( $editor ) || ! $editor instanceof \WP_Image_Editor_GD ) {
			return $fail;
		}
		if ( 'image/jpeg' === $mime ) {
			$editor->maybe_exif_rotate();
		}
		if ( max( $width, $height ) > self::MAX_STORED_SIDE ) {
			$resized = $editor->resize( self::MAX_STORED_SIDE, self::MAX_STORED_SIDE, false );
			if ( is_wp_error( $resized ) ) {
				return $fail;
			}
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['path'] ) ) {
			return self::error( 'addsynapse_assistant_image_storage', __( 'The media library folder is not writable.', 'addsynapse-store-assistant' ), 500 );
		}
		$ext   = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		)[ $mime ];
		$file  = wp_unique_filename( (string) $uploads['path'], 'addsynapse-assistant-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext );
		$saved = $editor->save( trailingslashit( (string) $uploads['path'] ) . $file, $mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( (string) $saved['path'] ) ) {
			return $fail;
		}
		$check = @getimagesize( (string) $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $check ) || ( $check['mime'] ?? '' ) !== $mime ) {
			wp_delete_file( (string) $saved['path'] );
			return $fail;
		}
		return array(
			'path' => (string) $saved['path'],
			'mime' => $mime,
		);
	}

	/**
	 * The chip / message / AI summary of an uploaded image.
	 *
	 * @param int $id Attachment id.
	 * @return array<string,mixed>
	 */
	public static function summary( int $id ): array {
		$meta    = wp_get_attachment_metadata( $id );
		$pending = self::pending( $id );
		$file    = (string) get_attached_file( $id );
		$thumb   = wp_get_attachment_image_url( $id, 'thumbnail' );
		return array(
			'attachment_id' => $id,
			'name'          => (string) ( $pending['name'] ?? wp_basename( $file ) ),
			'width'         => (int) ( is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0 ),
			'height'        => (int) ( is_array( $meta ) ? ( $meta['height'] ?? 0 ) : 0 ),
			'size'          => is_file( $file ) ? (int) filesize( $file ) : 0,
			'mime'          => (string) get_post_mime_type( $id ),
			'thumb'         => $thumb ? (string) $thumb : '',
			'pending'       => null !== $pending,
		);
	}

	/**
	 * Pending state of an attachment (null = not pending).
	 *
	 * @param int $id Attachment id.
	 * @return array<string,mixed>|null
	 */
	public static function pending( int $id ): ?array {
		$value = $id > 0 ? get_post_meta( $id, self::PENDING_META, true ) : '';
		return is_array( $value ) && ! empty( $value['user_id'] ) ? $value : null;
	}

	/**
	 * A pending image the user uploaded (null = missing, not pending, or someone else's).
	 *
	 * @param int $id      Attachment id.
	 * @param int $user_id User.
	 * @return array<string,mixed>|null Summary.
	 */
	public static function find_for_user( int $id, int $user_id ): ?array {
		$post    = $id > 0 ? get_post( $id ) : null;
		$pending = self::pending( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! $pending || (int) $pending['user_id'] !== $user_id ) {
			return null;
		}
		return self::summary( $id );
	}

	/**
	 * Why an attachment may not be used by the current user's plan ('' = it may): another
	 * manager's pending upload is treated like a missing image.
	 *
	 * @param int $id Attachment id.
	 */
	public static function usable_problem( int $id ): string {
		$pending = self::pending( $id );
		if ( $pending && get_current_user_id() !== (int) $pending['user_id'] ) {
			return sprintf( 'Image %d is not in the media library.', $id );
		}
		return '';
	}

	/**
	 * Links the image to the conversation it was sent in.
	 *
	 * @param int $id              Attachment id.
	 * @param int $conversation_id Conversation.
	 */
	public static function attach( int $id, int $conversation_id ): void {
		$pending = self::pending( $id );
		if ( $pending ) {
			$pending['conversation_id'] = $conversation_id;
			update_post_meta( $id, self::PENDING_META, $pending );
		}
		update_post_meta( $id, self::CONVERSATION_META, $conversation_id );
	}

	/**
	 * Newest image the user uploaded in a conversation that still exists (0 = none).
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $user_id         User.
	 */
	public static function latest_in_conversation( int $conversation_id, int $user_id ): int {
		if ( $conversation_id < 1 || $user_id < 1 ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE m.meta_value = %s AND p.post_type = 'attachment' AND p.post_author = %d ORDER BY p.ID DESC LIMIT 1", self::CONVERSATION_META, (string) $conversation_id, $user_id ) );
		return (int) $id;
	}

	/**
	 * The message block (after the text block; content[0] stays the text for commands).
	 *
	 * @param array<string,mixed> $summary summary().
	 * @return array<string,mixed>
	 */
	public static function block( array $summary ): array {
		return array(
			'type'          => self::BLOCK,
			'attachment_id' => (int) $summary['attachment_id'],
			'name'          => (string) $summary['name'],
			'width'         => (int) $summary['width'],
			'height'        => (int) $summary['height'],
			'size'          => (int) $summary['size'],
			'mime'          => (string) $summary['mime'],
		);
	}

	/**
	 * The image block of a stored message ([] = none).
	 *
	 * @param array<int,mixed> $blocks Content blocks.
	 * @return array<string,mixed>
	 */
	public static function from_blocks( array $blocks ): array {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && self::BLOCK === ( $block['type'] ?? '' ) && (int) ( $block['attachment_id'] ?? 0 ) > 0 ) {
				return $block;
			}
		}
		return array();
	}

	/**
	 * A copy of the image at most VISION_SIDE px (the model's view), base64. Null on failure.
	 *
	 * @param int $id Attachment id.
	 * @return array{media_type:string,data:string}|null
	 */
	public static function vision_copy( int $id ): ?array {
		$file = (string) get_attached_file( $id );
		if ( '' === $file || ! is_file( $file ) ) {
			return null;
		}
		$mime   = (string) get_post_mime_type( $id );
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		$size = $editor->get_size();
		if ( max( (int) $size['width'], (int) $size['height'] ) > self::VISION_SIDE && is_wp_error( $editor->resize( self::VISION_SIDE, self::VISION_SIDE, false ) ) ) {
			return null;
		}
		// JPEG stays JPEG; PNG/WebP (maybe transparent) → PNG unless WebP is supported.
		$target = 'image/jpeg' === $mime ? 'image/jpeg' : ( $editor::supports_mime_type( 'image/webp' ) ? 'image/webp' : 'image/png' );
		$tmp    = trailingslashit( get_temp_dir() ) . 'addsynapse-assistant-vision-' . wp_generate_password( 12, false ) . '.' . ( 'image/jpeg' === $target ? 'jpg' : substr( $target, 6 ) );
		$saved  = $editor->save( $tmp, $target );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( (string) $saved['path'] ) ) {
			return null;
		}
		$bytes = (string) file_get_contents( (string) $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( (string) $saved['path'] );
		if ( '' === $bytes || strlen( $bytes ) > 1400000 ) {
			return null;
		}
		return array(
			'media_type' => $target,
			'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image content block for the model.
		);
	}

	/* ---------- Lifecycle ---------- */

	/**
	 * A new draft: links the pending images it references (params / item values).
	 *
	 * @param int                            $changeset_id Changeset.
	 * @param array<string,mixed>            $params       Plan params.
	 * @param array<int,array<string,mixed>> $items        Items.
	 */
	public static function on_created( $changeset_id, $params = array(), $items = array() ): void {
		$ints = array();
		self::collect_ints( $params, $ints );
		foreach ( (array) $items as $item ) {
			self::collect_ints( is_array( $item ) ? ( $item['after'] ?? null ) : null, $ints );
		}
		foreach ( array_keys( $ints ) as $id ) {
			if ( self::pending( (int) $id ) && 'attachment' === get_post_type( (int) $id ) ) {
				add_post_meta( (int) $id, self::CHANGESET_META, (int) $changeset_id );
			}
		}
	}

	/**
	 * Positive integers anywhere in a value (ids of images in params / values), except raw content.
	 *
	 * @param mixed           $value Value.
	 * @param array<int,bool> $out   Collected ids.
	 * @param int             $depth Recursion guard.
	 */
	private static function collect_ints( $value, array &$out, int $depth = 0 ): void {
		if ( $depth > 6 || count( $out ) > 2000 ) {
			return;
		}
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d{1,18}$/', $value ) ) ) {
			if ( (int) $value > 0 ) {
				$out[ (int) $value ] = true;
			}
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( 'raw' === $key ) {
					continue;
				}
				self::collect_ints( $child, $out, $depth + 1 );
			}
		}
	}

	/**
	 * Images linked to a changeset.
	 *
	 * @param int $changeset_id Changeset.
	 * @return array<int,int>
	 */
	public static function linked( int $changeset_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::CHANGESET_META, (string) $changeset_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Applied: a linked pending image the site now really uses becomes «used».
	 *
	 * @param int $changeset_id Changeset.
	 */
	public static function on_applied( $changeset_id ): void {
		foreach ( self::linked( (int) $changeset_id ) as $id ) {
			if ( self::pending( $id ) && ImageUsage::referenced( $id ) ) {
				delete_post_meta( $id, self::PENDING_META );
				update_post_meta( $id, '_addsynapse_assistant_used_at', time() );
			}
		}
	}

	/**
	 * Rejected: its pending images are released (deleted after the grace period if unused).
	 *
	 * @param int $changeset_id Changeset.
	 */
	public static function on_released( $changeset_id ): void {
		foreach ( self::linked( (int) $changeset_id ) as $id ) {
			$pending = self::pending( $id );
			if ( $pending ) {
				$pending['released_at'] = time();
				update_post_meta( $id, self::PENDING_META, $pending );
				self::schedule_cleanup();
			}
		}
	}

	/**
	 * Undone: a fresh upload it had put to use becomes pending again when nothing uses it any
	 * more (the library keeps it until the cleanup; used elsewhere → it stays «used»).
	 *
	 * @param int $changeset_id Changeset.
	 */
	public static function on_reverted( $changeset_id ): void {
		foreach ( self::linked( (int) $changeset_id ) as $id ) {
			if ( ! get_post_meta( $id, self::UPLOADED_META, true ) || ImageUsage::referenced( $id ) ) {
				continue;
			}
			$pending = self::pending( $id );
			if ( ! $pending ) {
				$post    = get_post( $id );
				$pending = array(
					'user_id'    => $post ? (int) $post->post_author : 0,
					'created_at' => time(),
					'name'       => wp_basename( (string) get_attached_file( $id ) ),
				);
			}
			if ( (int) $pending['user_id'] < 1 ) {
				continue;
			}
			$pending['released_at'] = time();
			update_post_meta( $id, self::PENDING_META, $pending );
			delete_post_meta( $id, '_addsynapse_assistant_used_at' );
			self::schedule_cleanup();
		}//end foreach
	}

	/**
	 * One cleanup run right after the grace period.
	 */
	private static function schedule_cleanup(): void {
		\Addsynapse\StoreAssistant\Jobs::single( time() + Config::image_release_minutes() * MINUTE_IN_SECONDS + 30, self::CLEANUP_HOOK, self::GROUP );
	}

	/**
	 * Whether a draft (not expired) / an applying or reverting changeset references the image.
	 *
	 * @param int $id Attachment id.
	 */
	public static function in_live_changeset( int $id ): bool {
		$linked = array_map( 'intval', (array) get_post_meta( $id, self::CHANGESET_META, false ) );
		if ( ! $linked ) {
			return false;
		}
		global $wpdb;
		$in  = implode( ', ', array_fill( 0, count( $linked ), '%d' ) );
		$sql = "SELECT COUNT(*) FROM %i WHERE id IN ( {$in} ) AND ( status IN ( 'applying', 'reverting' ) OR ( status = 'draft' AND expires_at > %s ) )";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $in holds only placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( Schema::table( 'changesets' ) ), $linked, array( gmdate( 'Y-m-d H:i:s' ) ) ) ) ) > 0;
	}

	/**
	 * Deletes pending images nobody uses: older than the pending window, or released (plan
	 * rejected / undone) longer than the grace period. Never while a live plan references them,
	 * never when the site references them.
	 *
	 * @param int|null $now Time (tests / E2E).
	 * @return int Deleted attachments.
	 */
	public static function cleanup( $now = null ): int {
		$now = is_numeric( $now ) ? (int) $now : time();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id LIMIT 500", self::PENDING_META ) );
		$deleted = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$pending = self::pending( $id );
			if ( ! $pending || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$expired  = $now - (int) ( $pending['created_at'] ?? 0 ) >= Config::image_pending_hours() * HOUR_IN_SECONDS;
			$released = ! empty( $pending['released_at'] ) && $now - (int) $pending['released_at'] >= Config::image_release_minutes() * MINUTE_IN_SECONDS;
			if ( ( $expired || $released ) && self::delete_if_unused( $id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Deletes a pending image completely (file + sizes) when no live plan and nothing on the site
	 * uses it.
	 *
	 * @param int $id Attachment id.
	 */
	public static function delete_if_unused( int $id ): bool {
		if ( ! self::pending( $id ) || self::in_live_changeset( $id ) || ImageUsage::referenced( $id ) ) {
			return false;
		}
		return (bool) wp_delete_attachment( $id, true );
	}

	/**
	 * Safe display name of the browser's file name.
	 *
	 * @param string $filename Name.
	 */
	public static function clean_name( string $filename ): string {
		$name = sanitize_file_name( wp_basename( str_replace( '\\', '/', $filename ) ) );
		return mb_substr( $name, 0, 120 );
	}

	/**
	 * WP_Error with an HTTP status.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  Status.
	 */
	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
