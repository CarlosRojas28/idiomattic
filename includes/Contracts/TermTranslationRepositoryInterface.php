<?php
/**
 * TermTranslationRepositoryInterface — contract for taxonomy term translation persistence.
 *
 * @package IdiomatticWP\Contracts
 */

declare( strict_types=1 );

namespace IdiomatticWP\Contracts;

interface TermTranslationRepositoryInterface {

	/**
	 * Find translations for a term in a specific language.
	 *
	 * @param int    $termId The WordPress term ID.
	 * @param string $lang   The target language code.
	 * @return array|null Associative array with keys 'name', 'slug', 'description', or null if not found.
	 */
	public function find( int $termId, string $lang ): ?array;

	/**
	 * Save (insert or update) translations for a term.
	 *
	 * @param int    $termId   The WordPress term ID.
	 * @param string $taxonomy The taxonomy name.
	 * @param string $lang     The target language code.
	 * @param array  $data     Associative array with optional keys 'name', 'slug', 'description'.
	 */
	public function save( int $termId, string $taxonomy, string $lang, array $data ): void;

	/**
	 * Delete translations for a term in a specific language.
	 *
	 * @param int    $termId The WordPress term ID.
	 * @param string $lang   The target language code.
	 */
	public function delete( int $termId, string $lang ): void;

	/**
	 * Return all translations for a term, keyed by language code.
	 *
	 * @param int $termId The WordPress term ID.
	 * @return array[] Associative array keyed by language code, each value being ['name', 'slug', 'description'].
	 */
	public function findAllForTerm( int $termId ): array;
}
