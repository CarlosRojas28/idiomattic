# Idiomattic WP — Feature Roadmap

Review conducted from the perspective of an experienced WordPress site owner.
Priority tiers: 🔴 Critical (blocking core value) · 🟠 High · 🟡 Medium · 🟢 Nice-to-have

---

## 🔴 Critical — Completed

### 1. String translations never reached the frontend ✅
`StringTranslator::translate()` computed `md5($domain.$string.$context)` but `StringRepository`
stored `md5($value)`. Every gettext filter lookup returned a miss, so no UI-saved translation
was ever served. Fixed: hash normalised to `md5($string)` in `StringTranslator`.

### 2. MoCompiler used wrong column ✅
`MoCompiler::compile()` queried `original_string` (old schema); the table column is `source_string`.
Fixed: column name corrected.

### 3. .mo files never recompiled after saving translations ✅
`StringTranslationPage::maybeSave()` wrote translations to the DB but never called
`MoCompiler::compile()`, so the `.mo` file used by `maybeLoadCustomMo` stayed stale.
Fixed: compile is now triggered for each affected domain+language pair after every save.

---

## 🔴 Critical — Completed

### 4. URL strategies: directory and subdomain modes ✅
Both `DirectoryStrategy` and `SubdomainStrategy` are fully implemented (the ROADMAP was
wrong calling them stubs). `DirectoryStrategy` rewrites rules, filters `home_url` /
`option_siteurl`, and flushes permalinks. `SubdomainStrategy` detects subdomains, builds
URLs, and emits a `COOKIE_DOMAIN` admin notice. `RoutingHooks` wires both at boot.

### 5. AI auto-translate for String Translation ✅
- "Auto-translate pending" button added to the String Translation page header.
- `AutoTranslateStringsAjax` batches up to 200 pending strings per call, sends them to
  the configured AI provider via `ProviderInterface::translate()`, saves results, and
  recompiles `.mo` files.
- Pro-gated via `LicenseChecker::isPro()`.

---

## 🟠 High Priority — Completed

### 6. Bulk post translation from post list ✅
- "Translate to all languages" bulk action registered on all translatable post type list
  screens via `PostListHooks`.
- Each post+language pair is created via `CreateTranslation` and dispatched to
  `TranslationQueue` (Action Scheduler when available, synchronous fallback).
- Admin notice shows the number of jobs queued after the action runs.

### 7. Manual string registration from UI ✅
- "+ Add string" button on the String Translation page opens a modal form.
- Form fields: Domain (autocomplete from existing), Context (optional), Source string.
- On submit, `StringRepository::register()` is called for every active non-default language.

### 8a. Link existing posts as translations ✅
- "Link existing post" expandable section added below the "Add" button in
  `TranslationsMetabox` for each language without a translation.
- Debounced title search via `idiomatticwp_search_posts` AJAX action returns matching posts.
- Selecting a post calls `idiomatticwp_link_translation` which saves the relationship
  directly to the translations table without duplicating content.
- Validation prevents linking when a translation already exists.

### 8b. Import / Export UI ✅
- New **Import / Export** submenu page (`ImportExportPage`) added under Idiomattic.
- Export: choose a language → download ZIP of XLIFF 2.0 files via `Exporter::downloadZip()`.
- Import: upload an XLIFF 2.0 or 1.2 file → `Importer::importFromFile()` writes translations
  to the database and shows a result summary.

### 9. Translation memory — UI and usage ✅
- `TranslationMemoryHooks` populates TM on every Translation Editor save (title + excerpt fields).
- `TranslationEditor` now accepts `TranslationMemory` and displays a match banner above each
  target field when a ≥ 70 % match is found, with a one-click "Apply" button.
- TM was already wired into `AIOrchestrator` for AI-translation runs (pre-existing).

### 9b. FSE (Full Site Editing) theme support ✅
- `SettingsPage::renderContentTab()` now detects block themes via `wp_is_block_theme()` and
  appends `wp_template`, `wp_template_part`, and `wp_navigation` post types to the Content tab
  table so they can be configured for translation.

### 9c. Advanced tab Export/Import consolidation ✅
- Removed the basic export-only section from the Advanced tab.
- Replaced with a card linking to the dedicated **Import / Export** submenu page, which supports
  both export and import in one place.

---

## 🟡 Medium Priority — Completed

