<?php
/**
 * BeaverBuilderIntegration — translatable field support for Beaver Builder.
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;

class BeaverBuilderIntegration implements IntegrationInterface {

	public function __construct( private CustomElementRegistry $registry ) {}

	public function isAvailable(): bool {
		return class_exists( 'FLBuilder' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerFields' ], 25 );

		// After Beaver Builder saves layout data, mark translations as outdated
		add_action( 'fl_builder_after_save_layout', [ $this, 'onLayoutSaved' ], 10, 1 );
	}

	public function registerFields(): void {
		// Beaver Builder stores its layout as serialized JSON in post meta
		$this->registry->registerPostField(
			[ 'page', 'post', 'fl-builder-template' ],
			'_fl_builder_data',
			[ 'label' => 'Beaver Builder Data', 'field_type' => 'json', 'mode' => 'translate' ]
		);
	}

	public function onLayoutSaved( int $postId ): void {
		do_action( 'idiomatticwp_source_content_changed', $postId );
	}
}
