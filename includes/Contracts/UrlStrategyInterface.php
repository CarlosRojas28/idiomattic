<?php
declare( strict_types=1 );
namespace IdiomatticWP\Contracts;
use IdiomatticWP\ValueObjects\LanguageCode;

interface UrlStrategyInterface {
	public function detectLanguage( \WP $wp ): LanguageCode;
	public function buildUrl( string $url, LanguageCode $lang ): string;
	public function homeUrl( LanguageCode $lang ): string;
	public function getRewriteRules(): array;
}
