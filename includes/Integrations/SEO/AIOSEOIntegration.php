<?php
/**
 * AIOSEOIntegration — hreflang and meta field support for All in One SEO.
 *
 * @package IdiomatticWP\Integrations\SEO
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\SEO;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;

class AIOSEOIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry          $registry,
		private TranslationRepositoryInterface $repository,
		private UrlStrategyInterface           $urlStrategy,
		private LanguageManager                $languageManager,
	) {}

	public function isAvailable(): bool {
		return defined( 'AIOSEO_VERSION' );
	}

	public function register(): void {
		// Suppress our hreflang — AIOSEO will handle its own
		add_filter( 'idiomatticwp_hreflang_links', '__return_empty_array' );

		// Register AIOSEO meta fields for translation
		add_action( 'init', [ $this, 'registerAioseoFields' ], 25 );

		// Filter canonical URL through our strategy
		add_filter( 'aioseo_canonical_url', [ $this, 'filterCanonical' ] );
	}

	public function registerAioseoFields(): void {
		$this->registry->registerPostField( '*', '_aioseo_title',              [ 'label' => 'SEO Title',        'field_type' => 'text'     ] );
		$this->registry->registerPostField( '*', '_aioseo_description',        [ 'label' => 'Meta Description', 'field_type' => 'textarea' ] );
		$this->registry->registerPostField( '*', '_aioseo_og_title',           [ 'label' => 'OG Title',         'field_type' => 'text'     ] );
		$this->registry->registerPostField( '*', '_aioseo_og_description',     [ 'label' => 'OG Description',   'field_type' => 'textarea' ] );
		$this->registry->registerPostField( '*', '_aioseo_twitter_title',      [ 'label' => 'Twitter Title',    'field_type' => 'text'     ] );
		$this->registry->registerPostField( '*', '_aioseo_twitter_description',[ 'label' => 'Twitter Desc',     'field_type' => 'textarea' ] );
	}

	public function filterCanonical( string $url ): string {
		$current = $this->languageManager->getCurrentLanguage();
		return $this->urlStrategy->buildUrl( $url, $current );
	}
}
