<?php
/**
 * GlossaryInterface — contract for glossary term storage and retrieval.
 *
 * Implementations:
 *   - WpdbGlossaryRepository  (persistent, Pro)
 *   - NullGlossary            (no-op, Free tier)
 *
 * @package IdiomatticWP\Contracts
 */

declare( strict_types=1 );

namespace IdiomatticWP\Contracts;

use IdiomatticWP\Glossary\GlossaryTerm;
use IdiomatticWP\ValueObjects\LanguageCode;

interface GlossaryInterface {

	/**
	 * Retrieve all glossary terms for a language pair.
	 *
	 * @param LanguageCode $source Source language.
	 * @param LanguageCode $target Target language.
	 * @return GlossaryTerm[]
	 */
	public function getTerms( LanguageCode $source, LanguageCode $target ): array;

	/**
	 * Persist a new glossary term.
	 *
	 * @param GlossaryTerm $term Term to store.
	 */
	public function addTerm( GlossaryTerm $term ): void;

	/**
	 * Build a natural-language instructions block for AI provider prompts.
	 *
	 * Returns an empty string when no terms exist.
	 *
	 * @param LanguageCode $source Source language.
	 * @param LanguageCode $target Target language.
	 * @return string Ready-to-inject prompt fragment.
	 */
	public function buildPromptInstructions( LanguageCode $source, LanguageCode $target ): string;
}
