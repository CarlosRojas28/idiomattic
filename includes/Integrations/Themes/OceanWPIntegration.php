<?php
/**
 * OceanWPIntegration — language switcher and string support for OceanWP theme.
 *
 * @package IdiomatticWP\Integrations\Themes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Themes;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class OceanWPIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher      $switcher,
	) {}

	public function isAvailable(): bool {
		return defined( 'OCEANWP_THEME_VERSION' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerCustomizerStrings' ], 25 );

		// OceanWP header — inject after the navigation
		add_action( 'ocean_after_header', [ $this, 'renderSwitcher' ] );

		// Register the switcher as an OceanWP header custom content item
		add_filter( 'ocean_header_custom_links', [ $this, 'addSwitcherToHeaderLinks' ] );
	}

	public function registerCustomizerStrings(): void {
		$options = [
			'ocean_custom_header_template',
			'ocean_footer_copyright_text',
		];
		foreach ( $options as $option ) {
			$this->registry->registerOption( $option, [ 'field_type' => 'html' ] );
		}
	}

	public function renderSwitcher(): void {
		echo '<div class="oceanwp-idiomatticwp-switcher">'
			. $this->switcher->render( [ 'style' => 'list' ] )
			. '</div>';
	}

	public function addSwitcherToHeaderLinks( array $links ): array {
		$links['idiomatticwp_switcher'] = $this->switcher->render( [ 'style' => 'dropdown' ] );
		return $links;
	}
}
