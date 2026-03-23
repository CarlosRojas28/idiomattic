<?php
/**
 * WpdbTermTranslationRepository — $wpdb implementation of TermTranslationRepositoryInterface.
 *
 * Table: {prefix}idiomatticwp_term_translations
 * Columns: id, term_id, taxonomy, lang, name, slug, description
 *
 * @package IdiomatticWP\Infrastructure
 */

declare( strict_types=1 );

namespace IdiomatticWP\Infrastructure;

use IdiomatticWP\Contracts\TermTranslationRepositoryInterface;

class WpdbTermTranslationRepository implements TermTranslationRepositoryInterface {

	private string $table;

	public function __construct( private \wpdb $wpdb ) {
		$this->table = $this->wpdb->prefix . 'idiomatticwp_term_translations';
	}

	// ── find ──────────────────────────────────────────────────────────────

	public function find( int $termId, string $lang ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT name, slug, description FROM {$this->table} WHERE term_id = %d AND lang = %s LIMIT 1",
				$termId,
				$lang
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ── save ──────────────────────────────────────────────────────────────

	public function save( int $termId, string $taxonomy, string $lang, array $data ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->table} (term_id, taxonomy, lang, name, slug, description)
				 VALUES (%d, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
				   name        = VALUES(name),
				   slug        = VALUES(slug),
				   description = VALUES(description)",
				$termId,
				$taxonomy,
				$lang,
				$data['name']        ?? null,
				$data['slug']        ?? null,
				$data['description'] ?? null
			)
		);
	}

	// ── delete ────────────────────────────────────────────────────────────

	public function delete( int $termId, string $lang ): void {
		$this->wpdb->delete(
			$this->table,
			[ 'term_id' => $termId, 'lang' => $lang ],
			[ '%d', '%s' ]
		);
	}

	// ── findAllForTerm ────────────────────────────────────────────────────

	public function findAllForTerm( int $termId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT lang, name, slug, description FROM {$this->table} WHERE term_id = %d",
				$termId
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$result = [];
		foreach ( $rows as $row ) {
			$lang            = $row['lang'];
			$result[ $lang ] = [
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'description' => $row['description'],
			];
		}

		return $result;
	}
}