### 10. Glossary — UI and enforcement ✅
- CRUD admin page at **Idiomattic → Glossary** (`GlossaryPage.php`): add/edit/delete terms per language pair, filter by language.
- `WpdbGlossaryRepository::getAllTerms()` added for filtered listing.
- Glossary terms are injected into AI provider prompts via `GlossaryManager` → `AIOrchestrator`; repository is now wired in `ContainerConfig`.

### 11. Translation status dashboard — per-language counts ✅
- Added `countByStatusAndLang()` and `countAllByLang()` to `TranslationRepositoryInterface` and `WpdbTranslationRepository`.
- `DashboardPage::renderLanguageCoverage()` now shows real per-language complete/outdated/draft counts with colour-coded progress bars and a quick-action link to retranslate outdated content.

### 12. Outdated translation notifications ✅
- `OutdatedTranslationNotifier` fully implemented: immediate email + daily WP-Cron digest, configurable via options.
- `NotificationHooks` wires the notifier to `idiomatticwp_translation_marked_outdated` and schedules the daily cron.
- **Admin notice** added in `TranslationEditorHooks::maybeShowOutdatedNotice()`: a warning banner appears on the Translation Editor when the open translation is `outdated`, with a link to the source post.
- Webhook: `WebhookDispatcher` fires `idiomatticwp_translation_outdated` via `WebhookHooks`.

### 13. WooCommerce product attribute and term translation ✅
- `WooCommerceIntegration` fully implemented: registers `product`/`product_variation` post types and attribute taxonomies (`pa_*` plus dynamic ones), translates cart item names and product titles, stores order language for email locale switching, reads translated term names from term-meta.

### 14. Multisite / network support — guard ✅
- `Installer::activate()` now blocks network-activation with a clear user-facing `wp_die()` error, preventing silent undefined behaviour.
- Full per-site table creation and network settings page remain future work (see Technical Debt).

---

## ✅ Content Translation Hub — Completado

### Content Translation page
- Nueva página **Idiomattic → Content Translation** (`ContentTranslationPage`).
- Una card por post type translatable. Cada card muestra una fila por idioma con:
  - Bandera + nombre del idioma + código.
  - Conteo de posts publicados sin ninguna traducción para ese idioma.
  - Barra de progreso de cobertura.
  - Botón "Queue AI translation" (Pro-gated) que crea los posts duplicados y los encola en `TranslationQueue`.
- Botón "Translate all languages" por card (Pro) y "Translate all missing (N)" en el header de la página.
- Capped at 500 posts per post-type × language per click; click again for the next batch.
- Empty state "All content fully translated ✓" cuando no hay nada pendiente.
- Dashboard actualizado: botón "Content Translation →" en el header de Language Coverage y en los atajos del header.
- Dos nuevos métodos en repositorio: `countUntranslatedByPostTypeAndLang()` y `getUntranslatedPostIdsByTypeAndLang()`.

---

---

## WPML Feature Gap — Implemented ✅

### G1. Browser Language Detection + Auto-Redirect ✅
- `BrowserLanguageRedirectHooks` hooks `template_redirect` at priority 1.
- Parses `Accept-Language` header with quality factor ordering; three-tier matching
  (exact → primary tag → reverse prefix).
- Stores preference in `idiomatticwp_visitor_lang` cookie (30d, httponly, SameSite=Lax).
- Respects explicit URL language signals (no redirect loop).
- Toggle via Advanced settings: "Browser language auto-redirect" checkbox.

### G2. Taxonomy Term Translation ✅
- New DB table `idiomatticwp_term_translations` (term_id, taxonomy, lang, name, slug, description).
  Created by `Installer::createTables()` on activation; existing installs upgraded via
  `Installer::maybeUpgradeTables()` (DB_VERSION bumped to 1.1.0).
- `WpdbTermTranslationRepository` implements `TermTranslationRepositoryInterface` with
  `find()`, `save()`, `delete()`, `findAllForTerm()`.
- Admin: `TermTranslationHooks` adds name/slug/description fields to every taxonomy's
  edit-term and add-term screens; nonce-protected, sanitized on save.
- Frontend: `TermTranslationHooks` filters `get_term` and `get_terms` to overlay
  translated name/slug/description for the active language.

### G3. URL Slug Translation in Translation Editor ✅
- Translation Editor `maybeSave()` reads `te_post_slug` from POST and saves it as
  `post_name` on the translated post via `wp_update_post()`.
- A new "URL Slug" field is rendered between Title and Content in the editor UI,
  showing the source slug and an editable target slug.

