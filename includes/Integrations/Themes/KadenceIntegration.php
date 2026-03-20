<?php
/**
 * KadenceIntegration — language switcher and string support for Kadence theme.
 *
 * @package IdiomatticWP\Integrations\Themes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Themes;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class KadenceIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher      $switcher,
	) {}

	public function isAvailable(): bool {
		return defined( 'KADENCE_VERSION' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerCustomizerStrings' ], 25 );

		// Add switcher to Kadence header via its element hook
		add_action( 'kadence_after_header_navigation', [ $this, 'renderSwitcher' ] );
	}

	public function registerCustomizerStrings(): void {
		// Kadence stores many settings as JSON in a single option
		$this->registry->registerOption(
			'kadence_blocks_config_blocks',
			[ 'field_type' => 'json', 'label' => 'Kadence Blocks Config' ]
		);
	}

	public function renderSwitcher(): void {
		echo $this->switcher->render( [ 'style' => 'list' ] );
	}
}
