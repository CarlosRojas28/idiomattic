<?php
/**
 * RankMathIntegration — hreflang and meta field support for Rank Math SEO.
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

class RankMathIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry          $registry,
		private TranslationRepositoryInterface $repository,
		private UrlStrategyInterface           $urlStrategy,
		private LanguageManager                $languageManager,
	) {}

	public function isAvailable(): bool {
		return defined( 'RANK_MATH_VERSION' );
	}

	public function register(): void {
		// Suppress our own hreflang — Rank Math will output its own
		add_filter( 'idiomatticwp_hreflang_links', '__return_empty_array' );

		// Inject hreflang into Rank Math's head output
		add_filter( 'rank_math/frontend/head', [ $this, 'outputHreflang' ] );

		// Register Rank Math meta fields for translation
		add_action( 'init', [ $this, 'registerRankMathFields' ], 25 );

		// Sitemap: add hreflang alternates
		add_filter( 'rank_math/sitemap/entry', [ $this, 'addAlternatesToSitemapEntry' ], 10, 3 );
	}

	public function outputHreflang(): void {
		$postId    = get_the_ID();
		$records   = $postId ? $this->repository->findAllForSource( $postId ) : [];
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();

		global $wp;
		$currentUrl = home_url( add_query_arg( [], $wp->request ?? '' ) );

		// x-default always points to default language version
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s">' . PHP_EOL,
			esc_url( $this->urlStrategy->buildUrl( $currentUrl, $this->languageManager->getDefaultLanguage() ) )
		);

		foreach ( $this->languageManager->getActiveLanguages() as $lang ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s">' . PHP_EOL,
				esc_attr( (string) $lang ),
				esc_url( $this->urlStrategy->buildUrl( $currentUrl, $lang ) )
			);
		}
	}

	public function registerRankMathFields(): void {
		$this->registry->registerPostField( '*', 'rank_math_title',            [ 'label' => 'SEO Title',        'field_type' => 'text'     ] );
		$this->registry->registerPostField( '*', 'rank_math_description',      [ 'label' => 'Meta Description', 'field_type' => 'textarea' ] );
		$this->registry->registerPostField( '*', 'rank_math_og_description',   [ 'label' => 'OG Description',   'field_type' => 'textarea' ] );
		$this->registry->registerPostField( '*', 'rank_math_twitter_title',    [ 'label' => 'Twitter Title',    'field_type' => 'text'     ] );
	}

	public function addAlternatesToSitemapEntry( array $url, string $type, $object ): array {
		if ( $type !== 'post' || empty( $object->ID ) ) return $url;

		$records = $this->repository->findAllForSource( (int) $object->ID );
		if ( empty( $records ) ) return $url;

		$url['alternates'] = $url['alternates'] ?? [];
		foreach ( $records as $row ) {
			$url['alternates'][] = [
				'hreflang' => $row['target_lang'],
				'href'     => get_permalink( (int) $row['translated_post_id'] ),
			];
		}
		return $url;
	}
}
