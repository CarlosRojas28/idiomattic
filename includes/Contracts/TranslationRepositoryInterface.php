<?php
/**
 * TranslationRepositoryInterface — contract for translation persistence.
 *
 * Isolates all database operations for translation records behind
 * a clean interface. Implemented by WpdbTranslationRepository.
 *
 * @package IdiomatticWP\Contracts
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Contracts;

use IdiomatticWP\ValueObjects\LanguageCode;

interface TranslationRepositoryInterface
{

    /**
     * Save (insert or update) a translation record.
     *
     * If `$data['id']` is set the record is updated; otherwise it is inserted.
     *
     * @param array $data Column => value pairs.
     * @return int The row ID (new on insert, existing on update).
     */
    public function save(array $data): int;

    /**
     * Find a translation by source post ID and target language.
     *
     * @return array|null Associative array or null if not found.
     */
    public function findBySourceAndLang(int $sourceId, LanguageCode $lang): ?array;

    /**
     * Find the translation record that points to a translated post.
     *
     * @return array|null Associative array or null if not found.
     */
    public function findByTranslatedPost(int $translatedPostId): ?array;

    /**
     * Return all translation records for a given source post.
     *
     * @return array[] Array of associative arrays.
     */
    public function findAllForSource(int $sourceId): array;

    /**
     * Mark every "complete" translation of a source post as outdated.
     */
    public function markOutdated(int $sourceId): void;

    /**
     * Update a translation's status.
     */
    public function updateStatus(int $translationId, string $status): void;

    /**
     * Delete a translation record.
     */
    public function delete(int $translationId): void;

    /**
     * Check whether a translation already exists for a source + language pair.
     */
    public function existsForSourceAndLang(int $sourceId, LanguageCode $lang): bool;

    /**
     * Count all translation records.
     */
    public function countAll(): int;

    /**
     * Count translation records by status.
     */
    public function countByStatus(string $status): int;

    /**
     * Get the latest translation records.
     *
     * @return array[]
     */
    public function getLatest(int $limit = 10): array;

    /**
     * Find all translations for a source post ID.
     * 
     * @return array[] Array of associative arrays.
     */
    public function findBySourcePostId(int $sourcePostId): array;
}
