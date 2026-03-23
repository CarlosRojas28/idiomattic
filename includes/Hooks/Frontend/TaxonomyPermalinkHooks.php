<?php
/**
 * TaxonomyPermalinkHooks — translates WooCommerce and WordPress archive
 * base slugs on-the-fly for the current active language.
 *
 * Slugs are stored in option `idiomatticwp_taxonomy_slugs` as:
 *   [ lang => [ product_slug => 'producto', product_category_slug => 'categoria', ... ] ]
 *
 * Only active when WooCommerce is installed (class_exists check).
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;

class TaxonomyPermalinkHooks implements HookRegistrarInterface {

	public function __construct( private LanguageManager $lm ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_product_rewrite_slug',          [ $this, 'translateProductSlug' ] );
		add_filter( 'woocommerce_product_category_rewrite_slug', [ $this, 'translateProductCategorySlug' ] );
		add_filter( 'woocommerce_product_tag_rewrite_slug',      [ $this, 'translateProductTagSlug' ] );
	}

	// ── Filter callbacks ──────────────────────────────────────────────────

	public function translateProductSlug( string $slug ): string {
		return $this->getTranslatedBase( 'product_slug', $slug );
	}

	public function translateProductCategorySlug( string $slug ): string {
		return $this->getTranslatedBase( 'product_category_slug', $slug );
	}

	public function translateProductTagSlug( string $slug ): string {
		return $this->getTranslatedBase( 'product_tag_slug', $slug );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Look up a translated slug from the stored option.
	 *
	 * Returns $default unchanged when:
	 *  - the current language is the site default, or
	 *  - no translation has been configured for this language + key.
	 */
	private function getTranslatedBase( string $key, string $default ): string {
		$lang        = (string) $this->lm->getCurrentLanguage();
		$defaultLang = (string) $this->lm->getDefaultLanguage();

		if ( $lang === $defaultLang ) {
			return $default;
		}

		$slugs = get_option( 'idiomatticwp_taxonomy_slugs', [] );

		if ( ! is_array( $slugs ) ) {
			return $default;
		}

		return $slugs[ $lang ][ $key ] ?? $default;
	}
}
