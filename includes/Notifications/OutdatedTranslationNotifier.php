<?php
/**
 * OutdatedTranslationNotifier — sends email alerts when translations fall out of date.
 *
 * Supports two modes:
 *   - immediate: sends one email per event (default)
 *   - digest: accumulates events and sends one daily summary via WP-Cron
 *
 * Configured via options:
 *   idiomatticwp_notify_outdated        (string '1'|'' — enabled)
 *   idiomatticwp_notify_email           (string — recipient, defaults to admin_email)
 *   idiomatticwp_notify_mode            (string 'immediate'|'digest')
 *
 * @package IdiomatticWP\Notifications
 */

declare(strict_types=1);

namespace IdiomatticWP\Notifications;

class OutdatedTranslationNotifier
{
    private const TRANSIENT_PENDING  = 'idiomatticwp_notify_pending';
    private const CRON_HOOK          = 'idiomatticwp_send_translation_digest';
    private const TRANSIENT_LIFETIME = DAY_IN_SECONDS * 2;

    public function __construct()
    {
    }

    // -------------------------------------------------------------------------
    // Public API (hooked via NotificationHooks)
    // -------------------------------------------------------------------------

    /**
     * Called on `idiomatticwp_translation_marked_outdated`.
     *
     * Decides whether to send immediately or queue for the daily digest.
     *
     * @param int $sourcePostId The source post whose translations were flagged.
     */
    public function notify(int $sourcePostId): void
    {
        if ( ! $this->isEnabled() ) {
            return;
        }

        if ( $this->isDigestMode() ) {
            $this->queueForDigest($sourcePostId);
        } else {
            $this->sendEmail([$sourcePostId]);
        }
    }

    /**
     * Called by WP-Cron on `idiomatticwp_send_translation_digest`.
     *
     * Retrieves all queued post IDs, clears the transient, then sends one
     * consolidated email covering every pending outdated translation.
     */
    public function sendDigest(): void
    {
        $postIds = $this->getPendingPostIds();

        if ( empty($postIds) ) {
            return;
        }

        // Clear before sending so a failure during wp_mail does not result
        // in duplicate sends on the next cron tick.
        delete_transient(self::TRANSIENT_PENDING);

        $this->sendEmail($postIds);
    }

    /**
     * Schedules the daily digest cron event if digest mode is active and
     * the event is not already scheduled. Hooked on `wp_loaded`.
     */
    public function scheduleDailyDigest(): void
    {
        if ( ! $this->isEnabled() || ! $this->isDigestMode() ) {
            return;
        }

        if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    // -------------------------------------------------------------------------
    // Core email builder
    // -------------------------------------------------------------------------

    /**
     * Builds and dispatches an HTML email for the given post IDs.
     *
     * @param int[] $postIds Source post IDs to report on.
     */
    public function sendEmail(array $postIds): void
    {
        if ( empty($postIds) ) {
            return;
        }

        $recipient = $this->getRecipientEmail();

        if ( empty($recipient) ) {
            return;
        }

        $siteName = get_bloginfo('name');
        $count    = count($postIds);

        $subject = sprintf(
            /* translators: 1: number of posts, 2: site name */
            _n(
                'Translation Alert: %1$d post needs retranslation — %2$s',
                'Translation Alert: %1$d posts need retranslation — %2$s',
                $count,
                'idiomattic-wp'
            ),
            $count,
            $siteName
        );

        $body = $this->buildEmailBody($postIds, $siteName);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        wp_mail($recipient, $subject, $body, $headers);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when the notification feature is enabled.
     */
    private function isEnabled(): bool
    {
        return (bool) get_option('idiomatticwp_notify_outdated', '');
    }

    /**
     * Returns true when the configured mode is 'digest'.
     */
    private function isDigestMode(): bool
    {
        return 'digest' === get_option('idiomatticwp_notify_mode', 'immediate');
    }

    /**
     * Returns the sanitized recipient email address, falling back to the
     * WordPress admin email when the option is not set.
     */
    private function getRecipientEmail(): string
    {
        $configured = get_option('idiomatticwp_notify_email', '');
        $email      = $configured ?: get_option('admin_email', '');

        return sanitize_email((string) $email);
    }

    /**
     * Appends a post ID to the pending transient list for the daily digest.
     *
     * @param int $postId
     */
    private function queueForDigest(int $postId): void
    {
        $current = $this->getPendingPostIds();

        // Avoid duplicates — a post may be updated multiple times in one day.
        if ( ! in_array($postId, $current, true) ) {
            $current[] = $postId;
        }

        set_transient(self::TRANSIENT_PENDING, $current, self::TRANSIENT_LIFETIME);
    }

    /**
     * Returns the list of post IDs currently queued for the digest, or an
     * empty array when the transient is absent or malformed.
     *
     * @return int[]
     */
    private function getPendingPostIds(): array
    {
        $stored = get_transient(self::TRANSIENT_PENDING);

        if ( ! is_array($stored) ) {
            return [];
        }

        return array_values(array_filter($stored, 'is_int'));
    }

    /**
     * Renders a self-contained HTML email body listing every affected post.
     *
     * @param int[]  $postIds
     * @param string $siteName
     *
     * @return string
     */
    private function buildEmailBody(array $postIds, string $siteName): string
    {
        $siteUrl     = esc_url(get_bloginfo('url'));
        $siteNameEsc = esc_html($siteName);
        $count       = count($postIds);

        $rows = $this->buildPostRows($postIds);

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html(
    sprintf(
        /* translators: site name */
        __('Translation Alert — %s', 'idiomattic-wp'),
        $siteName
    )
); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f1;">
    <tr>
        <td align="center" style="padding:40px 20px;">

            <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);">

                <!-- Header -->
                <tr>
                    <td style="background-color:#2271b1;border-radius:4px 4px 0 0;padding:24px 32px;">
                        <p style="margin:0;color:#ffffff;font-size:20px;font-weight:600;line-height:1.3;">
                            <?php esc_html_e('Translation Alert', 'idiomattic-wp'); ?>
                        </p>
                        <p style="margin:6px 0 0;color:rgba(255,255,255,.8);font-size:14px;">
                            <a href="<?php echo $siteUrl; ?>" style="color:rgba(255,255,255,.8);text-decoration:none;"><?php echo $siteNameEsc; ?></a>
                        </p>
                    </td>
                </tr>

                <!-- Intro -->
                <tr>
                    <td style="padding:32px 32px 16px;">
                        <p style="margin:0;font-size:15px;line-height:1.6;color:#3c434a;">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: count of posts */
                                    _n(
                                        '%d post has been updated and its translation is now outdated. Please review and retranslate the content listed below.',
                                        '%d posts have been updated and their translations are now outdated. Please review and retranslate the content listed below.',
                                        $count,
                                        'idiomattic-wp'
                                    ),
                                    $count
                                )
                            );
                            ?>
                        </p>
                    </td>
                </tr>

