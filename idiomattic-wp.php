<?php
/**
 * Plugin Name:       Idiomattic WP
 * Plugin URI:        https://idiomatticwp.com
 * Description:       Multilingual WordPress plugin. Bring Your Own Key (BYOK) — connect your own AI translation provider. No intermediaries, no markup on translations.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Idiomattic WP
 * Author URI:        https://idiomatticwp.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       idiomattic-wp
 * Domain Path:       /languages
 *
 * @package IdiomatticWP
 */

declare( strict_types=1 );

// ── Guards ────────────────────────────────────────────────────────────────────
defined( 'ABSPATH' ) || exit;

if ( defined( 'IDIOMATTICWP_VERSION' ) ) {
	return;
}

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'IDIOMATTICWP_VERSION',    '1.0.1' );
define( 'IDIOMATTICWP_FILE',       __FILE__ );
define( 'IDIOMATTICWP_PATH',       plugin_dir_path( __FILE__ ) );
define( 'IDIOMATTICWP_URL',        plugin_dir_url( __FILE__ ) );
define( 'IDIOMATTICWP_ASSETS_URL', IDIOMATTICWP_URL . 'assets/' );
// Use minified assets in production. During development (SCRIPT_DEBUG=true or
// no build artefacts present) fall back to the unminified source files.
define( 'IDIOMATTICWP_MIN', ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : (
	file_exists( plugin_dir_path( __FILE__ ) . 'assets/js/admin/admin.min.js' ) ? '.min' : ''
) );

// ── Autoloader ────────────────────────────────────────────────────────────────
require_once IDIOMATTICWP_PATH . 'includes/Support/Autoloader.php';
\IdiomatticWP\Support\Autoloader::register();

// ── Public API helper functions ───────────────────────────────────────────────
require_once IDIOMATTICWP_PATH . 'includes/Support/PublicApi.php';

// ── Freemius SDK ──────────────────────────────────────────────────────────────
// Uncomment when Freemius SDK is added (Phase: licensing):
// require_once IDIOMATTICWP_PATH . 'includes/License/FreemiusBootstrap.php';

// ── Lifecycle hooks ───────────────────────────────────────────────────────────
register_activation_hook(   __FILE__, [ \IdiomatticWP\Core\Installer::class, 'activate'   ] );
register_deactivation_hook( __FILE__, [ \IdiomatticWP\Core\Installer::class, 'deactivate' ] );

// ── Translations ──────────────────────────────────────────────────────────────
// Must be hooked on 'init' (WP 6.7+ requirement). Priority 1 ensures the
// textdomain is loaded before any of our own 'init' callbacks run.
add_action( 'init', static function (): void {
	load_plugin_textdomain(
		'idiomattic-wp',
		false,
		dirname( plugin_basename( IDIOMATTICWP_FILE ) ) . '/languages'
	);
}, 1 );

// ── Boot ──────────────────────────────────────────────────────────────────────
// Priority 0 so all other plugins are loaded before our integrations run.
add_action( 'plugins_loaded', static function (): void {
	\IdiomatticWP\Core\Plugin::getInstance()->boot();
}, 0 );
