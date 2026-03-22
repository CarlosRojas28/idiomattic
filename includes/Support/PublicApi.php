<?php
/**
 * Public API — helper functions for themes and plugin developers.
 *
 * All functions guard against early calls (before plugins_loaded).
 * Safe to call from theme functions.php or other plugins.
 *
 * @package IdiomatticWP
 */

declare( strict_types=1 );

defined( 'IDIOMATTICWP_VERSION' ) || exit;

// ── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Returns the plugin container, or null if the plugin hasn't booted yet.
 *
 * @internal
 */
function _idiomatticwp_container(): ?\IdiomatticWP\Core\Container {
	if ( ! did_action( 'idiomatticwp_loaded' ) ) {
		return null;
	}
	try {
		return \IdiomatticWP\Core\Plugin::getInstance()->getContainer();
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Returns the LanguageManager, or null if not available.
 *
 * @internal
 */
function _idiomatticwp_lang_manager(): ?\IdiomatticWP\Core\LanguageManager {
	$c = _idiomatticwp_container();
	return $c ? $c->get( \IdiomatticWP\Core\LanguageManager::class ) : null;
}

// ── Language functions ────────────────────────────────────────────────────────

/**
 * Get the current active language code (e.g. 'es', 'fr', 'pt-BR').
 *
 * @return string Language code, or empty string if plugin not yet booted.
 */
function idiomatticwp_get_current_language(): string {
	$manager = _idiomatticwp_lang_manager();
	return $manager ? (string) $manager->getCurrentLanguage() : '';
}

/**
 * Get the default (source) language code.
 *
 * @return string Language code, or empty string if plugin not yet booted.
 */
function idiomatticwp_get_default_language(): string {
	$manager = _idiomatticwp_lang_manager();
	return $manager ? (string) $manager->getDefaultLanguage() : '';
}

/**
 * Get all active language codes as an array of strings.
 *
 * @return string[] e.g. ['en', 'es', 'fr']
 */
function idiomatticwp_get_active_languages(): array {
	$manager = _idiomatticwp_lang_manager();
	if ( ! $manager ) return [];
	return array_map( 'strval', $manager->getActiveLanguages() );
}

/**
 * Returns true when the current language is the default language.
 */
function idiomatticwp_is_default_language(): bool {
	$manager = _idiomatticwp_lang_manager();
	if ( ! $manager ) return true;
	return $manager->isDefault( $manager->getCurrentLanguage() );
}

// ── URL functions ─────────────────────────────────────────────────────────────

/**
 * Build a language-aware URL for a given language code.
 *
 * @param string $url  Absolute URL to localise.
 * @param string $lang Language code (e.g. 'es').
 * @return string Language-aware URL.
 */
function idiomatticwp_get_url_for_language( string $url, string $lang ): string {
	$c = _idiomatticwp_container();
	if ( ! $c ) return $url;
	try {
		$langCode = \IdiomatticWP\ValueObjects\LanguageCode::from( $lang );
		return $c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class )->buildUrl( $url, $langCode );
	} catch ( \Throwable $e ) {
		return $url;
	}
}

/**
 * Get the home URL for a specific language.
 *
 * @param string $lang Language code.
 * @return string Home URL for that language.
 */
function idiomatticwp_get_home_url( string $lang ): string {
	$c = _idiomatticwp_container();
	if ( ! $c ) return home_url();
	try {
		$langCode = \IdiomatticWP\ValueObjects\LanguageCode::from( $lang );
		return $c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class )->homeUrl( $langCode );
	} catch ( \Throwable $e ) {
		return home_url();
	}
}

// ── Translation relationship functions ────────────────────────────────────────

/**
 * Get the translated post ID for a given source post and language.
 *
 * @param int    $postId Source post ID.
 * @param string $lang   Target language code.
 * @return int|null Translated post ID, or null if none exists.
 */
function idiomatticwp_get_translation( int $postId, string $lang ): ?int {
	$c = _idiomatticwp_container();
	if ( ! $c ) return null;
	try {
		$langCode = \IdiomatticWP\ValueObjects\LanguageCode::from( $lang );
		$repo     = $c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class );
		$record   = $repo->findBySourceAndLang( $postId, $langCode );
		return $record ? (int) $record['translated_post_id'] : null;
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Get all translations for a post, keyed by language code.
 *
 * @param int $postId Source post ID.
 * @return array<string, int> e.g. ['es' => 42, 'fr' => 99]
 */
function idiomatticwp_get_all_translations( int $postId ): array {
	$c = _idiomatticwp_container();
	if ( ! $c ) return [];
	try {
		$repo    = $c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class );
		$records = $repo->findAllForSource( $postId );
		$result  = [];
		foreach ( $records as $row ) {
			$result[ $row['target_lang'] ] = (int) $row['translated_post_id'];
		}
		return $result;
	} catch ( \Throwable $e ) {
		return [];
	}
}

