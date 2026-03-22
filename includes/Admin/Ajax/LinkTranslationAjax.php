<?php
/**
 * LinkTranslationAjax — search posts and link an existing post as a translation.
 *
 * Handles two actions:
 *   idiomatticwp_search_posts        — full-text title search, returns JSON list.
 *   idiomatticwp_link_translation    — saves a translation relationship.
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\TranslationAlreadyExistsException;
use IdiomatticWP\ValueObjects\LanguageCode;

class LinkTranslationAjax {

	public function __construct(
		private TranslationRepositoryInterface $repository,
		private LanguageManager $languageManager,
	) {}

	// ── Post search ───────────────────────────────────────────────────────

	public function handleSearch(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		$search    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$excludeId = absint( $_POST['exclude'] ?? 0 );

		$posts = get_posts( [
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			's'              => $search,
			'posts_per_page' => 10,
			'exclude'        => $excludeId ? [ $excludeId ] : [],
		] );

		$items = array_map(
			fn( \WP_Post $p ) => [
				'id'    => $p->ID,
				'title' => $p->post_title ?: '(' . $p->post_type . ' #' . $p->ID . ')',
				'type'  => $p->post_type,
			],
			$posts
		);

		wp_send_json_success( $items );
	}

	// ── Link translation ──────────────────────────────────────────────────

	public function handleLink(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		$sourceId   = absint( $_POST['source_id'] ?? 0 );
		$targetId   = absint( $_POST['target_id'] ?? 0 );
		$langString = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );

		if ( ! $sourceId || ! $targetId || $langString === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing required parameters.', 'idiomattic-wp' ) ] );
		}

		try {
			$lang = LanguageCode::from( $langString );
		} catch ( \Throwable ) {
			wp_send_json_error( [ 'message' => __( 'Invalid language code.', 'idiomattic-wp' ) ] );
		}

		if ( $this->repository->existsForSourceAndLang( $sourceId, $lang ) ) {
			wp_send_json_error( [ 'message' => __( 'A translation already exists for this language. Delete it first.', 'idiomattic-wp' ) ] );
		}

		$sourceLang    = (string) $this->languageManager->getDefaultLanguage();
		$translationId = $this->repository->save( [
			'source_post_id'     => $sourceId,
			'translated_post_id' => $targetId,
			'source_lang'        => $sourceLang,
			'target_lang'        => $langString,
			'status'             => 'draft',
			'translation_mode'   => 'editor',
			'created_at'         => current_time( 'mysql', true ),
		] );

		$editUrl = add_query_arg(
			[ 'post' => $targetId, 'action' => 'idiomatticwp_translate' ],
			admin_url( 'post.php' )
		);

		wp_send_json_success( [
			'translation_id' => $translationId,
			'edit_url'       => $editUrl,
		] );
	}
}
