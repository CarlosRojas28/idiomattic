<?php
/**
 * ScanStringsAjax — scans a plugin or theme directory for translatable strings
 * and registers them in the idiomatticwp_strings table for all active languages.
 *
 * POST params:
 *   nonce  — idiomatticwp_nonce
 *   type   — 'plugin' | 'theme' | 'theme-child'
 *   slug   — plugin file (e.g. 'my-plugin/my-plugin.php') or theme stylesheet slug
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Repositories\StringRepository;
use IdiomatticWP\Strings\LanguagePackImporter;
use IdiomatticWP\Strings\StringScanner;

class ScanStringsAjax {

	public function __construct(
		private StringScanner        $scanner,
		private StringRepository     $stringRepo,
		private LanguageManager      $languageManager,
		private LanguagePackImporter $importer,
	) {}

	public function handle(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ], 403 );
		}

		$type = sanitize_key( $_POST['type'] ?? '' );
		$slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );

		if ( ! in_array( $type, [ 'plugin', 'theme', 'theme-child' ], true ) || $slug === '' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'idiomattic-wp' ) ], 400 );
		}

		[ $directory, $domain, $version ] = $this->resolveDirectoryAndDomain( $type, $slug );

		if ( ! $directory || ! is_dir( $directory ) ) {
			wp_send_json_error( [ 'message' => __( 'Directory not found.', 'idiomattic-wp' ) ], 404 );
		}

		if ( $domain === '' ) {
			wp_send_json_error( [ 'message' => __( 'No text domain found for this source.', 'idiomattic-wp' ) ], 400 );
		}

		$strings     = $this->scanner->scan( $directory, $domain );
		$targetLangs = $this->getTargetLanguages();
		$registered  = 0;

		// Register each scanned string as pending for every target language.
		foreach ( $strings as $ts ) {
			foreach ( $targetLangs as $lang ) {
				$this->stringRepo->register( $ts->domain, $ts->originalString, $ts->context, $lang );
				$registered++;
			}
		}

		// Import existing .po/.mo translations for this source across all target languages.
		// This fills in status='translated' for any string that already has a translation,
		// including files bundled inside the plugin/theme directory (e.g. WooCommerce).
		$importType = ( $type === 'theme-child' ) ? 'theme' : $type;

		// For plugins, $slug is the full plugin file (e.g. "woocommerce/woocommerce.php").
		// LanguagePackImporter expects the directory slug (e.g. "woocommerce"), so derive it.
		$importSlug = ( $type === 'plugin' ) ? dirname( $slug ) : $slug;
		if ( $importSlug === '.' ) {
			$importSlug = basename( $slug, '.php' );
		}

		$this->importer->importSourceForLangs( $importType, $importSlug, $domain, $version, $targetLangs );

		wp_send_json_success( [
			'found'      => count( $strings ),
			'registered' => $registered,
			'languages'  => count( $targetLangs ),
		] );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * @return array{0: string|null, 1: string, 2: string}  [directory, domain, version]
	 */
	private function resolveDirectoryAndDomain( string $type, string $slug ): array {
		if ( $type === 'theme' || $type === 'theme-child' ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				return [ null, '', '' ];
			}
			return [
				$theme->get_stylesheet_directory(),
				(string) ( $theme->get( 'TextDomain' ) ?: $slug ),
				(string) $theme->get( 'Version' ),
			];
		}

		// Plugin: slug is the plugin file relative to plugins dir.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$allPlugins = get_plugins();
		$pluginData = $allPlugins[ $slug ] ?? null;
		$domain     = $pluginData ? (string) ( $pluginData['TextDomain'] ?? '' ) : '';
		$version    = $pluginData ? (string) ( $pluginData['Version'] ?? '' ) : '';
		$directory  = WP_PLUGIN_DIR . '/' . dirname( $slug );

		return [ is_dir( $directory ) ? $directory : null, $domain, $version ];
	}

	/** @return string[] Active non-default language codes. */
	private function getTargetLanguages(): array {
		$default = (string) $this->languageManager->getDefaultLanguage();
		return array_values( array_filter(
			array_map( 'strval', $this->languageManager->getActiveLanguages() ),
			fn( $l ) => $l !== $default
		) );
	}
}