/**
 * Get the translation status for a post and language.
 *
 * @param int    $postId Source post ID.
 * @param string $lang   Target language code.
 * @return string|null One of: 'draft', 'in_progress', 'complete', 'outdated', or null.
 */
function idiomatticwp_get_translation_status( int $postId, string $lang ): ?string {
	$c = _idiomatticwp_container();
	if ( ! $c ) return null;
	try {
		$langCode = \IdiomatticWP\ValueObjects\LanguageCode::from( $lang );
		$repo     = $c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class );
		$record   = $repo->findBySourceAndLang( $postId, $langCode );
		return $record ? $record['status'] : null;
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Returns true when the given post is a translation (not an original source post).
 *
 * @param int $postId Post ID to check.
 */
function idiomatticwp_is_translation( int $postId ): bool {
	$c = _idiomatticwp_container();
	if ( ! $c ) return false;
	try {
		$repo = $c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class );
		return $repo->findByTranslatedPost( $postId ) !== null;
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Get the source (original) post ID for a translated post.
 *
 * @param int $postId Translated post ID.
 * @return int|null Source post ID, or null if $postId is not a translation.
 */
function idiomatticwp_get_source_post( int $postId ): ?int {
	$c = _idiomatticwp_container();
	if ( ! $c ) return null;
	try {
		$repo   = $c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class );
		$record = $repo->findByTranslatedPost( $postId );
		return $record ? (int) $record['source_post_id'] : null;
	} catch ( \Throwable $e ) {
		return null;
	}
}

// ── Element registration ──────────────────────────────────────────────────────

/**
 * Register a custom post meta field for translation.
 *
 * @param string|string[] $postTypes  Post type(s) this field belongs to.
 * @param string          $key        Meta key.
 * @param array           $options    {
 *     @type string $label      Human-readable field label.
 *     @type string $field_type 'text' | 'html' | 'textarea' (default: 'text').
 *     @type string $mode       'translate' | 'copy' | 'ignore' (default: 'translate').
 * }
 */
function idiomatticwp_register_field( string|array $postTypes, string $key, array $options = [] ): void {
	$c = _idiomatticwp_container();
	if ( ! $c ) {
		// Called too early — queue for later
		add_action( 'idiomatticwp_loaded', static function () use ( $postTypes, $key, $options ) {
			idiomatticwp_register_field( $postTypes, $key, $options );
		} );
		return;
	}
	try {
		$registry = $c->get( \IdiomatticWP\Core\CustomElementRegistry::class );
		foreach ( (array) $postTypes as $postType ) {
			$registry->registerField( $postType, $key, $options );
		}
	} catch ( \Throwable $e ) {
		// Silently ignore — field registration is best-effort
	}
}

/**
 * Register a WordPress option value for translation.
 *
 * @param string $optionName  wp_options key.
 * @param array  $options     { @type string $label, @type string $field_type }
 */
function idiomatticwp_register_option( string $optionName, array $options = [] ): void {
	$c = _idiomatticwp_container();
	if ( ! $c ) {
		add_action( 'idiomatticwp_loaded', static function () use ( $optionName, $options ) {
			idiomatticwp_register_option( $optionName, $options );
		} );
		return;
	}
	try {
		$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )->registerOption( $optionName, $options );
	} catch ( \Throwable $e ) {}
}

/**
 * Register a shortcode attribute for translation.
 *
 * @param string   $tag        Shortcode tag.
 * @param string[] $attributes Attribute names to translate.
 * @param array    $options    Additional options.
 */
function idiomatticwp_register_shortcode( string $tag, array $attributes, array $options = [] ): void {
	$c = _idiomatticwp_container();
	if ( ! $c ) {
		add_action( 'idiomatticwp_loaded', static function () use ( $tag, $attributes, $options ) {
			idiomatticwp_register_shortcode( $tag, $attributes, $options );
		} );
		return;
	}
	try {
		$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )->registerShortcode( $tag, $attributes, $options );
	} catch ( \Throwable $e ) {}
}

/**
 * Register a Gutenberg block attribute for translation.
 *
 * @param string   $blockName  Block name (e.g. 'my-plugin/hero').
 * @param string[] $attributes Attribute keys to translate.
 * @param array    $options    Additional options.
 */
function idiomatticwp_register_block( string $blockName, array $attributes, array $options = [] ): void {
	$c = _idiomatticwp_container();
	if ( ! $c ) {
		add_action( 'idiomatticwp_loaded', static function () use ( $blockName, $attributes, $options ) {
			idiomatticwp_register_block( $blockName, $attributes, $options );
		} );
		return;
	}
	try {
		$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )->registerBlock( $blockName, $attributes, $options );
	} catch ( \Throwable $e ) {}
}

