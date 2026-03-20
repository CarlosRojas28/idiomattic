<?php
/**
 * AstraIntegration — language switcher placement and customizer string support for Astra.
 *
 * @package IdiomatticWP\Integrations\Themes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Themes;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class AstraIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher      $switcher,
	) {}

	public function isAvailable(): bool {
		return defined( 'ASTRA_THEME_VERSION' );
	}

	public function register(): void {
		// Register Astra customizer strings for translation
		add_action( 'init', [ $this, 'registerCustomizerStrings' ], 25 );

		// Add language switcher to Astra's primary header (after nav)
		add_action( 'astra_header_custom_button', [ $this, 'renderSwitcher' ] );

		// Allow placing the switcher inside Astra's header builder (Pro feature)
		if ( defined( 'ASTRA_EXT_VER' ) ) {
			add_filter( 'astra_addon_header_elements', [ $this, 'addSwitcherToHeaderElements' ] );
		}
	}

	public function registerCustomizerStrings(): void {
		$stringOptions = [
			'astra-settings[header-main-rt-section-html-1]',
			'astra-settings[footer-html-1]',
			'astra-settings[footer-html-2]',
		];
		foreach ( $stringOptions as $option ) {
			$this->registry->registerOption( $option, [ 'field_type' => 'html' ] );
		}
	}

	public function renderSwitcher(): void {
		echo $this->switcher->render( [ 'style' => 'list' ] );
	}

	public function addSwitcherToHeaderElements( array $elements ): array {
		$elements['idiomatticwp-switcher'] = [
			'name'    => __( 'Language Switcher', 'idiomattic-wp' ),
			'section' => 'section-idiomatticwp-switcher',
		];
		return $elements;
	}
}
