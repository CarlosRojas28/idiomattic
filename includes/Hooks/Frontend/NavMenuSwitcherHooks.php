<?php
/**
 * NavMenuSwitcherHooks — injects language-switching items into WordPress nav menus.
 *
 * Unlike NavMenuHooks (which translates existing menu item URLs), this class
 * appends new `<li>` items representing each active language so visitors can
 * switch language directly from the navigation menu.
 *
 * ── Two ways to enable the switcher on a menu ──────────────────────────────
 *
 * 1. Theme location setting (stored in DB):
 *    Add a theme location slug to the `idiomatticwp_menu_lang_switcher_locations`
 *    option (an array of strings). Example via filter:
 *
 *      add_filter( 'idiomatticwp_menu_lang_switcher_locations', function( $locs ) {
 *          $locs[] = 'primary';
 *          return $locs;
 *      } );
 *
 * 2. Inline `wp_nav_menu()` argument:
 *    Pass `idiomatticwp_lang_switcher` in the args array when calling
 *    `wp_nav_menu()` — either `true` for defaults or an array of render options:
 *
 *      wp_nav_menu( [
 *          'theme_location'             => 'primary',
 *          'idiomatticwp_lang_switcher' => true,
 *      ] );
 *
 *      wp_nav_menu( [
 *          'theme_location'             => 'primary',
 *          'idiomatticwp_lang_switcher' => [
 *              'show_flags'   => true,
 *              'show_names'   => true,
 *              'hide_current' => false,
 *          ],
 *      ] );
 *
 * The injected items carry two CSS classes:
 *   `menu-item`                — makes them behave like standard menu items
 *   `idiomatticwp-menu-lang`   — allows per-plugin targeting in CSS
 *   `idiomatticwp-menu-lang--{code}` — per-language targeting (e.g. --fr, --es)
 *   `current-menu-item` + `idiomatticwp-menu-lang--active` — on the active language
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Frontend\LanguageSwitcher;

class NavMenuSwitcherHooks implements HookRegistrarInterface {

	public function __construct( private LanguageSwitcher $switcher ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_filter( 'wp_nav_menu_items', [ $this, 'injectLanguageItems' ], 20, 2 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Append language-switcher `<li>` items to enabled nav menus.
	 *
	 * Runs after WordPress renders the full menu HTML so it works with every
	 * theme regardless of how they call `wp_nav_menu()`.
	 *
	 * @param string    $items  Rendered menu items HTML.
	 * @param \stdClass $args   Arguments passed to wp_nav_menu().
	 * @return string
	 */
	public function injectLanguageItems( string $items, \stdClass $args ): string {
		[ $enabled, $renderArgs ] = $this->resolveConfig( $args );

		if ( ! $enabled ) {
			return $items;
		}

		$langItems = $this->switcher->renderMenuItems( $renderArgs );
		if ( $langItems === '' ) {
			return $items;
		}

		return $items . $langItems;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Determine whether this menu should receive language items and what args to use.
	 *
	 * Checks in priority order:
	 *   1. `idiomatticwp_lang_switcher` key in the wp_nav_menu() args
	 *   2. Theme location in the `idiomatticwp_menu_lang_switcher_locations` option/filter
	 *
	 * @param \stdClass $args  wp_nav_menu() arguments.
	 * @return array{ bool, array }  [enabled, render_args]
	 */
	private function resolveConfig( \stdClass $args ): array {
		// 1. Explicit inline argument
		if ( isset( $args->idiomatticwp_lang_switcher ) && $args->idiomatticwp_lang_switcher !== false ) {
			$renderArgs = is_array( $args->idiomatticwp_lang_switcher )
				? $args->idiomatticwp_lang_switcher
				: [];
			return [ true, $renderArgs ];
		}

		// 2. Theme location setting
		$location = $args->theme_location ?? '';
		if ( $location === '' ) {
			return [ false, [] ];
		}

		$stored  = get_option( 'idiomatticwp_menu_lang_switcher_locations', [] );
		$enabled = is_array( $stored ) ? $stored : [];
		$enabled = apply_filters( 'idiomatticwp_menu_lang_switcher_locations', $enabled );
		$enabled = is_array( $enabled ) ? $enabled : []; // guard against bad filter return

		if ( in_array( $location, $enabled, true ) ) {
			return [ true, [] ];
		}

		return [ false, [] ];
	}
}
