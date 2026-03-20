<?php
/**
 * NavMenuHooks — translates WordPress navigation menus.
 *
 * Strategy:
 *   - For menus assigned to a theme location, replace all menu item URLs
 *     with their language-aware equivalents.
 *   - For menu items that link to a post/page with a translation in our DB,
 *     replace the URL with the translated post's permalink.
 *   - For items with no post-backed translation, apply URL strategy fallback.
 *   - The menu "Home" link (type=custom, url=home_url()) always points to
 *     the language-appropriate home URL.
 *
 * This is a pure filter approach — we never duplicate menu objects in the DB.
 * The source menu is shared; only the URLs are rewritten on the fly.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;

class NavMenuHooks implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager                $languageManager,
		private UrlStrategyInterface           $urlStrategy,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Rewrite all nav menu item URLs to the current language
		add_filter( 'wp_nav_menu_objects', [ $this, 'localizeMenuItems' ], 10, 2 );

		// Also filter the HTML title/aria-label of the nav element if needed
		add_filter( 'wp_page_menu_args', [ $this, 'localizePageMenuArgs' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Rewrite nav menu item URLs for the current language.
	 *
	 * @param \WP_Post[] $items Menu item post objects.
	 * @param \stdClass  $args  wp_nav_menu() arguments.
	 * @return \WP_Post[]
	 */
	public function localizeMenuItems( array $items, \stdClass $args ): array {
		$currentLang = $this->languageManager->getCurrentLanguage();
		$defaultLang = $this->languageManager->getDefaultLanguage();
		$homeUrl     = trailingslashit( home_url() );

		foreach ( $items as $item ) {
			if ( ! $item instanceof \WP_Post ) {
				continue;
			}

			$url = $item->url ?? '';

			// ── Post/page backed items ────────────────────────────────────
			if ( in_array( $item->type, [ 'post_type', 'post_type_archive' ], true ) ) {
				$postId = (int) ( $item->object_id ?? 0 );

				if ( $postId > 0 ) {
					// Look for a translation of this menu target
					$translationUrl = $this->getTranslatedPostUrl( $postId, $currentLang, $defaultLang );
					if ( $translationUrl !== null ) {
						$item->url = $translationUrl;
						continue;
					}
				}

				// No specific translation — apply URL strategy
				$item->url = $this->urlStrategy->buildUrl( $url, $currentLang );
				continue;
			}

			// ── Taxonomy / term items ─────────────────────────────────────
			if ( $item->type === 'taxonomy' ) {
				$item->url = $this->urlStrategy->buildUrl( $url, $currentLang );
				continue;
			}

			// ── Custom / home links ───────────────────────────────────────
			if ( $item->type === 'custom' ) {
				// Home link detection: exact match or starts with home_url
				if ( trailingslashit( $url ) === $homeUrl || $url === home_url() ) {
					$item->url = $this->urlStrategy->homeUrl( $currentLang );
					continue;
				}

				// Other custom links — only apply strategy if same domain
				if ( $this->isSameDomain( $url ) ) {
					$item->url = $this->urlStrategy->buildUrl( $url, $currentLang );
				}
				// External links are left untouched
			}
		}

		return $items;
	}

	/**
	 * Localize wp_page_menu() fallback (classic themes without nav menus).
	 */
	public function localizePageMenuArgs( array $args ): array {
		// wp_page_menu() uses page_link filter which RoutingHooks already handles.
		// Nothing extra needed here — just a hook point for future extensions.
		return $args;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Find the URL for the translated version of a post in the current language.
	 *
	 * Returns:
	 *   - Permalink of the translated post  if a direct translation exists.
	 *   - Permalink of the source post      if current lang is the default.
	 *   - null                              if no mapping found.
	 *
	 * @param int                                    $postId
	 * @param \IdiomatticWP\ValueObjects\LanguageCode $currentLang
	 * @param \IdiomatticWP\ValueObjects\LanguageCode $defaultLang
	 * @return string|null
	 */
	private function getTranslatedPostUrl( int $postId, $currentLang, $defaultLang ): ?string {
		$currentStr = (string) $currentLang;
		$defaultStr = (string) $defaultLang;

		// Determine whether $postId is a source or a translation
		$ownRecord = $this->repository->findByTranslatedPost( $postId );

		if ( $ownRecord ) {
			// $postId is itself a translated post
			$sourceId       = (int) $ownRecord['source_post_id'];
			$thisLang       = $ownRecord['target_lang'];

			if ( $currentStr === $thisLang ) {
				// Menu was built against this very translation — just use its permalink
				return get_permalink( $postId ) ?: null;
			}

			if ( $currentStr === $defaultStr ) {
				return get_permalink( $sourceId ) ?: null;
			}

			// Look for a sibling translation
			$sibling = $this->repository->findBySourceAndLang(
				$sourceId,
				$currentLang
			);
			if ( $sibling ) {
				return get_permalink( (int) $sibling['translated_post_id'] ) ?: null;
			}

			// No sibling — link back to source as best effort
			return get_permalink( $sourceId ) ?: null;
		}

		// $postId is a source post
		if ( $currentStr === $defaultStr ) {
			return get_permalink( $postId ) ?: null;
		}

		$translation = $this->repository->findBySourceAndLang( $postId, $currentLang );
		if ( $translation ) {
			return get_permalink( (int) $translation['translated_post_id'] ) ?: null;
		}

		return null; // Let caller apply URL strategy fallback
	}

	/**
	 * Check if a URL belongs to the current site (same domain/scheme).
	 */
	private function isSameDomain( string $url ): bool {
		$siteHost = (string) parse_url( home_url(), PHP_URL_HOST );
		$linkHost = (string) parse_url( $url, PHP_URL_HOST );

		return $linkHost === '' || $linkHost === $siteHost;
	}
}
