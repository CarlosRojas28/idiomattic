<?php
/**
 * GlossaryTerm — immutable value object representing a single glossary entry.
 *
 * @package IdiomatticWP\Glossary
 */

declare( strict_types=1 );

namespace IdiomatticWP\Glossary;

/**
 * Represents a source ↔ target terminology pair with optional usage rules.
 *
 * Usage:
 *   $term = new GlossaryTerm( 0, 'en', 'es', 'plugin', 'complemento', false, 'Preferred term per marketing' );
 *   if ( $term->forbidden ) { ... }
 */
final class GlossaryTerm {

	public function __construct(
		public readonly int     $id,
		public readonly string  $sourceLang,
		public readonly string  $targetLang,
		public readonly string  $sourceTerm,
		public readonly string  $translatedTerm,
		public readonly bool    $forbidden = false,
		public readonly ?string $notes = null,
	) {}

	/**
	 * Create from a raw database row.
	 *
	 * @param array $row Associative array from wpdb.
	 */
	public static function fromRow( array $row ): self {
		return new self(
			id:             (int) $row['id'],
			sourceLang:     (string) $row['source_lang'],
			targetLang:     (string) $row['target_lang'],
			sourceTerm:     (string) $row['source_term'],
			translatedTerm: (string) $row['translated_term'],
			forbidden:      (bool) $row['forbidden'],
			notes:          isset( $row['notes'] ) ? (string) $row['notes'] : null,
		);
	}

	/**
	 * Serialize to array for DB persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'source_lang'     => $this->sourceLang,
			'target_lang'     => $this->targetLang,
			'source_term'     => $this->sourceTerm,
			'translated_term' => $this->translatedTerm,
			'forbidden'       => $this->forbidden ? 1 : 0,
			'notes'           => $this->notes,
		];
	}

	/**
	 * Returns true if this term should be left untranslated.
	 */
	public function isForbidden(): bool {
		return $this->forbidden;
	}

	/**
	 * Format as a prompt instruction line for AI providers.
	 */
	public function toPromptInstruction(): string {
		if ( $this->forbidden ) {
			return "- Do NOT translate \"{$this->sourceTerm}\" — keep it unchanged.";
		}

		$line = "- \"{$this->sourceTerm}\" → \"{$this->translatedTerm}\"";

		if ( $this->notes ) {
			$line .= " (Note: {$this->notes})";
		}

		return $line;
	}
}
