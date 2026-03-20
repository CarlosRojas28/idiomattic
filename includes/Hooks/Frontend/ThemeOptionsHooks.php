<?php
/**
 * ThemeOptionsHooks — filters WordPress options to return translated values.
 *
 * When a theme or plugin stores translatable content in wp_options (e.g. site
 * tagline, header text, footer credits), this hook intercepts get_option() and
 * returns the translated value for the current language.
 *
 * Only options explicitly registered via idiomatticwp_register_option() are
 * filtered. Everything else passes through unchanged.
 *
 * Also filters:
 *   - blogname / blogdescription (site title and tagline)
 *   - widget settings (Classic Widgets content blocks)
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Strings\StringTranslator;

class ThemeOptionsHooks implements HookRegistrarInterface {

	/**
	 * Core WP options that are always translatable without explicit registration.
	 */
	private const CORE_TRANSLATABLE_OPTIONS = [
		'blogname',
		'blogdescription',
	];

	public function __construct(
		private LanguageManager       $languageManager,
		private CustomElementRegistry $registry,
		private StringTranslator      $stringTranslator,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Filter core site options
		foreach ( self::CORE_TRANSLATABLE_OPTIONS as $optionName ) {
			add_filter( "option_{$optionName}", [ $this, 'filterCoreOption' ], 10, 2 );
		}

		// Filter registered custom options
		add_action( 'idiomatticwp_loaded', [ $this, 'hookRegisteredOptions' ], 20 );

		// Filter Classic Widgets text blocks
		add_filter( 'widget_text',         [ $this, 'filterWidgetText' ], 10 );
		add_filter( 'widget_text_content',  [ $this, 'filterWidgetText' ], 10 );
		add_filter( 'widget_title',        [ $this, 'filterWidgetText' ], 10 );
	}

	/**
	 * After the plugin is fully loaded, hook into each registered option.
	 * Called late so all calls to idiomatticwp_register_option() have run.
	 */
	public function hookRegisteredOptions(): void {
		foreach ( $this->registry->getRegisteredOptions() as $optionName => $config ) {
			// Skip core options already hooked above to avoid double-filtering
			if ( in_array( $optionName, self::CORE_TRANSLATABLE_OPTIONS, true ) ) {
				continue;
			}

			// Bind option name via closure to avoid shared variable issues
			add_filter(
				"option_{$optionName}",
				function ( $value ) use ( $optionName ) {
					return $this->filterRegisteredOption( $value, $optionName );
				},
				10
			);
		}
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Filter blogname / blogdescription and other core options.
	 *
	 * The $optionName is passed by WordPress as the second argument to the
	 * `option_{$option}` filter, but WordPress actually passes the option
	 * value and the option name in that order only since WP 4.9. We rely
	 * on the filter tag itself to know the option name.
	 *
	 * @param mixed  $value      The option value.
	 * @param string $optionName The option name (WP passes this since 4.9).
	 * @return mixed
	 */
	public function filterCoreOption( $value, string $optionName = '' ): mixed {
		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}

		$current = $this->languageManager->getCurrentLanguage();
		if ( $this->languageManager->isDefault( $current ) ) {
			return $value;
		}

		// Use StringTranslator — the translated value may have been registered
		// via idiomatticwp_register_string() or edited in the Strings screen.
		$translated = $this->stringTranslator->translate( $value, 'idiomattic-wp', $current );

		return ( $translated !== $value ) ? $translated : $value;
	}

	/**
	 * Filter a custom registered option value.
	 *
	 * @param mixed  $value      The option value (may be string, array, or object).
	 * @param string $optionName The option name.
	 * @return mixed
	 */
	public function filterRegisteredOption( $value, string $optionName ): mixed {
		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}

		$current = $this->languageManager->getCurrentLanguage();
		if ( $this->languageManager->isDefault( $current ) ) {
			return $value;
		}

		$translated = $this->stringTranslator->translate( $value, 'idiomattic-wp', $current );

		return ( $translated !== $value ) ? $translated : $value;
	}

	/**
	 * Filter widget text content (Classic Widgets text blocks, titles).
	 *
	 * @param string $text Widget text / title.
	 * @return string
	 */
	public function filterWidgetText( string $text ): string {
		if ( $text === '' ) {
			return $text;
		}

		$current = $this->languageManager->getCurrentLanguage();
		if ( $this->languageManager->isDefault( $current ) ) {
			return $text;
		}

		$translated = $this->stringTranslator->translate( $text, 'idiomattic-wp', $current );

		return ( $translated !== $text ) ? $translated : $text;
	}
}
