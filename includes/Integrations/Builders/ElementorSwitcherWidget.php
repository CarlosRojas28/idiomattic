<?php
/**
 * ElementorSwitcherWidget — language switcher widget para Elementor.
 *
 * Este archivo SOLO se carga desde ElementorIntegration::registerSwitcherWidget(),
 * que se ejecuta dentro del hook 'elementor/widgets/register', garantizando que
 * \Elementor\Widget_Base ya existe cuando PHP parsea esta clase.
 *
 * @package IdiomatticWP\Integrations\Builders
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\Builders;

use IdiomatticWP\Frontend\LanguageSwitcher;

// Doble guardia: si por alguna razón se incluye este archivo antes de que
// Elementor esté cargado, no produce un fatal — simplemente no define la clase.
if ( ! class_exists( \Elementor\Widget_Base::class ) ) {
	return;
}

class ElementorSwitcherWidget extends \Elementor\Widget_Base {

	public function __construct(
		array $data = [],
		?array $args = null,
		private ?LanguageSwitcher $switcher = null,
	) {
		parent::__construct( $data, $args );
	}

	public function get_name(): string {
		return 'idiomatticwp-switcher';
	}

	public function get_title(): string {
		return __( 'Language Switcher', 'idiomattic-wp' );
	}

	public function get_icon(): string {
		return 'eicon-globe';
	}

	public function get_categories(): array {
		return [ 'general' ];
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'section_style',
			[ 'label' => __( 'Style', 'idiomattic-wp' ) ]
		);

		$this->add_control(
			'show_flags',
			[
				'label'   => __( 'Show Flags', 'idiomattic-wp' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->add_control(
			'show_names',
			[
				'label'   => __( 'Show Names', 'idiomattic-wp' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! $this->switcher ) {
			return;
		}

		$settings = $this->get_settings_for_display();

		echo $this->switcher->render( [ // phpcs:ignore WordPress.Security.EscapeOutput
			'show_flags' => $settings['show_flags'] === 'yes',
			'show_names' => $settings['show_names'] === 'yes',
		] );
	}
}
