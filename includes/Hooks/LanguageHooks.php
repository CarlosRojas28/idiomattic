<?php
/**
 * LanguageHooks — wires language detection into WordPress.
 *
 * Detection priority (highest to lowest):
 *   1. URL strategy  (query param / directory prefix / subdomain)
 *   2. Cookie        (idiomatticwp_lang) — persisted from last visit
 *   3. Default language
 *
 * The URL strategy always wins over the cookie so that an explicit
 * language in the URL (e.g. /es/my-post/) is always honoured, even if
 * the visitor's cookie says a different language.
 *
 * @package IdiomatticWP\Hooks
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;
use IdiomatticWP\Routing\DirectoryStrategy;
use IdiomatticWP\ValueObjects\LanguageCode;

class LanguageHooks implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager                $languageManager,
		private UrlStrategyInterface           $urlStrategy,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Priority 1 — must fire before other plugins read the language.
		// DirectoryStrategy mutates $_SERVER['REQUEST_URI'] here to strip the
		// lang prefix BEFORE WordPress reads it to build $wp->request.
		add_action( 'parse_request', [ $this, 'detectAndSetLanguage' ], 1 );

		// Persist language choice in a cookie for the next request
		add_action( 'template_redirect', [ $this, 'persistLanguageCookie' ] );

		// Register home_url filter AFTER parse_request completes.
		//
		// WordPress calls home_url() inside WP::parse_request() to compute the
		// WP base path ($home_path) which it uses to strip the install subdirectory
		// from the request URI before matching rewrite rules.
		//
		// If filterHomeUrl is already active at that point, it returns a
		// language-prefixed home URL (e.g. http://site.test/fr/) because we have
		// already set the current language to 'fr' in detectAndSetLanguage().
		// WordPress then computes home_path = 'fr', builds regex /^fr/i, and
		// strips 'fr' from 'fr-sample-page/' via preg_replace, leaving
		// '-sample-page/' — which matches no rewrite rule → 404.
		//
		// Delaying to the 'wp' action (after WP::main() finishes parse_request,
		// query_posts, handle_404, register_globals) means the filter is active
		// for all template rendering (wp_head, the_content, menus, etc.) but
		// does not interfere with WordPress's internal URL routing.
		add_action( 'wp', function () {
			add_filter( 'home_url', [ $this, 'filterHomeUrl' ], 10, 2 );
		}, 1 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Detect language and set it as current.
	 *
	 * Detection priority (highest to lowest):
	 *   1. Explicit URL signal (?lang=, directory prefix, subdomain)
	 *   2. Post-based detection — if visiting a translated post with no URL
	 *      signal, infer the language from the translation record in the DB
	 *   3. Cookie fallback
	 *   4. Default language
	 */
	public function detectAndSetLanguage( \WP $wp ): void {
		// 1. Ask the URL strategy — always authoritative when it finds a signal.
		$fromUrl      = $this->urlStrategy->detectLanguage( $wp );
		$default      = $this->languageManager->getDefaultLanguage();
		$urlIsDefault = $fromUrl->equals( $default );

		$detected = $fromUrl;

		if ( $urlIsDefault && ! ( $this->urlStrategy instanceof DirectoryStrategy ) ) {

			// 2. Post-based detection: if the current request maps to a translated
			//    post, use that post's target language.
			//    This handles the case where ?lang= was omitted but the slug
			//    belongs to a translated post (e.g. /fr-hello-world/ without ?lang=fr).
			$postLang = $this->detectLanguageFromPost( $wp );
			if ( $postLang !== null ) {
				$detected = $postLang;
			} else {
				// 3. Cookie fallback
				$cookie = sanitize_key( $_COOKIE['idiomatticwp_lang'] ?? '' );
				if ( $cookie !== '' ) {
					try {
						$fromCookie = LanguageCode::from( $cookie );
						if ( $this->languageManager->isActive( $fromCookie ) ) {
							$detected = $fromCookie;
						}
					} catch ( InvalidLanguageCodeException $e ) {
						// Invalid cookie — ignore
					}
				}
			}
		}

		/**
		 * Allow third-party code to override the final language decision.
		 *
		 * @param LanguageCode $detected  The language that was detected.
		 * @param \WP          $wp        The WordPress request object.
		 * @param LanguageCode $fromUrl   The language from the URL signal.
		 */
		$detected = apply_filters( 'idiomatticwp_language_detection_order', $detected, $wp, $fromUrl );

		$this->languageManager->setCurrentLanguage( $detected );
	}

	/**
	 * Write the current language to a cookie so the next request has a
	 * sensible default even without an explicit URL signal.
	 *
	 * We skip this for DirectoryStrategy: the URL is always the definitive
	 * signal there, so a cookie would just add noise.
	 */
	public function persistLanguageCookie(): void {
		// Don't set cookies for directory strategy — the URL is the source of truth
		if ( $this->urlStrategy instanceof DirectoryStrategy ) {
			return;
		}

		$current  = (string) $this->languageManager->getCurrentLanguage();
		$existing = sanitize_key( $_COOKIE['idiomatticwp_lang'] ?? '' );

		if ( $current === $existing ) {
			return; // Already set — nothing to do
		}

		setcookie(
			'idiomatticwp_lang',
			$current,
			time() + YEAR_IN_SECONDS,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true // httponly
		);

		$_COOKIE['idiomatticwp_lang'] = $current;
	}

	/**
	 * Attempt to detect the language from the post being requested.
	 *
	 * At parse_request time WP hasn't run the query yet, so we resolve the
	 * post via get_page_by_path() using the request slug. If the resolved
	 * post is a translated post in our translations table, we return its
	 * target language.
	 *
	 * Returns null when no post-language mapping can be determined.
	 */
	private function detectLanguageFromPost( \WP $wp ): ?LanguageCode {
		// Extract the slug from the request path (last non-empty segment)
		$request = trim( $wp->request ?? '', '/' );
		if ( $request === '' ) {
			return null;
		}

		// Try to find the post by its slug across all public post types
		$slug      = basename( $request ); // handles nested paths like category/slug
		$postTypes = get_post_types( [ 'public' => true ] );

		foreach ( $postTypes as $postType ) {
			$post = get_page_by_path( $slug, OBJECT, $postType );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			// Check if this post is a translation in our table
			$record = $this->repository->findByTranslatedPost( $post->ID );
			if ( $record === null ) {
				continue;
			}

			try {
				$lang = LanguageCode::from( $record['target_lang'] );
				if ( $this->languageManager->isActive( $lang ) ) {
					return $lang;
				}
			} catch ( InvalidLanguageCodeException $e ) {
				// Corrupt record — skip
			}
		}

		return null;
	}

	/**
	 * Filter home_url() to include the language indicator.
	 * Skipped for admin so wp-admin links stay clean.
	 */
	public function filterHomeUrl( string $url, string $path ): string {
		if ( is_admin() ) {
			return $url;
		}

		$current = $this->languageManager->getCurrentLanguage();
		return $this->urlStrategy->buildUrl( $url, $current );
	}
}
