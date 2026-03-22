<?php
/**
 * CreateTranslationAjax — AJAX handler for creating a new translation.
 *
 * Registered action: wp_ajax_idiomatticwp_create_translation
 *
 * Expected POST parameters:
 *   nonce   — wp_nonce value for 'idiomatticwp_nonce'
 *   post_id — (int) source post ID
 *   lang    — (string) BCP-47 target language code (e.g. 'fr', 'pt-BR')
 *
 * On success, responds with:
 *   { success: true, data: { redirect_url: string } }
 *   The caller should redirect to redirect_url to open the Translation Editor.
 *
 * On failure, responds with:
 *   { success: false, data: { message: string } }
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Translation\CreateTranslation;
use IdiomatticWP\ValueObjects\LanguageCode;

class CreateTranslationAjax {

	public function __construct( private CreateTranslation $createTranslation ) {}

	/**
	 * Handle the wp_ajax_idiomatticwp_create_translation request.
	 *
	 * Validates the nonce, checks capability, runs CreateTranslation, and
	 * returns the URL of the newly created translation's editor page.
	 * Sends JSON and exits — never returns normally.
	 */
	public function handle(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		$postId = absint( $_POST['post_id'] ?? 0 );
		$lang   = sanitize_key( $_POST['lang'] ?? '' );

		if ( ! $postId || ! $lang ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'idiomattic-wp' ) ] );
		}

		try {
			$result = ( $this->createTranslation )( $postId, LanguageCode::from( $lang ) );

			// Point the caller at the Translation Editor (not the standard Gutenberg editor).
			// $result contains 'translation_id' and 'translated_post_id' from CreateTranslation.
			$editorUrl = add_query_arg(
				[
					'post'   => $result['translated_post_id'],
					'action' => 'idiomatticwp_translate',
				],
				admin_url( 'post.php' )
			);

			wp_send_json_success( [ 'redirect_url' => $editorUrl ] );

		} catch ( \Throwable $e ) {
			// Covers InvalidLanguageCodeException, TranslationAlreadyExistsException,
			// TranslationCreationException, and any unexpected errors.
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}
