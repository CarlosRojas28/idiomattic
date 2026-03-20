<?php
/**
 * ProviderInterface — the contract for all translation providers.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Contracts;

interface ProviderInterface
{
    /**
     * Translate an array of text segments.
     *
     * @param array  $segments      Strings to translate.
     * @param string $sourceLang    Source language code.
     * @param string $targetLang    Target language code.
     * @param array  $glossaryTerms Optional terminologies.
     * 
     * @return array Same-length array of translated strings.
     */
    public function translate(array $segments, string $sourceLang, string $targetLang, array $glossaryTerms = []): array;

    /**
     * Human-readable provider name.
     */
    public function getName(): string;

    /**
     * Provider slug identifier.
     */
    public function getId(): string;

    /**
     * Returns true if API key is configured.
     */
    public function isConfigured(): bool;

    /**
     * Estimated cost in USD for translating these segments.
     */
    public function estimateCost(array $segments): float;

    /**
     * Configuration fields for the settings UI.
     */
    public function getConfigFields(): array;
}
