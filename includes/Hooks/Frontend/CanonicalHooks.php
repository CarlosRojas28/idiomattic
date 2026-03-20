<?php
/**
 * CanonicalHooks — ensures the canonical URL is always the language-aware version.
 *
 * WordPress and SEO plugins (Yoast, RankMath, AIOSEO) emit a
 * <link rel="canonical"> that may not include the language indicator.
 * This class intercepts those outputs and rewrites the canonical URL
 * so it always matches the URL a visitor would actually land on.
 *
 * Also suppresses WordPress's built-in canonical redirect when the only
 * difference between the requested URL and the canonical is the language
 * indicator — preventing redirect loops with DirectoryStrategy.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;

class CanonicalHooks implements HookRegistrarInterface {

	public function __construct(
		private UrlStrategyInterface $urlStrategy,
		private LanguageManager $languageManager,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// WordPress core canonical URL
		add_filter( 'get_canonical_url', [ $this, 'filterCanonicalUrl' ], 20 );

		// Yoast SEO
		add_filter( 'wpseo_canonical', [ $this, 'filterCanonicalUrl' ], 20 );

		// RankMath
		add_filter( 'rank_math/frontend/canonical', [ $this, 'filterCanonicalUrl' ], 20 );

		// All in One SEO
		add_filter( 'aioseo_canonical_url', [ $this, 'filterCanonicalUrl' ], 20 );

		// Suppress WP canonical redirect when only the language indicator differs
		add_filter( 'redirect_canonical', [ $this, 'suppressLangRedirect' ], 10, 2 );

		// Paginated archives: canonical for /page/N/ must keep lang indicator
		add_filter( 'get_pagenum_link', [ $this, 'filterCanonicalUrl' ], 20 );
	}

	// ── Filter callbacks ──────────────────────────────────────────────────

	/**
	 * Inject the current language indicator into a canonical URL.
	 *
	 * @param string|false $url Canonical URL to filter (false = skip).
	 * @return string|false
	 */
	public function filterCanonicalUrl( string|false $url ): string|false {
		if ( ! $url || is_admin() ) {
			return $url;
		}

		$current = $this->languageManager->getCurrentLanguage();

		// Default language: strip any stale lang indicator
		return $this->urlStrategy->buildUrl( (string) $url, $current );
	}

	/**
	 * Prevent WordPress from redirecting away from a language-prefixed URL.
	 *
	 * Scenario: visitor hits /es/mi-post/, WP generates a canonical redirect
	 * to /mi-post/ — which would silently strip the language. We suppress that
	 * redirect when the normalised (lang-stripped) paths are identical.
	 *
	 * @param string|false $redirectUrl  The URL WordPress wants to redirect to.
	 * @param string       $requestedUrl The originally requested URL.
	 * @return string|false
	 */
	public function suppressLangRedirect( string|false $redirectUrl, string $requestedUrl ): string|false {
		if ( false === $redirectUrl ) {
			return false;
		}

		$cleanRedirect  = $this->stripAllLangIndicators( $redirectUrl );
		$cleanRequested = $this->stripAllLangIndicators( $requestedUrl );

		if ( untrailingslashit( $cleanRedirect ) === untrailingslashit( $cleanRequested ) ) {
			return false; // The only difference was the lang indicator — suppress
		}

		return $redirectUrl;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Strip all possible language indicators from a URL for comparison.
	 * Handles ?lang=xx (parameter), /xx/ (directory), and xx.domain (subdomain).
	 */
	private function stripAllLangIndicators( string $url ): string {
		// Parameter strategy: ?lang=xx
		$url = remove_query_arg( 'lang', $url );

		// Directory strategy: /xx/ or /xx-XX/ path segment
		$url = preg_replace(
			'#(https?://[^/]+)/[a-z]{2}(-[A-Z]{2})?(/|$)#',
			'$1$3',
			$url
		) ?? $url;

		// Subdomain strategy: xx.domain.com → domain.com
		// (only strip known 2-letter subdomains that aren't 'www')
		$url = preg_replace(
			'#(https?://)[a-z]{2}\.([^/]+)#',
			'$1$2',
			$url
		) ?? $url;

		return $url;
	}
}
