<?php
declare( strict_types=1 );
namespace IdiomatticWP\Exceptions;
class RateLimitException extends \RuntimeException {
	public function __construct( string $provider, private int $retryAfter = 60 ) {
		parent::__construct( "Rate limit exceeded for provider: {$provider}. Retry after {$retryAfter} seconds." );
	}
	public function getRetryAfter(): int { return $this->retryAfter; }
}
