<?php
/**
 * UrlStrategyResolver — resolves the active URL strategy from settings.
 *
 * Reads `idiomatticwp_url_mode` from wp_options and instantiates the
 * correct UrlStrategyInterface implementation. Acts as a factory with
 * plan-gating: Pro-only strategies fall back to ParameterStrategy when
 * the license is not active.
 *
 * Usage in ContainerConfig:
 *
 *   $c->singleton(
 *       UrlStrategyInterface::class,
 *       fn($c) => $c->get(UrlStrategyResolver::class)->resolve()
 *   );
 *
 * @package IdiomatticWP\Routing
 */

declare( strict_types=1 );

namespace IdiomatticWP\Routing;

use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\License\LicenseChecker;

class UrlStrategyResolver {

	public function __construct(
		private LanguageManager $languageManager,
		private LicenseChecker  $licenseChecker,
	) {}

	/**
	 * Read the configured URL mode and return the matching strategy.
	 *
	 * Falls back to ParameterStrategy if:
	 *   - An unknown mode is stored.
	 *   - A Pro-only mode is requested without a Pro license.
	 *   - WordPress plain permalinks are active (no permalink structure set).
	 */
	public function resolve(): UrlStrategyInterface {
		// Directory and subdomain strategies require pretty permalinks.
		// If plain permalinks are active, only parameter strategy works.
		if ( $this->isPlainPermalinks() ) {
			return new ParameterStrategy( $this->languageManager );
		}

		$mode = get_option( 'idiomatticwp_url_mode', 'parameter' );

		return match ( $mode ) {
			'directory' => $this->licenseChecker->isPro()
				? new DirectoryStrategy( $this->languageManager )
				: new ParameterStrategy( $this->languageManager ),

			'subdomain' => $this->licenseChecker->isPro()
				? new SubdomainStrategy( $this->languageManager )
				: new ParameterStrategy( $this->languageManager ),

			default => new ParameterStrategy( $this->languageManager ),
		};
	}

	/**
	 * Returns the currently configured mode string regardless of license.
	 * Useful for displaying the setting in the admin UI.
	 */
	public function getMode(): string {
		return get_option( 'idiomatticwp_url_mode', 'parameter' );
	}

	/**
	 * True when WordPress is using plain (non-pretty) permalinks.
	 * DirectoryStrategy and SubdomainStrategy both depend on mod_rewrite /
	 * Nginx rewrite; they cannot function without a permalink structure.
	 */
	private function isPlainPermalinks(): bool {
		return get_option( 'permalink_structure', '' ) === '';
	}
}
