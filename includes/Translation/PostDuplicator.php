<?php
/**
 * PostDuplicator — creates a copy of a post for translation.
 *
 * What IS copied (structural): post_type, post_author, menu_order,
 * comment_status, ping_status, featured image, and meta keys not excluded.
 *
 * What is NOT copied (to be translated): post_title, post_content,
 * post_excerpt — these are left empty for the translator to fill.
 *
 * post_status is always forced to 'draft' on the new post.
 *
 * Filters:
 *   idiomatticwp_before_duplicate_post
 *   idiomatticwp_after_duplicate_post
 *   idiomatticwp_copied_post_meta_keys
 *
 * @package IdiomatticWP\Translation
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Translation;

use IdiomatticWP\Exceptions\TranslationCreationException;
use IdiomatticWP\ValueObjects\LanguageCode;

class PostDuplicator
{

    /**
     * Meta keys that should never be copied to the duplicate.
     */
    private const SKIP_META_KEYS = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
    ];

    /**
     * Meta key prefixes that belong to third-party integrations
     * which handle their own duplication logic.
     */
    private const SKIP_META_PREFIXES = [
        '_elementor_',
    ];

    public function __construct()
    {
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Duplicate a post as a translation draft.
     *
     * @param int          $sourcePostId Source post ID to duplicate.
     * @param LanguageCode $targetLang   Target language for the new copy.
     *
     * @return int The new (duplicate) post ID.
     *
     * @throws \InvalidArgumentException   If the source post does not exist.
     * @throws TranslationCreationException If wp_insert_post() fails.
     */
    public function duplicate(int $sourcePostId, LanguageCode $targetLang): int
    {
        $source = get_post($sourcePostId);

        if (!$source instanceof \WP_Post) {
            throw new \InvalidArgumentException(
                sprintf('Source post %d does not exist.', $sourcePostId),
                );
        }

        // Build structural post data.
        // post_title uses the source title as a placeholder (WordPress rejects
        // posts where title + content + excerpt are all empty).
        // We intentionally do NOT embed the source title in the placeholder:
        // the source title is already visible in the Translation Editor's left
        // column, and embedding it here causes the right (editable) column to
        // show English text, misleading editors into thinking the post is
        // already written in the source language.
        // post_content and post_excerpt are intentionally empty — they will be
        // filled by the translator or AIOrchestrator.
        $postData = [
            'post_type'      => $source->post_type,
            'post_status'    => 'draft',
            'post_author'    => $source->post_author,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
            'post_parent'    => 0,
            'post_title'     => sprintf(
                /* translators: %s = target language code, e.g. "fr" */
                __( '[%s — translation pending]', 'idiomattic-wp' ),
                strtoupper( (string) $targetLang )
            ),
            'post_content'   => '',
            'post_excerpt'   => '',
        ];

        /**
         * Filter the post data array before insertion.
         *
         * @param array        $postData     The post data that will be inserted.
         * @param int          $sourcePostId The original post ID.
         * @param LanguageCode $targetLang   The target language.
         */
        $postData = apply_filters(
            'idiomatticwp_before_duplicate_post',
            $postData,
            $sourcePostId,
            $targetLang,
        );

        $newId = wp_insert_post($postData, true);

        if (is_wp_error($newId)) {
            throw new TranslationCreationException(
                $sourcePostId,
                (string)$targetLang,
                $newId->get_error_message()
                );
        }

        // Copy meta fields
        $this->copyMetaFields($sourcePostId, $newId, $targetLang);

        // Copy featured image
        $thumbId = get_post_thumbnail_id($sourcePostId);
        if ($thumbId) {
            set_post_thumbnail($newId, (int)$thumbId);
        }

        /**
         * Fires after a post has been duplicated for translation.
         *
         * @param int          $sourcePostId The original post ID.
         * @param int          $newId        The new (duplicate) post ID.
         * @param LanguageCode $targetLang   The target language.
         */
        do_action('idiomatticwp_after_duplicate_post', $sourcePostId, $newId, $targetLang);

        return $newId;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Copy meta fields from source to target, skipping WP-internal and
     * third-party keys.
     */
    private function copyMetaFields(int $sourceId, int $targetId, LanguageCode $targetLang): void
    {
        $allMeta = get_post_meta($sourceId);

        if (!is_array($allMeta)) {
            return;
        }

        $keysToCopy = [];

        foreach ($allMeta as $key => $values) {
            // Skip WP-internal keys
            if (in_array($key, self::SKIP_META_KEYS, true)) {
                continue;
            }

            // Skip third-party prefixed keys
            if ($this->hasSkippedPrefix($key)) {
                continue;
            }

            $keysToCopy[] = $key;
        }

        /**
         * Filter the list of meta keys that will be copied to the duplicate.
         *
         * @param string[]     $keysToCopy The meta keys that will be copied.
         * @param int          $sourceId   The original post ID.
         * @param int          $targetId   The new post ID.
         * @param LanguageCode $targetLang The target language.
         */
        $keysToCopy = apply_filters(
            'idiomatticwp_copied_post_meta_keys',
            $keysToCopy,
            $sourceId,
            $targetId,
            $targetLang,
        );

        foreach ($keysToCopy as $key) {
            if (!isset($allMeta[$key])) {
                continue;
            }

            // Each meta key can have multiple values (serialized array from get_post_meta)
            foreach ($allMeta[$key] as $value) {
                $value = maybe_unserialize($value);
                update_post_meta($targetId, $key, $value);
            }
        }
    }

    /**
     * Check if a meta key starts with any of the skipped prefixes.
     */
    private function hasSkippedPrefix(string $key): bool
    {
        foreach (self::SKIP_META_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
