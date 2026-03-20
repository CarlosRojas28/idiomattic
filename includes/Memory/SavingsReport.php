<?php
/**
 * SavingsReport — immutable value object summarising Translation Memory savings.
 *
 * @package IdiomatticWP\Memory
 */

declare( strict_types=1 );

namespace IdiomatticWP\Memory;

/**
 * Aggregated cost/hit statistics for a language pair or the whole TM.
 *
 * All monetary values are in USD.
 *
 * Usage:
 *   $report = $tmRepository->getSavingsReport( $source, $target );
 *   echo $report->hitRatePercent();  // "34.5 %"
 *   echo $report->formattedSavings(); // "$ 1.23"
 */
final class SavingsReport {

	public function __construct(
		/** Total segments processed (TM hits + API calls). */
		public readonly int   $totalSegments,
		/** Number of segments served from TM (not sent to AI). */
		public readonly int   $tmHits,
		/** Approximate characters saved (not sent to provider). */
		public readonly int   $savedCharacters,
		/** Estimated USD saved by avoiding API calls. */
		public readonly float $savedUsd,
		/** Hit rate expressed as a 0–100 float. */
		public readonly float $hitRate,
	) {}

	/**
	 * Returns the hit rate as a formatted percentage string.
	 */
	public function hitRatePercent(): string {
		return number_format( $this->hitRate, 1 ) . ' %';
	}

	/**
	 * Returns a formatted USD savings string.
	 */
	public function formattedSavings(): string {
		return '$ ' . number_format( $this->savedUsd, 2 );
	}

	/**
	 * Returns an empty / zero-value report (useful as a safe default).
	 */
	public static function empty(): self {
		return new self( 0, 0, 0, 0.0, 0.0 );
	}

	/**
	 * Serialize for caching or REST responses.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'total_segments'  => $this->totalSegments,
			'tm_hits'         => $this->tmHits,
			'saved_chars'     => $this->savedCharacters,
			'saved_usd'       => $this->savedUsd,
			'hit_rate'        => $this->hitRate,
		];
	}
}
