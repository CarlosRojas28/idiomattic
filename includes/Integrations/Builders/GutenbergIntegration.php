<?php
/**
 * GutenbergIntegration — registers the Language Switcher block.
 *
 * The block is registered server-side (render_callback) so it works without
 * a compiled JS bundle for the save() function. The editor UI is provided by
 * a plain script that uses the globally-available wp.* packages — no build
 * step required.
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Frontend\LanguageSwitcher;
use IdiomatticWP\Core\LanguageManager;

class GutenbergIntegration implements IntegrationInterface {

	public function __construct(
		private LanguageSwitcher $switcher,
		private LanguageManager  $languageManager,
	) {}

	// ── IntegrationInterface ──────────────────────────────────────────────

	public function isAvailable(): bool {
		return function_exists( 'register_block_type' );
	}

	public function register(): void {
		add_action( 'init',                       [ $this, 'registerSwitcherBlock'  ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorAssets'   ] );
	}

	// ── Block registration ────────────────────────────────────────────────

	public function registerSwitcherBlock(): void {
		// Register the script first so we can reference its handle
		wp_register_script(
			'idiomatticwp-block-language-switcher',
			IDIOMATTICWP_ASSETS_URL . 'js/blocks/index.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ],
			IDIOMATTICWP_VERSION,
			true
		);

		register_block_type( 'idiomattic-wp/language-switcher', [
			'editor_script'   => 'idiomatticwp-block-language-switcher',
			'render_callback' => [ $this, 'renderSwitcherBlock' ],
			'attributes'      => [
				'style'            => [ 'type' => 'string',  'default' => 'list'  ],
				'showFlags'        => [ 'type' => 'boolean', 'default' => true    ],
				'showNames'        => [ 'type' => 'boolean', 'default' => true    ],
				'showNativeNames'  => [ 'type' => 'boolean', 'default' => false   ],
				'hideCurrent'      => [ 'type' => 'boolean', 'default' => false   ],
				'hideUntranslated' => [ 'type' => 'boolean', 'default' => false   ],
			],
		] );
	}

	/**
	 * Enqueue the block editor script.
	 * Called on enqueue_block_editor_assets — the script is already registered
	 * via registerSwitcherBlock() (init) so we just need to enqueue it here.
	 */
	public function enqueueEditorAssets(): void {
		wp_enqueue_script( 'idiomatticwp-block-language-switcher' );
	}

	// ── Render callback (server-side, frontend + editor preview) ─────────

	public function renderSwitcherBlock( array $attributes ): string {
		return $this->switcher->render( [
			'style'             => $attributes['style']            ?? 'list',
			'show_flags'        => $attributes['showFlags']        ?? true,
			'show_names'        => $attributes['showNames']        ?? true,
			'show_native_names' => $attributes['showNativeNames']  ?? false,
			'hide_current'      => $attributes['hideCurrent']      ?? false,
			'hide_untranslated' => $attributes['hideUntranslated'] ?? false,
		] );
	}
}
