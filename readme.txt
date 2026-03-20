=== Idiomattic WP ===
Contributors: idiomatticwp
Tags: multilingual, translation, i18n, WPML alternative, AI translation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The fastest multilingual plugin for WordPress. Bring Your Own Key (BYOK) — connect OpenAI, Claude, DeepL or Google directly. No margins on translations.

== Description ==

**Idiomattic WP** is a modern, developer-first multilingual plugin for WordPress. Unlike WPML or Polylang, Idiomattic is built on a clean PHP 8.1 architecture with a proper DI container, and it never takes a cut of your translation costs — you connect your own AI API key directly.

= Key Features =

* **BYOK AI Translation** — Connect OpenAI, Anthropic Claude, DeepL, or Google Translate with your own API key. Pay the provider directly, no markup.
* **Three URL strategies** — Query parameter (`?lang=es`), directory prefix (`/es/my-post/`), or subdomain (`es.example.com`). Pro required for directory + subdomain.
* **Side-by-side Translation Editor** — Purpose-built editor showing source and translated content simultaneously. Field-level saving with live status updates.
* **Translation Memory** — Automatically reuses previous translations to reduce cost and maintain consistency.
* **Glossary** — Define terms that must always be translated a specific way.
* **Nav menu localisation** — Menus automatically switch to the translated post URLs for the active language.
* **SEO-ready** — Correct `hreflang` tags, language-aware canonical URLs, integration with Yoast SEO, RankMath, and AIOSEO.
* **`<html lang>` attribute** — The HTML `lang` and `dir` attributes are automatically updated for the active language.
* **Builder integrations** — Gutenberg, Elementor, Divi, Beaver Builder, Bricks, Oxygen, WPBakery.
* **Theme integrations** — Astra, GeneratePress, Kadence, Neve, OceanWP, Blocksy, Avada.
* **WooCommerce support** — Product and shop page translations.
* **WP-CLI** — Full CLI interface: `wp idiomattic status`, `wp idiomattic translations sync`, and more.
* **REST API** — Expose the current language via `X-IdiomatticWP-Language` header, and use the `/idiomattic-wp/v1/` namespace.
* **WPML migration** — One-click import of your existing WPML translation data.
* **Import/Export** — XLIFF, TMX, CSV, JSON formats.

= Quick Start =

1. Install and activate the plugin.
2. The Setup Wizard launches automatically — choose your default language, active languages, and URL structure.
3. Go to any post and click **Add** in the Translations metabox.
4. Translate in the side-by-side editor. Optionally use AI auto-translate with your own API key.

= Developer API =

```php
// Get the current language
$lang = idiomatticwp_get_current_language(); // e.g. 'es'

// Get the translated post ID
$translatedId = idiomatticwp_get_translation( $postId, 'es' );

// Register a custom field for translation
idiomatticwp_register_field( 'product', '_hero_tagline', [
    'label'      => 'Hero Tagline',
    'field_type' => 'text',
] );

// Register a string for translation
idiomatticwp_register_string( 'Free shipping on orders over $50', 'my-plugin' );
```

Full API documentation: [idiomattic.app/docs](https://idiomattic.app/docs)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/idiomattic-wp`, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. The Setup Wizard will guide you through the initial configuration.
4. Go to **Idiomattic → Settings** to configure AI providers and advanced options.

== Frequently Asked Questions ==

= Do I need an AI API key? =

No. You can create and translate posts manually using the side-by-side editor. AI translation is optional and uses your own API key — we never proxy or mark up API calls.

= Which AI providers are supported? =

OpenAI (GPT-4o, GPT-4), Anthropic Claude (claude-3.5-sonnet, claude-3-opus), DeepL, and Google Translate. More providers coming.

= Is it compatible with WPML? =

Idiomattic includes a one-click WPML migration tool. It reads WPML's translation tables and imports all existing relationships into Idiomattic's data model.

= Does it work with WooCommerce? =

Yes. WooCommerce products, shop pages, and taxonomy terms are fully translatable.

= Does it work with Elementor / Divi / Gutenberg? =

Yes. All four major page builders (Elementor, Divi, Beaver Builder, Bricks, Oxygen, WPBakery) are integrated. Gutenberg is natively supported.

= Can I use directory-based URLs (/es/my-post/)? =

Yes, with a Pro license. The free version supports the `?lang=es` query parameter strategy.

= Is it multisite compatible? =

Single-site only in v1.0. Multisite support is planned for a future release.

== Screenshots ==

1. Translation metabox in the post editor — shows all active languages with status badges.
2. Side-by-side Translation Editor — source content on the left, editable translation on the right.
3. Dashboard — translation statistics at a glance.
4. Settings — Languages tab.
5. Settings — AI provider configuration (BYOK).
6. Setup Wizard — language selection step.
7. Language Switcher widget on the frontend.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Full BYOK AI translation pipeline (OpenAI, Claude, DeepL, Google Translate).
* Side-by-side translation editor with field-level saving.
* Three URL strategies: parameter, directory (Pro), subdomain (Pro).
* Nav menu localisation.
* SEO: hreflang, canonical, html lang attribute.
* WP-CLI interface.
* REST API integration.
* Translation Memory and Glossary.
* XLIFF / TMX / CSV / JSON import-export.
* WPML migration tool.
* Integrations: Yoast, RankMath, AIOSEO, Elementor, Divi, Gutenberg, WooCommerce.
* Setup Wizard on first activation.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade path from previous versions.
