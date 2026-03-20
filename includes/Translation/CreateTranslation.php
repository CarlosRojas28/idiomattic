<?php
/**
 * CreateTranslation — use case: create a translation relationship for a post.
 *
 * Core business logic for creating a translation. Called by Ajax handlers
 * and automation. Knows nothing about HTTP, $_POST, or WordPress hooks.
 *
 * Flow:
 *  1. Validate: post exists, target lang is active, no existing translation
 *  2. Fire before action
 *  3. Duplicate post via PostDuplicator
 *  4. Save relationship to TranslationRepository
 *  5. Fire after action
 *  6. Return result array
 *
 * @package IdiomatticWP\Translation
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Translation;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;
use IdiomatticWP\Exceptions\TranslationAlreadyExistsException;
use IdiomatticWP\ValueObjects\LanguageCode;

class CreateTranslation
{

    public function __construct(private
        TranslationRepositoryInterface $repository, private
        PostDuplicator $duplicator, private
        LanguageManager $languageManager,
        )
    {
    }

    /**
     * Create a translation for the given post in the target language.
     *
     * @param int          $postId     Source post ID.
     * @param LanguageCode $targetLang Target language code.
     *
     * @return array{translation_id: int, translated_post_id: int, status: string}
     *
     * @throws \InvalidArgumentException        If the source post does not exist.
     * @throws InvalidLanguageCodeException      If the target language is not active.
     * @throws TranslationAlreadyExistsException If a translation already exists.
     */
    public function __invoke(int $postId, LanguageCode $targetLang): array
    {
        // 1. Validate post exists
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            throw new \InvalidArgumentException(
                sprintf('Post %d does not exist.', $postId),
                );
        }

        // 2. Validate target language is active
        if (!$this->languageManager->isActive($targetLang)) {
            throw new InvalidLanguageCodeException(
                sprintf('Language "%s" is not active.', (string)$targetLang),
                );
        }

        // 3. Check no existing translation for this source + lang pair
        if ($this->repository->existsForSourceAndLang($postId, $targetLang)) {
            throw new TranslationAlreadyExistsException($postId, (string)$targetLang);
        }

        /**
         * Fires before a translation is created.
         *
         * @param int          $postId     The source post ID.
         * @param LanguageCode $targetLang The target language.
         */
        do_action('idiomatticwp_before_create_translation', $postId, $targetLang);

        // 4. Duplicate the post as a draft
        $duplicateId = $this->duplicator->duplicate($postId, $targetLang);

        // 5. Save the translation relationship in the repository
        $translationId = $this->repository->save([
            'source_post_id' => $postId,
            'translated_post_id' => $duplicateId,
            'source_lang' => (string)$this->languageManager->getDefaultLanguage(),
            'target_lang' => (string)$targetLang,
            'status' => 'draft',
            'translation_mode' => 'duplicate',
            'created_at' => current_time('mysql', true),
        ]);

        /**
         * Fires after a translation has been created.
         *
         * @param int          $translationId The translation record ID.
         * @param int          $postId        The source post ID.
         * @param int          $duplicateId   The duplicated (translated) post ID.
         * @param LanguageCode $targetLang    The target language.
         */
        do_action(
            'idiomatticwp_after_create_translation',
            $translationId,
            $postId,
            $duplicateId,
            $targetLang,
        );

        // 6. Return result
        return [
            'translation_id' => $translationId,
            'translated_post_id' => $duplicateId,
            'status' => 'draft',
        ];
    }
}
