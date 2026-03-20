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
 *   On `parse_request` (priority 1) we inspect $wp->request. If the first
 *   path segment is a known active non-default language code we:
 *     1. Record the detected language.
 *     2. Strip the prefix from $wp->request so WP resolves the remainder.
 *
 * ── URL generation ───────────────────────────────────────────────────────────
 *   buildUrl() injects /{lang}/ after the base path. Handles:
 *     - Trailing slash setting (from WP → Settings → Permalinks)
 *     - WordPress installed in a subdirectory (home_url ≠ site_url root)
 *     - Query strings — lang prefix goes before the ? separator
 *     - URLs that already contain the prefix (idempotent)
 *
 * ── Rewrite rules ────────────────────────────────────────────────────────────
 *   getRewriteRules() and registerRewriteRules() add high-priority rules
 *   that map ^{lang}/(.*) → index.php?{original_query}&lang={lang}
 *   so that WordPress correctly resolves prefixed permalinks.
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
	 * Detect the current language from $wp->request path.
	 *
	 * Called on `parse_request` (priority 1) so that WordPress processes
	 * the cleaned path rather than the prefixed one.
	 */
	public function detectLanguage( \WP $wp ): LanguageCode {
		$request = ltrim( $wp->request ?? '', '/' );
		$default = $this->languageManager->getDefaultLanguage();

		foreach ( $this->getActivePrefixes() as $code ) {
			// Match: "es", "es/", "es/path/...", but NOT "esc" or "essential"
			if (
				$request === $code
				|| str_starts_with( $request, $code . '/' )
			) {
				// Strip the language prefix so WP resolves the rest normally
				$stripped = ltrim( substr( $request, strlen( $code ) ), '/' );
				$wp->request = $stripped;

				try {
					$detected = LanguageCode::from( $code );
					return apply_filters( 'idiomatticwp_detected_language', $detected, 'url' );
				} catch ( InvalidLanguageCodeException $e ) {
					// Shouldn't happen — codes come from active languages
				}
			}
		}

		// No prefix found — default language
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
	 */
	public function homeUrl( LanguageCode $lang ): string {
		if ( $this->languageManager->isDefault( $lang ) ) {
			return trailingslashit( home_url( '/' ) );
		}
		return trailingslashit( home_url( '/' . (string) $lang . '/' ) );
	}

	/**
	 * Returns rewrite rules to be prepended to the WordPress ruleset.
	 *
	 * We need rules like:
	 *   ^es/(.*)$  → index.php?lang=es&$matches[1]
	 *
	 * These fire BEFORE all other rules so /{lang}/post-slug resolves
	 * to the correct post with the lang variable set.
	 */
	public function getRewriteRules(): array {
		$rules = [];

		foreach ( $this->getActivePrefixes() as $code ) {
			// Match /{lang}/anything or just /{lang}
			$rules[ '^' . preg_quote( $code, '#' ) . '/(.*)$' ] =
				'index.php?lang=' . $code . '&$matches[1]';

			$rules[ '^' . preg_quote( $code, '#' ) . '$' ] =
				'index.php?lang=' . $code;
		}

		return $rules;
	}

	/**
	 * Register rewrite rules with WordPress.
	 * Hook into 'rewrite_rules_array' to prepend our rules.
	 *
	 * Called from RoutingHooks when this strategy is active.
	 */
	public function registerRewriteRules(): void {
		add_filter( 'rewrite_rules_array', [ $this, 'prependRules' ] );

		// Also add 'lang' as a public query variable
		add_filter( 'query_vars', static function ( array $vars ): array {
			if ( ! in_array( 'lang', $vars, true ) ) {
				$vars[] = 'lang';
			}
			return $vars;
		} );
	}

	/**
	 * Prepend our language rules at the top of the rewrite array.
	 *
	 * @param array<string,string> $rules Existing WordPress rewrite rules.
	 * @return array<string,string>
	 */
	public function prependRules( array $rules ): array {
		return array_merge( $this->getRewriteRules(), $rules );
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
		$home = untrailingslashit( home_url() );
		$parsed = wp_parse_url( $home );
		$path = $parsed['path'] ?? '';
		return rtrim( $path, '/' );
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
