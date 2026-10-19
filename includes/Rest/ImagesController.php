<?php
/**
 * REST: images uploaded in the chat (POST /images, DELETE /images/{id}).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Rest;

use Addsynapse\StoreAssistant\Media\ImageUploads;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Behind RestRoutes::permission (logged in + wp_rest nonce + manage_woocommerce) AND
 * `upload_files` (permission()). A pending image belongs to its uploader: another user's id is a
 * 404, exactly like a missing one.
 */
final class ImagesController {

	/**
	 * Route permission: the plugin's checks + the WordPress capability to upload media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		$base = RestRoutes::permission( $request );
		if ( true !== $base ) {
			return $base;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to upload files.', 'addsynapse-store-assistant' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Stores an uploaded image (multipart field `file`) as a pending media-library attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = is_array( $files['file'] ?? null ) ? $files['file'] : null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			$code = (int) ( $file['error'] ?? 0 );
			if ( in_array( $code, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				return new WP_Error( 'addsynapse_assistant_image_size', __( 'The image is too large.', 'addsynapse-store-assistant' ), array( 'status' => 413 ) );
			}
			return new WP_Error( 'addsynapse_assistant_image_missing', __( 'No file was received.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		$tmp = (string) $file['tmp_name'];
		// Must be a real PHP upload. Only the PHPUnit suite can skip this check (Seams slot `any_upload`, read only while
		// ADDSYNAPSE_ASSISTANT_TESTING is true); there is no filter.
		if ( ! is_uploaded_file( $tmp ) && null === \Addsynapse\StoreAssistant\Seams::get( 'any_upload', 'array' ) ) {
			return new WP_Error( 'addsynapse_assistant_image_missing', __( 'No file was received.', 'addsynapse-store-assistant' ), array( 'status' => 400 ) );
		}
		$stored = ImageUploads::store( $tmp, (string) ( $file['name'] ?? '' ), get_current_user_id() );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		return new WP_REST_Response( array( 'image' => $stored ), 201 );
	}

	/**
	 * The chip's ×: deletes the user's pending image if no plan / nothing on the site uses it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request['attachment_id'];
		if ( ! ImageUploads::find_for_user( $id, get_current_user_id() ) ) {
			return self::not_found();
		}
		return new WP_REST_Response( array( 'deleted' => ImageUploads::delete_if_unused( $id ) ) );
	}

	/**
	 * 404 for missing / foreign / already used images.
	 */
	public static function not_found(): WP_Error {
		return new WP_Error( 'addsynapse_assistant_image_not_found', __( 'The image was not found (it may have been removed). Upload it again.', 'addsynapse-store-assistant' ), array( 'status' => 404 ) );
	}
}
