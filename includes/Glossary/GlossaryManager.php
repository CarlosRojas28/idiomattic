<?php
/**
 * GlossaryManager — terminology management for AI translation prompts.
 *
 * @package IdiomatticWP\Glossary
 */

declare( strict_types=1 );

namespace IdiomatticWP\Glossary;

use IdiomatticWP\ValueObjects\LanguageCode;

class GlossaryManager {

	private ?WpdbGlossaryRepository $repository = null;

	/**
	 * Inject the repository.
	 * Called from ContainerConfig after both singletons are resolved.
	 */
	public function setRepository( WpdbGlossaryRepository $repository ): void {
		$this->repository = $repository;
	}

	/**
	 * Get glossary terms for a language pair.
	 *
	 * @return GlossaryTerm[]
	 */
	public function getTerms( LanguageCode $source, LanguageCode $target ): array {
		$terms = $this->repository
			? $this->repository->getTerms( $source, $target )
			: [];

		return apply_filters( 'idiomatticwp_glossary_terms', $terms, (string) $source, (string) $target );
	}

	/**
	 * Build a natural-language instructions string to inject into the AI prompt.
	 */
	public function buildPromptInstructions( LanguageCode $source, LanguageCode $target ): string {
		$terms = $this->getTerms( $source, $target );

		if ( empty( $terms ) ) {
			return '';
		}

		$lines = [ 'Use the following terminology:' ];

		foreach ( $terms as $term ) {
			if ( $term->forbidden ) {
				$lines[] = "- Do NOT translate \"{$term->sourceTerm}\" — keep it unchanged.";
			} else {
				$line = "- \"{$term->sourceTerm}\" → \"{$term->translatedTerm}\"";
				if ( $term->notes ) {
					$line .= " (Note: {$term->notes})";
				}
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}
}
