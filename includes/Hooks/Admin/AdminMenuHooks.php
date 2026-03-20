<?php
/**
 * AdminMenuHooks — registers the main plugin menu and submenus.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Admin\Pages\DashboardPage;
use IdiomatticWP\Admin\Pages\SettingsPage;
use IdiomatticWP\Admin\Pages\CompatibilityPage;
use IdiomatticWP\Core\Installer;

class AdminMenuHooks implements HookRegistrarInterface {

	public function __construct(
		private DashboardPage    $dashboardPage,
		private SettingsPage     $settingsPage,
		private CompatibilityPage $compatibilityPage,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────────

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenuPages' ] );

		// Run the post-activation compatibility scan on the first admin load.
		add_action( 'admin_init', [ Installer::class, 'maybeRunPostActivationScan' ] );

		// Show admin notice after a fresh scan triggered by activation.
		add_action( 'admin_notices', [ $this, 'maybeShowScanNotice' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────────

	public function addMenuPages(): void {
		$capability = 'manage_options';

		add_menu_page(
			'Idiomattic WP',
			'Idiomattic',
			$capability,
			'idiomatticwp',
			[ $this->dashboardPage, 'render' ],
			'dashicons-translation',
			65
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'Dashboard', 'idiomattic-wp' ),
			__( 'Dashboard', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp',
			[ $this->dashboardPage, 'render' ]
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'Compatibility', 'idiomattic-wp' ),
			__( 'Compatibility', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp-compatibility',
			[ $this->compatibilityPage, 'render' ]
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'Settings', 'idiomattic-wp' ),
			__( 'Settings', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp-settings',
			[ $this->settingsPage, 'render' ]
		);
	}

	/**
	 * Show a one-time admin notice pointing to the Compatibility page
	 * right after plugin activation.
	 */
	public function maybeShowScanNotice(): void {
		if ( ! get_option( 'idiomatticwp_show_compat_notice' ) ) {
			return;
		}
		delete_option( 'idiomatticwp_show_compat_notice' );

		$url = admin_url( 'admin.php?page=idiomatticwp-compatibility' );
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Idiomattic WP has scanned your active plugins and theme for compatibility.', 'idiomattic-wp' ),
			esc_url( $url ),
			esc_html__( 'View compatibility report →', 'idiomattic-wp' )
		);
	}
}
