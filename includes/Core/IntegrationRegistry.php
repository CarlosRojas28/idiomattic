<?php
/**
 * IntegrationRegistry — central registry for third-party integrations.
 *
 * Allows external plugins and themes to register their own integrations
 * without modifying this plugin's source code.
 *
 * === HOW TO REGISTER AN INTEGRATION FROM AN EXTERNAL PLUGIN ===
 *
 * Option A — PHP hook (recommended for most cases):
 *
 *   add_action( 'idiomatticwp_register_integrations', function( $registry ) {
 *       $registry->register( new MyPlugin_IdiomatticIntegration() );
 *   } );
 *
 * Option B — JSON config file (no PHP required, for simple field registration):
 *
 *   Place a file named `idiomattic-elements.json` in your plugin or theme root.
 *   The CustomElementRegistry scans for this file automatically.
 *   See docs/idiomattic-elements.schema.json for the full schema.
 *
 * Option C — Static method / late binding (for complex integrations):
 *
 *   add_filter( 'idiomatticwp_integrations', function( $classes ) {
 *       $classes[] = 'MyPlugin\\IdiomatticIntegration';
 *       return $classes;
 *   } );
 *
 * === INTEGRATION CLASS REQUIREMENTS ===
 *
 * Your integration class must implement IntegrationInterface:
 *
 *   class MyPlugin_IdiomatticIntegration implements IntegrationInterface {
 *       public function isAvailable(): bool {
 *           return defined( 'MY_PLUGIN_VERSION' );
 *       }
 *       public function register(): void {
 *           // Register fields, hooks, filters here
 *       }
 *   }
 *
 * @package IdiomatticWP\Core
 */

declare( strict_types=1 );

namespace IdiomatticWP\Core;

use IdiomatticWP\Contracts\IntegrationInterface;

class IntegrationRegistry {

	/** @var IntegrationInterface[] */
	private array $integrations = [];

	/** @var string[] Lazy-loaded class names (Option C) */
	private array $lazyClasses = [];

	// ── Registration API ──────────────────────────────────────────────────

	/**
	 * Register an already-instantiated integration.
	 */
	public function register( IntegrationInterface $integration ): void {
		$this->integrations[] = $integration;
	}

	/**
	 * Register an integration class name for lazy instantiation.
	 * The class must implement IntegrationInterface and have a no-arg constructor.
	 *
	 * @param string $className Fully-qualified class name.
	 */
	public function registerClass( string $className ): void {
		$this->lazyClasses[] = $className;
	}

	// ── Activation ───────────────────────────────────────────────────────

	/**
	 * Boot all registered integrations.
	 *
	 * Called once by IntegrationLoader after all built-in integrations are loaded.
	 * Fires the 'idiomatticwp_register_integrations' action to let external code
	 * add integrations at the last possible moment before loading.
	 *
	 * @param Container $container DI container (available for complex integrations).
	 */
	public function boot( Container $container ): void {
		/**
		 * Hook for external plugins/themes to register integrations.
		 *
		 * @param IntegrationRegistry $registry  Call $registry->register() or $registry->registerClass()
		 * @param Container           $container DI container for dependency resolution
		 */
		do_action( 'idiomatticwp_register_integrations', $this, $container );

		/**
		 * Filter for registering integration classes by name.
		 *
		 * @param string[] $classes Fully-qualified class names implementing IntegrationInterface.
		 */
		$classNames = (array) apply_filters( 'idiomatticwp_integrations', $this->lazyClasses );

		foreach ( $classNames as $className ) {
			if ( ! is_string( $className ) || ! class_exists( $className ) ) {
				continue;
			}
			try {
				$instance = new $className();
				if ( $instance instanceof IntegrationInterface ) {
					$this->integrations[] = $instance;
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions
					error_log( "[IdiomatticWP] Failed to instantiate integration class {$className}: " . $e->getMessage() );
				}
			}
		}

		// Now activate all registered integrations
		foreach ( $this->integrations as $integration ) {
			try {
				if ( $integration->isAvailable() ) {
					$integration->register();
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions
					error_log( '[IdiomatticWP] Integration error (' . get_class( $integration ) . '): ' . $e->getMessage() );
				}
			}
		}
	}

	// ── Introspection ─────────────────────────────────────────────────────

	/**
	 * Get all registered integration instances (active and inactive).
	 *
	 * @return IntegrationInterface[]
	 */
	public function all(): array {
		return $this->integrations;
	}

	/**
	 * Get only the active (available) integrations.
	 *
	 * @return IntegrationInterface[]
	 */
	public function active(): array {
		return array_filter( $this->integrations, fn( $i ) => $i->isAvailable() );
	}
}
