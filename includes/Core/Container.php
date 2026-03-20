<?php
/**
 * Dependency Injection Container
 *
 * Minimal, explicit, no magic. Every binding is defined in ContainerConfig.
 *
 * @package IdiomatticWP\Core
 */

declare( strict_types=1 );

namespace IdiomatticWP\Core;

class Container {

	/** @var array<string, array{factory: callable, singleton: bool}> */
	private array $bindings = [];

	/** @var array<string, mixed> */
	private array $instances = [];

	/** @var array<string, string> */
	private array $aliases = [];

	/**
	 * Register a singleton binding — factory called once, result cached.
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->bindings[ $id ] = [ 'factory' => $factory, 'singleton' => true ];
	}

	/**
	 * Register a factory binding — called fresh on every get().
	 */
	public function bind( string $id, callable $factory ): void {
		$this->bindings[ $id ] = [ 'factory' => $factory, 'singleton' => false ];
	}

	/**
	 * Map an interface or abstract name to a concrete binding.
	 */
	public function alias( string $abstract, string $concrete ): void {
		$this->aliases[ $abstract ] = $concrete;
	}

	/**
	 * Resolve a binding by ID.
	 *
	 * @throws \RuntimeException If the ID is not registered.
	 */
	public function get( string $id ): mixed {
		// Resolve alias first
		$resolved = $this->aliases[ $id ] ?? $id;

		// Return cached singleton
		if ( isset( $this->instances[ $resolved ] ) ) {
			return $this->instances[ $resolved ];
		}

		if ( ! isset( $this->bindings[ $resolved ] ) ) {
			throw new \RuntimeException(
				sprintf( 'IdiomatticWP Container: no binding registered for "%s".', $id )
			);
		}

		$binding  = $this->bindings[ $resolved ];
		$instance = ( $binding['factory'] )( $this );

		if ( $binding['singleton'] ) {
			$this->instances[ $resolved ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if an ID is registered (as a binding or alias).
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] )
			|| isset( $this->aliases[ $id ] )
			|| isset( $this->bindings[ $this->aliases[ $id ] ?? '' ] );
	}
}
