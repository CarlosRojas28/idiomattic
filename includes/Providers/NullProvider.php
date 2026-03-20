<?php
/**
 * NullProvider — no-op translation provider used as a safe fallback.
 *
 * Returned by ProviderFactory when no provider is configured or the
 * active provider ID is unknown. Implements the full interface so the
 * rest of the system never needs to null-check the provider.
 *
 * translate() returns the source segments unchanged so that callers
 * receive a valid same-length array and can detect the no-op by
 * comparing source vs translated values.
 *
 * @package IdiomatticWP\Providers
 */

declare( strict_types=1 );

namespace IdiomatticWP\Providers;

use IdiomatticWP\Contracts\ProviderInterface;

class NullProvider implements ProviderInterface {

	public function translate(
		array  $segments,
		string $sourceLang,
		string $targetLang,
		array  $glossaryTerms = []
	): array {
		// Return segments unchanged — caller can detect no translation occurred
		return $segments;
	}

	public function getName(): string {
		return 'None (not configured)';
	}

	public function getId(): string {
		return 'null';
	}

	public function isConfigured(): bool {
		return false;
	}

	public function estimateCost( array $segments ): float {
		return 0.0;
	}

	public function getConfigFields(): array {
		return [];
	}
}