### G4. Multilingual Sitemaps (WP Core) ✅
- `MultilingualSitemapHooks` extends the native WordPress XML sitemap (WP 5.5+).
- Filters `wp_sitemaps_posts_entry` to annotate each source-post entry with
  `idiomatticwp_alternates` (hreflang + href pairs for all translations).
- Filters `wp_sitemaps_posts_query_args` to exclude translated posts from the sitemap
  (they appear as alternates of their source posts, not as standalone entries).
- Skips automatically when Yoast, Rank Math, or AIOSEO is active (those plugins
  handle sitemaps themselves; their integrations already add hreflang alternates).

### G5. Menu Translation Per Language ✅
- Admin: Menus settings tab extended with a full location × language matrix.
  For each registered theme location, admins pick a different menu per non-default language.
- Stored in `idiomatticwp_nav_menus` option as `[location => [lang => menu_id]]`.
- Frontend: `MenuTranslationHooks` filters `wp_nav_menu_args` (priority 5) to swap the
  `menu` argument before rendering, clearing `theme_location` so WP uses the override.

### G6. Attachment / Media Metadata Translation ✅
- Admin: `AttachmentTranslationHooks` adds per-language alt text, title, and caption
  fields to the WordPress media attachment edit screen.
- Data stored in `idiomatticwp_strings` table (domain `idiomatticwp_attachment`,
  context `field:attachment_id`).
- Frontend: `AttachmentTranslationHooks` filters `get_post_metadata` for
  `_wp_attachment_image_alt` and `wp_get_attachment_image_attributes` to return
  the translated alt text for the active language.

### G7. Login Page Translation ✅
- `LoginPageTranslationHooks` (loaded in `$coreHooks`) filters `locale` when
  `wp-login.php` is detected; respects `?lang=` query param and visitor cookie.
- Renders a language-switcher strip below the login form with links per active language.

### G8. Email Locale Switching ✅
- `EmailLocaleHooks` (in `$translationHooks`) hooks `wp_mail` at priority 1.
- Looks up the recipient user by email, reads their `idiomatticwp_preferred_lang`
  meta (falling back to WP user locale meta), maps it to a WP locale, and adds a
  high-priority `locale` filter so the email content is generated in that locale.

### G9. Translator User Roles ✅
- `TranslationRoles::register()` creates:
  - `idiomatticwp_translator` — read + edit assigned posts, custom cap `idiomatticwp_translate`
  - `idiomatticwp_translation_manager` — edit others' posts, custom cap `idiomatticwp_manage_translations`
- Called from `Installer::activate()`.
- `TranslatorAccessHooks` grants `edit_post` capability for posts assigned via
  `_idiomatticwp_assigned_translator` post meta; restricts post list to own assignments.
- `AssignTranslatorAjax` + metabox dropdown lets managers assign translators to
  individual translated posts.

### G10. WooCommerce Multi-Currency ✅
- `MultiCurrency` class registered inside `WooCommerceIntegration`.
- Filters: `woocommerce_currency`, `woocommerce_currency_symbol`,
  `woocommerce_product_get_price` (and all price variants), and
  `woocommerce_checkout_create_order` to persist currency on orders.
- Per-language `[code, symbol, rate]` stored in `idiomatticwp_wc_currencies` option.
- Settings UI added to Translation tab (shown only when WooCommerce is active).
- Exchange rate applied via `round(price × rate, wc_get_price_decimals())`.

### G11. Field Synchronization Between Translations ✅
- `FieldSyncHooks` hooks `updated_post_meta` / `added_post_meta`.
- When a field registered with `'sync' => true` in `CustomElementRegistry` is updated
  on any language version, the value is propagated to all sibling translations
  (source → all targets, or translated → source + other targets).
- Guard flag `IDIOMATTICWP_SYNCING_META` prevents infinite loops.

### G12. Taxonomy Base Slug Translation (WooCommerce) ✅
- `TaxonomyPermalinkHooks` filters `woocommerce_product_rewrite_slug`,
  `woocommerce_product_category_rewrite_slug`, `woocommerce_product_tag_rewrite_slug`.
- Per-language slug overrides stored in `idiomatticwp_taxonomy_slugs` option.
- Settings UI added to URL tab when WooCommerce is active.

### G13. REST API Multilingual Endpoints ✅
- `GET /wp-json/idiomatticwp/v1/languages` — active languages with metadata.
- `GET /wp-json/idiomatticwp/v1/translations/{post_id}` — all translations for a post.
- `register_rest_field()` adds `idiomatticwp_lang` and `idiomatticwp_translations` to
  all post-type REST responses.
