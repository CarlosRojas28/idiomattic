<?php
/**
 * BlocksyIntegration — language switcher and string support for Blocksy theme.
 *
 * @package IdiomatticWP\Integrations\Themes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Themes;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class BlocksyIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher      $switcher,
	) {}

	public function isAvailable(): bool {
		return defined( 'BLOCKSY_VERSION' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerCustomizerStrings' ], 25 );

		// Blocksy uses a hook system for header items
		add_filter( 'blocksy:header:items-config', [ $this, 'addSwitcherHeaderItem' ] );
		add_action( 'blocksy:header:offcanvas:top', [ $this, 'renderSwitcher' ] );
	}

	public function registerCustomizerStrings(): void {
		// Blocksy stores customizer data as JSON
		$this->registry->registerOption(
			'blocksy_active_condition',
			[ 'field_type' => 'json', 'label' => 'Blocksy Conditions', 'mode' => 'copy' ]
		);
	}

	public function renderSwitcher(): void {
		echo $this->switcher->render( [ 'style' => 'list' ] );
	}

	public function addSwitcherHeaderItem( array $items ): array {
		$items['idiomatticwp_switcher'] = [
			'label' => __( 'Language Switcher', 'idiomattic-wp' ),
			'icon'  => 'flag',
		];
		return $items;
	}
}
