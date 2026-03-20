<?php
/**
 * BricksIntegration — translatable field support for Bricks Builder.
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;

class BricksIntegration implements IntegrationInterface {

	public function __construct( private CustomElementRegistry $registry ) {}

	public function isAvailable(): bool {
		return defined( 'BRICKS_VERSION' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerFields' ], 25 );

		// When Bricks saves data, fire our content-changed action
		add_action( 'bricks/data/save_post', [ $this, 'onBricksSave' ], 10, 1 );
	}

	public function registerFields(): void {
		// Bricks stores its layout as a serialized array in this meta key
		$this->registry->registerPostField(
			[ 'page', 'post', 'bricks_template' ],
			'_bricks_page_content_2',
			[ 'label' => 'Bricks Layout', 'field_type' => 'json', 'mode' => 'translate' ]
		);
	}

	public function onBricksSave( int $postId ): void {
		do_action( 'idiomatticwp_source_content_changed', $postId );
	}
}
