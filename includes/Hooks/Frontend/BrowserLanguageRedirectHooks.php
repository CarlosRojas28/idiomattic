<?php
/**
 * BrowserLanguageRedirectHooks — detects the visitor's preferred language
 * from the Accept-Language HTTP header and redirects to the matching URL.
 *
 * The redirect only fires when no explicit language indicator was present in
 * the URL (i.e. the URL strategy defaulted to the site's default language).
 * A cookie prevents repeated redirects on subsequent visits.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;
use IdiomatticWP\ValueObjects\LanguageCode;

class BrowserLanguageRedirectHooks implements HookRegistrarInterface {

	private const COOKIE_NAME = 'idiomatticwp_visitor_lang';
	private const COOKIE_TTL  = 30 * DAY_IN_SECONDS;

	public function __construct(
		private LanguageManager      $languageManager,
		private UrlStrategyInterface $urlStrategy,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Priority 1 — fires before template is loaded, before output starts.
		add_action( 'template_redirect', [ $this, 'maybeRedirect' ], 1 );
	}

	// ── Action callback ───────────────────────────────────────────────────

	/**
	 * Inspect the Accept-Language header and redirect to the best matching
	 * active language when the visitor has not already been routed explicitly.
	 */
	public function maybeRedirect(): void {

		// ── Guard clauses ─────────────────────────────────────────────────

		if ( is_admin() ) {
			return;
		}

		if ( wp_doing_ajax() ) {
			return;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Feature must be enabled (default = on).
		if ( ! get_option( 'idiomatticwp_browser_lang_detect', '1' ) ) {
			return;
		}

		$activeLangs = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		if ( empty( $activeLangs ) ) {
			return;
		}

		$currentLang = (string) $this->languageManager->getCurrentLanguage();
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();

		// ── Cookie check ──────────────────────────────────────────────────
		// If the visitor already has a cookie for an active language AND the
		// current request is already serving that language, nothing to do.
		$cookieLang = sanitize_key( $_COOKIE[ self::COOKIE_NAME ] ?? '' );

		if ( $cookieLang !== '' && in_array( $cookieLang, $activeLangs, true ) ) {
			// Cookie is set — only redirect if we're not yet on that language.
			if ( $cookieLang === $currentLang ) {
				return; // Already on the right language.
			}

			// We are on a different language than the cookie says. Check whether
			// the current URL has an explicit language signal (currentLang ≠ default
			// would be a URL signal). If the URL explicitly set a language, respect
			// it and update the cookie rather than overriding the visitor's choice.
			if ( $currentLang !== $defaultLang ) {
				// URL explicitly requested a non-default language — update cookie
				// to match and don't redirect.
				$this->setCookie( $currentLang );
				return;
			}

			// Current language is the default (= no explicit URL signal). The
			// cookie says the visitor prefers a different language — redirect.
			$best = $cookieLang;
		} else {
			// ── Parse Accept-Language header ──────────────────────────────

			// If there is an explicit non-default URL signal, honour it and just
			// persist a cookie rather than redirecting.
			if ( $currentLang !== $defaultLang ) {
				$this->setCookie( $currentLang );
				return;
			}

			$header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
			if ( $header === '' ) {
				return;
			}

			$preferred = $this->parseBrowserLanguages( $header );
			if ( empty( $preferred ) ) {
				return;
			}

			$best = $this->matchLanguage( $preferred, $activeLangs );
			if ( $best === null ) {
				return;
			}
		}

		// ── No redirect needed ────────────────────────────────────────────

		if ( $best === $currentLang ) {
			$this->setCookie( $currentLang );
			return;
		}

		// ── Build target URL and redirect ─────────────────────────────────

		try {
			$targetLangCode = LanguageCode::from( $best );
		} catch ( InvalidLanguageCodeException $e ) {
			return;
		}

		$currentUrl = home_url( $_SERVER['REQUEST_URI'] ?? '/' );
		$targetUrl  = $this->urlStrategy->buildUrl( $currentUrl, $targetLangCode );

		// Avoid redirecting to the same URL (can happen with subdomain strategy
		// when the visitor is already on the right subdomain).
		if ( $targetUrl === $currentUrl ) {
			$this->setCookie( $best );
			return;
		}

		// Set the cookie before redirecting so it is sent in the response.
		$this->setCookie( $best );

		wp_redirect( $targetUrl, 302 );
		exit;
	}

	// ── Public helpers ────────────────────────────────────────────────────

	/**
	 * Parse an Accept-Language header and return an ordered list of BCP-47
	 * language tags, highest quality first.
	 *
	 * Example input : "fr-CH, fr;q=0.9, en;q=0.8, de;q=0.7, *;q=0.5"
	 * Example output: ["fr-CH", "fr", "en", "de"]  (* is dropped)
	 *
	 * @param string $header Raw Accept-Language header value.
	 * @return string[]      Ordered list of BCP-47 codes (wildcards excluded).
	 */
	public function parseBrowserLanguages( string $header ): array {
		$tags = [];

		foreach ( explode( ',', $header ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}

			// Split "fr-CH;q=0.9" into tag and quality.
			$segments = explode( ';', $part, 2 );
			$tag      = trim( $segments[0] );
			$quality  = 1.0;

			if ( isset( $segments[1] ) ) {
				$qPart = trim( $segments[1] );
				if ( strncasecmp( $qPart, 'q=', 2 ) === 0 ) {
					$quality = (float) substr( $qPart, 2 );
				}
			}

			// Wildcard is not useful for language matching.
			if ( $tag === '*' || $tag === '' ) {
				continue;
			}

			// Normalise to BCP-47: primary subtag lowercase, region uppercase.
			// e.g. "ZH-cn" → "zh-CN", "EN" → "en"
			$tag = $this->normaliseLangTag( $tag );

			$tags[] = [ 'tag' => $tag, 'q' => $quality ];
		}

		// Sort by quality descending, then by original order (stable-ish).
		usort( $tags, fn( $a, $b ) => $b['q'] <=> $a['q'] );

		return array_values( array_unique( array_column( $tags, 'tag' ) ) );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Normalise a language tag to BCP-47 form.
	 * Primary tag → lowercase; region subtag → UPPERCASE.
	 * e.g. "zh-CN", "fr-CH", "en"
	 */
	private function normaliseLangTag( string $tag ): string {
		$parts = explode( '-', $tag, 2 );
		$primary = strtolower( $parts[0] );

		if ( isset( $parts[1] ) ) {
			return $primary . '-' . strtoupper( $parts[1] );
		}

		return $primary;
	}

	/**
	 * Find the best active language for the ordered list of browser preferences.
	 *
	 * Matching strategy (highest priority first):
	 *   1. Exact match      — "fr-CH" → "fr-CH"
	 *   2. Primary subtag   — "fr-CH" → "fr"
	 *   3. Region prefix    — "fr"    → "fr-CH" (first active lang with same primary)
	 *
	 * @param string[] $preferred  Ordered list of BCP-47 tags from the browser.
	 * @param string[] $active     Active language codes from LanguageManager.
	 * @return string|null         Best matching active language, or null.
	 */
	private function matchLanguage( array $preferred, array $active ): ?string {
		foreach ( $preferred as $tag ) {
			// 1. Exact match.
			if ( in_array( $tag, $active, true ) ) {
				return $tag;
			}

			// 2. Primary subtag match (strip region from browser tag).
			$primary = explode( '-', $tag, 2 )[0];
			if ( in_array( $primary, $active, true ) ) {
				return $primary;
			}

			// 3. Region prefix match (browser wants "fr", active has "fr-CH").
			foreach ( $active as $activeLang ) {
				$activePrimary = explode( '-', $activeLang, 2 )[0];
				if ( $activePrimary === $primary ) {
					return $activeLang;
				}
			}
		}

		return null;
	}

	/**
	 * Write the visitor language cookie.
	 *
	 * @param string $lang BCP-47 language code.
	 */
	private function setCookie( string $lang ): void {
		$expires = time() + self::COOKIE_TTL;

		// setcookie() with SameSite=Lax requires PHP 7.3+ (array options form).
		setcookie(
			self::COOKIE_NAME,
			$lang,
			[
				'expires'  => $expires,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		// Reflect in the superglobal so subsequent code in this request sees it.
		$_COOKIE[ self::COOKIE_NAME ] = $lang;
	}
}
