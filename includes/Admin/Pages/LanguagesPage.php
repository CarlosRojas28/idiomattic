<?php
/**
 * LanguagesPage — admin page for managing active languages.
 *
 * Note: language management has been merged into SettingsPage → Languages tab.
 * This class exists as a compatibility stub in case it is referenced elsewhere.
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

class LanguagesPage {

	public function render(): void {
		// Redirect to the Settings page Languages tab
		wp_safe_redirect( admin_url( 'admin.php?page=idiomatticwp-settings&tab=languages' ) );
		exit;
	}
}
