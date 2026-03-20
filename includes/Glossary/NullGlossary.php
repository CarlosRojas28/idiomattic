<?php
/**
 * NullGlossary — no-op glossary for the free tier (Null Object pattern).
 *
 * Satisfies GlossaryInterface without performing any DB operations,
 * allowing the AI Orchestrator to function without glossary lookups.
 *
 * @package IdiomatticWP\Glossary
 */

declare( strict_types=1 );

namespace IdiomatticWP\Glossary;

use IdiomatticWP\Contracts\GlossaryInterface;
use IdiomatticWP\ValueObjects\LanguageCode;

class NullGlossary implements GlossaryInterface {

	/**
	 * Always returns an empty set — no glossary terms on free tier.
	 *
	 * @return GlossaryTerm[]
	 */
	public function getTerms( LanguageCode $source, LanguageCode $target ): array {
		return [];
	}

	/**
	 * No-op: free tier does not persist glossary terms.
	 */
	public function addTerm( GlossaryTerm $term ): void {
		// Intentionally empty — use WpdbGlossaryRepository for persistence.
	}

	/**
	 * Always returns an empty string — no glossary instructions to inject.
	 */
	public function buildPromptInstructions( LanguageCode $source, LanguageCode $target ): string {
		return '';
	}
}
