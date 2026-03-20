<?php
/**
 * TranslationMemory — orchestrator for memory lookups and saves.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Memory;

use IdiomatticWP\ValueObjects\LanguageCode;

class TranslationMemory
{

    public function __construct(private
        WpdbTranslationMemoryRepository $repository
        )
    {
    }

    /**
     * Look up a segment in the memory.
     */
    public function lookup(string $text, LanguageCode $source, LanguageCode $target): ?MemoryMatch
    {
        do_action('idiomatticwp_tm_before_lookup', $text, $source, $target);

        // Allow filters to normalize text before lookup
        $normalizedText = apply_filters('idiomatticwp_tm_segment_text', trim($text));

        if (empty($normalizedText))
            return null;

        $match = $this->repository->lookup($normalizedText, $source, $target);

        if ($match) {
            do_action('idiomatticwp_tm_hit', $text, $match->matchType, $match->score);
        }

        return $match;
    }

    /**
     * Save a segment translation.
     */
    public function save(string $source, string $translated, LanguageCode $sourceLang, LanguageCode $targetLang, string $provider): void
    {
        $source = trim($source);
        $translated = trim($translated);

        if (empty($source) || empty($translated))
            return;

        $shouldSave = apply_filters('idiomatticwp_tm_before_save', true, $source, $translated, $sourceLang, $targetLang);

        if ($shouldSave) {
            $this->repository->save($source, $translated, $sourceLang, $targetLang, $provider);
        }
    }
}
