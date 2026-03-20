<?php
/**
 * SwitcherHooks — registers the language switcher shortcode and widget.
 *
 * Shortcode usage:
 *   [idiomatticwp_switcher]
 *   [idiomatticwp_switcher style="dropdown" hide_current="true" hide_untranslated="true"]
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Frontend\LanguageSwitcher;
use IdiomatticWP\Frontend\LanguageSwitcherWidget;

class SwitcherHooks implements HookRegistrarInterface {

	public function __construct( private LanguageSwitcher $switcher ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Widget
		add_action( 'widgets_init', static function () {
			register_widget( LanguageSwitcherWidget::class );
		} );

		// Shortcode — [idiomatticwp_switcher]
		add_shortcode( 'idiomatticwp_switcher', [ $this, 'renderShortcode' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Render the language switcher shortcode.
	 *
	 * Supported attributes (all optional):
	 *   style             list|dropdown       default: list
	 *   show_flags        true|false          default: true
	 *   show_names        true|false          default: true
	 *   show_native_names true|false          default: false
	 *   hide_current      true|false          default: false
	 *
	 * Note: languages without a translation are hidden automatically based on
	 * the post type's translation mode (Settings → Content). There is no
	 * separate hide_untranslated attribute — visibility is managed per-context.
	 *
	 * @param array|string $atts  Shortcode attributes.
	 * @return string             HTML output.
	 */
	public function renderShortcode( array|string $atts ): string {
		$atts = shortcode_atts(
			[
				'style'             => 'list',
				'show_flags'        => 'true',
				'show_names'        => 'true',
				'show_native_names' => 'false',
				'hide_current'      => 'false',
			],
			$atts,
			'idiomatticwp_switcher'
		);

		// Cast string booleans from shortcode attributes to real booleans
		$args = [
			'style'             => sanitize_key( $atts['style'] ),
			'show_flags'        => $this->toBool( $atts['show_flags'] ),
			'show_names'        => $this->toBool( $atts['show_names'] ),
			'show_native_names' => $this->toBool( $atts['show_native_names'] ),
			'hide_current'      => $this->toBool( $atts['hide_current'] ),
		];

		return $this->switcher->render( $args );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Convert a shortcode string attribute value to a PHP boolean.
	 * Treats "false", "0", "no", "" as false; everything else as true.
	 */
	private function toBool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$str = strtolower( trim( (string) $value ) );
		return ! in_array( $str, [ 'false', '0', 'no', '' ], true );
	}
}
