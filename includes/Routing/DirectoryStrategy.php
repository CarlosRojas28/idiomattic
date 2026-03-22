<?php
/**
 * DirectoryStrategy — URL routing via /{lang}/ subdirectories.
 *
 * The default language is never prefixed. All other active languages
 * receive a /{lang}/ prefix immediately after the site base path.
 *
 * Examples (default = en):
 *   English:  https://example.com/about/
 *   Spanish:  https://example.com/es/about/
 *   French:   https://example.com/fr/news/my-post/
 *
 * ── Detection ────────────────────────────────────────────────────────────────
 *   On `parse_request` (priority 1), BEFORE WordPress reads $_SERVER['REQUEST_URI']
 *   to set $wp->request, we strip the /{lang}/ prefix from $_SERVER['REQUEST_URI']
 *   in-place. WordPress then sees the stripped path and matches it against its own
 *   existing rewrite rules normally — no custom rules required.
 *
 * ── URL generation ───────────────────────────────────────────────────────────
 *   buildUrl() injects /{lang}/ after the base path. Handles:
 *     - Trailing slash setting (from WP → Settings → Permalinks)
 *     - WordPress installed in a subdirectory (home_url ≠ site_url root)
 *     - Query strings — lang prefix goes before the ? separator
 *     - URLs that already contain the prefix (idempotent)
 *
 * @package IdiomatticWP\Routing
 */

declare( strict_types=1 );

namespace IdiomatticWP\Routing;

use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;
use IdiomatticWP\ValueObjects\LanguageCode;

class DirectoryStrategy implements UrlStrategyInterface {

	/**
	 * Cached set of active non-default language codes as plain strings.
	 * Built lazily on first use.
	 *
	 * @var string[]|null
	 */
	private ?array $prefixes = null;

	public function __construct( private LanguageManager $languageManager ) {}

	// ── UrlStrategyInterface ──────────────────────────────────────────────

	/**
	 * Detect the current language from the request URL path.
	 *
	 * The `parse_request` action fires at the very top of WP::parse_request(),
	 * BEFORE WordPress sets $wp->request from $_SERVER['REQUEST_URI'].
	 * Therefore we read from and write back to $_SERVER['REQUEST_URI'] directly,
	 * so that WordPress sees the already-stripped path when it processes the
	 * rewrite rules immediately after the action returns.
	 *
	 * E.g. /fr/my-post/ → strip "fr/" → $_SERVER['REQUEST_URI'] = /my-post/
	 * WordPress then matches /my-post/ against its normal rewrite rules.
	 */
	public function detectLanguage( \WP $wp ): LanguageCode {
		$uri      = $_SERVER['REQUEST_URI'] ?? '/';
		$basePath = $this->getBasePath();

		// Isolate the path component (without query string or fragment).
		$path = (string) ( parse_url( $uri, PHP_URL_PATH ) ?? '/' );

		// Remove the WP base path (non-empty when WP lives in a subdirectory).
		$relative = $path;
		if ( $basePath !== '' && str_starts_with( $path, $basePath ) ) {
			$relative = substr( $path, strlen( $basePath ) );
		}
		$relative = ltrim( $relative, '/' );

		$default = $this->languageManager->getDefaultLanguage();

		foreach ( $this->getActivePrefixes() as $code ) {
			// Match "fr", "fr/", "fr/my-post/", but NOT "french" or "framework"
			if ( $relative === $code || str_starts_with( $relative, $code . '/' ) ) {
				// Strip the language prefix so WordPress resolves the remainder
				// through its normal rewrite rules.
				$stripped = ltrim( substr( $relative, strlen( $code ) ), '/' );
				$newPath  = $basePath . '/' . $stripped;
				$newPath  = '/' . ltrim( $newPath, '/' );

				$queryString = parse_url( $uri, PHP_URL_QUERY );
				$_SERVER['REQUEST_URI'] = $newPath . ( $queryString ? '?' . $queryString : '' );

				try {
					$detected = LanguageCode::from( $code );
					return apply_filters( 'idiomatticwp_detected_language', $detected, 'url' );
				} catch ( InvalidLanguageCodeException $e ) {
					// Corrupt active-languages data — fall through to default
				}
			}
		}

		// No prefix matched — default language
		return apply_filters( 'idiomatticwp_detected_language', $default, 'url' );
	}

