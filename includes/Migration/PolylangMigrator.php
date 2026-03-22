<?php
/**
 * PolylangMigrator — migrates translation relationships from Polylang.
 *
 * Polylang stores translation groups as `post_translations` taxonomy terms.
 * Each term's `description` is a serialised array mapping language slugs to post IDs:
 *   [ 'en' => 123, 'fr' => 456, 'es' => 789 ]
 *
 * This class reads those terms in batches and creates the corresponding rows
 * in `idiomatticwp_translations`, treating the default-language post as the source.
 *
 * @package IdiomatticWP\Migration
 */

declare( strict_types=1 );

namespace IdiomatticWP\Migration;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class PolylangMigrator {

	public function __construct(
		private \wpdb                          $wpdb,
		private PolylangDetector               $detector,
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
	) {}

	public function migrateBatch( int $offset, int $limit = 100 ): MigrationReport {
		$report            = new MigrationReport();
		$report->startedAt = current_time( 'mysql' );

		$terms = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT tt.description
			 FROM {$this->wpdb->term_taxonomy} tt
			 WHERE tt.taxonomy = 'post_translations'
			 ORDER BY tt.term_id ASC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );

		foreach ( $terms as $term ) {
			$report->translationsFound++;
			$this->migrateGroup( (string) $term->description, $report );
		}

		$report->completedAt = current_time( 'mysql' );
		return $report;
	}

	private function migrateGroup( string $description, MigrationReport $report ): void {
		$mapping = maybe_unserialize( $description );
		if ( ! is_array( $mapping ) || count( $mapping ) <= 1 ) {
			return;
		}

		// Prefer the site's default language as the source.
		$defaultLang  = (string) $this->languageManager->getDefaultLanguage();
		$sourcePostId = $mapping[ $defaultLang ] ?? null;
		$sourceLang   = $defaultLang;

		// If the default lang is not in the group, use the first entry.
		if ( ! $sourcePostId ) {
			reset( $mapping );
			$sourceLang   = (string) key( $mapping );
			$sourcePostId = (int) current( $mapping );
		}

		foreach ( $mapping as $langCode => $postId ) {
			$postId = (int) $postId;
			if ( $postId === (int) $sourcePostId ) {
				continue;
			}
			if ( ! get_post( $postId ) ) {
				$report->addError( "Polylang: post {$postId} not found — skipped." );
				continue;
			}

			try {
				$this->repository->save( [
					'source_post_id'     => (int) $sourcePostId,
					'translated_post_id' => $postId,
					'source_lang'        => $sourceLang,
					'target_lang'        => $langCode,
					'status'             => 'complete',
					'translation_mode'   => 'duplicate',
					'needs_update'       => 0,
					'created_at'         => current_time( 'mysql', true ),
				] );
				$report->translationsMigrated++;
			} catch ( \Exception $e ) {
				$report->addError( "Polylang: failed to migrate post {$postId}: " . $e->getMessage() );
			}
		}
	}
}
