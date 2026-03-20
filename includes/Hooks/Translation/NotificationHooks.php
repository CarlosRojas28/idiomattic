<?php
/**
 * NotificationHooks — wires outdated-translation email notification events.
 *
 * Binds OutdatedTranslationNotifier to:
 *   - idiomatticwp_translation_marked_outdated  (immediate or digest queuing)
 *   - idiomatticwp_send_translation_digest      (WP-Cron daily digest dispatch)
 *   - wp_loaded                                  (digest cron scheduling)
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare(strict_types=1);

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Notifications\OutdatedTranslationNotifier;

class NotificationHooks implements HookRegistrarInterface
{
    public function __construct(private OutdatedTranslationNotifier $notifier)
    {
    }

    public function register(): void
    {
        // React to each post whose translations were just flagged outdated.
        add_action(
            'idiomatticwp_translation_marked_outdated',
            [$this->notifier, 'notify']
        );

        // WP-Cron hook that fires the daily digest email.
        add_action(
            'idiomatticwp_send_translation_digest',
            [$this->notifier, 'sendDigest']
        );

        // Schedule the daily digest event once WordPress is fully loaded,
        // so all options (including the mode setting) are available.
        add_action(
            'wp_loaded',
            [$this->notifier, 'scheduleDailyDigest']
        );
    }
}