- `lang` query parameter on standard WP REST endpoints filters posts to a single language.

---

## 🟢 Nice-to-have — Completed

### 15. String Translation — AI auto-translate per cell ✅
- Small "AI" icon button added next to every translation textarea in `StringTranslationPage`.
- On click, dispatches to `TranslateSingleStringAjax` and updates the textarea in place.
- Loading state prevents double-clicks; errors shown inline.

### 16. String Translation — filter by status ✅
- "Status" dropdown (All / Pending / Translated) added to the String Translation filter bar.
- `StringRepository::getStringsMultiLang()` and `countDistinctStrings()` accept a `$statusFilter`
  parameter; `StringTranslationPage` passes `$_GET['str_status']` through after allow-listing.

### 17. Translation Editor — side-by-side diff for outdated translations ✅
- When a translation has `status = outdated`, a collapsible diff panel is rendered above the
  editor fields showing per-field word-level diffs (added/removed spans).
- Powered by a lightweight PHP diff implementation in `TranslationEditor::computeWordDiff()`.

### 18. Translation Editor — segment-level TM matches ✅
- `TranslationEditor` splits post content into plain-text paragraphs and runs each through
  `TranslationMemory::findBestMatch()`.
- Match badges (exact / fuzzy %) appear next to each segment with a one-click "Apply" button.
- An "AI translate by segment" button runs the AI only on the unmatched paragraphs.

### 19. REST API / Headless support ✅
- `GET /wp-json/idiomatticwp/v1/languages` — active languages list with metadata.
- `GET /wp-json/idiomatticwp/v1/translations/{post_id}` — all translations for a post.
- `register_rest_field()` injects `idiomatticwp_lang` and `idiomatticwp_translations` into
  all post-type REST responses; `lang` query param filters posts by language.

### 20. WPML migration — automated import ✅
- `WpmlMigrationPage` runs an AJAX-batch wizard that reads WPML's `icl_translations` /
  `icl_string_translations` tables and creates Idiomattic translation records in batches
  of 50 to avoid PHP timeouts. Progress bar shown in the UI.

### 21. Polylang migration wizard ✅
- Same `WpmlMigrationPage` hosts a second wizard tab for Polylang.
- Reads the `pll_term_relationships` / `pll_translations` taxonomy data and creates
  Idiomattic records via `PolylangMigrator::migrateBatch()`.

### 22. "Translate on publish" option ✅
- Per post-type toggle in Settings → Content tab.
- `TranslateOnPublishHooks` fires on `publish_post` / `post_updated` and queues AI
  translation for all active non-default languages via `TranslationQueue`.

### 23. Compatibility report — auto-fix suggestions ✅
- "Apply fix" button added to each WPML-config entry in `CompatibilityPage`.
- Writes/merges an `idiomattic-elements.json` override into the plugin/theme directory.
- Bulk "Apply all fixes" button at the top of the page applies to all detected items at once.

### 24. Front-end language switcher — more display modes ✅
- `LanguageSwitcher` supports: `list` (default), `dropdown` (native `<select>`),
  `nav-dropdown` (CSS hover), `flags-only`, and `floating` (sticky bottom-right widget).
- All modes configurable via widget settings, shortcode attribute, or block attribute.

### 25. RTL language support ✅
- `FrontendAssetHooks` injects inline RTL overrides (`direction: rtl`) when the current
  language is right-to-left.
- Admin bar language bar also switches direction for RTL languages.

---

## 🟢 Additional Features — Completed

### 26. Multilingual search results ✅
- `SearchFilterHooks` hooks `pre_get_posts` on frontend main-query search requests.
- Default language: all translated posts (stored copies of originals) are excluded from
  search results so only source posts appear.
- Non-default language X: source posts that already have a translation for X are excluded;
  their translated copies appear naturally via WP full-text search.
- Result is cached per-request in WP object cache to avoid repeated DB reads.

---

## Technical Debt

- `StringTranslator::register()` uses obsolete schema (`original_string` column, combined hash).
  Either align it with `StringRepository` or remove it — it is currently unused.
- `MoCompiler` still depends on `StringTranslator` in its constructor even though
  `compile()` only uses `$wpdb`. Remove the dead dependency.
- `DirectoryStrategy` and `SubdomainStrategy` return empty strings / throw no exceptions;
  activating them silently breaks all URLs. Add a runtime capability check and a fallback.
- `TranslationQueue` does not enforce concurrency limits. Bulk-translating a large site
  can exhaust PHP memory. Implement a batch size cap and WP-Cron scheduling.
