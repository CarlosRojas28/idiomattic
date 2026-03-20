<?php
declare( strict_types=1 );
namespace IdiomatticWP\Exceptions;
class ProviderUnavailableException extends \RuntimeException {
	public function __construct( string $provider, ?\Throwable $previous = null ) {
		parent::__construct( "Translation provider unavailable: {$provider}", 0, $previous );
	}
}
