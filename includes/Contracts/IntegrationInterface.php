<?php
declare( strict_types=1 );
namespace IdiomatticWP\Contracts;

interface IntegrationInterface {
	public function isAvailable(): bool;
	public function register(): void;
}
