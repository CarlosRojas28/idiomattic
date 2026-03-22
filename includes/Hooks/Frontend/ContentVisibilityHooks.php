<?php
/**
 * ContentVisibilityHooks — hides translated posts when their post type is
 * no longer configured as translatable.
 *
 * If a post type was set to translatable, content was translated, and then
 * the post type was switched back to non-translatable, the secondary-language
 * copies should not appear on the frontend. This hook excludes those posts
 * from all main frontend queries (archives and singular), causing direct URLs
 * to return 404.
 *
 * The exclusion is non-destructive: re-enabling translation for the post type
 * makes the content visible again immediately.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\CustomElementRegistry;

class ContentVisibilityHooks implements HookRegistrarInterface {

	private string $table;

	public function __construct(
		private \wpdb $wpdb,
		private CustomElementRegistry $registry,
	) {
		$this->table = $this->wpdb->prefix . 'idiomatticwp_translations';
	}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_action( 'pre_get_posts', [ $this, 'maybeExcludeTranslations' ] );
	}

	// ── Hook callbacks ────────────────────────────────────────────────────

	public function maybeExcludeTranslations( \WP_Query $query ): void {
		// Admin screens manage their own visibility.
		if ( is_admin() ) {
			return;
		}

		// REST API consumers handle language context themselves.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Only touch the main query to avoid side-effects on secondary loops.
		if ( ! $query->is_main_query() ) {
			return;
		}

		$nonTranslatableTypes = $this->getNonTranslatablePostTypes();
		if ( empty( $nonTranslatableTypes ) ) {
			return;
		}

		$excludeIds = $this->getExcludedIds( $nonTranslatableTypes );
		if ( empty( $excludeIds ) ) {
			return;
		}

		$existing = (array) ( $query->get( 'post__not_in' ) ?: [] );
		$query->set( 'post__not_in', array_unique( array_merge( $existing, $excludeIds ) ) );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Returns post types that are registered in WordPress but are NOT
	 * configured as translatable in Idiomattic settings.
	 *
	 * @return string[]
	 */
	private function getNonTranslatablePostTypes(): array {
		$config   = get_option( 'idiomatticwp_post_type_config', [] );
		$allTypes = array_keys( get_post_types( [ 'public' => true ] ) );

		$nonTranslatable = [];
		foreach ( $allTypes as $postType ) {
			if ( $postType === 'attachment' ) {
				continue;
			}
			$mode = $config[ $postType ] ?? $this->registry->getPostTypeDefaultMode( $postType );
			if ( ! in_array( $mode, [ 'translate', 'show_as_translated' ], true ) ) {
				$nonTranslatable[] = $postType;
			}
		}

		return $nonTranslatable;
	}

	/**
	 * Returns the IDs of posts that are stored as translations of a source post
	 * and whose post type is currently non-translatable.
	 *
	 * Result is cached in the WP object cache for the duration of the request.
	 *
	 * @param string[] $nonTranslatableTypes
	 * @return int[]
	 */
	private function getExcludedIds( array $nonTranslatableTypes ): array {
		$cacheKey = 'iwp_hidden_translation_ids_' . md5( implode( ',', $nonTranslatableTypes ) );
		$cached   = wp_cache_get( $cacheKey, 'idiomatticwp' );

		if ( $cached !== false ) {
			return $cached;
		}

		$placeholders = implode( ',', array_fill( 0, count( $nonTranslatableTypes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT t.translated_post_id
				FROM {$this->table} t
				INNER JOIN {$this->wpdb->posts} p ON p.ID = t.translated_post_id
				WHERE p.post_type IN ($placeholders)",
				...$nonTranslatableTypes
			)
		);

		$ids = array_map( 'intval', $rows ?: [] );
		wp_cache_set( $cacheKey, $ids, 'idiomatticwp' );

		return $ids;
	}
}
