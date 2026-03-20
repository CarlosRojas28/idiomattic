<?php
/**
 * AssetHooks — enqueues the admin JavaScript and CSS for Idiomattic WP.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;

class AssetHooks implements HookRegistrarInterface
{

    public function __construct()
    {
    }

    // ── HookRegistrarInterface ────────────────────────────────────────────

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    // ── Callbacks ─────────────────────────────────────────────────────────

    /**
     * Enqueue assets on post list and post editor screens.
     *
     * @param string $hookSuffix The current admin page suffix.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        // Only load on post list and editor pages
        $allowedHooks = ['edit.php', 'post.php', 'post-new.php'];
        if (!in_array($hookSuffix, $allowedHooks, true)) {
            return;
        }

        wp_enqueue_script(
            'idiomatticwp-admin',
            IDIOMATTICWP_ASSETS_URL . 'js/admin/admin' . IDIOMATTICWP_MIN . '.js',
            [ 'jquery' ],
            IDIOMATTICWP_VERSION,
            true
        );

        wp_localize_script( 'idiomatticwp-admin', 'idiomatticwpAdmin', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'idiomatticwp_nonce' ),
            'upgradeUrl' => idiomatticwp_upgrade_url(),
            'i18n'       => [
                'creating'       => __( 'Creating...', 'idiomattic-wp' ),
                'error'          => __( 'Error creating translation. Please try again.', 'idiomattic-wp' ),
                'confirm_delete' => __( 'Are you sure you want to delete this translation?', 'idiomattic-wp' ),
            ],
        ] );

        wp_enqueue_style(
            'idiomatticwp-admin',
            IDIOMATTICWP_ASSETS_URL . 'css/admin' . IDIOMATTICWP_MIN . '.css',
            [],
            IDIOMATTICWP_VERSION
        );
    }
}
