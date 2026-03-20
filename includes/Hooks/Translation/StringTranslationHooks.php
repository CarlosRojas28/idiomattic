<?php
/**
 * StringTranslationHooks — loads translated .mo files and filters gettext calls.
 *
 * Two translation surfaces:
 *
 *   1. Plugin/theme .mo files — compiled by MoCompiler and stored in
 *      wp-content/uploads/idiomattic-wp/languages/{domain}-{lang}.mo.
 *      Loaded via load_textdomain() on 'override_load_textdomain'.
 *
 *   2. WordPress core strings — filtered via gettext hooks so that
 *      strings registered through idiomatticwp_register_string() are
 *      served from the idiomatticwp_strings DB table instead of the
 *      default WordPress MO file.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Strings\StringTranslator;

class StringTranslationHooks implements HookRegistrarInterface {

	/**
	 * Domains that have been successfully loaded for the current request.
	 * Prevents redundant disk reads.
	 *
	 * @var array<string, bool>
	 */
	private array $loadedDomains = [];

	public function __construct(
		private LanguageManager   $languageManager,
		private StringTranslator  $stringTranslator,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Intercept textdomain loading to inject our translated .mo files
		add_filter( 'override_load_textdomain', [ $this, 'maybeLoadCustomMo' ], 10, 3 );

		// Filter gettext to serve DB-stored string translations
		add_filter( 'gettext',          [ $this, 'filterGettext' ],          10, 3 );
		add_filter( 'gettext_with_context', [ $this, 'filterGettextContext' ], 10, 4 );
		add_filter( 'ngettext',         [ $this, 'filterNgettext' ],         10, 5 );

		// Set the WordPress locale to match the current language
		add_filter( 'locale', [ $this, 'filterLocale' ] );

		// Load custom .mo files for the default plugin textdomain
		add_action( 'plugins_loaded', [ $this, 'loadPluginStrings' ], 25 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Override WordPress's textdomain loading to inject our custom .mo file.
	 *
	 * Called by WordPress when load_plugin_textdomain() or load_textdomain()
	 * is invoked. Returning true tells WordPress "I handled this, don't load
	 * the default file".
	 *
	 * @param bool   $override  Whether to override. False by default.
	 * @param string $domain    Text domain.
	 * @param string $mofile    Path to the .mo file WP would load.
	 * @return bool True if we loaded our custom file, false to let WP handle it.
	 */
	public function maybeLoadCustomMo( bool $override, string $domain, string $mofile ): bool {
		$current = $this->languageManager->getCurrentLanguage();

		// Default language → let WordPress handle it normally
		if ( $this->languageManager->isDefault( $current ) ) {
			return false;
		}

		// Already loaded for this domain in this request
		if ( ! empty( $this->loadedDomains[ $domain ] ) ) {
			return true; // Still override to prevent WP loading its own file
		}

		$customMo = $this->getCustomMoPath( $domain, (string) $current );

		if ( $customMo && file_exists( $customMo ) ) {
			$loaded = load_textdomain( $domain, $customMo );
			$this->loadedDomains[ $domain ] = $loaded;
			return $loaded; // If loaded successfully, prevent WP loading its own file
		}

		return false; // Fall through to WordPress default
	}

	/**
	 * Filter gettext() calls to use DB-stored string translations.
	 *
	 * Only fires when a string was explicitly registered via
	 * idiomatticwp_register_string() — all other strings pass through.
	 *
	 * @param string $translation  Current translation (from MO or original).
	 * @param string $text         Original untranslated string.
	 * @param string $domain       Text domain.
	 * @return string
	 */
	public function filterGettext( string $translation, string $text, string $domain ): string {
		$current = $this->languageManager->getCurrentLanguage();

		if ( $this->languageManager->isDefault( $current ) ) {
			return $translation;
		}

		$dbTranslation = $this->stringTranslator->translate( $text, $domain, $current );

		// Only use DB translation if it exists and isn't the fallback (= original text)
		return ( $dbTranslation !== $text ) ? $dbTranslation : $translation;
	}

	/**
	 * Filter _x() / gettext_with_context() calls.
	 */
	public function filterGettextContext(
		string $translation,
		string $text,
		string $context,
		string $domain
	): string {
		$current = $this->languageManager->getCurrentLanguage();

		if ( $this->languageManager->isDefault( $current ) ) {
			return $translation;
		}

		$dbTranslation = $this->stringTranslator->translate( $text, $domain, $current, $context );

		return ( $dbTranslation !== $text ) ? $dbTranslation : $translation;
	}

	/**
	 * Filter ngettext() (plural forms) calls.
	 *
	 * We translate the singular and plural forms independently from the DB.
	 * The $number determines which form to display, but we handle that the
	 * same way WordPress would — by translating the winning form.
	 */
	public function filterNgettext(
		string $translation,
		string $single,
		string $plural,
		int    $number,
		string $domain
	): string {
		$current = $this->languageManager->getCurrentLanguage();

		if ( $this->languageManager->isDefault( $current ) ) {
			return $translation;
		}

		// Translate whichever form WordPress already picked
		$source        = ( $number === 1 ) ? $single : $plural;
		$dbTranslation = $this->stringTranslator->translate( $source, $domain, $current );

		return ( $dbTranslation !== $source ) ? $dbTranslation : $translation;
	}

	/**
	 * Set the WordPress locale to the current language's WP locale.
	 *
	 * This affects date formats, number formats, and any code that
	 * calls get_locale() directly.
	 */
	public function filterLocale( string $locale ): string {
		// Only filter on the frontend — leave admin locale unchanged
		if ( is_admin() ) {
			return $locale;
		}

		$current = $this->languageManager->getCurrentLanguage();

		if ( $this->languageManager->isDefault( $current ) ) {
			return $locale;
		}

		return $current->toLocale();
	}

	/**
	 * Load our own plugin's string translations for the current language.
	 * Called after all plugins are loaded so our domain is registered.
	 */
	public function loadPluginStrings(): void {
		$current = $this->languageManager->getCurrentLanguage();

		if ( $this->languageManager->isDefault( $current ) ) {
			return;
		}

		$domain   = 'idiomattic-wp';
		$customMo = $this->getCustomMoPath( $domain, (string) $current );

		if ( $customMo && file_exists( $customMo ) && empty( $this->loadedDomains[ $domain ] ) ) {
			$this->loadedDomains[ $domain ] = load_textdomain( $domain, $customMo );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Build the path to a custom .mo file for a domain and language.
	 *
	 * Path format: {uploads}/idiomattic-wp/languages/{domain}-{lang}.mo
	 */
	private function getCustomMoPath( string $domain, string $lang ): ?string {
		$uploadDir = wp_upload_dir( null, false );
		if ( empty( $uploadDir['basedir'] ) ) {
			return null;
		}

		$sanitizedDomain = sanitize_file_name( $domain );
		$sanitizedLang   = sanitize_file_name( $lang );

		return $uploadDir['basedir']
			. '/idiomattic-wp/languages/'
			. $sanitizedDomain . '-' . $sanitizedLang . '.mo';
	}
}
