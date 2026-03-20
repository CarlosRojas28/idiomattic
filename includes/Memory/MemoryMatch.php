<?php
/**
 * MemoryMatch — immutable value object representing a TM lookup result.
 *
 * @package IdiomatticWP\Memory
 */

declare( strict_types=1 );

namespace IdiomatticWP\Memory;

/**
 * Wraps a single hit from the Translation Memory.
 *
 * @property-read string $matchType  'exact' | 'fuzzy'
 * @property-read int    $score      0–100 similarity score
 */
final class MemoryMatch {

	/** Exact match threshold (100 %). */
	public const EXACT = 'exact';

	/** Fuzzy match (< 100 %, >= configurable threshold). */
	public const FUZZY = 'fuzzy';

	public function __construct(
		public readonly string $sourceText,
		public readonly string $translatedText,
		public readonly int    $score,
		public readonly string $matchType,
		public readonly string $provider = '',
	) {}

	/**
	 * Returns true when the segment is a 100 % identical match.
	 */
	public function isExact(): bool {
		return $this->score === 100 && $this->matchType === self::EXACT;
	}

	/**
	 * Returns true for fuzzy matches (useful for UI indicators).
	 */
	public function isFuzzy(): bool {
		return $this->matchType === self::FUZZY;
	}

	/**
	 * Human-readable label (e.g. "100 % (exact)" or "82 % (fuzzy)").
	 */
	public function label(): string {
		return sprintf( '%d %% (%s)', $this->score, $this->matchType );
	}
}
