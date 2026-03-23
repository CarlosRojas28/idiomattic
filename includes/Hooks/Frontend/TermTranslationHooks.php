<?php
/**
 * TermTranslationHooks — frontend hooks for taxonomy term translation.
 *
 * Intercepts WordPress term-fetching calls and replaces term name, slug,
 * and description with translated values for the active language.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TermTranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class TermTranslationHooks implements HookRegistrarInterface {

	public function __construct(
		private TermTranslationRepositoryInterface $repo,
		private LanguageManager $languageManager,
	) {}

	// ── register ──────────────────────────────────────────────────────────

	public function register(): void {
		add_filter( 'get_term',  [ $this, 'translateTerm' ],  10, 2 );
		add_filter( 'get_terms', [ $this, 'translateTerms' ], 10, 2 );
	}

	// ── filters ───────────────────────────────────────────────────────────

	/**
	 * Translate a single term for the current language.
	 *
	 * @param mixed  $term     The term object (or anything — guard inside).
	 * @param string $taxonomy The taxonomy name.
	 * @return mixed The translated term, or the original if no translation exists.
	 */
	public function translateTerm( mixed $term, string $taxonomy ): mixed {
		if ( ! $term instanceof \WP_Term ) {
			return $term;
		}

		$lang    = (string) $this->languageManager->getCurrentLanguage();
		$default = (string) $this->languageManager->getDefaultLanguage();

		if ( $lang === $default ) {
			return $term;
		}

		$translation = $this->repo->find( $term->term_id, $lang );
		if ( ! $translation ) {
			return $term;
		}

		$translated = clone $term;

		if ( ! empty( $translation['name'] ) ) {
			$translated->name = $translation['name'];
		}
		if ( ! empty( $translation['slug'] ) ) {
			$translated->slug = $translation['slug'];
		}
		if ( ! empty( $translation['description'] ) ) {
			$translated->description = $translation['description'];
		}

		return $translated;
	}

	/**
	 * Translate an array of terms for the current language.
	 *
	 * @param array $terms      Array of WP_Term objects or other values.
	 * @param mixed $taxonomies The taxonomy or taxonomies queried (unused — each term carries its own).
	 * @return array The array with each WP_Term translated in-place.
	 */
	public function translateTerms( array $terms, mixed $taxonomies ): array {
		return array_map(
			fn( $t ) => $t instanceof \WP_Term
				? $this->translateTerm( $t, $t->taxonomy )
				: $t,
			$terms
		);
	}
}
