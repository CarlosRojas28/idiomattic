<?php
/**
 * WpdbTranslationRepository — $wpdb implementation of TranslationRepositoryInterface.
 *
 * Table schema (idiomatticwp_translations):
 *   id, source_post_id, translated_post_id, source_lang, target_lang,
 *   status, translation_mode, provider_used, needs_update,
 *   translated_at, created_at
 *
 * Note: no updated_at column — do NOT add it to queries.
 *
 * @package IdiomatticWP\Infrastructure
 */

declare( strict_types=1 );

namespace IdiomatticWP\Infrastructure;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\ValueObjects\LanguageCode;

class WpdbTranslationRepository implements TranslationRepositoryInterface {

	private string $table;

	public function __construct( private \wpdb $wpdb ) {
		$this->table = $this->wpdb->prefix . 'idiomatticwp_translations';
	}

	// ── save ──────────────────────────────────────────────────────────────

	public function save( array $data ): int {
		if ( isset( $data['id'] ) && $data['id'] > 0 ) {
			return $this->doUpdate( $data );
		}

		return $this->doInsert( $data );
	}

	// ── finders ───────────────────────────────────────────────────────────

	public function findBySourceAndLang( int $sourceId, LanguageCode $lang ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_post_id = %d AND target_lang = %s LIMIT 1",
				$sourceId,
				(string) $lang
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function findByTranslatedPost( int $translatedPostId ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE translated_post_id = %d LIMIT 1",
				$translatedPostId
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function findAllForSource( int $sourceId ): array {
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_post_id = %d ORDER BY target_lang ASC",
				$sourceId
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : [];
	}

	public function findBySourcePostId( int $sourcePostId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_post_id = %d",
				$sourcePostId
			),
			ARRAY_A
		) ?: [];
	}

	// ── status mutations ──────────────────────────────────────────────────

	public function markOutdated( int $sourceId ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = 'outdated', needs_update = 1
				 WHERE source_post_id = %d AND status = 'complete'",
				$sourceId
			)
		);
	}

	public function updateStatus( int $translationId, string $status ): void {
		$this->wpdb->update(
			$this->table,
			[ 'status' => $status ],
			[ 'id'     => $translationId ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	// ── delete ────────────────────────────────────────────────────────────

	public function delete( int $translationId ): void {
		$this->wpdb->delete(
			$this->table,
			[ 'id' => $translationId ],
			[ '%d' ]
		);
	}

	// ── existence / counts ────────────────────────────────────────────────

	public function existsForSourceAndLang( int $sourceId, LanguageCode $lang ): bool {
		$count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE source_post_id = %d AND target_lang = %s",
				$sourceId,
				(string) $lang
			)
		);

		return $count > 0;
	}

	public function countAll(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	public function countByStatus( string $status ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
				$status
			)
		);
	}

	public function getLatest( int $limit = 10 ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
				absint( $limit )
			),
			ARRAY_A
		) ?: [];
	}

	// ── private helpers ───────────────────────────────────────────────────

	private function doInsert( array $data ): int {
		// Ensure required timestamp (no updated_at column in this table)
		if ( ! isset( $data['created_at'] ) ) {
			$data['created_at'] = current_time( 'mysql', true );
		}

		unset( $data['id'] ); // Never pass id on insert

		$this->wpdb->insert( $this->table, $data );

		return (int) $this->wpdb->insert_id;
	}

	private function doUpdate( array $data ): int {
		$id = (int) $data['id'];
		unset( $data['id'] );

		// Note: no updated_at column — only created_at and translated_at exist
		$this->wpdb->update( $this->table, $data, [ 'id' => $id ] );

		return $id;
	}
}
