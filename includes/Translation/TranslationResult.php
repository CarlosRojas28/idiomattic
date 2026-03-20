<?php
/**
 * TranslationResult — immutable value object returned by AI providers.
 *
 * Captures both the translated segments and the processing statistics
 * (cost, TM hits, provider used) for a single translation batch.
 *
 * @package IdiomatticWP\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Translation;

/**
 * Usage:
 *   $result = new TranslationResult(
 *       segments:     ['Hola', 'Mundo'],
 *       provider:     'openai',
 *       estimatedCost: 0.002,
 *       tmHits:       3,
 *   );
 *
 *   $result->get(0);          // 'Hola'
 *   $result->count();         // 2
 *   $result->totalCost();     // 0.002
 */
final class TranslationResult {

	/**
	 * @param string[] $segments      Translated strings, index-aligned with input.
	 * @param string   $provider      Provider slug (openai, deepl, google, claude, …).
	 * @param float    $estimatedCost Estimated cost in USD for this batch.
	 * @param int      $tmHits        Number of segments served from Translation Memory.
	 * @param float    $elapsedMs     Wall-clock time for the provider round-trip (ms).
	 */
	public function __construct(
		private readonly array  $segments,
		public readonly string  $provider,
		public readonly float   $estimatedCost = 0.0,
		public readonly int     $tmHits = 0,
		public readonly float   $elapsedMs = 0.0,
	) {}

	/**
	 * Get the translated string at a given index.
	 *
	 * @throws \OutOfRangeException When index is out of bounds.
	 */
	public function get( int $index ): string {
		if ( ! isset( $this->segments[ $index ] ) ) {
			throw new \OutOfRangeException(
				sprintf( 'No segment at index %d (total: %d).', $index, $this->count() )
			);
		}

		return $this->segments[ $index ];
	}

	/**
	 * Return all translated segments.
	 *
	 * @return string[]
	 */
	public function all(): array {
		return $this->segments;
	}

	/**
	 * Number of translated segments in this result.
	 */
	public function count(): int {
		return count( $this->segments );
	}

	/**
	 * Total cost including any TM overhead (currently same as estimatedCost).
	 */
	public function totalCost(): float {
		return $this->estimatedCost;
	}

	/**
	 * Returns true when every segment was served from TM (no API cost).
	 */
	public function isFullTmHit(): bool {
		return $this->tmHits >= $this->count() && $this->count() > 0;
	}

	/**
	 * Serialize for logging / REST responses.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'segments'       => $this->segments,
			'provider'       => $this->provider,
			'estimated_cost' => $this->estimatedCost,
			'tm_hits'        => $this->tmHits,
			'elapsed_ms'     => $this->elapsedMs,
		];
	}
}
