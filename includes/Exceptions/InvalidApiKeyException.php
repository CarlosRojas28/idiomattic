<?php
declare( strict_types=1 );
namespace IdiomatticWP\Exceptions;
class InvalidApiKeyException extends \RuntimeException {
	public function __construct( string $provider ) {
		parent::__construct( "Invalid or missing API key for provider: {$provider}" );
	}
}
