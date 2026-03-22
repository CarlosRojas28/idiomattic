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

## 🟢 Nice-to-have

### 15. String Translation — AI auto-translate per cell
A small "AI" icon button next to each translation textarea that translates that single
string on demand without affecting others.

### 16. String Translation — filter by status
Add a "Status" filter (All / Pending / Translated) to the String Translation table
so translators can focus on untranslated strings.

### 17. Translation Editor — side-by-side diff for outdated translations
When a translation is outdated, show a diff of what changed in the source so the
translator knows exactly what to update.

### 18. Translation Editor — segment-level TM matches
Show fuzzy-match percentage from TM for each segment, similar to professional CAT tools.

### 19. REST API / Headless support
Expose translated post content and string translations through the WP REST API so
headless/Gutenberg-as-frontend builds can consume them.

### 20. WPML migration — automated import
`WpmlDetector` detects WPML data but migration is manual. A one-click import wizard
would be a strong selling point for users switching from WPML.

### 21. Polylang migration wizard
Similar to the WPML migration but for Polylang, which stores language assignments
as taxonomy terms (`language` taxonomy).

### 22. "Translate on publish" option
Per post-type setting: automatically queue AI translation whenever a post is published
or updated, without requiring a manual trigger.

### 23. Compatibility report — auto-fix suggestions
The Compatibility page scans for untranslatable fields. Add a one-click "Apply fix"
button that generates and activates a `wpml-config.xml` override for the detected issues.

### 24. Front-end language switcher — more display modes
Current switcher renders a simple list. Add: dropdown, flags-only, flags + name,
and a floating sticky widget option (configurable in Customizer).

### 25. RTL language support
Layouts, admin pages, and the language switcher are not tested with RTL languages
(Arabic, Hebrew, Persian). Add RTL stylesheet overrides and swap directionality when
the active language is RTL.

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
