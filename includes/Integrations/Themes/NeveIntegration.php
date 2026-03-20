<?php
/**
 * NeveIntegration — language switcher and string support for Neve theme.
 *
 * @package IdiomatticWP\Integrations\Themes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Themes;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class NeveIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher      $switcher,
	) {}

	public function isAvailable(): bool {
		return defined( 'NEVE_VERSION' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerCustomizerStrings' ], 25 );

		// Neve fires this action in the header area
		add_action( 'neve_after_header_wrapper_hook', [ $this, 'renderSwitcher' ] );

		// If Neve's Header Booster (Pro) is active, add a custom component
		if ( class_exists( 'Neve_Pro\Modules\Header_Footer_Grid\Components\Custom_Component' ) ) {
			add_filter( 'neve_pro_header_custom_components', [ $this, 'addSwitcherComponent' ] );
		}
	}

	public function registerCustomizerStrings(): void {
		$options = [
			'neve_header_custom_html',
			'neve_footer_custom_html',
		];
		foreach ( $options as $option ) {
			$this->registry->registerOption( $option, [ 'field_type' => 'html' ] );
		}
	}

	public function renderSwitcher(): void {
		echo '<div class="neve-idiomatticwp-switcher">'
			. $this->switcher->render( [ 'style' => 'list' ] )
			. '</div>';
	}

	public function addSwitcherComponent( array $components ): array {
		$components['idiomatticwp_switcher'] = __( 'Language Switcher', 'idiomattic-wp' );
		return $components;
	}
}
