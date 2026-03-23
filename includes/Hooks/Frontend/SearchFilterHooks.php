<?php
/**
 * SearchFilterHooks — filters WordPress search results by the current language.
 *
 * Behaviour:
 *  - Default language:       source posts appear; translated copies are hidden.
 *  - Non-default language X: source posts that have a translation for X are
 *    hidden (the translated copy appears naturally via WP search); source posts
 *    without a translation for X are left visible.
 *
 * The filter only applies to main-query search requests on the frontend.
 * Admin, REST API, and secondary loops are untouched.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;

class SearchFilterHooks implements HookRegistrarInterface {

	private string $table;

	public function __construct(
		private \wpdb           $wpdb,
		private LanguageManager $languageManager,
	) {
		$this->table = $this->wpdb->prefix . 'idiomatticwp_translations';
	}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_action( 'pre_get_posts', [ $this, 'filterSearchByLanguage' ] );
	}

	// ── Callback ─────────────────────────────────────────────────────────

	/**
	 * Exclude posts from search results that do not belong to the current language.
	 *
	 * @param \WP_Query $query The current query object.
	 */
	public function filterSearchByLanguage( \WP_Query $query ): void {
		if ( ! $query->is_search() || ! $query->is_main_query() ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$currentLang = (string) $this->languageManager->getCurrentLanguage();
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();

		if ( $currentLang === $defaultLang ) {
			$this->excludeTranslatedPostsFromSearch( $query );
		} else {
			$this->excludeSourcePostsWithTranslation( $query, $currentLang );
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * In the default language: hide all posts that exist only as translations
	 * (i.e. their ID appears in the translated_post_id column of the table).
	 */
	private function excludeTranslatedPostsFromSearch( \WP_Query $query ): void {
		$cacheKey = 'iwp_search_translated_ids';
		$ids      = wp_cache_get( $cacheKey, 'idiomatticwp' );

		if ( $ids === false ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $this->wpdb->get_col(
				"SELECT DISTINCT translated_post_id FROM {$this->table}"
			);
			$ids = array_map( 'intval', $rows ?: [] );
			wp_cache_set( $cacheKey, $ids, 'idiomatticwp', 60 );
		}

		if ( empty( $ids ) ) {
			return;
		}

		$existing = (array) ( $query->get( 'post__not_in' ) ?: [] );
		$query->set( 'post__not_in', array_unique( array_merge( $existing, $ids ) ) );
	}

	/**
	 * In a non-default language: hide source posts that already have a
	 * translated copy for the current language (the copy will appear in results).
	 *
	 * @param string $lang BCP-47 language code of the current language.
	 */
	private function excludeSourcePostsWithTranslation( \WP_Query $query, string $lang ): void {
		$cacheKey = 'iwp_search_sources_with_' . sanitize_key( $lang );
		$ids      = wp_cache_get( $cacheKey, 'idiomatticwp' );

		if ( $ids === false ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT DISTINCT source_post_id FROM {$this->table} WHERE target_lang = %s",
					$lang
				)
			);
			$ids = array_map( 'intval', $rows ?: [] );
			wp_cache_set( $cacheKey, $ids, 'idiomatticwp', 60 );
		}

		if ( empty( $ids ) ) {
			return;
		}

		$existing = (array) ( $query->get( 'post__not_in' ) ?: [] );
		$query->set( 'post__not_in', array_unique( array_merge( $existing, $ids ) ) );
	}
}
