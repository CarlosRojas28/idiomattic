<?php
/**
 * Contracts directory — all plugin interfaces
 *
 * Every interface is in its own file under includes/Contracts/.
 * This file is a map/index only — it does not define anything.
 *
 * ── Interface index ───────────────────────────────────────────────────────────
 *
 *  UrlStrategyInterface
 *   Implemented by: ParameterStrategy, DirectoryStrategy, SubdomainStrategy
 *   Methods: detectLanguage( WP $wp ): LanguageCode
 *            buildUrl( string $url, LanguageCode $lang ): string
 *            homeUrl( LanguageCode $lang ): string
 *            getRewriteRules(): array
 *
 *  ProviderInterface
 *   Implemented by: OpenAIProvider, ClaudeProvider, DeepLProvider, GoogleProvider
 *   Methods: translate( Segment[] $segments, LanguageCode $source, LanguageCode $target, Glossary $glossary ): TranslationResult[]
 *            getName(): string
 *            isConfigured(): bool
 *            estimateCost( Segment[] $segments ): float
 *
 *  TranslationMemoryInterface
 *   Implemented by: WpdbTranslationMemory (Pro), NullTranslationMemory (Free)
 *   Methods: lookup( string $text, LanguageCode $source, LanguageCode $target ): MemoryMatch|null
 *            save( Segment $segment, string $translation, LanguageCode $source, LanguageCode $target ): void
 *            getSavingsReport(): SavingsReport
 *
 *  TranslationEditorInterface
 *   Implemented by: DuplicateEditor (Free), SideBySideEditor (Pro)
 *   Methods: render( int $postId, LanguageCode $targetLang ): string
 *            getUrl( int $postId, LanguageCode $targetLang ): string
 *
 *  TranslationRepositoryInterface
 *   Implemented by: WpdbTranslationRepository
 *   Methods: save( Translation $translation ): void
 *            findBySourceAndLang( int $sourceId, LanguageCode $lang ): Translation|null
 *            findAllForSource( int $sourceId ): Translation[]
 *            markOutdated( int $sourceId ): void
 *            delete( int $translationId ): void
 *
 *  StringRepositoryInterface
 *   Implemented by: WpdbStringRepository
 *   Methods: save( TranslatableString $string ): void
 *            findByHash( string $hash, LanguageCode $lang ): TranslatableString|null
 *            findUntranslated( string $domain, LanguageCode $lang ): TranslatableString[]
 *
 *  GlossaryInterface
 *   Implemented by: WpdbGlossary (Pro), NullGlossary (Free basic)
 *   Methods: getTerms( LanguageCode $source, LanguageCode $target ): GlossaryTerm[]
 *            addTerm( GlossaryTerm $term ): void
 *            buildPromptInstructions( LanguageCode $source, LanguageCode $target ): string
 *
 *  HookRegistrarInterface
 *   Implemented by: all Hook classes
 *   Methods: register(): void
 *
 *  IntegrationInterface
 *   Implemented by: all Integration classes (builders, themes, SEO, WooCommerce)
 *   Methods: register(): void
 *            isAvailable(): bool
 *
 * @package  IdiomatticWP\Contracts
 */
