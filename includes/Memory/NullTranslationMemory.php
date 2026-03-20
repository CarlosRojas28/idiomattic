<?php
/**
 * NullTranslationMemory — no-op implementation for the free tier.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Memory;

use IdiomatticWP\ValueObjects\LanguageCode;

class NullTranslationMemory extends TranslationMemory
{

    public function __construct()
    {
    // No repository needed
    }

    public function lookup(string $text, LanguageCode $source, LanguageCode $target): ?MemoryMatch
    {
        return null;
    }

    public function save(string $source, string $translated, LanguageCode $sourceLang, LanguageCode $targetLang, string $provider): void
    {
    // Do nothing
    }
}
