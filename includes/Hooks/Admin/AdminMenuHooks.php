<?php
/**
 * AdminMenuHooks — registers the main plugin menu and submenus.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Admin\Pages\ContentTranslationPage;
use IdiomatticWP\Admin\Pages\DashboardPage;
use IdiomatticWP\Admin\Pages\ImportExportPage;
use IdiomatticWP\Admin\Pages\OnboardingPage;
use IdiomatticWP\Admin\Pages\SettingsPage;
use IdiomatticWP\Admin\Pages\CompatibilityPage;
use IdiomatticWP\Admin\Pages\StringTranslationPage;
use IdiomatticWP\Core\Installer;
use IdiomatticWP\Core\LanguageManager;

class AdminMenuHooks implements HookRegistrarInterface {

	public function __construct(
		private DashboardPage          $dashboardPage,
		private SettingsPage           $settingsPage,
		private CompatibilityPage      $compatibilityPage,
		private StringTranslationPage  $stringTranslationPage,
		private ImportExportPage       $importExportPage,
		private ContentTranslationPage $contentTranslationPage,
		private OnboardingPage         $onboardingPage,
		private LanguageManager        $languageManager,
	) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenuPages' ] );
		add_action( 'admin_init', [ Installer::class, 'maybeRunPostActivationScan' ] );
		add_action( 'admin_notices', [ $this, 'maybeShowScanNotice' ] );
	}

	public function addMenuPages(): void {
		$capability = 'manage_options';

		add_menu_page(
			__( 'Idiomattic WP', 'idiomattic-wp' ),
			__( 'Idiomattic', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp',
			[ $this, 'maybeRenderDashboard' ],
			'dashicons-translation',
			65
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'Dashboard', 'idiomattic-wp' ),
			__( 'Dashboard', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp',
			[ $this, 'maybeRenderDashboard' ]
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'Content Translation', 'idiomattic-wp' ),
			__( 'Content Translation', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp-content',
			[ $this->contentTranslationPage, 'render' ]
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'String Translation', 'idiomattic-wp' ),
			__( 'String Translation', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp-strings',
			[ $this->stringTranslationPage, 'render' ]
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'Settings', 'idiomattic-wp' ),
			__( 'Settings', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp-settings',
			[ $this->settingsPage, 'render' ]
		);

		add_submenu_page(
			'idiomatticwp',
			__( 'Import / Export', 'idiomattic-wp' ),
			__( 'Import / Export', 'idiomattic-wp' ),
			$capability,
			'idiomatticwp-import-export',
			[ $this->importExportPage, 'render' ]
		);
	}

	public function maybeRenderDashboard(): void {
		$onboardingDone = get_option( 'idiomatticwp_onboarding_done', false );
		$hasLanguages   = count( $this->languageManager->getActiveLanguages() ) >= 2;

		if ( ! $onboardingDone && ! $hasLanguages ) {
			$this->onboardingPage->render();
		} else {
			$this->dashboardPage->render();
		}
	}

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
