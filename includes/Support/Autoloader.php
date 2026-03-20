<?php
/**
 * PSR-4 Autoloader
 *
 * Maps IdiomatticWP\ → includes/
 *
 * @package IdiomatticWP\Support
 */

declare( strict_types=1 );

namespace IdiomatticWP\Support;

class Autoloader {

	private const NAMESPACE_PREFIX = 'IdiomatticWP\\';
	private const BASE_DIR         = IDIOMATTICWP_PATH . 'includes/';

	public static function register(): void {
		spl_autoload_register( static function ( string $class ): void {
			// Only handle our namespace
			if ( strncmp( $class, self::NAMESPACE_PREFIX, strlen( self::NAMESPACE_PREFIX ) ) !== 0 ) {
				return;
			}

			// Strip prefix, convert namespace separators to directory separators
			$relative = substr( $class, strlen( self::NAMESPACE_PREFIX ) );
			$file     = self::BASE_DIR . str_replace( '\\', '/', $relative ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		} );
	}
}
