<?php
/**
 * ElementorIntegration — full Elementor page builder support.
 *
 * IMPORTANTE: Este archivo NO debe contener ninguna clase que extienda
 * clases de Elementor. El widget vive en ElementorSwitcherWidget.php y
 * se carga únicamente cuando Elementor ya está activo (dentro del hook
 * elementor/widgets/register).
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Frontend\LanguageSwitcher;

class ElementorIntegration implements IntegrationInterface {

	public function __construct(
		private CustomElementRegistry $registry,
		private LanguageSwitcher $switcher,
	) {}

	// ── IntegrationInterface ──────────────────────────────────────────────

	public function isAvailable(): bool {
		// did_action devuelve >0 si Elementor ya disparó 'elementor/loaded'
		return did_action( 'elementor/loaded' ) > 0;
	}

	public function register(): void {
		// El widget se registra dentro del hook de Elementor — nunca antes
		add_action( 'elementor/widgets/register', [ $this, 'registerSwitcherWidget' ] );

		// Registrar campos de widgets built-in después de init
		add_action( 'init', [ $this, 'registerBuiltInWidgetFields' ], 20 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Carga y registra el widget del language switcher.
	 * Llamado desde elementor/widgets/register — Elementor ya está cargado aquí.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager
	 */
	public function registerSwitcherWidget( $widgets_manager ): void {
		// El archivo del widget solo se incluye aquí, cuando Elementor existe
		require_once __DIR__ . '/ElementorSwitcherWidget.php';

		$widgets_manager->register(
			new \IdiomatticWP\Integrations\Builders\ElementorSwitcherWidget( [], null, $this->switcher )
		);
	}

	public function registerBuiltInWidgetFields(): void {
		// Guardar dato raw de Elementor como campo traducible
		$this->registry->registerPostField(
			[ 'post', 'page' ],
			'_elementor_data',
			[ 'mode' => 'translate', 'field_type' => 'json' ]
		);

		// Controles de widgets comunes
		$this->registry->registerElementorWidget( 'heading',    [ 'title' ] );
		$this->registry->registerElementorWidget( 'text-editor', [ 'editor' ] );
		$this->registry->registerElementorWidget( 'button',     [ 'text', 'link' ] );
		$this->registry->registerElementorWidget( 'icon-box',   [ 'title_text', 'description_text' ] );
		$this->registry->registerElementorWidget( 'image-box',  [ 'title_text', 'description_text' ] );
	}
}
