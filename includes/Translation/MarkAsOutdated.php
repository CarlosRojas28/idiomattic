<?php
/**
 * MarkAsOutdated — flags all translations of a post as outdated
 * when the source post's content is updated.
 *
 * Called from the WordPress `post_updated` hook. Skips auto-drafts,
 * revisions, and updates that don't change translatable content
 * (title, content, excerpt).
 *
 * @package IdiomatticWP\Translation
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Translation;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class MarkAsOutdated
{

    public function __construct(private TranslationRepositoryInterface $repository)
    {
    }

    /**
     * Mark all "complete" translations of a post as outdated.
     *
     * Intended to be hooked into `post_updated` (priority 10, 3 args).
     *
     * @param int      $postId  The post ID.
     * @param \WP_Post $newPost The post object after the update.
     * @param \WP_Post $oldPost The post object before the update.
     */
    public function __invoke(int $postId, \WP_Post $newPost, \WP_Post $oldPost): void
    {
        // Skip auto-drafts and revisions — these are not meaningful updates.
        if ('auto-draft' === $newPost->post_status) {
            return;
        }

        if ('inherit' === $newPost->post_status) {
            return;
        }

        // Only mark outdated if translatable content actually changed.
        if (!$this->contentChanged($newPost, $oldPost)) {
            return;
        }

        $this->repository->markOutdated($postId);

        /**
         * Fires after translations have been marked as outdated.
         *
         * @param int $postId The source post ID whose translations were flagged.
         */
        do_action('idiomatticwp_translation_marked_outdated', $postId);
    }

    /**
     * Compare translatable fields between old and new versions.
     */
    private function contentChanged(\WP_Post $newPost, \WP_Post $oldPost): bool
    {
        return $newPost->post_title !== $oldPost->post_title
            || $newPost->post_content !== $oldPost->post_content
            || $newPost->post_excerpt !== $oldPost->post_excerpt;
    }
}
