<?php
/**
 * Installer — activation, deactivation, DB table creation.
 *
 * @package IdiomatticWP\Core
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Core;

class Installer
{

	private const DB_VERSION = '1.0.0';

	// ── Activation ────────────────────────────────────────────────────────────

	public static function activate(): void
	{
		// Multisite network-activation is not supported yet.
		// The plugin must be activated per-site until network support is implemented.
		if ( is_multisite() && is_network_admin() ) {
			// translators: plugin name
			$message = sprintf(
				__(
					'%s cannot be network-activated. Please activate it on individual sites instead. Network-wide support is planned for a future release.',
					'idiomattic-wp'
				),
				'<strong>Idiomattic WP</strong>'
			);
			wp_die(
				wp_kses( $message, [ 'strong' => [] ] ),
				esc_html__( 'Plugin Activation Error', 'idiomattic-wp' ),
				[ 'back_link' => true ]
			);
		}

		self::createTables();
		self::setDefaultOptions();
		update_option('idiomatticwp_db_version', self::DB_VERSION);

		// Signal setup wizard only on genuinely fresh installations
		// (i.e. no active languages configured yet). This flag is cleared in
		// SettingsHooks::sanitizeActiveLanguages() once the user saves languages.
		$activeLangs = get_option( 'idiomatticwp_active_langs', [] );
		if ( empty( $activeLangs ) ) {
			update_option( 'idiomatticwp_needs_setup', '1' );
		} else {
			// Already configured (e.g. re-activation) — clear flag immediately.
			delete_option( 'idiomatticwp_needs_setup' );
		}

		// Schedule a compatibility scan to run on the next admin load.
		// We can't run it here directly because WP theme/plugin functions
		// (get_plugins, wp_get_theme) are not fully available during activation.
		update_option('idiomatticwp_run_compat_scan', true);

		flush_rewrite_rules();
	}

	// ── Deactivation ──────────────────────────────────────────────────────────

	public static function deactivate(): void
	{
		flush_rewrite_rules();

		// Remove our transients
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_idiomatticwp_%'
			    OR option_name LIKE '_transient_timeout_idiomatticwp_%'"
		);
	}

	/**
	 * Run the compatibility scan on the first admin load after activation.
	 * Called by the CompatibilityScanOnActivationHook.
	 */
	public static function maybeRunPostActivationScan(): void
	{
		if ( ! get_option( 'idiomatticwp_run_compat_scan' ) ) {
			return;
		}

		delete_option( 'idiomatticwp_run_compat_scan' );

		// Trigger a fresh scan via the container
		if ( class_exists( \IdiomatticWP\Core\Plugin::class ) ) {
			$scanner = \IdiomatticWP\Core\Plugin::getInstance()
				->getContainer()
				->get( \IdiomatticWP\Compatibility\CompatibilityScanner::class );
			$scanner->scan( true );

			// Show a one-time admin notice pointing to the Compatibility page.
			update_option( 'idiomatticwp_show_compat_notice', true );
		}
	}

	// ── Table creation ────────────────────────────────────────────────────────

	private static function createTables(): void
	{
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p = $wpdb->prefix;

		// ── idiomatticwp_translations ─────────────────────────────────────────
		dbDelta("CREATE TABLE IF NOT EXISTS {$p}idiomatticwp_translations (
		  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  source_post_id     BIGINT UNSIGNED NOT NULL,
		  translated_post_id BIGINT UNSIGNED NOT NULL,
		  source_lang        VARCHAR(10) NOT NULL,
		  target_lang        VARCHAR(10) NOT NULL,
		  status             ENUM('draft','in_progress','complete','outdated','failed') NOT NULL DEFAULT 'draft',
		  translation_mode   ENUM('duplicate','editor','automatic') NOT NULL DEFAULT 'duplicate',
		  provider_used      VARCHAR(50) DEFAULT NULL,
		  needs_update       TINYINT(1) NOT NULL DEFAULT 0,
		  translated_at      DATETIME DEFAULT NULL,
		  created_at         DATETIME NOT NULL,
		  PRIMARY KEY  (id),
		  UNIQUE KEY source_lang_pair (source_post_id, target_lang),
		  KEY translated_post_id (translated_post_id),
		  KEY status (status)
		) {$charset};");

		// ── idiomatticwp_field_translations ───────────────────────────────────
		dbDelta("CREATE TABLE IF NOT EXISTS {$p}idiomatticwp_field_translations (
		  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  translation_id   BIGINT UNSIGNED NOT NULL,
		  field_key        VARCHAR(255) NOT NULL,
		  source_value     LONGTEXT,
		  translated_value LONGTEXT,
		  status           ENUM('pending','translated','reviewed') NOT NULL DEFAULT 'pending',
		  PRIMARY KEY  (id),
		  KEY translation_id (translation_id),
		  KEY field_key (field_key(191))
		) {$charset};");

		// ── idiomatticwp_translation_memory ───────────────────────────────────
		dbDelta("CREATE TABLE IF NOT EXISTS {$p}idiomatticwp_translation_memory (
		  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  source_lang     VARCHAR(10) NOT NULL,
		  target_lang     VARCHAR(10) NOT NULL,
		  source_hash     CHAR(32) NOT NULL,
		  source_text     MEDIUMTEXT NOT NULL,
		  translated_text MEDIUMTEXT NOT NULL,
		  provider_used   VARCHAR(50) DEFAULT NULL,
		  quality_score   TINYINT UNSIGNED DEFAULT NULL,
		  usage_count     INT UNSIGNED NOT NULL DEFAULT 0,
		  last_used_at    DATETIME DEFAULT NULL,
		  created_at      DATETIME NOT NULL,
		  PRIMARY KEY  (id),
		  UNIQUE KEY lang_hash (source_lang, target_lang, source_hash),
		  KEY usage_count (usage_count)
		) {$charset};");

		// ── idiomatticwp_strings ──────────────────────────────────────────────
		dbDelta("CREATE TABLE IF NOT EXISTS {$p}idiomatticwp_strings (
		  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  domain            VARCHAR(255) NOT NULL,
		  context           VARCHAR(255) DEFAULT NULL,
		  source_string     MEDIUMTEXT NOT NULL,
		  source_hash       CHAR(32) NOT NULL,
		  lang              VARCHAR(10) NOT NULL,
		  translated_string MEDIUMTEXT DEFAULT NULL,
		  status            ENUM('pending','translated','reviewed') NOT NULL DEFAULT 'pending',
		  PRIMARY KEY  (id),
		  UNIQUE KEY domain_hash_lang (domain, source_hash, lang),
		  KEY status (status)
		) {$charset};");

		// ── idiomatticwp_glossary ─────────────────────────────────────────────
		dbDelta("CREATE TABLE IF NOT EXISTS {$p}idiomatticwp_glossary (
		  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  source_lang     VARCHAR(10) NOT NULL,
		  target_lang     VARCHAR(10) NOT NULL,
		  source_term     VARCHAR(500) NOT NULL,
		  translated_term VARCHAR(500) NOT NULL,
		  forbidden       TINYINT(1) NOT NULL DEFAULT 0,
		  notes           TEXT DEFAULT NULL,
		  created_at      DATETIME NOT NULL,
		  PRIMARY KEY  (id),
		  KEY lang_pair (source_lang, target_lang)
		) {$charset};");
	}

	// ── Default options ───────────────────────────────────────────────────────

	/**
	 * Detect the WordPress installation locale and return the matching
	 * BCP-47 language code from the plugin's languages config.
	 *
	 * Resolution order:
	 *  1. Exact locale match (e.g. en_GB → en-GB).
	 *  2. Primary-language match (e.g. es_MX → es).
	 *  3. Fallback: 'en'.
	 */
	private static function detectWpLanguageCode(): string
	{
		$wpLocale = get_locale(); // e.g. 'en_GB', 'es_ES', 'fr_FR'

		$languages = require dirname( __DIR__, 2 ) . '/config/languages.php';

		// Build a locale → BCP-47 code map.
		$localeMap = [];
		foreach ( $languages as $code => $data ) {
			$localeMap[ $data['locale'] ] = $code;
		}

		// 1. Exact match.
		if ( isset( $localeMap[ $wpLocale ] ) ) {
			return $localeMap[ $wpLocale ];
		}

		// 2. Primary-language prefix match (e.g. 'es' from 'es_MX').
		$primary = strtolower( explode( '_', $wpLocale )[0] );
		if ( isset( $languages[ $primary ] ) ) {
			return $primary;
		}

		return 'en';
	}

	private static function setDefaultOptions(): void
	{
		add_option('idiomatticwp_default_lang', self::detectWpLanguageCode());
		add_option('idiomatticwp_active_langs', []);
		add_option('idiomatticwp_url_mode', 'parameter');
		add_option('idiomatticwp_auto_translate', false);
		add_option('idiomatticwp_tm_enabled', false);
		add_option('idiomatticwp_uninstall_retention', '1');  // '1' = keep data on uninstall
		add_option('idiomatticwp_db_version', '');
	}
}
