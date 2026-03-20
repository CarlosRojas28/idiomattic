<?php
/**
 * WPBakeryIntegration — translatable field support for WPBakery Page Builder.
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;

class WPBakeryIntegration implements IntegrationInterface {

	public function __construct( private CustomElementRegistry $registry ) {}

	public function isAvailable(): bool {
		return defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerFields' ], 25 );
	}

	public function registerFields(): void {
		// WPBakery stores its content in post_content as shortcodes.
		// The core Segmenter handles post_content — nothing extra needed.
		// We do register the custom CSS meta key so inline styles are copied.
		$this->registry->registerPostField(
			[ 'page', 'post' ],
			'_wpb_vc_js_status',
			[ 'label' => 'WPBakery JS Status', 'field_type' => 'text', 'mode' => 'copy' ]
		);

		$this->registry->registerPostField(
			[ 'page', 'post' ],
			'_vc_post_settings',
			[ 'label' => 'WPBakery Post Settings', 'field_type' => 'text', 'mode' => 'copy' ]
		);
	}
}
