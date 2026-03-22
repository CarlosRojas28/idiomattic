<?php
/**
 * FrontendAssetHooks — enqueues the language switcher stylesheet on the frontend.
 *
 * Registers a minimal CSS file for the language switcher widget and shortcode.
 * Also injects a small <style> block with RTL overrides when the current
 * language is right-to-left, so no separate RTL stylesheet is needed.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;

class FrontendAssetHooks implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager $languageManager,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueStyles' ] );
		add_action( 'wp_head',            [ $this, 'inlineRtlStyles' ], 5 );
		add_action( 'wp_head',            [ $this, 'inlineCurrentLangAttr' ], 1 );

		// Filter <html lang=""> and dir="" attributes
		add_filter( 'language_attributes', [ $this, 'filterHtmlLangAttr' ], 20 );
		// Also filter the locale used by WordPress date/number formatters
		add_filter( 'locale',             [ $this, 'filterLocale' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Enqueue the language switcher stylesheet.
	 * Only loads when at least one active (non-default) language exists.
	 */
	public function enqueueStyles(): void {
		$activeLanguages = $this->languageManager->getActiveLanguages();
		if ( count( $activeLanguages ) <= 1 ) {
			return; // Single-language site — nothing to switch
		}

		wp_enqueue_style(
			'idiomatticwp-switcher',
			IDIOMATTICWP_ASSETS_URL . 'css/language-switcher.css',
			[],
			IDIOMATTICWP_VERSION
		);
	}

	/**
	 * Inject RTL overrides inline when the current language is RTL.
	 * Avoids loading a separate stylesheet for a small set of properties.
	 */
	public function inlineRtlStyles(): void {
		$current = $this->languageManager->getCurrentLanguage();
		if ( ! $current->isRtl() ) {
			return;
		}

		echo '<style id="idiomatticwp-rtl">'
			/* Base direction */
			. '.idiomatticwp-switcher { direction: rtl; }'
			/* List variant: mirror gap/padding */
			. '.idiomatticwp-switcher--list { padding-right: 0; }'
			/* Flags-only: already row layout, direction handles it */
			. '.idiomatticwp-switcher--flags { direction: rtl; }'
			/* Floating widget: anchor to bottom-left in RTL contexts */
			. '.idiomatticwp-switcher--floating { left: 24px; right: auto; }'
			. '.idiomatticwp-float-panel { right: auto; left: 0; transform-origin: bottom left; }'
			/* Admin language bar (if shown on frontend) */
			. '#wpadminbar .idiomatticwp-lang-bar { direction: rtl; }'
			. '</style>' . PHP_EOL;
	}

	/**
	 * Set the lang attribute on <html> to match the current language.
	 *
	 * WordPress sets this via the `language_attributes` filter, but some
	 * themes bypass it. We add a small <meta> as a belt-and-suspenders fallback.
	 */
	public function inlineCurrentLangAttr(): void {
		$current = $this->languageManager->getCurrentLanguage();
		if ( $this->languageManager->isDefault( $current ) ) {
			return; // WordPress already handles the default lang attribute
		}

		printf(
			'<meta name="content-language" content="%s">' . PHP_EOL,
			esc_attr( (string) $current )
		);
	}

	/**
	 * Replace lang="" and dir="" in the <html> opening tag.
	 *
	 * WordPress core outputs e.g. lang="en-US" dir="ltr" via get_language_attributes().
	 * We intercept the final string and swap in the current language's locale + RTL flag.
	 *
	 * @param string $output  The language attributes string built by WordPress.
	 * @return string
	 */
	public function filterHtmlLangAttr( string $output ): string {
		$current = $this->languageManager->getCurrentLanguage();

		if ( $this->languageManager->isDefault( $current ) ) {
			return $output; // No change needed for the default language
		}

		$locale = $current->toLocale(); // e.g. 'es_ES', 'fr_FR'
		// Convert WP locale to BCP-47 lang tag: es_ES → es-ES
		$bcp47  = str_replace( '_', '-', $locale );
		$dir    = $current->isRtl() ? 'rtl' : 'ltr';

		// Replace lang="..." regardless of what WordPress put in
		$output = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $bcp47 ) . '"', $output );

		// Replace or add dir="..."
		if ( preg_match( '/dir="[^"]*"/', $output ) ) {
			$output = preg_replace( '/dir="[^"]*"/', 'dir="' . esc_attr( $dir ) . '"', $output );
		} else {
			$output .= ' dir="' . esc_attr( $dir ) . '"';
		}

		return (string) $output;
	}

	/**
	 * Override WordPress's locale to match the current language on the frontend.
	 * This affects date_i18n(), number_format_i18n(), and similar WP functions.
	 */
	public function filterLocale( string $locale ): string {
		if ( is_admin() ) {
			return $locale; // Never change admin locale
		}

		$current = $this->languageManager->getCurrentLanguage();
		if ( $this->languageManager->isDefault( $current ) ) {
			return $locale;
		}

		return $current->toLocale();
	}
}