	/**
	 * Inject the /{lang}/ prefix into a URL for a given language.
	 * Idempotent: already-prefixed URLs are not double-prefixed.
	 */
	public function buildUrl( string $url, LanguageCode $lang ): string {
		$original = $url;

		// Default language never gets a prefix
		if ( $this->languageManager->isDefault( $lang ) ) {
			$url = $this->stripLangPrefix( $url );
			return apply_filters( 'idiomatticwp_url_for_language', $url, (string) $lang, $original );
		}

		$code     = (string) $lang;
		$basePath = $this->getBasePath(); // e.g. '' or '/subdir'

		// Separate query string from path+fragment
		$query    = '';
		$fragment = '';
		if ( str_contains( $url, '#' ) ) {
			[ $url, $fragment ] = explode( '#', $url, 2 );
			$fragment = '#' . $fragment;
		}
		if ( str_contains( $url, '?' ) ) {
			[ $url, $query ] = explode( '?', $url, 2 );
			$query = '?' . $query;
		}

		// Strip any existing language prefix before adding the new one
		$url = $this->stripLangPrefix( $url );

		// Find the insertion point: right after the base path
		$scheme       = '';
		$host         = '';
		$pathWithBase = $url;

		// Handle absolute URLs (https://example.com/...)
		if ( preg_match( '#^(https?://)([^/]+)(.*)$#i', $url, $m ) ) {
			$scheme       = $m[1];
			$host         = $m[2];
			$pathWithBase = $m[3]; // e.g. '/subdir/about/' or '/'
		}

		// Remove the base path prefix to get the relative path
		$relativePath = $pathWithBase;
		if ( $basePath !== '' && str_starts_with( $pathWithBase, $basePath ) ) {
			$relativePath = substr( $pathWithBase, strlen( $basePath ) );
		}

		// Build new path: /basepath/lang/relative
		$newPath = $basePath . '/' . $code . '/' . ltrim( $relativePath, '/' );
		$newPath = '/' . ltrim( $newPath, '/' ); // ensure leading slash

		// Re-assemble
		$result = $scheme . $host . $newPath . $query . $fragment;

		return apply_filters( 'idiomatticwp_url_for_language', $result, $code, $original );
	}

	/**
	 * Home URL for a language.
	 * Default language → site home. Others → site home + /lang/
	 *
	 * Uses get_option('home') instead of home_url() to avoid the 'home_url'
	 * filter (registered in LanguageHooks::filterHomeUrl) from silently
	 * rewriting the path based on the *current* visitor language rather than
	 * the $lang argument.  For example, when generating hreflang alternates,
	 * homeUrl('fr') is called while the current language is 'en'; if we used
	 * home_url('/fr/') the filter would see the URL as already-prefixed for 'en'
	 * (the default), strip the '/fr/' prefix, and return the bare home URL —
	 * producing a wrong link.
	 */
	public function homeUrl( LanguageCode $lang ): string {
		// Build the raw home URL from the option, bypassing the home_url filter.
		$base = untrailingslashit( (string) get_option( 'home' ) );

		if ( $this->languageManager->isDefault( $lang ) ) {
			return trailingslashit( $base );
		}

		return trailingslashit( $base . '/' . (string) $lang );
	}

	/**
	 * No custom rewrite rules needed for the directory strategy.
	 *
	 * Language detection works by stripping the /{lang}/ prefix from
	 * $_SERVER['REQUEST_URI'] in detectLanguage() (called on `parse_request`
	 * priority 1, before WordPress reads the URI). WordPress then matches the
	 * stripped path against its own existing rewrite rules normally.
	 *
	 * @return array<string,string>
	 */
	public function getRewriteRules(): array {
		return [];
	}

	/**
	 * Register rewrite rules with WordPress.
	 * Called from RoutingHooks when this strategy is active.
	 */
	public function registerRewriteRules(): void {
		// No-op: detection via $_SERVER['REQUEST_URI'] mutation requires no
		// additional WordPress rewrite rules.
	}

	// ── Capability check ─────────────────────────────────────────────────

	/**
	 * Return a list of human-readable requirement errors.
	 * An empty array means the strategy is fully functional.
	 *
	 * @return string[]
	 */
	public function checkCapabilities(): array {
		$issues = [];

		// Directory strategy requires pretty permalinks.
		// Plain permalinks (?p=123) cannot carry a language directory prefix.
		if ( get_option( 'permalink_structure' ) === '' ) {
			$issues[] = __( 'Directory URL mode requires pretty permalinks. Go to Settings → Permalinks and choose any option other than "Plain".', 'idiomattic-wp' );
		}

		return $issues;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Return all active non-default language codes as plain strings.
	 * The default language never gets a URL prefix.
	 *
	 * @return string[]
	 */
	private function getActivePrefixes(): array {
		if ( $this->prefixes !== null ) {
			return $this->prefixes;
		}

		$default  = $this->languageManager->getDefaultLanguage();
		$this->prefixes = [];

		foreach ( $this->languageManager->getActiveLanguages() as $lang ) {
			if ( ! $lang->equals( $default ) ) {
				$this->prefixes[] = (string) $lang;
			}
		}

		return $this->prefixes;
	}

	/**
	 * Returns the base path of the WordPress installation relative to the
	 * domain root. Empty string if WP is at the domain root.
	 *
	 * Example: WP at https://example.com/blog/ → '/blog'
	 */
	private function getBasePath(): string {
		// Use get_option() instead of home_url() to avoid triggering the
		// 'home_url' filter, which calls buildUrl(), which calls this method
		// again — causing infinite recursion and ERR_CONNECTION_RESET.
		$home   = untrailingslashit( (string) get_option( 'home' ) );
		$parsed = wp_parse_url( $home );
		$path   = $parsed['path'] ?? '';
		return rtrim( (string) $path, '/' );
	}

	/**
	 * Remove any existing /{lang}/ prefix from a URL so we can
	 * re-apply the correct one without doubling.
	 */
	private function stripLangPrefix( string $url ): string {
		foreach ( $this->getActivePrefixes() as $code ) {
			$pattern = '#(https?://[^/]+(?:/[^/]+)*)/' . preg_quote( $code, '#' ) . '(/)#i';
			if ( preg_match( $pattern, $url ) ) {
				$url = preg_replace( $pattern, '$1$2', $url );
				break;
			}
		}
		return $url;
	}
}
