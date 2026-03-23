<?php
/**
 * FieldSyncHooks — propagates synchronized custom fields across all
 * language variants of a post automatically on save.
 *
 * When a post meta key is registered with 'sync' => true the value
 * written to any language variant (source or translated) is mirrored
 * to every sibling translation without triggering a recursive loop.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\CustomElementRegistry;

class FieldSyncHooks implements HookRegistrarInterface {

	public function __construct(
		private TranslationRepositoryInterface $repository,
		private CustomElementRegistry $registry,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Hook both updated_post_meta and added_post_meta so the sync fires
		// whether the meta row already exists or is being created for the first time.
		add_action( 'updated_post_meta', [ $this, 'maybeSyncMeta' ], 10, 4 );
		add_action( 'added_post_meta',   [ $this, 'maybeSyncMeta' ], 10, 4 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Called whenever a post meta value is added or updated.
	 *
	 * @param int    $metaId    The ID of the meta row (unused but required by hook signature).
	 * @param int    $postId    The post whose meta changed.
	 * @param string $metaKey   The meta key.
	 * @param mixed  $metaValue The new value.
	 */
	public function maybeSyncMeta( int $metaId, int $postId, string $metaKey, mixed $metaValue ): void {
		// Guard: skip if this update was triggered by the sync itself to
		// prevent infinite recursion.
		if ( defined( 'IDIOMATTICWP_SYNCING_META' ) ) {
			return;
		}

		if ( ! $this->isFieldSynced( $metaKey, $postId ) ) {
			return;
		}

		$this->syncToAllTranslations( $postId, $metaKey, $metaValue );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Determine whether the given meta key is marked as synchronized.
	 *
	 * Checks global ('*') fields first, then post-type-specific fields.
	 */
	private function isFieldSynced( string $metaKey, int $postId ): bool {
		// Check fields registered for all post types ('*').
		foreach ( $this->registry->getPostFields( '*' ) as $field ) {
			if ( $field['key'] === $metaKey && ! empty( $field['sync'] ) ) {
				return true;
			}
		}

		// Check post-type-specific fields.
		$post = get_post( $postId );
		if ( $post ) {
			foreach ( $this->registry->getPostFields( $post->post_type ) as $field ) {
				if ( $field['key'] === $metaKey && ! empty( $field['sync'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Write $metaValue to every translation sibling of $postId.
	 *
	 * Works whether $postId is the source post or one of its translations.
	 */
	private function syncToAllTranslations( int $postId, string $metaKey, mixed $metaValue ): void {
		$targetIds = [];

		// Try treating $postId as a source post.
		$records = $this->repository->findBySourcePostId( $postId );
		if ( ! empty( $records ) ) {
			$targetIds = array_map( 'intval', array_column( $records, 'translated_post_id' ) );
		}

		// If nothing found, try treating $postId as a translated post.
		if ( empty( $targetIds ) ) {
			$record = $this->repository->findByTranslatedPost( $postId );
			if ( $record ) {
				$sourceId = (int) $record['source_post_id'];

				// Sync back to the source.
				$targetIds[] = $sourceId;

				// Sync to other translations that share the same source.
				$siblings = $this->repository->findBySourcePostId( $sourceId );
				foreach ( $siblings as $sib ) {
					$sibId = (int) $sib['translated_post_id'];
					if ( $sibId !== $postId ) {
						$targetIds[] = $sibId;
					}
				}
			}
		}

		if ( empty( $targetIds ) ) {
			return;
		}

		// Define the guard constant so any update_post_meta calls below do
		// not re-enter this method.
		if ( ! defined( 'IDIOMATTICWP_SYNCING_META' ) ) {
			define( 'IDIOMATTICWP_SYNCING_META', true );
		}

		foreach ( $targetIds as $targetId ) {
			if ( $targetId !== $postId ) {
				update_post_meta( $targetId, $metaKey, $metaValue );
			}
		}
	}
}
