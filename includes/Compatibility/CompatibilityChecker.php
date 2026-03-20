<?php
/**
 * CompatibilityChecker — detects and warns about known plugin conflicts.
 *
 * @package IdiomatticWP\Compatibility
 */

declare( strict_types=1 );

namespace IdiomatticWP\Compatibility;

class CompatibilityChecker {

	/**
	 * Render admin notices for detected conflicts.
	 * Hooked into admin_notices.
	 */
	public function renderNotices(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		foreach ( $this->detect() as $notice ) {
			printf(
				'<div class="notice notice-%s"><p><strong>Idiomattic WP:</strong> %s</p></div>',
				esc_attr( $notice['type'] ),
				wp_kses_post( $notice['message'] )
			);
		}
	}

	/**
	 * Returns array of detected conflicts with type ('error'|'warning') and message.
	 */
	public function detect(): array {
		$notices = [];

		// ── Other multilingual plugins ─────────────────────────────────────
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$notices[] = [
				'type'    => 'error',
				'message' => __( 'WPML is active. Running two multilingual plugins simultaneously can cause conflicts. Please deactivate WPML before using Idiomattic WP, or use the <a href="admin.php?page=idiomatticwp&tab=migration">WPML migration tool</a>.', 'idiomattic-wp' ),
			];
		}

		if ( defined( 'POLYLANG_VERSION' ) ) {
			$notices[] = [
				'type'    => 'error',
				'message' => __( 'Polylang is active. Running two multilingual plugins simultaneously can cause conflicts. Please deactivate Polylang before using Idiomattic WP.', 'idiomattic-wp' ),
			];
		}

		if ( defined( 'TRANSLATEPRESS_VERSION' ) ) {
			$notices[] = [
				'type'    => 'warning',
				'message' => __( 'TranslatePress is active. This may conflict with Idiomattic WP\'s language switching. Consider deactivating it.', 'idiomattic-wp' ),
			];
		}

		// ── Caching plugins — warn when using DirectoryStrategy ───────────────
		$urlMode = get_option( 'idiomatticwp_url_mode', 'parameter' );
		if ( $urlMode === 'directory' || $urlMode === 'subdomain' ) {
			if ( defined( 'WP_ROCKET_VERSION' ) ) {
				$notices[] = [
					'type'    => 'warning',
					'message' => __( 'WP Rocket is active. Make sure to configure WP Rocket\'s "Separate cache files for each language" option under Advanced Rules to prevent incorrect pages being served from cache.', 'idiomattic-wp' ),
				];
			}

			if ( class_exists( 'LiteSpeed_Cache' ) || defined( 'LSCWP_V' ) ) {
				$notices[] = [
					'type'    => 'warning',
					'message' => __( 'LiteSpeed Cache is active. Ensure the "Vary Group" is configured to cache pages separately per language.', 'idiomattic-wp' ),
				];
			}

			if ( defined( 'W3TC_VERSION' ) ) {
				$notices[] = [
					'type'    => 'warning',
					'message' => __( 'W3 Total Cache is active. Make sure page caching is configured to handle language-specific URLs correctly.', 'idiomattic-wp' ),
				];
			}
		}

		// ── No default language configured ─────────────────────────────────
		$defaultLang = get_option( 'idiomatticwp_default_lang', '' );
		if ( '' === $defaultLang ) {
			$notices[] = [
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %s = link to settings */
					__( 'Idiomattic WP is not configured yet. <a href="%s">Configure your languages →</a>', 'idiomattic-wp' ),
					admin_url( 'admin.php?page=idiomatticwp-settings&tab=languages' )
				),
			];
		}

		return $notices;
	}
}
