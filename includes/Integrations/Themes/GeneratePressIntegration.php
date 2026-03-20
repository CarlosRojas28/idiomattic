<?php
/**
 * GeneratePressIntegration — language switcher and string support for GeneratePress.
 *
 * @package IdiomatticWP\Integrations\Themes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Themes;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class GeneratePressIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher      $switcher,
	) {}

	public function isAvailable(): bool {
		return defined( 'GENERATE_VERSION' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerCustomizerStrings' ], 25 );

		// Insert switcher into GP navigation — fires after primary navigation
		add_action( 'generate_after_primary_navigation', [ $this, 'renderSwitcher' ] );
	}

	public function registerCustomizerStrings(): void {
		$options = [
			'generate_settings[nav_extras_text]',
			'generate_settings[footer_html]',
		];
		foreach ( $options as $option ) {
			$this->registry->registerOption( $option, [ 'field_type' => 'html' ] );
		}
	}

	public function renderSwitcher(): void {
		echo '<div class="gp-idiomatticwp-switcher">'
			. $this->switcher->render( [ 'style' => 'list' ] )
			. '</div>';
	}
}
