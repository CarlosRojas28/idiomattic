<?php
/**
 * OxygenIntegration — translatable field support for Oxygen Builder.
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;

class OxygenIntegration implements IntegrationInterface {

	public function __construct( private CustomElementRegistry $registry ) {}

	public function isAvailable(): bool {
		return defined( 'CT_VERSION' ) || function_exists( 'oxygen_get_combined_shortcodes' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerFields' ], 25 );
	}

	public function registerFields(): void {
		// Oxygen stores layout shortcodes in this meta key
		$this->registry->registerPostField(
			[ 'page', 'post' ],
			'ct_builder_shortcodes',
			[ 'label' => 'Oxygen Shortcodes', 'field_type' => 'text', 'mode' => 'translate' ]
		);

		// JSON layout data (Oxygen 4+)
		$this->registry->registerPostField(
			[ 'page', 'post' ],
			'ct_builder_json',
			[ 'label' => 'Oxygen JSON Layout', 'field_type' => 'json', 'mode' => 'translate' ]
		);
	}
}