/**
 * Register an Elementor widget control for translation.
 *
 * @param string   $widgetName Widget name.
 * @param string[] $controls   Control keys to translate.
 * @param array    $options    Additional options.
 */
function idiomatticwp_register_elementor_widget( string $widgetName, array $controls, array $options = [] ): void {
	$c = _idiomatticwp_container();
	if ( ! $c ) {
		add_action( 'idiomatticwp_loaded', static function () use ( $widgetName, $controls, $options ) {
			idiomatticwp_register_elementor_widget( $widgetName, $controls, $options );
		} );
		return;
	}
	try {
		$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )->registerElementorWidget( $widgetName, $controls, $options );
	} catch ( \Throwable $e ) {}
}

// ── String translation ────────────────────────────────────────────────────────

/**
 * Register a string for translation in the String Translation module.
 *
 * Call this on the 'init' hook to make a string available for translation
 * in the admin. The string will appear in Idiomattic → String Translation.
 *
 * @param string $value   The original string value.
 * @param string $domain  The textdomain (plugin or theme slug). Default 'default'.
 * @param string $context Optional gettext context.
 */
function idiomatticwp_register_string( string $value, string $domain = 'default', string $context = '' ): void {
	$activeLangs = get_option( 'idiomatticwp_active_langs', [] );
	$defaultLang = get_option( 'idiomatticwp_default_lang', '' );

	if ( empty( $activeLangs ) || empty( $defaultLang ) ) {
		return;
	}

	// Lazy-load the repository via the plugin container.
	static $repo = null;
	if ( $repo === null ) {
		try {
			$repo = \IdiomatticWP\Core\Plugin::getInstance()
				->getContainer()
				->get( \IdiomatticWP\Repositories\StringRepository::class );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	foreach ( $activeLangs as $lang ) {
		$lang = (string) $lang;
		if ( $lang === $defaultLang ) {
			continue;
		}
		$repo->register( $domain, $value, $context, $lang );
	}
}

/**
 * Translate a registered string into the current language.
 *
 * Falls back to the original string when:
 *   - The plugin is not booted yet.
 *   - No translation exists for the current language.
 *   - The current language is the default.
 *
 * @param string $string  Original (source) string.
 * @param string $domain  Text domain.
 * @param string $context Optional disambiguation context.
 * @return string Translated string, or $string as fallback.
 */
function idiomatticwp_t( string $string, string $domain, string $context = '' ): string {
	$c = _idiomatticwp_container();
	if ( ! $c ) return $string;
	try {
		$manager = $c->get( \IdiomatticWP\Core\LanguageManager::class );
		$current = $manager->getCurrentLanguage();
		if ( $manager->isDefault( $current ) ) return $string;
		return $c->get( \IdiomatticWP\Strings\StringTranslator::class )
			->translate( $string, $domain, $current, $context );
	} catch ( \Throwable $e ) {
		return $string;
	}
}

// ── Plugin metadata helpers ──────────────────────────────────────────────────

/**
 * Returns the upgrade URL for this plugin (Pro upsell links).
 * Filterable so white-label / agency builds can point elsewhere.
 *
 * @return string Absolute URL.
 */
function idiomatticwp_upgrade_url( string $campaign = '' ): string {
	$base = 'https://idiomattic.app/upgrade';
	if ( $campaign !== '' ) {
		$base = add_query_arg( 'utm_campaign', sanitize_key( $campaign ), $base );
	}
	return (string) apply_filters( 'idiomatticwp_upgrade_url', $base, $campaign );
}

/**
 * Returns the plugin documentation URL.
 *
 * @return string Absolute URL.
 */
function idiomatticwp_docs_url( string $path = '' ): string {
	$base = 'https://idiomattic.app/docs';
	if ( $path !== '' ) {
		$base = trailingslashit( $base ) . ltrim( $path, '/' );
	}
	return (string) apply_filters( 'idiomatticwp_docs_url', $base, $path );
}

// ── Language switcher shortcode ───────────────────────────────────────────────

/**
 * Render the language switcher.
 *
 * Can be used in templates:
 *   echo idiomatticwp_language_switcher( ['style' => 'dropdown'] );
 *
 * Or as a shortcode: [idiomatticwp_switcher style="list" show_flags="1"]
 *
 * @param array $args See LanguageSwitcher::render() for available options.
 * @return string HTML output.
 */
function idiomatticwp_language_switcher( array $args = [] ): string {
	$c = _idiomatticwp_container();
	if ( ! $c ) return '';
	try {
		return $c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )->render( $args );
	} catch ( \Throwable $e ) {
		return '';
	}
}
