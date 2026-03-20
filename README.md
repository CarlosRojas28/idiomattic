# Idiomattic WP — Plugin Skeleton

WordPress multilingual plugin. Bring Your Own Key (BYOK) translation.

## Directory Map

```
idiomattic-wp.php                    Bootstrap: constants, autoloader, activation hooks, boot
uninstall.php                    Data cleanup on plugin deletion

config/
  languages.php                  All supported locales (single source of truth)
  elements-schema.json           JSON schema for idiomattic-elements.json config files

includes/
  Support/
    Autoloader.php               PSR-4 autoloader ( IdiomatticWP\ → includes/)
    PublicApi.php                Public helper functions for themes/plugins (idiomatticwp_*)
    HttpClient.php               Wrapper around wp_remote_post/get
    EncryptionService.php        AES-256 encryption for API keys (uses AUTH_KEY)

  Core/
    Plugin.php                   Singleton orchestrator — wires container, hooks, integrations
    Container.php                Minimal DI container (no reflection, no magic)
    ContainerConfig.php          All dependency wiring in one readable file ← START HERE
    HookLoader.php               Resolves and registers all Hook classes
    IntegrationLoader.php        Conditionally loads builder/theme/SEO/WC integrations
    Installer.php                DB table creation, default options, upgrade migrations
    LanguageManager.php          Active languages, current language, language metadata
    CustomElementRegistry.php    Translatable element registration (replaces WPML XML config)

  Contracts/
    UrlStrategyInterface.php     URL detection + building contract
    ProviderInterface.php        BYOK translation provider contract
    TranslationMemoryInterface.php TM lookup + save contract
    TranslationEditorInterface.php Free (duplicate) vs Pro (side-by-side) editor contract
    TranslationRepositoryInterface.php DB operations for translation relationships
    StringRepositoryInterface.php DB operations for string translations
    GlossaryInterface.php        Glossary term access contract
    HookRegistrarInterface.php   register(): void contract for all Hook classes
    IntegrationInterface.php     register() + isAvailable() for all Integration classes

  ValueObjects/
    LanguageCode.php             Immutable, validated language code ('en', 'pt-BR')

  Exceptions/
    InvalidLanguageCodeException.php
    TranslationAlreadyExistsException.php
    TranslationCreationException.php
    InvalidApiKeyException.php
    RateLimitException.php
    ProviderUnavailableException.php

  Routing/
    ParameterStrategy.php        FREE: ?lang=es — no rewrite rules, works everywhere
    DirectoryStrategy.php        PRO: /es/about/ — rewrite rules, most compatible
    SubdomainStrategy.php        PRO: es.example.com — DNS wildcard required

  Translation/
    CreateTranslation.php        Use case: create post translation relationship
    AutoTranslate.php            Use case: translate post via BYOK provider (Pro)
    MarkAsOutdated.php           Use case: mark translations outdated on source update
    PostDuplicator.php           Duplicates a post as translation draft
    FieldTranslator.php          Reads/writes field-level translations
    AIOrchestrator.php           Coordinates provider + TM + glossary for auto-translate
    Segmenter.php                Splits content into TM-compatible segments
    Translation.php              Domain entity: relationship between source and translated post
    TranslationResult.php        Value object: result of a translation operation

  Providers/
    OpenAIProvider.php           OpenAI API (gpt-4o-mini recommended)
    ClaudeProvider.php           Anthropic Claude API (claude-haiku recommended)
    DeepLProvider.php            DeepL API (best for European languages)
    GoogleProvider.php           Google Cloud Translation
    ProviderRegistry.php         Map of provider ID → class, filterable
    ProviderFactory.php          Resolves the active provider at runtime
    NullProvider.php             No-op provider for Free tier (never called)

  Memory/
    TranslationMemory.php        TM orchestrator: lookup → exact → fuzzy → save
    WpdbTranslationMemoryRepository.php DB implementation
    NullTranslationMemory.php    No-op for Free tier
    MemoryMatch.php              Value object: TM lookup result with match type + score
    SavingsReport.php            Value object: cost savings statistics for dashboard

  Glossary/
    GlossaryManager.php          Builds AI prompt instructions from glossary terms
    GlossaryTerm.php             Entity: source term → preferred translation
    WpdbGlossaryRepository.php   DB implementation
    NullGlossary.php             No-op for Free tier basic glossary

  Strings/
    StringScanner.php            Scans theme/plugin files for __(), _e(), _x() calls
    StringTranslator.php         Stores and retrieves string translations
    MoCompiler.php               Compiles translations to .mo binary files
    TranslatableString.php       Entity: a single translatable string with translations

  Fields/
    FieldClassifier.php          Admin UI for classify → translate | copy | ignore
    FieldConfiguration.php       Reads/writes field classification to wp_options

  Migration/
    WpmlMigrator.php             5-step WPML migration wizard orchestrator
    WpmlDetector.php             Detects WPML tables and reads configuration
    MigrationReport.php          Value object: migration summary with counts + errors

  ImportExport/
    Exporter.php                 Dispatches to format-specific exporters
    Importer.php                 Dispatches to format-specific importers
    CsvFormat.php                CSV export/import (Free)
    JsonFormat.php               JSON export/import (Free)
    XliffFormat.php              XLIFF 2.0 export/import (Pro)
    TmxFormat.php                TMX 1.4b export/import — Translation Memory (Pro)
    TbxFormat.php                TBX export/import — Glossary (Pro)

  License/
    FreemiusBootstrap.php        Freemius SDK initialisation
    LicenseChecker.php           isPro(), isAgency(), reads Freemius JWT token
    StagingDetector.php          Auto-detect + manual staging flag

  Queue/
    TranslationQueue.php         Action Scheduler wrapper for async translation (Pro)

  Compatibility/
    CompatibilityChecker.php     Detect/resolve conflicts with other plugins

  Hooks/                         WordPress hook registrars — ONLY place add_action/filter is called
    LanguageHooks.php            Language detection, cookie, switching
    RoutingHooks.php             URL rewriting, redirects, parse_request
    Translation/
      PostTranslationHooks.php   Create/update/delete, post_updated outdated marking
      FieldTranslationHooks.php  Custom field sync on save_post
      StringTranslationHooks.php gettext filters for translated strings
    Admin/
      AdminMenuHooks.php         admin_menu, admin_bar
      PostListHooks.php          Language column in post lists
      MetaboxHooks.php           Translation metabox on post editor
      AssetHooks.php             admin_enqueue_scripts
      AjaxHooks.php              wp_ajax_idiomatticwp_* handlers
      SettingsHooks.php          admin_init settings registration
    Frontend/
      FrontendAssetHooks.php     wp_enqueue_scripts
      HreflangHooks.php          wp_head hreflang tags
      SwitcherHooks.php          Language switcher widget + block
      CanonicalHooks.php         Canonical URL language-awareness
    Queue/
      QueueHooks.php             Action Scheduler job callbacks

  Integrations/                  Optional — loaded only when host is active
    Builders/
      GutenbergIntegration.php   Blocks: switcher block, translatable block attributes, FSE
      ElementorIntegration.php   Elementor: switcher widget, content filter, Pro compat
      DiviIntegration.php        Divi Builder + Divi Theme
      WPBakeryIntegration.php    WPBakery Page Builder
      BeaverBuilderIntegration.php Beaver Builder
      BricksIntegration.php      Bricks Builder
      OxygenIntegration.php      Oxygen Builder
    Themes/
      AstraIntegration.php       Header/footer builder elements, native switcher element
      OceanWPIntegration.php     Topbar strings, header customizer
      GeneratePressIntegration.php Hook content areas
      KadenceIntegration.php     Header/footer builder
      AvadaIntegration.php       Fusion Builder elements
      BlocksyIntegration.php     Header builder
      NeveIntegration.php        Header customizer
    SEO/
      YoastIntegration.php       Hreflang in Yoast head, meta fields in editor, sitemap
      RankMathIntegration.php    Same for RankMath
      AIOSEOIntegration.php      Same for AIOSEO
    WooCommerce/
      WooCommerceIntegration.php Products, orders, emails, cart routing
    REST/
      RestApiIntegration.php     lang param, language headers, /idiomattic-wp/v1/ namespace

  Admin/
    Pages/
      DashboardPage.php          Main translation dashboard
      SettingsPage.php           Plugin settings
      LanguagesPage.php          Language management
      TranslationEditorPage.php  Side-by-side editor (Pro)
      WpmlMigrationPage.php      WPML migration wizard
    Metaboxes/
      TranslationsMetabox.php    Language status indicators on post editor
      TranslationOriginMetabox.php "Translation of: [source]" on translated posts
    Ajax/
      CreateTranslationAjax.php
      GetTranslationStatusAjax.php
      AutoTranslateAjax.php
      SaveFieldTranslationAjax.php

  Frontend/
    LanguageSwitcher.php         Widget + block render logic
    HreflangOutput.php           <link rel="alternate" hreflang="..."> output

assets/
  css/                           Compiled CSS (admin + frontend)
  js/
    admin/                       Admin JS (post list, metabox, editor, settings)
    frontend/                    Frontend JS (language switcher interaction)
    blocks/                      Gutenberg block JS (idiomattic-wp/language-switcher)
  flags/                         SVG flag icons (40+ locales, no CDN dependency)

templates/
  admin/                         PHP template partials for admin pages
  frontend/                      PHP template partials for frontend output

languages/                       .pot template + bundled .mo files

tests/
  Unit/                          PHPUnit unit tests (no WordPress needed)
  Integration/                   Integration tests (WordPress test suite)
```

## Key Design Decisions

### Free vs Pro gating
Resolved ONCE in ContainerConfig.php. The rest of the codebase calls interfaces,
never checking is_pro() inline. Changing plan behavior = changing one line in ContainerConfig.

### Custom element registration (the WPML XML replacement)
Three ways to register: PHP API (idiomatticwp_register_field()), filter
(idiomatticwp_registered_elements), or JSON file (idiomattic-elements.json).
See CustomElementRegistry.php and config/elements-schema.json.

### Builder/theme compatibility
Each builder and theme has its own Integration class loaded only when detected.
They use CustomElementRegistry to declare what they make translatable,
keeping the core clean of builder-specific knowledge.

### Hook isolation
ZERO add_action/add_filter calls outside of includes/Hooks/ and Integration classes.
Application and domain code is pure PHP — testable without WordPress.
