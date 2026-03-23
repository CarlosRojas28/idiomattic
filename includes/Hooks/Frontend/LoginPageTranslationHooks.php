<?php
/**
 * LoginPageTranslationHooks — switches the locale on wp-login.php based on
 * the visitor's explicit `lang` query parameter or cookie preference, and
 * renders a language-switcher strip below the login form.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;

class LoginPageTranslationHooks implements HookRegistrarInterface {

	public function __construct( private LanguageManager $lm ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Switch locale for the login page (priority 5 = before WP's own locale filter).
		add_filter( 'locale', [ $this, 'switchLoginLocale' ], 5 );
		// Render language-switcher links below the login form.
		add_action( 'login_footer', [ $this, 'renderLanguageSwitcher' ] );
	}

	// ── Filter / action callbacks ─────────────────────────────────────────

	/**
	 * Return the locale that should be used for the login page.
	 *
	 * Priority order:
	 *   1. `lang` query parameter (explicit visitor choice)
	 *   2. `idiomatticwp_visitor_lang` cookie (remembered preference)
	 *   3. Original locale (no change)
	 *
	 * @param string $locale Current locale string (e.g. "en_US").
	 * @return string Possibly-overridden locale string.
	 */
	public function switchLoginLocale( string $locale ): string {
		if ( ! $this->isLoginPage() ) {
			return $locale;
		}

		// 1. Explicit ?lang= query parameter takes priority.
		$langParam = sanitize_key( $_GET['lang'] ?? '' );
		if ( $langParam !== '' ) {
			$resolved = $this->resolveLocale( $langParam );
			if ( $resolved !== null ) {
				return $resolved;
			}
		}

		// 2. Cookie set by the browser-language redirect feature.
		$cookieLang = sanitize_key( $_COOKIE['idiomatticwp_visitor_lang'] ?? '' );
		if ( $cookieLang !== '' ) {
			$resolved = $this->resolveLocale( $cookieLang );
			if ( $resolved !== null ) {
				return $resolved;
			}
		}

		return $locale;
	}

	/**
	 * Echo a centered row of language links below the login form.
	 * Nothing is rendered when fewer than two active languages exist.
	 */
	public function renderLanguageSwitcher(): void {
		$activeLangs = $this->lm->getActiveLanguages();
		if ( count( $activeLangs ) < 2 ) {
			return;
		}

		$currentUrl = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$baseUrl    = remove_query_arg( 'lang', $currentUrl );

		echo '<div style="text-align:center;margin-top:16px;font-size:13px;">';
		foreach ( $activeLangs as $lang ) {
			$langStr  = (string) $lang;
			$langName = $this->lm->getNativeLanguageName( $lang );
			$url      = add_query_arg( 'lang', $langStr, $baseUrl );
			echo '<a href="' . esc_url( $url ) . '" style="margin:0 6px;">' . esc_html( $langName ) . '</a>';
		}
		echo '</div>';
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Detect whether the current request is for wp-login.php.
	 *
	 * $GLOBALS['pagenow'] is set by WordPress only after wp-settings.php has
	 * run; on the login page itself it is reliably set to 'wp-login.php'.
	 * The PHP_SELF check acts as a fallback for very early hook invocations.
	 */
	private function isLoginPage(): bool {
		if ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) {
			return true;
		}

		$phpSelf = $_SERVER['PHP_SELF'] ?? '';
		return $phpSelf !== '' && str_contains( $phpSelf, 'wp-login.php' );
	}

	/**
	 * Map a BCP-47 language code to the WordPress locale string for that
	 * language, or return null when the code is not among the active languages.
	 *
	 * @param string $langCode BCP-47 code (e.g. "fr", "pt-BR").
	 * @return string|null WP locale (e.g. "fr_FR") or null.
	 */
	private function resolveLocale( string $langCode ): ?string {
		$active = array_map( 'strval', $this->lm->getActiveLanguages() );
		if ( ! in_array( $langCode, $active, true ) ) {
			return null;
		}

		$allLangs = $this->lm->getAllSupportedLanguages();
		return $allLangs[ $langCode ]['locale'] ?? null;
	}
}
