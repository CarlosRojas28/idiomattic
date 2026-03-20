<?php
/**
 * SubdomainStrategy — URL routing via per-language subdomains.
 *
 * Each non-default language is served from its own subdomain.
 * The default language is always served from the root domain.
 *
 * Examples (default = en, domain = example.com):
 *   English:  https://example.com/about/
 *   Spanish:  https://es.example.com/about/
 *   French:   https://fr.example.com/news/my-post/
 *
 * ── Detection ────────────────────────────────────────────────────────────────
 *   On `parse_request` we read HTTP_HOST, strip the root domain, and check
 *   whether the leftover subdomain segment matches an active language code.
 *
 * ── URL generation ───────────────────────────────────────────────────────────
 *   buildUrl() replaces the host component of an absolute URL with the
 *   language-specific subdomain host. Handles https/http and port numbers.
 *
 * ── No rewrite rules needed ───────────────────────────────────────────────────
 *   The web server routes all *.example.com traffic to the same WP install.
 *   WordPress never sees the subdomain part as a path — only as HTTP_HOST.
 *
 * ── Prerequisites ────────────────────────────────────────────────────────────
 *   - Wildcard DNS record: *.example.com → server IP
 *   - SSL certificate covering *.example.com
 *   - COOKIE_DOMAIN = '.example.com' in wp-config.php
 *
 * @package IdiomatticWP\Routing
 */

declare( strict_types=1 );

namespace IdiomatticWP\Routing;

use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;
use IdiomatticWP\ValueObjects\LanguageCode;

class SubdomainStrategy implements UrlStrategyInterface {

	/**
	 * Root domain without www or language subdomain, e.g. 'example.com'.
	 * Built lazily from home_url().
	 */
	private ?string $rootDomain = null;

	public function __construct( private LanguageManager $languageManager ) {}

	// ── UrlStrategyInterface ──────────────────────────────────────────────

	/**
	 * Detect language from the current HTTP_HOST.
	 *
	 * e.g. HTTP_HOST = 'es.example.com' → LanguageCode('es')
	 *      HTTP_HOST = 'example.com'    → default language
	 */
	public function detectLanguage( \WP $wp ): LanguageCode {
		$default = $this->languageManager->getDefaultLanguage();
		$host    = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$host    = preg_replace( '/:\d+$/', '', $host ); // strip port

		$subdomain = $this->extractSubdomain( $host );

		if ( $subdomain !== null ) {
			try {
				$detected = LanguageCode::from( $subdomain );
				if ( $this->languageManager->isActive( $detected ) && ! $detected->equals( $default ) ) {
					return apply_filters( 'idiomatticwp_detected_language', $detected, 'url' );
				}
			} catch ( InvalidLanguageCodeException $e ) {
				// Subdomain isn't a language code — fall through
			}
		}

		return apply_filters( 'idiomatticwp_detected_language', $default, 'url' );
	}

	/**
	 * Replace the host in $url with the subdomain for $lang.
	 *
	 * Default language → strip any language subdomain (back to root domain).
	 * Other languages  → replace/insert {lang}.{rootDomain}.
	 */
	public function buildUrl( string $url, LanguageCode $lang ): string {
		$original = $url;

		// Only operate on absolute URLs
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return $url;
		}

		$targetHost = $this->hostForLang( $lang );
		$currentHost = $parsed['host'] . ( ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '' );

		if ( $currentHost === $targetHost ) {
			return $url; // Already correct host — nothing to do
		}

		// Swap the host component
		$result = str_replace(
			$parsed['scheme'] . '://' . $currentHost,
			$parsed['scheme'] . '://' . $targetHost,
			$url
		);

		return apply_filters( 'idiomatticwp_url_for_language', $result, (string) $lang, $original );
	}

	/**
	 * Home URL for a language.
	 */
	public function homeUrl( LanguageCode $lang ): string {
		$scheme = is_ssl() ? 'https' : 'http';
		return trailingslashit( $scheme . '://' . $this->hostForLang( $lang ) );
	}

	/**
	 * No rewrite rules needed for subdomain routing.
	 */
	public function getRewriteRules(): array {
		return [];
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Returns the hostname (with optional port) for a given language.
	 *
	 * Default language → root domain (e.g. 'example.com')
	 * Other languages  → '{lang}.{rootDomain}' (e.g. 'es.example.com')
	 */
	private function hostForLang( LanguageCode $lang ): string {
		$root = $this->getRootDomain();

		if ( $this->languageManager->isDefault( $lang ) ) {
			return $root;
		}

		return (string) $lang . '.' . $root;
	}

	/**
	 * Extract the language subdomain from a fully-qualified host.
	 *
	 * 'es.example.com'   → 'es'
	 * 'fr.example.com'   → 'fr'
	 * 'example.com'      → null   (no subdomain)
	 * 'www.example.com'  → null   (www is not a language)
	 * 'sub.es.example.com' → null (nested subdomains unsupported)
	 */
	private function extractSubdomain( string $host ): ?string {
		$root = $this->getRootDomain();
		// Strip port from root if present
		$root = preg_replace( '/:\d+$/', '', $root );

		if ( ! str_ends_with( $host, '.' . $root ) && $host !== $root ) {
			return null; // Different domain entirely
		}

		$sub = rtrim( substr( $host, 0, strlen( $host ) - strlen( $root ) ), '.' );

		// Must be a single segment (no dots) and not 'www'
		if ( $sub === '' || $sub === 'www' || str_contains( $sub, '.' ) ) {
			return null;
		}

		return $sub;
	}

	/**
	 * Returns the root domain from home_url, stripping scheme, 'www.', and port.
	 *
	 * Example: 'https://www.example.com:8080' → 'example.com:8080'
	 * (We keep the port so URLs remain valid in dev environments.)
	 */
	private function getRootDomain(): string {
		if ( $this->rootDomain !== null ) {
			return $this->rootDomain;
		}

		$parsed = wp_parse_url( home_url() );
		$host   = strtolower( $parsed['host'] ?? 'localhost' );
		$port   = ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '';

		// Strip leading 'www.'
		$host = preg_replace( '/^www\./', '', $host );

		$this->rootDomain = $host . $port;
		return $this->rootDomain;
	}
}
