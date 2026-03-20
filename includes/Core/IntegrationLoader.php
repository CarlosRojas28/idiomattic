<?php
/**
 * IntegrationLoader — conditionally loads optional third-party integrations.
 *
 * Built-in integrations (page builders, SEO plugins, themes, WooCommerce) are
 * registered directly here. External integrations — from third-party plugins
 * or themes — are loaded via IntegrationRegistry which exposes:
 *
 *   - Action:  idiomatticwp_register_integrations( $registry, $container )
 *   - Filter:  idiomatticwp_integrations( $classNames[] )
 *   - JSON:    idiomattic-elements.json in plugin/theme root (field definitions)
 *
 * IMPORTANT: isAvailable() must be checked BEFORE resolving the class from
 * the container. Resolving triggers the autoloader (require_once), which will
 * fatal-error if the integration file references an external class that doesn't
 * exist yet (e.g. Elementor\Widget_Base).
 *
 * @package IdiomatticWP\Core
 */

declare( strict_types=1 );

namespace IdiomatticWP\Core;

use IdiomatticWP\Contracts\IntegrationInterface;

class IntegrationLoader {

	public function __construct( private Container $container ) {}

	public function load(): void {
		// ── REST API ───────────────────────────────────────────────────────────
		$this->loadIfAvailable( \IdiomatticWP\Integrations\REST\RestApiIntegration::class );

		// ── Page builders ──────────────────────────────────────────────────────
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Builders\GutenbergIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Builders\ElementorIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Builders\BeaverBuilderIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Builders\DiviIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Builders\BricksIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Builders\OxygenIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Builders\WPBakeryIntegration::class );

		// ── WooCommerce ────────────────────────────────────────────────────────
		$this->loadIfAvailable( \IdiomatticWP\Integrations\WooCommerce\WooCommerceIntegration::class );

		// ── SEO plugins ────────────────────────────────────────────────────────
		$this->loadIfAvailable( \IdiomatticWP\Integrations\SEO\AIOSEOIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\SEO\RankMathIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\SEO\YoastIntegration::class );

		// ── Themes ─────────────────────────────────────────────────────────────
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Themes\AstraIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Themes\GeneratePressIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Themes\KadenceIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Themes\NeveIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Themes\OceanWPIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Themes\BlocksyIntegration::class );
		$this->loadIfAvailable( \IdiomatticWP\Integrations\Themes\AvadaIntegration::class );

		// ── External integrations (plugins/themes) ─────────────────────────────
		// The IntegrationRegistry fires 'idiomatticwp_register_integrations' and
		// 'idiomatticwp_integrations' so third-party code can hook in without
		// modifying this file. It must be booted AFTER built-in integrations so
		// built-in hooks are already registered when external code runs.
		$registry = $this->container->get( IntegrationRegistry::class );
		$registry->boot( $this->container );
	}

	/**
	 * Resolve and register an integration only when its host plugin/theme is active.
	 */
	private function loadIfAvailable( string $class ): void {
		if ( ! $this->container->has( $class ) ) {
			return;
		}

		try {
			$integration = $this->container->get( $class );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( sprintf(
					'IdiomatticWP: Could not load integration %s — %s',
					$class,
					$e->getMessage()
				) );
			}
			return;
		}

		if ( ! $integration instanceof IntegrationInterface ) {
			return;
		}

		if ( $integration->isAvailable() ) {
			$integration->register();
		}
	}
}
