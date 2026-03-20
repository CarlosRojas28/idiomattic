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

	/** @return LanguageCode[] */
	public function getActiveLanguages(): array {
		return apply_filters( 'idiomatticwp_active_languages', $this->activeLanguages );
	}

	public function getDefaultLanguage(): LanguageCode {
		$default = $this->defaultLanguage ?? LanguageCode::from( 'en' );
		return apply_filters( 'idiomatticwp_default_language', $default );
	}

	public function getCurrentLanguage(): LanguageCode {
		return $this->currentLanguage ?? $this->getDefaultLanguage();
	}

	// ── Setters ───────────────────────────────────────────────────────────

	public function setCurrentLanguage( LanguageCode $lang ): void {
		$previous              = $this->currentLanguage;
		$this->currentLanguage = $lang;
		do_action( 'idiomatticwp_language_switched', $lang, $previous );
	}

	/** @param string[]|LanguageCode[] $langs */
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

	public function setDefaultLanguage( LanguageCode $lang ): void {
		$this->defaultLanguage = $lang;
		update_option( 'idiomatticwp_default_lang', (string) $lang );
	}

	// ── State checks ──────────────────────────────────────────────────────

	public function isDefault( LanguageCode $lang ): bool {
		return $lang->equals( $this->getDefaultLanguage() );
	}

	public function isActive( LanguageCode $lang ): bool {
		foreach ( $this->getActiveLanguages() as $active ) {
			if ( $active->equals( $lang ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Language metadata ─────────────────────────────────────────────────

	public function getLanguageData( LanguageCode $lang ): array {
		$code = (string) $lang;
		if ( isset( $this->languageData[ $code ] ) ) {
			return array_merge( [ 'code' => $code ], $this->languageData[ $code ] );
		}
		// Fallback for unknown codes
		return [
			'code'        => $code,
			'locale'      => $lang->toLocale(),
			'name'        => $code,
			'native_name' => $code,
			'rtl'         => $lang->isRtl(),
			'flag'        => $lang->getBase(),
		];
	}

	public function getLanguageName( LanguageCode $lang, bool $native = false ): string {
		$data = $this->getLanguageData( $lang );
		return $native ? ( $data['native_name'] ?? (string) $lang ) : ( $data['name'] ?? (string) $lang );
	}

	/**
	 * Returns the native name of the language.
	 * Alias for getLanguageName( $lang, true ) used by LanguageSwitcher and REST API.
	 */
	public function getNativeLanguageName( LanguageCode $lang ): string {
		return $this->getLanguageName( $lang, true );
	}

	public function getAllSupportedLanguages(): array {
		$custom = get_option( 'idiomatticwp_custom_languages', [] );
		if ( ! is_array( $custom ) || empty( $custom ) ) {
			return $this->languageData;
		}
		return array_merge( $this->languageData, $custom );
	}
}
