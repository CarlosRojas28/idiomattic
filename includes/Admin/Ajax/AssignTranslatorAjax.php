<?php
/**
 * AssignTranslatorAjax — AJAX handler to assign a translator to a translation post.
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

class AssignTranslatorAjax {

	public function handle(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if (
			! current_user_can( 'idiomatticwp_manage_translations' ) &&
			! current_user_can( 'manage_options' )
		) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		$postId = absint( $_POST['post_id'] ?? 0 );
		$userId = absint( $_POST['user_id'] ?? 0 );

		if ( ! $postId ) {
			wp_send_json_error( [ 'message' => __( 'Missing post ID.', 'idiomattic-wp' ) ] );
		}

		if ( $userId ) {
			update_post_meta( $postId, '_idiomatticwp_assigned_translator', $userId );
		} else {
			delete_post_meta( $postId, '_idiomatticwp_assigned_translator' );
		}

		wp_send_json_success( [ 'message' => __( 'Translator assigned.', 'idiomattic-wp' ) ] );
	}
}
