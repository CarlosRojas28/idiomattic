<?php
/**
 * HreflangOutput — generates <link rel="alternate" hreflang> tags for SEO.
 *
 * On singular pages, uses the actual translated post permalink when a
 * translation exists. On archives and other templates, builds a language-
 * aware variant of the current URL via the active UrlStrategy.
 *
 * Outputs `x-default` pointing to the default language version.
 *
 * @package IdiomatticWP\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Frontend;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;

class HreflangOutput {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
		private UrlStrategyInterface           $urlStrategy,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Output all hreflang <link> tags.
	 * Hooked into wp_head at priority 1.
	 */
	public function output(): void {
		if ( is_admin() ) {
			return;
		}

		$links = $this->buildLinks();

		/**
		 * Filter the hreflang links before output.
		 * SEO integrations (Yoast, RankMath) suppress this by returning [].
		 *
		 * @param array<string,string> $links   hreflang → href map.
		 * @param string               $baseUrl The current request's base URL.
		 */
		$links = apply_filters( 'idiomatticwp_hreflang_links', $links, $this->getBaseUrl() );

		foreach ( $links as $hreflang => $href ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s">' . PHP_EOL,
				esc_attr( $hreflang ),
				esc_url( $href )
			);
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Build the full hreflang map for the current page.
	 *
	 * @return array<string, string> hreflang code → absolute URL
	 */
	private function buildLinks(): array {
		$activeLangs = $this->languageManager->getActiveLanguages();
		$defaultLang = $this->languageManager->getDefaultLanguage();
		$baseUrl     = $this->getBaseUrl();
		$links       = [];

		// ── Singular post/page: use actual translated post permalinks ─────────
		$postId = is_singular() ? get_queried_object_id() : 0;

		if ( $postId > 0 ) {
			// Determine if this post is a source or a translation
			$sourceRecord = $this->repository->findByTranslatedPost( $postId );
			$sourceId     = $sourceRecord ? (int) $sourceRecord['source_post_id'] : $postId;

			// Gather all translations for this source post
			$translationsByLang = [];
			foreach ( $this->repository->findAllForSource( $sourceId ) as $row ) {
				$translationsByLang[ $row['target_lang'] ] = (int) $row['translated_post_id'];
			}

			// Source language URL
			$sourceLangStr = $sourceRecord
				? $sourceRecord['source_lang']
				: (string) $this->languageManager->getDefaultLanguage();

			foreach ( $activeLangs as $lang ) {
				$langStr = (string) $lang;

				if ( $langStr === $sourceLangStr ) {
					// Source language → permalink of the original post
					$url = (string) get_permalink( $sourceId );
				} elseif ( isset( $translationsByLang[ $langStr ] ) ) {
					// Translation exists → its actual permalink
					$url = (string) get_permalink( $translationsByLang[ $langStr ] );
				} else {
					// No translation yet → build URL via strategy (so x-default still works)
					$url = $this->urlStrategy->buildUrl( $baseUrl, $lang );
				}

				$links[ $langStr ] = $url;
			}

			// x-default points to the default language version
			$links['x-default'] = isset( $links[ (string) $defaultLang ] )
				? $links[ (string) $defaultLang ]
				: (string) get_permalink( $sourceId );

		} else {
			// ── Non-singular pages: build via URL strategy ────────────────────
			foreach ( $activeLangs as $lang ) {
				$links[ (string) $lang ] = $this->urlStrategy->buildUrl( $baseUrl, $lang );
			}

			$links['x-default'] = $this->urlStrategy->buildUrl( $baseUrl, $defaultLang );
		}

		return $links;
	}

	/**
	 * Get the clean base URL for the current request (no lang param).
	 */
	private function getBaseUrl(): string {
		global $wp;
		return home_url( remove_query_arg( 'lang', $wp->request ?? '' ) );
	}
}
