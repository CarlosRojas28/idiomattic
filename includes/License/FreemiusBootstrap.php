<?php
/**
 * FreemiusBootstrap — initialises the Freemius SDK
 *
 * Freemius handles: licensing, payments (Stripe/PayPal), plugin updates,
 * staging site detection, user analytics opt-in, affiliate program.
 *
 * This file is included directly from idiomattic-wp.php (not autoloaded)
 * because Freemius must be initialised before WordPress's 'init' action
 * and before any other Freemius-dependent code runs.
 *
 * Freemius SDK location: includes/License/freemius/ (bundled)
 * The SDK is NOT autoloaded — it has its own require_once chain.
 *
 * Configuration values to fill in from your Freemius dashboard:
 *  - id         : Your plugin ID from Freemius
 *  - slug       : 'idiomattic-wp'
 *  - public_key : Your plugin's public key from Freemius
 *
 * Staging detection:
 *  Freemius automatically treats localhost, *.local, *.dev, *.test,
 *  *.wpengine.com, *.kinsta.cloud, etc. as sandbox environments.
 *  Manual staging designation is configured in the Freemius dashboard.
 *
 * Plan slugs (must match Freemius dashboard exactly):
 *  - 'free'   — no license required
 *  - 'pro'    — single site
 *  - 'agency' — up to 30 sites
 *
 * @package  IdiomatticWP\License
 */

declare( strict_types=1 );

// Guard: only initialise once (in case of theme/plugin conflicts)
if ( function_exists( 'idiomatticwp_fs' ) ) {
    return;
}

/**
 * Returns the Freemius instance for Idiomattic WP.
 * Call idiomatticwp_fs() anywhere you need to interact with Freemius.
 */
function idiomatticwp_fs(): \Freemius {
    global $idiomatticwp_fs;

    if ( ! isset( $idiomatticwp_fs ) ) {
        // TODO: require_once IDIOMATTICWP_PATH . 'includes/License/freemius/start.php';

        // TODO: $idiomatticwp_fs = fs_dynamic_init( [
        //     'id'              => 'YOUR_PLUGIN_ID',
        //     'slug'            => 'idiomattic-wp',
        //     'type'            => 'plugin',
        //     'public_key'      => 'YOUR_PUBLIC_KEY',
        //     'is_premium'      => true,
        //     'has_addons'      => false,
        //     'has_paid_plans'  => true,
        //     'menu'            => [
        //         'slug'        => 'idiomattic-wp',
        //         'contact'     => false,
        //         'support'     => false,
        //     ],
        // ] );
    }

    return $idiomatticwp_fs;
}

// TODO: idiomatticwp_fs(); // Trigger initialisation
