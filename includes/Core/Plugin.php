<?php
/**
 * Plugin — main orchestrator (Singleton).
 *
 * Wires the container, hooks, and integrations. Zero business logic here.
 *
 * @package IdiomatticWP\Core
 */

declare( strict_types=1 );

namespace IdiomatticWP\Core;

class Plugin {

	private static ?self $instance = null;
	private Container $container;
	private bool $booted = false;

	private function __construct() {}

	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->container = new Container();
		ContainerConfig::configure( $this->container );
		( new HookLoader( $this->container ) )->register();
		( new IntegrationLoader( $this->container ) )->load();

		// Setup wizard — admin only, registers its own hooks
		if ( is_admin() ) {
			$this->container->get( \IdiomatticWP\Admin\Pages\SetupWizard::class )->register();
		}

		// WP-CLI commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cmd = $this->container->get( \IdiomatticWP\CLI\IdiomatticCommand::class );
			\WP_CLI::add_command( 'idiomattic', $cmd );
		}

		$this->booted = true;

		do_action( 'idiomatticwp_loaded' );
	}

	/**
	 * Resolve a service from the container.
	 * Use this only where constructor injection is not practical (e.g. templates).
	 */
	public function get( string $id ): mixed {
		return $this->container->get( $id );
	}

	public function getContainer(): Container {
		return $this->container;
	}
}
