<?php
/**
 * LanguageManager — central authority for language state.
 *
 * @package IdiomatticWP\Core
 */

declare( strict_types=1 );

namespace IdiomatticWP\Core;

use IdiomatticWP\ValueObjects\LanguageCode;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;

class LanguageManager {

	private array $languageData    = [];
	private array $activeLanguages = [];   // LanguageCode[]
	private ?LanguageCode $defaultLanguage = null;
	private ?LanguageCode $currentLanguage = null;

	public function __construct() {
		// Load all supported language metadata
		$this->languageData = require IDIOMATTICWP_PATH . 'config/languages.php';

		// Load persisted active languages
		$stored = get_option( 'idiomatticwp_active_langs', [] );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $code ) {
				try {
					$this->activeLanguages[] = LanguageCode::from( (string) $code );
				} catch ( InvalidLanguageCodeException $e ) {
					// Skip invalid stored codes silently
				}
			}
		}

		// Load persisted default language
		$default = get_option( 'idiomatticwp_default_lang', '' );
		if ( '' !== $default ) {
			try {
				$this->defaultLanguage = LanguageCode::from( (string) $default );
			} catch ( InvalidLanguageCodeException $e ) {
				$this->defaultLanguage = null;
			}
		}
	}

	// ── Getters ───────────────────────────────────────────────────────────

	/**
	 * Return all active languages as LanguageCode objects.
	 *
	 * Passes through the `idiomatticwp_active_languages` filter so third-party
	 * code (e.g. integrations) can add or remove languages at runtime without
	 * touching the database.
	 *
	 * @return LanguageCode[]
	 */
	public function getActiveLanguages(): array {
		return apply_filters( 'idiomatticwp_active_languages', $this->activeLanguages );
	}

	/**
	 * Return the site's default language.
	 *
	 * Falls back to English ('en') when no default is configured, so callers
	 * never need to handle a null return.
	 * Passes through the `idiomatticwp_default_language` filter.
	 */
	public function getDefaultLanguage(): LanguageCode {
		$default = $this->defaultLanguage ?? LanguageCode::from( 'en' );
		return apply_filters( 'idiomatticwp_default_language', $default );
	}

	/**
	 * Return the language active for the current request.
	 *
	 * Set by LanguageHooks on each frontend request and by the admin language
	 * bar switch in the admin. Falls back to the default language before any
	 * language detection has run (e.g. during early boot or in CLI contexts).
	 */
	public function getCurrentLanguage(): LanguageCode {
		return $this->currentLanguage ?? $this->getDefaultLanguage();
	}

	// ── Setters ───────────────────────────────────────────────────────────

	/**
	 * Set the current request language and fire the language-switched action.
	 *
	 * Called by LanguageHooks::detectAndSetLanguage() once the URL strategy
	 * has identified the language for this request.
	 * Fires: `idiomatticwp_language_switched( LanguageCode $new, ?LanguageCode $previous )`
	 *
	 * @param LanguageCode $lang Detected language for this request.
	 */
	public function setCurrentLanguage( LanguageCode $lang ): void {
		$previous              = $this->currentLanguage;
		$this->currentLanguage = $lang;
		do_action( 'idiomatticwp_language_switched', $lang, $previous );
	}

	/**
	 * Replace the list of active languages and persist it to the database.
	 *
	 * Accepts both LanguageCode objects and raw BCP-47 strings. Invalid codes
	 * are silently skipped.
	 *
	 * @param string[]|LanguageCode[] $langs New set of active languages.
	 */
	public function setActiveLanguages( array $langs ): void {
		$codes = [];
		foreach ( $langs as $lang ) {
			if ( $lang instanceof LanguageCode ) {
				$codes[] = $lang;
			} else {
				try {
					$codes[] = LanguageCode::from( (string) $lang );
				} catch ( InvalidLanguageCodeException $e ) {
					// skip
				}
			}
		}
		$this->activeLanguages = $codes;
		update_option( 'idiomatticwp_active_langs', array_map( 'strval', $codes ) );
	}

	/**
	 * Set the site's default language and persist it to the database.
	 *
	 * @param LanguageCode $lang New default language.
	 */
	public function setDefaultLanguage( LanguageCode $lang ): void {
		$this->defaultLanguage = $lang;
		update_option( 'idiomatticwp_default_lang', (string) $lang );
	}

	// ── State checks ──────────────────────────────────────────────────────

	/**
	 * Return true when $lang is the site's default language.
	 *
	 * Used by URL strategies and the language bar to decide whether to add
	 * a language prefix to URLs (default language never gets a prefix).
	 */
	public function isDefault( LanguageCode $lang ): bool {
		return $lang->equals( $this->getDefaultLanguage() );
	}

	/**
	 * Return true when $lang is in the list of active languages.
	 *
	 * Used to validate language codes from URL parameters, user meta, and
	 * admin input before acting on them.
	 */
	public function isActive( LanguageCode $lang ): bool {
		foreach ( $this->getActiveLanguages() as $active ) {
			if ( $active->equals( $lang ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Language metadata ─────────────────────────────────────────────────

	/**
	 * Return the metadata array for a language.
	 *
	 * Data is sourced from config/languages.php for known codes. Unknown codes
	 * (e.g. custom-added languages) receive a synthesised fallback so callers
	 * never have to null-check.
	 *
	 * Returned keys: code, locale, name, native_name, rtl (bool), flag (string).
	 *
	 * @param LanguageCode $lang Language to look up.
	 * @return array{code: string, locale: string, name: string, native_name: string, rtl: bool, flag: string}
	 */
	public function getLanguageData( LanguageCode $lang ): array {
		$code = (string) $lang;
		if ( isset( $this->languageData[ $code ] ) ) {
			return array_merge( [ 'code' => $code ], $this->languageData[ $code ] );
		}
		// Fallback for custom or unknown codes — synthesise from LanguageCode.
		return [
			'code'        => $code,
			'locale'      => $lang->toLocale(),
			'name'        => $code,
			'native_name' => $code,
			'rtl'         => $lang->isRtl(),
			'flag'        => $lang->getBase(),
		];
	}

	/**
	 * Return the display name of a language in English (or its native name).
	 *
	 * @param LanguageCode $lang   Language to look up.
	 * @param bool         $native When true, return the native name (e.g. "Français").
	 *                             When false (default), return the English name (e.g. "French").
	 */
	public function getLanguageName( LanguageCode $lang, bool $native = false ): string {
		$data = $this->getLanguageData( $lang );
		return $native ? ( $data['native_name'] ?? (string) $lang ) : ( $data['name'] ?? (string) $lang );
	}

	/**
	 * Return the native name of a language (e.g. "Français" for 'fr').
	 *
	 * Alias for getLanguageName( $lang, true ), used by LanguageSwitcher and
	 * the REST API where the native name is always preferred.
	 */
	public function getNativeLanguageName( LanguageCode $lang ): string {
		return $this->getLanguageName( $lang, true );
	}

	/**
	 * Return all supported languages, including any custom ones added via Settings.
	 *
	 * Merges the built-in language list (config/languages.php) with custom
	 * language definitions stored in the `idiomatticwp_custom_languages` option.
	 * Custom definitions override built-in ones when codes collide.
	 *
	 * @return array<string, array> Map of BCP-47 code → language metadata.
	 */
	public function getAllSupportedLanguages(): array {
		$custom = get_option( 'idiomatticwp_custom_languages', [] );
		if ( ! is_array( $custom ) || empty( $custom ) ) {
			return $this->languageData;
		}
		return array_merge( $this->languageData, $custom );
	}
}
