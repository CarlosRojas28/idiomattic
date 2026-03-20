<?php
/**
 * DashboardPage — renders the main overview of translations.
 *
 * Displays summary stat cards (total, complete, outdated, TM hits,
 * draft/in-progress), a language-coverage table, quick-action links,
 * and a recent-activity table.
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare(strict_types=1);

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class DashboardPage
{
    public function __construct(
        private TranslationRepositoryInterface $repository,
        private LanguageManager $languageManager
    ) {}

    // ── Public entry-point ─────────────────────────────────────────────────

    /**
     * Render the full dashboard page.
     */
    public function render(): void
    {
        $stats   = $this->getStats();
        $tmHits  = $this->getTmHits();
        $draftCount = $this->repository->countByStatus('draft');
        $inProgressCount = $this->repository->countByStatus('in_progress');
        ?>
        <div class="wrap idiomatticwp-dashboard">
            <h1><?php esc_html_e('Idiomattic WP Dashboard', 'idiomattic-wp'); ?></h1>

            <?php $this->renderSummaryCards($stats, $tmHits, $draftCount, $inProgressCount); ?>
            <?php $this->renderQuickActions(); ?>
            <?php $this->renderLanguageCoverage(); ?>

            <div style="margin-top: 40px;">
                <h2><?php esc_html_e('Recent Activity', 'idiomattic-wp'); ?></h2>
                <?php $this->renderTranslationsTable(); ?>
            </div>
        </div>
        <?php
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    private function getStats(): array
    {
        return [
            'total'    => $this->repository->countAll(),
            'complete' => $this->repository->countByStatus('complete'),
            'outdated' => $this->repository->countByStatus('outdated'),
        ];
    }

    /**
     * Query the translation-memory table for the total number of TM hits
     * (sum of usage_count across all segments).  Returns 0 when the table
     * does not exist or the query fails.
     */
    private function getTmHits(): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'idiomatticwp_translation_memory';

        // Check table existence without triggering a DB error.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($exists !== $table) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hits = $wpdb->get_var("SELECT SUM(usage_count) FROM {$table}");

        return (int) ($hits ?? 0);
    }

    // ── Summary cards ──────────────────────────────────────────────────────

    private function renderSummaryCards(
        array $stats,
        int $tmHits,
        int $draftCount,
        int $inProgressCount
    ): void {
        $cards = [
            [
                'label' => __('Total Translations', 'idiomattic-wp'),
                'value' => $stats['total'],
                'color' => '#1d2327',
            ],
            [
                'label' => __('Complete', 'idiomattic-wp'),
                'value' => $stats['complete'],
                'color' => '#46b450',
            ],
            [
                'label' => __('Outdated', 'idiomattic-wp'),
                'value' => $stats['outdated'],
                'color' => $stats['outdated'] > 0 ? '#ffb900' : '#46b450',
            ],
            [
                'label' => __('Translation Memory Hits', 'idiomattic-wp'),
                'value' => $tmHits,
                'color' => '#2271b1',
            ],
            [
                'label' => __('Draft / In Progress', 'idiomattic-wp'),
                'value' => $draftCount + $inProgressCount,
                'color' => '#646970',
                'detail' => sprintf(
                    /* translators: 1: draft count, 2: in-progress count */
                    __('%1$d draft, %2$d in progress', 'idiomattic-wp'),
                    $draftCount,
                    $inProgressCount
                ),
            ],
        ];
        ?>
        <div class="idiomatticwp-summary-cards"
             style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:20px; margin-top:20px;">
            <?php foreach ($cards as $card) : ?>
                <div class="card"
                     style="padding:20px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">
                    <h2 style="margin:0; font-size:13px; color:#646970; font-weight:400;">
                        <?php echo esc_html($card['label']); ?>
                    </h2>
                    <p style="font-size:32px; font-weight:700; margin:10px 0 0; color:<?php echo esc_attr($card['color']); ?>;">
                        <?php echo (int) $card['value']; ?>
                    </p>
                    <?php if (!empty($card['detail'])) : ?>
                        <p style="font-size:12px; color:#646970; margin:4px 0 0;">
                            <?php echo esc_html($card['detail']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ── Quick actions ──────────────────────────────────────────────────────

    private function renderQuickActions(): void
    {
        $bulkUrl    = admin_url('admin.php?page=idiomatticwp-bulk&action=translate_outdated');
        $settingsUrl = admin_url('admin.php?page=idiomatticwp-settings');
        $exportUrl  = admin_url('admin.php?page=idiomatticwp-export&format=tmx');
        ?>
        <div class="idiomatticwp-quick-actions"
             style="margin-top:30px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
            <strong style="margin-right:4px;"><?php esc_html_e('Quick actions:', 'idiomattic-wp'); ?></strong>

            <a href="<?php echo esc_url($bulkUrl); ?>"
               class="button button-primary">
                <?php esc_html_e('Translate All Outdated', 'idiomattic-wp'); ?>
            </a>

            <a href="<?php echo esc_url($settingsUrl); ?>"
               class="button">
                <?php esc_html_e('View Settings', 'idiomattic-wp'); ?>
            </a>

            <a href="<?php echo esc_url($exportUrl); ?>"
               class="button">
                <?php esc_html_e('Export TMX', 'idiomattic-wp'); ?>
            </a>
        </div>
        <?php
    }

    // ── Language coverage ──────────────────────────────────────────────────

    /**
     * Render a per-language breakdown of translation coverage.
     *
     * "Complete", "Outdated", and "Missing" counts are approximate because
     * the repository interface does not yet expose per-language queries.
     * Complete and outdated are read from the status column; missing is
     * estimated as (total_translations - complete - outdated) clamped to 0.
     */
    private function renderLanguageCoverage(): void
    {
        $activeLanguages = $this->languageManager->getActiveLanguages();
        if (empty($activeLanguages)) {
            return;
        }

        $totalComplete = $this->repository->countByStatus('complete');
        $totalOutdated = $this->repository->countByStatus('outdated');
        $totalAll      = $this->repository->countAll();
        $languageCount = count($activeLanguages);

        // Distribute totals evenly across languages as an approximation until
        // per-language repository methods are available.
        $avgComplete = $languageCount > 0 ? (int) round($totalComplete / $languageCount) : 0;
        $avgOutdated = $languageCount > 0 ? (int) round($totalOutdated / $languageCount) : 0;
        $avgPerLang  = $languageCount > 0 ? (int) round($totalAll / $languageCount) : 0;
        $avgMissing  = max(0, $avgPerLang - $avgComplete - $avgOutdated);
        ?>
        <div style="margin-top:40px;">
            <h2><?php esc_html_e('Language Coverage', 'idiomattic-wp'); ?></h2>
            <p style="color:#646970; margin-top:-8px; font-size:13px;">
                <?php esc_html_e(
                    'Counts are approximate until per-language stats are implemented.',
                    'idiomattic-wp'
                ); ?>
            </p>
            <table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Language', 'idiomattic-wp'); ?></th>
                        <th style="width:130px; text-align:right;">
                            <?php esc_html_e('Complete', 'idiomattic-wp'); ?>
                        </th>
                        <th style="width:130px; text-align:right;">
                            <?php esc_html_e('Outdated', 'idiomattic-wp'); ?>
                        </th>
                        <th style="width:130px; text-align:right;">
                            <?php esc_html_e('Missing', 'idiomattic-wp'); ?>
                        </th>
                        <th style="width:180px;">
                            <?php esc_html_e('Coverage', 'idiomattic-wp'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeLanguages as $lang) : ?>
                        <?php
                        $name     = $this->languageManager->getLanguageName($lang);
                        $native   = $this->languageManager->getNativeLanguageName($lang);
                        $label    = $name !== $native ? "{$name} ({$native})" : $name;
                        $pct      = $avgPerLang > 0
                            ? min(100, (int) round(($avgComplete / $avgPerLang) * 100))
                            : 0;
                        $barColor = $pct >= 80 ? '#46b450' : ($pct >= 40 ? '#ffb900' : '#dc3232');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($label); ?></strong>
                                <br>
                                <small style="color:#646970;">
                                    <?php echo esc_html((string) $lang); ?>
                                </small>
                            </td>
                            <td style="text-align:right; color:#46b450;">
                                <?php echo $avgComplete; ?>
                            </td>
                            <td style="text-align:right; color:<?php echo $avgOutdated > 0 ? '#ffb900' : '#46b450'; ?>;">
                                <?php echo $avgOutdated; ?>
                            </td>
                            <td style="text-align:right; color:<?php echo $avgMissing > 0 ? '#dc3232' : '#46b450'; ?>;">
                                <?php echo $avgMissing; ?>
                            </td>
                            <td>
                                <div style="background:#f0f0f0; border-radius:3px; height:14px; position:relative;">
                                    <div style="background:<?php echo esc_attr($barColor); ?>; width:<?php echo $pct; ?>%; height:100%; border-radius:3px;"></div>
                                </div>
                                <small style="color:#646970;"><?php echo $pct; ?>%</small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Recent activity table ──────────────────────────────────────────────

    private function renderTranslationsTable(): void
    {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Title', 'idiomattic-wp') . '</th>';
        echo '<th style="width:120px;">' . esc_html__('Language', 'idiomattic-wp') . '</th>';
        echo '<th style="width:120px;">' . esc_html__('Status', 'idiomattic-wp') . '</th>';
        echo '<th style="width:160px;">' . esc_html__('Date', 'idiomattic-wp') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $latest = $this->repository->getLatest(10);

        if (empty($latest)) {
            echo '<tr><td colspan="4">'
                . esc_html__('No translations found.', 'idiomattic-wp')
                . '</td></tr>';
        } else {
            foreach ($latest as $row) {
                $post = get_post((int) $row['translated_post_id']);
                if (!$post) {
                    continue;
                }

                $statusColor = match ($row['status'] ?? '') {
                    'complete'    => '#46b450',
                    'outdated'    => '#ffb900',
                    'draft'       => '#646970',
                    'in_progress' => '#2271b1',
                    default       => '#1d2327',
                };

                printf(
                    '<tr>'
                    . '<td><a href="%1$s"><strong>%2$s</strong></a></td>'
                    . '<td>%3$s</td>'
                    . '<td><span style="color:%4$s;">%5$s</span></td>'
                    . '<td>%6$s</td>'
                    . '</tr>',
                    esc_url((string) get_edit_post_link($post->ID)),
                    esc_html($post->post_title),
                    esc_html($row['target_lang'] ?? ''),
                    esc_attr($statusColor),
                    esc_html(ucfirst($row['status'] ?? '')),
                    esc_html($row['translated_at'] ?? $row['created_at'] ?? '')
                );
            }
        }

        echo '</tbody></table>';
    }
}
