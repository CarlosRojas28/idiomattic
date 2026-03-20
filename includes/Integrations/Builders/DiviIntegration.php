<?php
/**
 * DiviIntegration — translatable field support for the Divi page builder.
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;

class DiviIntegration implements IntegrationInterface {

	public function __construct( private CustomElementRegistry $registry ) {}

	public function isAvailable(): bool {
		// Divi Theme or Divi Builder plugin
		return defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'registerFields' ], 25 );

		// Divi saves its shortcode-based layout in post_content, which the
		// core Segmenter already handles. We additionally expose the raw
		// inline styles meta key so custom CSS is preserved.
		add_filter( 'idiomatticwp_copied_post_meta_keys', [ $this, 'ensureInlineStylesCopied' ], 10, 1 );
	}

	public function registerFields(): void {
		$this->registry->registerPostField(
			[ 'page', 'post', 'et_pb_layout' ],
			'_et_pb_page_layout',
			[ 'label' => 'Divi Page Layout', 'field_type' => 'text', 'mode' => 'copy' ]
		);
	}

	/**
	 * Ensure Divi's inline CSS meta key is always copied to the translation.
	 */
	public function ensureInlineStylesCopied( array $keys ): array {
		$diviKeys = [ '_et_pb_post_hide_nav', '_et_pb_use_builder', '_et_pb_old_content' ];
		return array_unique( array_merge( $keys, $diviKeys ) );
	}
}
