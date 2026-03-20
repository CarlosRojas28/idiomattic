<?php
/**
 * ParameterStrategy — URL routing via ?lang= query parameter (Free tier).
 *
 * @package IdiomatticWP\Routing
 */

declare( strict_types=1 );

namespace IdiomatticWP\Routing;

use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;
use IdiomatticWP\ValueObjects\LanguageCode;

class ParameterStrategy implements UrlStrategyInterface {

	public function __construct( private LanguageManager $languageManager ) {}

	public function detectLanguage( \WP $wp ): LanguageCode {
		$param = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';

		if ( '' !== $param ) {
			try {
				$detected = LanguageCode::from( $param );
				// Validate it is actually an active language
				if ( $this->languageManager->isActive( $detected ) ) {
					return apply_filters( 'idiomatticwp_detected_language', $detected, 'url' );
				}
			} catch ( InvalidLanguageCodeException $e ) {
				// Fall through to default
			}
		}

		$default = $this->languageManager->getDefaultLanguage();
		return apply_filters( 'idiomatticwp_detected_language', $default, 'url' );
	}

	public function buildUrl( string $url, LanguageCode $lang ): string {
		$original = $url;

		if ( $this->languageManager->isDefault( $lang ) ) {
			// Remove any existing ?lang= from the URL for the default language
			$url = remove_query_arg( 'lang', $url );
		} else {
			$url = add_query_arg( 'lang', (string) $lang, $url );
		}

		return apply_filters( 'idiomatticwp_url_for_language', $url, (string) $lang, $original );
	}

	public function homeUrl( LanguageCode $lang ): string {
		return $this->buildUrl( home_url( '/' ), $lang );
	}

	public function getRewriteRules(): array {
		return []; // No rewrite rules needed for parameter strategy
	}
}
