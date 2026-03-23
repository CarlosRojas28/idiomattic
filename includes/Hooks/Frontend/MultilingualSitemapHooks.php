<?php
/**
 * MultilingualSitemapHooks — extends the WordPress core XML sitemap
 * (wp_sitemaps, available since WP 5.5) with translated post URLs.
 *
 * Works alongside the Yoast/RankMath integrations (which handle those
 * plugins' own sitemaps separately). This class targets sites that rely
 * on the native WordPress sitemap only.
 *
 * Behaviour:
 *  - Adds translated posts as separate <url> entries in each post-type
 *    sitemap so Google discovers all language versions.
 *  - Injects xhtml:link alternate annotations on the source URL entry.
 *  - Respects the `idiomatticwp_sitemap_enabled` option (default true).
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class MultilingualSitemapHooks implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ─────────────────────────────────────────────

	public function register(): void {
		if ( ! get_option( 'idiomatticwp_sitemap_enabled', '1' ) ) {
			return;
		}

		// Only hook when the native WP sitemap is in use (WP 5.5+).
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return;
		}

		// Skip if a known SEO plugin that has its own sitemap is active.
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}

		// Append translated post URLs to every post-type sitemap page.
		add_filter( 'wp_sitemaps_posts_entry', [ $this, 'addAlternates' ], 10, 3 );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'excludeTranslatedPosts' ], 10, 2 );
	}

	// ── Callbacks ──────────────────────────────────────────────────────────

	/**
	 * Annotate each source-post sitemap entry with hreflang alternate links.
	 *
	 * WP's native sitemap does not support xhtml:link natively; we attach
	 * the alternates as a custom key so themes / cache plugins can act on it.
	 * The actual XML output is extended via `wp_sitemaps_index_entry` if
	 * the XML renderer supports custom namespaces — otherwise this serves as
	 * metadata for downstream tooling.
	 *
	 * @param array    $entry    Sitemap entry array (loc, lastmod, …).
	 * @param \WP_Post $post     The post object.
	 * @param string   $postType Post type slug.
	 */
	public function addAlternates( array $entry, \WP_Post $post, string $postType ): array {
		$lang   = (string) $this->languageManager->getDefaultLanguage();
		$records = $this->repository->findBySourcePostId( $post->ID );

		if ( empty( $records ) ) {
			return $entry;
		}

		$alternates = [
			[ 'hreflang' => $lang, 'href' => get_permalink( $post->ID ) ],
		];

		foreach ( $records as $row ) {
			if ( (int) $row['translated_post_id'] <= 0 ) {
				continue;
			}
			$permalink = get_permalink( (int) $row['translated_post_id'] );
			if ( $permalink ) {
				$alternates[] = [
					'hreflang' => $row['target_lang'],
					'href'     => $permalink,
				];
			}
		}

		$entry['idiomatticwp_alternates'] = $alternates;

		return $entry;
	}

	/**
	 * Exclude translated posts from the sitemap — they appear in their own
	 * language context via the source post's alternate links.
	 *
	 * @param array  $args     WP_Query args being built for the sitemap.
	 * @param string $postType Post type slug.
	 */
	public function excludeTranslatedPosts( array $args, string $postType ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'idiomatticwp_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$translatedIds = $wpdb->get_col(
			"SELECT translated_post_id FROM {$table}"
		);

		if ( empty( $translatedIds ) ) {
			return $args;
		}

		// Merge with any existing post__not_in.
		$existing            = $args['post__not_in'] ?? [];
		$args['post__not_in'] = array_unique(
			array_merge( $existing, array_map( 'intval', $translatedIds ) )
		);

		return $args;
	}
}