                <!-- Post list -->
                <tr>
                    <td style="padding:0 32px 24px;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <th align="left" style="padding:10px 12px;background-color:#f6f7f7;border:1px solid #dcdcde;font-size:12px;color:#646970;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">
                                    <?php esc_html_e('Post Title', 'idiomattic-wp'); ?>
                                </th>
                                <th align="left" style="padding:10px 12px;background-color:#f6f7f7;border:1px solid #dcdcde;border-left:none;font-size:12px;color:#646970;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">
                                    <?php esc_html_e('Status', 'idiomattic-wp'); ?>
                                </th>
                                <th align="left" style="padding:10px 12px;background-color:#f6f7f7;border:1px solid #dcdcde;border-left:none;font-size:12px;color:#646970;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">
                                    <?php esc_html_e('Action', 'idiomattic-wp'); ?>
                                </th>
                            </tr>
                            <?php echo $rows; ?>
                        </table>
                    </td>
                </tr>

                <!-- CTA -->
                <tr>
                    <td style="padding:0 32px 32px;text-align:center;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=idiomattic-wp')); ?>"
                           style="display:inline-block;padding:12px 24px;background-color:#2271b1;color:#ffffff;text-decoration:none;border-radius:3px;font-size:14px;font-weight:600;">
                            <?php esc_html_e('Open Translations Dashboard', 'idiomattic-wp'); ?>
                        </a>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="padding:20px 32px;border-top:1px solid #dcdcde;">
                        <p style="margin:0;font-size:12px;color:#646970;line-height:1.5;">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: plugin name */
                                    __('This alert was sent by the %s plugin.', 'idiomattic-wp'),
                                    'Idiomattic WP'
                                )
                            );
                            ?>
                            <?php esc_html_e('You can disable these notifications in the plugin settings.', 'idiomattic-wp'); ?>
                        </p>
                    </td>
                </tr>

            </table><!-- /inner -->

        </td>
    </tr>
</table><!-- /outer -->

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Builds the `<tr>` rows for each post in the email table.
     *
     * @param int[] $postIds
     *
     * @return string HTML rows.
     */
    private function buildPostRows(array $postIds): string
    {
        $html = '';

        foreach ( $postIds as $index => $postId ) {
            $post = get_post($postId);

            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            $title    = esc_html($post->post_title ?: __('(no title)', 'idiomattic-wp'));
            $editUrl  = esc_url(admin_url('post.php?post=' . $postId . '&action=edit'));
            $viewUrl  = esc_url((string) get_permalink($postId));
            $rowBg    = $index % 2 === 0 ? '#ffffff' : '#f6f7f7';

            $html .= sprintf(
                '<tr style="background-color:%s;">'
                . '<td style="padding:10px 12px;border:1px solid #dcdcde;border-top:none;font-size:14px;color:#3c434a;"><strong>%s</strong></td>'
                . '<td style="padding:10px 12px;border:1px solid #dcdcde;border-top:none;border-left:none;">'
                .   '<span style="display:inline-block;padding:2px 8px;background-color:#f0b849;color:#3c434a;border-radius:3px;font-size:12px;font-weight:600;">%s</span>'
                . '</td>'
                . '<td style="padding:10px 12px;border:1px solid #dcdcde;border-top:none;border-left:none;font-size:13px;">'
                .   '<a href="%s" style="color:#2271b1;text-decoration:none;">%s</a>'
                .   ' &middot; '
                .   '<a href="%s" style="color:#2271b1;text-decoration:none;">%s</a>'
                . '</td>'
                . '</tr>',
                esc_attr($rowBg),
                $title,
                esc_html__('Outdated', 'idiomattic-wp'),
                $editUrl,
                esc_html__('Edit', 'idiomattic-wp'),
                $viewUrl,
                esc_html__('View', 'idiomattic-wp')
            );
        }

        return $html;
    }
}
