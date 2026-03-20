<?php
declare( strict_types=1 );
namespace IdiomatticWP\Contracts;

interface HookRegistrarInterface {
	public function register(): void;
}
