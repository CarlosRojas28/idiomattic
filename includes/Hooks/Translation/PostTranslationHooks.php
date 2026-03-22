<?php
/**
 * PostTranslationHooks — wires post translation lifecycle events.
 *
 * This class handles marking translations as outdated when the source changes,
 * cleaning up relationship records when posts are deleted, and providing
 * warnings before deleting source posts.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Translation\CreateTranslation;
use IdiomatticWP\Translation\MarkAsOutdated;

class PostTranslationHooks implements HookRegistrarInterface {

	public function __construct(
		private CreateTranslation              $createTranslation,
		private MarkAsOutdated                 $markAsOutdated,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Mark translations outdated when source post changes
		add_action( 'post_updated', [ $this, 'onPostUpdated' ], 10, 3 );

		// Sync translation status when a translated post is published/unpublished
		add_action( 'transition_post_status', [ $this, 'onPostStatusTransition' ], 10, 3 );

		// Warn before deleting source posts that have translations
		add_action( 'before_delete_post', [ $this, 'onBeforeDeletePost' ] );

		// When a translated post is deleted, clean up the relationship record
		add_action( 'deleted_post', [ $this, 'onDeletedPost' ] );

		// Schedule an admin notice after a source post with translations was deleted
		add_action( 'idiomatticwp_deleting_source_post', [ $this, 'scheduleDeleteNotice' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'maybeShowDeleteNotice' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Sync the translation record status when a translated post changes wp status.
	 *
	 * Mapping:
	 *   publish              → 'complete'
	 *   draft / pending      → 'draft'
	 *   trash                → 'draft'  (soft, not deleted — onDeletedPost handles hard delete)
	 */
	public function onPostStatusTransition( string $new, string $old, \WP_Post $post ): void {
		// Only care about status changes (not same-status saves)
		if ( $new === $old ) {
			return;
		}

		// Check if this post is a translated post in our table
		$record = $this->repository->findByTranslatedPost( $post->ID );
		if ( ! $record ) {
			return;
		}

		// Map WordPress post_status to our translation status
		$translationStatus = match ( $new ) {
			'publish'          => 'complete',
			'draft', 'pending' => 'draft',
			'future'           => 'draft',
			default            => null, // trash, auto-draft, etc. — don't touch
		};

		if ( $translationStatus === null ) {
			return;
		}

		// Never overwrite 'outdated' with 'complete' via a plain Gutenberg save —
		// only the Translation Editor can resolve an outdated status after re-translation.
		if ( $translationStatus === 'complete' && $record['status'] === 'outdated' ) {
			return;
		}

		$this->repository->updateStatus( (int) $record['id'], $translationStatus );
	}

	/**
	 * Mark translations as outdated when the source post is updated.
	 * Skipped when idiomatticwp_skip_outdated_on_update filter returns true
	 * (e.g. when SaveFieldTranslationAjax updates a translated post field).
	 */
	public function onPostUpdated( int $postId, \WP_Post $newPost, \WP_Post $oldPost ): void {
		if ( apply_filters( 'idiomatticwp_skip_outdated_on_update', false ) ) {
			return;
		}
		( $this->markAsOutdated )( $postId, $newPost, $oldPost );
	}

	/**
	 * Check if this post is a source post for translations.
	 * Fires idiomatticwp_deleting_source_post action when it is.
	 */
	public function onBeforeDeletePost( int $postId ): void {
		$translations = $this->repository->findAllForSource( $postId );

		if ( empty( $translations ) ) {
			return;
		}

		do_action( 'idiomatticwp_deleting_source_post', $postId, $translations );

		// Delete all translated posts so they don't remain as orphaned drafts.
		foreach ( $translations as $translation ) {
			$translatedPostId = (int) ( $translation['translated_post_id'] ?? 0 );
			if ( $translatedPostId > 0 ) {
				wp_delete_post( $translatedPostId, true );
			}
		}
	}

	/**
	 * When a post is deleted, check if it was a translation and clean up the record.
	 */
	public function onDeletedPost( int $postId ): void {
		$record = $this->repository->findByTranslatedPost( $postId );

		if ( $record ) {
			$this->repository->delete( (int) $record['id'] );
		}
	}

	/**
	 * Store a transient so an admin notice can be shown on the next page load.
	 *
	 * Called via the 'idiomatticwp_deleting_source_post' action.
	 * We can't show a notice directly here because WordPress's post deletion
	 * redirects after before_delete_post fires.
	 *
	 * @param int   $postId       The source post ID being deleted.
	 * @param array $translations All translation records for this post.
	 */
	public function scheduleDeleteNotice( int $postId, array $translations ): void {
		$userId = get_current_user_id();
		if ( ! $userId ) {
			return;
		}

		$langs = array_column( $translations, 'target_lang' );
		set_transient(
			'idiomatticwp_delete_notice_' . $userId,
			[
				'post_id'    => $postId,
				'lang_count' => count( $langs ),
				'langs'      => $langs,
				'deleted'    => true,
			],
			60
		);
	}

	/**
	 * Show a dismissible admin notice if a source post with translations was just deleted.
	 */
	public function maybeShowDeleteNotice(): void {
		$userId = get_current_user_id();
		if ( ! $userId ) {
			return;
		}

		$key  = 'idiomatticwp_delete_notice_' . $userId;
		$data = get_transient( $key );
		if ( ! $data ) {
			return;
		}
		delete_transient( $key );

		$count = (int) $data['lang_count'];
		$langs = implode( ', ', array_map( 'strtoupper', (array) $data['langs'] ) );

		printf(
			'<div class="notice notice-info is-dismissible"><p>'
			. '<strong>%s</strong> — %s</p></div>',
			esc_html__( 'Idiomattic WP', 'idiomattic-wp' ),
			esc_html(
				sprintf(
					/* translators: %1$d = number of translations, %2$s = language codes */
					_n(
						'The deleted post had %1$d translation (%2$s). The translated post was also deleted.',
						'The deleted post had %1$d translations (%2$s). The translated posts were also deleted.',
						$count,
						'idiomattic-wp'
					),
					$count,
					$langs
				)
			)
		);
	}
}
