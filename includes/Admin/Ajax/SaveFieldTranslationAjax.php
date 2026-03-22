<?php
/**
 * SaveFieldTranslationAjax — persists a single translated field from the editor.
 *
 * Called by the Translation Editor JS when the user manually edits a field
 * and clicks Save, or when the AI translation result is accepted.
 *
 * POST params:
 *   nonce            — idiomatticwp_nonce
 *   translated_post_id — int  WP post ID of the translated post
 *   field            — string 'title' | 'content' | 'excerpt' | custom meta key
 *   value            — string  The translated value (HTML or plain text)
 *
 * On success the translated WP post is updated immediately so the change
 * is visible in the editor without a full page reload.
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Translation\FieldTranslator;

class SaveFieldTranslationAjax {

	public function __construct(
		private TranslationRepositoryInterface $repository,
		private FieldTranslator                $fieldTranslator,
	) {}

	// ── Entry point ───────────────────────────────────────────────────────

	public function handle(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		$translatedPostId = absint( $_POST['translated_post_id'] ?? 0 );
		$field            = sanitize_key( $_POST['field'] ?? '' );
		$rawValue         = wp_unslash( $_POST['value'] ?? '' );

		// Sanitize the value according to the field being saved and user capability.
		// Users with unfiltered_html (Admins on single-site) may store arbitrary HTML.
		// Everyone else goes through wp_kses_post() so scripts cannot be injected.
		$value = $this->sanitizeFieldValue( $field, $rawValue );

		if ( ! $translatedPostId || $field === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'idiomattic-wp' ) ] );
		}

		// Verify the post exists and the user can edit it
		$translatedPost = get_post( $translatedPostId );
		if ( ! $translatedPost instanceof \WP_Post ) {
			wp_send_json_error( [ 'message' => __( 'Post not found.', 'idiomattic-wp' ) ] );
		}

		if ( ! current_user_can( 'edit_post', $translatedPostId ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit this post.', 'idiomattic-wp' ) ] );
		}

		// Look up the translation record
		$record = $this->repository->findByTranslatedPost( $translatedPostId );
		if ( ! $record ) {
			wp_send_json_error( [ 'message' => __( 'Translation record not found.', 'idiomattic-wp' ) ] );
		}

		$translationId = (int) $record['id'];

		// ── Core post fields ──────────────────────────────────────────────

		$coreFieldMap = [
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		];

		if ( isset( $coreFieldMap[ $field ] ) ) {
			$this->saveCoreField( $translatedPostId, $coreFieldMap[ $field ], $value );
		} else {
			// ── Custom meta field ─────────────────────────────────────────
			$this->fieldTranslator->saveFieldTranslation( $translationId, $field, $value );
		}

		// ── Mark translation as draft if it was outdated ──────────────────
		// A manual save means the user is actively working on it.
		if ( $record['status'] === 'outdated' ) {
			$this->repository->updateStatus( $translationId, 'draft' );
		}

		wp_send_json_success( [
			'message' => __( 'Field saved.', 'idiomattic-wp' ),
			'field'   => $field,
		] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Sanitize a field value according to its type and the current user's capability.
	 *
	 * - title   → plain text (sanitize_text_field)
	 * - excerpt → plain text with newlines (sanitize_textarea_field)
	 * - content → HTML; wp_kses_post() for unprivileged users, raw for unfiltered_html
	 * - custom  → passed through as-is (stored in meta, not rendered directly)
	 *
	 * wp_kses_post() preserves HTML comments including Gutenberg block markers
	 * (<!-- wp:paragraph -->) so it is safe to apply even for block content.
	 *
	 * @param string $field    Field name: 'title' | 'content' | 'excerpt' | meta key.
	 * @param string $rawValue Unslashed raw value from $_POST.
	 * @return string Sanitized value.
	 */
	private function sanitizeFieldValue( string $field, string $rawValue ): string {
		return match ( $field ) {
			'title'   => sanitize_text_field( $rawValue ),
			'excerpt' => sanitize_textarea_field( $rawValue ),
			'content' => current_user_can( 'unfiltered_html' )
				? $rawValue
				: wp_kses_post( $rawValue ),
			default   => $rawValue, // Custom meta — validated/typed by caller context
		};
	}

	/**
	 * Update a core WP post field (title, content, excerpt).
	 * Uses a flag to suppress mark-as-outdated during save.
	 */
	private function saveCoreField( int $postId, string $wpField, string $value ): void {
		$updateData = [
			'ID'     => $postId,
			$wpField => $value,
		];

		// Use a flag to signal to PostTranslationHooks that this update
		// should not trigger mark-as-outdated for translated posts.
		add_filter( 'idiomatticwp_skip_outdated_on_update', '__return_true' );

		$result = wp_update_post( $updateData, true );

		remove_filter( 'idiomatticwp_skip_outdated_on_update', '__return_true' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
	}
}
