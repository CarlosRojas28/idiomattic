<?php
/**
 * MenuTranslationHooks — swaps nav menus per-language based on admin configuration.
 *
 * When a visitor views the site in a non-default language, any menu rendered
 * via wp_nav_menu() whose theme_location has a language-specific menu
 * configured in the plugin settings will be replaced by that menu.
 *
 * The option `idiomatticwp_nav_menus` stores:
 *   [ theme_location => [ lang_code => menu_id ] ]
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;

class MenuTranslationHooks implements HookRegistrarInterface {

	public function __construct( private LanguageManager $lm ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_filter( 'wp_nav_menu_args', [ $this, 'swapMenu' ], 5 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Replace the menu for the current language if one is configured.
	 *
	 * Priority 5 runs before NavMenuHooks (priority 10) so the correct menu
	 * items are loaded before URL rewriting occurs.
	 *
	 * @param array $args wp_nav_menu() arguments.
	 * @return array
	 */
	public function swapMenu( array $args ): array {
		$lang    = (string) $this->lm->getCurrentLanguage();
		$default = (string) $this->lm->getDefaultLanguage();

		// Nothing to swap for the default language.
		if ( $lang === $default ) {
			return $args;
		}

		$navMenus = get_option( 'idiomatticwp_nav_menus', [] );
		if ( ! is_array( $navMenus ) ) {
			return $args;
		}

		$location = $args['theme_location'] ?? '';
		if ( ! $location ) {
			return $args;
		}

		$menuId = (int) ( $navMenus[ $location ][ $lang ] ?? 0 );
		if ( $menuId <= 0 ) {
			return $args;
		}

		// Override with the language-specific menu.
		$args['menu'] = $menuId;

		// Clear theme_location so WordPress uses our explicit menu ID instead
		// of looking up the location's default assignment.
		$args['theme_location'] = '';

		return $args;
	}
}
