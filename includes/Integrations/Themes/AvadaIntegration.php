<?php
/**
 * AvadaIntegration — language switcher and string support for Avada theme.
 *
 * @package IdiomatticWP\Integrations\Themes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Themes;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class AvadaIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher      $switcher,
	) {}

	public function isAvailable(): bool {
		return defined( 'AVADA_VERSION' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerCustomizerStrings' ], 25 );

		// Inject switcher into Avada's secondary header / topbar
		add_action( 'avada_override_current_language', [ $this, 'syncLanguage' ] );
		add_action( 'avada_header_secondary_content', [ $this, 'renderSwitcher' ] );

		// Register Avada Fusion Builder post meta for translation
		add_action( 'init', [ $this, 'registerFusionBuilderFields' ], 26 );
	}

	public function registerCustomizerStrings(): void {
		// Avada stores global options as a single serialized array
		$this->registry->registerOption(
			'fusion_options',
			[ 'field_type' => 'json', 'label' => 'Avada Global Options', 'mode' => 'copy' ]
		);
	}

	public function registerFusionBuilderFields(): void {
		$this->registry->registerPostField(
			[ 'page', 'post', 'avada_portfolio', 'avada_faq' ],
			'_fusion',
			[ 'label' => 'Fusion Builder Data', 'field_type' => 'json', 'mode' => 'translate' ]
		);
	}

	public function renderSwitcher(): void {
		echo $this->switcher->render( [ 'style' => 'dropdown' ] );
	}

	/**
	 * Keep Avada's internal language in sync with ours.
	 * Avada has its own language switcher — we override it.
	 */
	public function syncLanguage( string $lang ): string {
		return idiomatticwp_get_current_language() ?: $lang;
	}
}
