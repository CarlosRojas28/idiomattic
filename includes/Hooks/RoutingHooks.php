<?php
/**
 * RoutingHooks — rewrites all front-end permalinks so they include
 * the correct language indicator, regardless of the active URL strategy.
 *
 * Key design principle — "post-aware localisation":
 *   When generating a URL for a specific post, we look up whether that post
 *   is a translation in our repository. If it is, we stamp the URL with the
 *   translation's target language rather than the visitor's current language.
 *   This ensures that links to translated posts always carry the right
 *   ?lang=XX parameter (ParameterStrategy) or language prefix
 *   (DirectoryStrategy/SubdomainStrategy).
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
use IdiomatticWP\Routing\SubdomainStrategy;
use IdiomatticWP\ValueObjects\LanguageCode;

class RoutingHooks implements HookRegistrarInterface {

	public function __construct(
		private UrlStrategyInterface           $urlStrategy,
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Post permalink filters — post ID is available, enabling post-aware localisation
		add_filter( 'the_permalink',  [ $this, 'filterPermalink'    ], 10, 2 );
		add_filter( 'page_link',      [ $this, 'filterPageLink'     ], 10, 2 );
		add_filter( 'post_type_link', [ $this, 'filterPostTypeLink' ], 10, 2 );
		add_filter( 'attachment_link',[ $this, 'filterAttachmentLink'], 10, 2 );

		// Filters where we don't have a post ID — fall back to current language
		add_filter( 'term_link',              [ $this, 'filterTermLink'    ], 10, 3 );
		add_filter( 'post_type_archive_link', [ $this, 'filterArchiveLink' ], 10, 2 );

		// Canonical redirect guard
		add_filter( 'redirect_canonical', [ $this, 'filterCanonicalRedirect' ], 10, 2 );

		// Strategy-specific setup
		if ( $this->urlStrategy instanceof DirectoryStrategy ) {
			add_action( 'init', [ $this->urlStrategy, 'registerRewriteRules' ], 1 );
		}

		if ( $this->urlStrategy instanceof SubdomainStrategy ) {
			add_action( 'admin_notices', [ $this, 'maybeCookieDomainNotice' ] );
		}
	}

	// ── Filter callbacks ──────────────────────────────────────────────────

	public function filterPermalink( string $permalink, int|\WP_Post $post = 0 ): string {
		$postId = $post instanceof \WP_Post ? $post->ID : (int) $post;
		return $this->localizeForPost( $permalink, $postId );
	}

	public function filterPageLink( string $link, int $postId ): string {
		return $this->localizeForPost( $link, $postId );
	}

	public function filterPostTypeLink( string $link, \WP_Post $post ): string {
		return $this->localizeForPost( $link, $post->ID );
	}

	public function filterAttachmentLink( string $link, int $postId ): string {
		return $this->localizeForPost( $link, $postId );
	}

	public function filterTermLink( string $link, \WP_Term $term, string $taxonomy ): string {
		return $this->localizeForCurrentLang( $link );
	}

	public function filterArchiveLink( string $link, string $postType ): string {
		return $this->localizeForCurrentLang( $link );
	}

	// ── Canonical redirect guard ──────────────────────────────────────────

	/**
	 * Prevent WordPress from stripping the language indicator via canonical redirect.
	 *
	 * ParameterStrategy: WP may redirect /page/?lang=es → /page/ (strips query param).
	 * DirectoryStrategy: WP may redirect /es/my-post/ → /my-post/ (strips prefix).
	 */
	public function filterCanonicalRedirect( string|false $redirectUrl, string $requestedUrl ): string|false {
		if ( false === $redirectUrl ) {
			return false;
		}

		$cleanRedirect  = $this->stripLangIndicator( $redirectUrl );
		$cleanRequested = $this->stripLangIndicator( $requestedUrl );

		if ( untrailingslashit( $cleanRedirect ) === untrailingslashit( $cleanRequested ) ) {
			return false; // Only difference is the lang indicator — suppress redirect
		}

		return $redirectUrl;
	}

	// ── Admin notices ─────────────────────────────────────────────────────

	public function maybeCookieDomainNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$configured = defined( 'COOKIE_DOMAIN' ) && str_starts_with( (string) COOKIE_DOMAIN, '.' );
		if ( ! $configured ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Idiomattic WP:</strong> %s</p></div>',
				esc_html__(
					'Subdomain URL mode is active but COOKIE_DOMAIN is not set in wp-config.php. '
					. 'Add: define(\'COOKIE_DOMAIN\', \'.yourdomain.com\'); to ensure login cookies work across language subdomains.',
					'idiomattic-wp'
				)
			);
		}
	}

	// ── Localisation helpers ──────────────────────────────────────────────

	/**
	 * Localise a URL for a specific post.
	 *
	 * Resolution order:
	 *   1. Post is a translation  → use that translation's target language.
	 *   2. Post is a source post  → use the current language (visitor's context).
	 *   3. No post ID available   → use the current language.
	 *
	 * This guarantees that get_permalink( $translatedPostId ) always returns
	 * a URL stamped with the correct language, regardless of who is visiting.
	 */
	private function localizeForPost( string $url, int $postId ): string {
		if ( $postId > 0 ) {
			$lang = $this->resolvePostLanguage( $postId );
			if ( $lang !== null ) {
				return $this->urlStrategy->buildUrl( $url, $lang );
			}
		}

		return $this->localizeForCurrentLang( $url );
	}

	/**
	 * Localise a URL using the visitor's current language.
	 * Used for permalinks where no post ID is available (terms, archives).
	 */
	private function localizeForCurrentLang( string $url ): string {
		$current = $this->languageManager->getCurrentLanguage();
		return $this->urlStrategy->buildUrl( $url, $current );
	}

	/**
	 * Resolve the language a given post should be displayed in.
	 *
	 * Returns:
	 *   - The translation's target language if the post is a translated post.
	 *   - The default language if the post is a source (original) post.
	 *   - null if the post has no translation relationship (e.g. attachment).
	 *
	 * Result is cached in a static array to avoid repeated DB lookups within
	 * the same request (common when rendering a post list).
	 */
	private function resolvePostLanguage( int $postId ): ?LanguageCode {
		static $cache = [];

		if ( array_key_exists( $postId, $cache ) ) {
			return $cache[ $postId ];
		}

		// Check if this post is itself a translation
		$record = $this->repository->findByTranslatedPost( $postId );
		if ( $record !== null ) {
			try {
				$lang = LanguageCode::from( $record['target_lang'] );
				$cache[ $postId ] = $lang;
				return $lang;
			} catch ( InvalidLanguageCodeException ) {
				// Corrupt data — fall through
			}
		}

		// Check if this post is a source post (has translations)
		$translations = $this->repository->findAllForSource( $postId );
		if ( ! empty( $translations ) ) {
			// It's a source post — it lives in the default language
			$lang = $this->languageManager->getDefaultLanguage();
			$cache[ $postId ] = $lang;
			return $lang;
		}

		// No translation relationship found — don't cache so future posts can re-check
		// (in case a translation is created later in the same request)
		$cache[ $postId ] = null;
		return null;
	}

	/**
	 * Strip the language indicator from a URL for canonical comparison.
	 */
	private function stripLangIndicator( string $url ): string {
		// Remove ?lang= query parameter
		$url = remove_query_arg( 'lang', $url );

		// Remove directory prefix /xx/ or /xx-XX/
		$url = preg_replace(
			'#(https?://[^/]+)/[a-z]{2}(-[A-Za-z]{2})?/#i',
			'$1/',
			$url
		) ?? $url;

		return $url;
	}
}
