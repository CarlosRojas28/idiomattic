<?php
/**
 * DashboardPage — renders the main overview of translations.
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

    public function render(): void
    {
        $stats           = $this->getStats();
        $tmHits          = $this->getTmHits();
        $draftCount      = $this->repository->countByStatus('draft');
        $inProgressCount = $this->repository->countByStatus('in_progress');

        // Compute $totalMissing and $nonDefaultLangs for smart status + celebration data
        $activeLanguages = $this->languageManager->getActiveLanguages();
        $defaultLang     = $this->languageManager->getDefaultLanguage();
        $nonDefaultLangs = array_filter(
            $activeLanguages,
            fn( $l ) => ! method_exists( $l, 'equals' ) || ! $l->equals( $defaultLang )
        );

        // Rough estimate: total posts × non-default languages - existing translations
        $postCounts  = wp_count_posts();
        $totalPosts  = isset( $postCounts->publish ) ? (int) $postCounts->publish : 0;
        $langCount   = count( $nonDefaultLangs );
        $maxPossible = $totalPosts * $langCount;
        $totalMissing = max( 0, $maxPossible - $stats['total'] );
        // Fallback proxy when we cannot compute precisely
        if ( $maxPossible === 0 ) {
            $totalMissing = ( $stats['total'] > 0 && $stats['complete'] < $stats['total'] ) ? 1 : 0;
        }

        // Coverage percentage for smart status message
        $coveragePct = ( $maxPossible > 0 )
            ? min( 100, (int) round( ( $stats['total'] / $maxPossible ) * 100 ) )
            : 0;
        ?>
        <div class="wrap idiomatticwp-dashboard">

            <div class="iwp-page-header">
                <div class="iwp-page-header__text">
                    <h1 class="iwp-page-title"><?php esc_html_e('Dashboard', 'idiomattic-wp'); ?></h1>
                    <p class="iwp-page-subtitle"><?php esc_html_e('Overview of your multilingual content', 'idiomattic-wp'); ?></p>
                </div>
                <div class="iwp-page-header__actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=idiomatticwp-content')); ?>"
                       class="iwp-btn iwp-btn--secondary">
                        <?php esc_html_e('Content Translation', 'idiomattic-wp'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=idiomatticwp-strings')); ?>"
                       class="iwp-btn iwp-btn--secondary">
                        <?php esc_html_e('String Translation', 'idiomattic-wp'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=idiomatticwp-settings')); ?>"
                       class="iwp-btn iwp-btn--primary">
                        <?php esc_html_e('Settings', 'idiomattic-wp'); ?>
                    </a>
                </div>
            </div>

            <?php $this->renderSmartStatus( $stats, $totalMissing, $coveragePct ); ?>

            <?php $this->renderSummaryCards($stats, $tmHits, $draftCount, $inProgressCount); ?>
            <?php $this->renderLanguageCoverage( $nonDefaultLangs, $totalMissing ); ?>
            <?php $this->renderRecentActivity(); ?>

        </div>

        <!-- Celebration trigger data (Improvement 4) -->
        <div id="iwp-dashboard-celebration-data"
             data-all-complete="<?php echo ( $totalMissing === 0 && $stats['total'] > 0 ) ? '1' : '0'; ?>"
             data-lang-count="<?php echo esc_attr( (string) $langCount ); ?>"
             style="display:none;"></div>

        <style>
        .iwp-smart-status {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            background: #f0f6fc;
            border-left: 4px solid #2271b1;
        }
        .iwp-smart-status--all-good {
            background: #edfaef;
            border-left-color: #46b450;
        }
        .iwp-smart-status--needs-action {
            background: #fef8ec;
            border-left-color: #dea000;
        }
        .iwp-smart-status__icon { font-size: 22px; flex-shrink: 0; }
        .iwp-smart-status__text { flex: 1; font-size: 14px; margin: 0; }
        .iwp-smart-status__action { flex-shrink: 0; }
        .iwp-empty-state-box {
            text-align: center;
            padding: 48px 24px;
        }
        .iwp-empty-state-box__icon { font-size: 48px; display: block; margin-bottom: 16px; }
        .iwp-empty-state-box__title { font-size: 18px; font-weight: 600; margin: 0 0 8px; }
        .iwp-empty-state-box__desc { color: #50575e; margin: 0 0 20px; }
        .iwp-coverage-actions { display: flex; flex-direction: column; gap: 4px; }
        .iwp-coverage-actions a { font-size: 12px; text-decoration: none; }
        .iwp-coverage-actions a.iwp-action-outdated { color: #dea000; }
        .iwp-coverage-actions a.iwp-action-missing { color: #2271b1; }
        </style>
        <?php
    }

    /**
     * Improvement 1: Smart status banner shown before stat cards.
     */
    private function renderSmartStatus( array $stats, int $totalMissing, int $coveragePct ): void
    {
        if ( $stats['total'] === 0 ) {
            $modifier = 'needs-action';
            $icon     = '🌐';
            $message  = __( 'Your site is monolingual. Add a language to get started.', 'idiomattic-wp' );
            $btnLabel = __( 'Add Language →', 'idiomattic-wp' );
            $btnUrl   = admin_url( 'admin.php?page=idiomatticwp-settings&tab=languages' );
        } elseif ( $stats['outdated'] > 0 ) {
            $modifier = 'needs-action';
            $icon     = '⚠️';
            /* translators: %d: number of outdated translations */
            $message  = sprintf( _n( 'You have %d outdated translation — source post has changed.', 'You have %d outdated translations — source posts have changed.', $stats['outdated'], 'idiomattic-wp' ), $stats['outdated'] );
            $btnLabel = __( 'View outdated →', 'idiomattic-wp' );
            $btnUrl   = admin_url( 'admin.php?page=idiomatticwp-content' );
        } elseif ( $totalMissing > 0 ) {
            $modifier = 'needs-action';
            $icon     = '📊';
            /* translators: 1: coverage percentage, 2: number of missing translations */
            $message  = sprintf( __( 'Your site is %1$d%% multilingual. %2$d translations still missing.', 'idiomattic-wp' ), $coveragePct, $totalMissing );
            $btnLabel = __( 'Translate missing →', 'idiomattic-wp' );
            $btnUrl   = admin_url( 'admin.php?page=idiomatticwp-content' );
        } else {
            $modifier = 'all-good';
            $icon     = '✅';
            $message  = __( 'Your site speaks all languages perfectly. ✓', 'idiomattic-wp' );
            $btnLabel = '';
            $btnUrl   = '';
        }
        ?>
        <div class="iwp-smart-status iwp-smart-status--<?php echo esc_attr( $modifier ); ?>">
            <span class="iwp-smart-status__icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <p class="iwp-smart-status__text"><?php echo esc_html( $message ); ?></p>
            <?php if ( $btnLabel && $btnUrl ) : ?>
                <a href="<?php echo esc_url( $btnUrl ); ?>"
                   class="iwp-btn iwp-btn--secondary iwp-btn--sm iwp-smart-status__action">
                    <?php echo esc_html( $btnLabel ); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    private function getStats(): array
    {
        return [
            'total'    => $this->repository->countAll(),
            'complete' => $this->repository->countByStatus('complete'),
            'outdated' => $this->repository->countByStatus('outdated'),
        ];
    }

    private function getTmHits(): int
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'idiomatticwp_translation_memory';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hits = $wpdb->get_var("SELECT SUM(usage_count) FROM {$table}");
        return (int) ($hits ?? 0);
    }

    private function renderSummaryCards(array $stats, int $tmHits, int $draftCount, int $inProgressCount): void
    {
        $cards = [
            [
                'label'  => __('Total Translations', 'idiomattic-wp'),
                'value'  => $stats['total'],
                'mod'    => 'default',
            ],
            [
                'label'  => __('Complete', 'idiomattic-wp'),
                'value'  => $stats['complete'],
                'mod'    => 'green',
            ],
            [
                'label'  => __('Outdated', 'idiomattic-wp'),
                'value'  => $stats['outdated'],
                'mod'    => $stats['outdated'] > 0 ? 'yellow' : 'green',
            ],
            [
                'label'  => __('Memory Hits', 'idiomattic-wp'),
                'value'  => $tmHits,
                'mod'    => 'blue',
            ],
            [
                'label'  => __('Draft / In Progress', 'idiomattic-wp'),
                'value'  => $draftCount + $inProgressCount,
                'mod'    => 'grey',
                'detail' => sprintf(
                    /* translators: 1: draft count, 2: in-progress count */
                    __('%1$d draft, %2$d in progress', 'idiomattic-wp'),
                    $draftCount,
                    $inProgressCount
                ),
            ],
        ];
        ?>
        <div class="iwp-stat-cards">
            <?php foreach ($cards as $card) : ?>
                <div class="iwp-stat-card iwp-stat-card--<?php echo esc_attr($card['mod']); ?>">
                    <div class="iwp-stat-card__value"><?php echo (int) $card['value']; ?></div>
                    <div class="iwp-stat-card__label"><?php echo esc_html($card['label']); ?></div>
                    <?php if (!empty($card['detail'])) : ?>
                        <div class="iwp-stat-card__detail"><?php echo esc_html($card['detail']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Improvement 2 & 4: Language coverage table with inline action links and data-iwp-complete attribute.
     *
     * @param array $nonDefaultLangs Pre-filtered list of non-default language objects.
     * @param int   $totalMissing    Total missing translations estimate (unused here but kept for signature consistency).
     */
    private function renderLanguageCoverage( array $nonDefaultLangs = [], int $totalMissing = 0 ): void
    {
        if ( empty( $nonDefaultLangs ) ) {
            return;
        }
        ?>
        <div class="iwp-card iwp-dashboard-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <h2 class="iwp-section-title" style="margin-bottom:0;"><?php esc_html_e('Language Coverage', 'idiomattic-wp'); ?></h2>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-content' ) ); ?>" class="iwp-btn iwp-btn--secondary iwp-btn--sm">
                    <span class="dashicons dashicons-translation" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                    <?php esc_html_e( 'Translate content →', 'idiomattic-wp' ); ?>
                </a>
            </div>

            <table class="iwp-data-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Language', 'idiomattic-wp'); ?></th>
                        <th class="iwp-col-num"><?php esc_html_e('Complete', 'idiomattic-wp'); ?></th>
                        <th class="iwp-col-num"><?php esc_html_e('Outdated', 'idiomattic-wp'); ?></th>
                        <th class="iwp-col-num"><?php esc_html_e('Draft/WIP', 'idiomattic-wp'); ?></th>
                        <th class="iwp-col-coverage"><?php esc_html_e('Coverage', 'idiomattic-wp'); ?></th>
                        <th><?php esc_html_e('Actions', 'idiomattic-wp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nonDefaultLangs as $lang) : ?>
                        <?php
                        $langCode = (string) $lang;
                        $name     = $this->languageManager->getLanguageName($lang);
                        $native   = $this->languageManager->getNativeLanguageName($lang);
                        $label    = $name !== $native ? "{$name} ({$native})" : $name;

                        $complete = $this->repository->countByStatusAndLang('complete', $langCode);
                        $outdated = $this->repository->countByStatusAndLang('outdated', $langCode);
                        $total    = $this->repository->countAllByLang($langCode);
                        $other    = max(0, $total - $complete - $outdated);

                        $pct      = $total > 0 ? min(100, (int) round(($complete / $total) * 100)) : 0;
                        $barColor = $pct >= 80 ? '#46b450' : ($pct >= 40 ? '#ffb900' : '#dc3232');

                        // Improvement 2: content page URL (no post_type filter)
                        $contentUrl = admin_url( 'admin.php?page=idiomatticwp-content' );

                        // Outdated link filtered by language (keep existing list URL approach)
                        $outdatedUrl = esc_url( add_query_arg( [
                            'post_type'   => 'any',
                            'filter_lang' => $langCode,
                        ], admin_url( 'edit.php' ) ) );
                        ?>
                        <!-- Improvement 4: data-iwp-complete and data-iwp-lang on <tr> -->
                        <tr data-iwp-complete="<?php echo ( $pct === 100 ) ? '1' : '0'; ?>"
                            data-iwp-lang="<?php echo esc_attr( $label ); ?>">
                            <td>
                                <strong><?php echo esc_html($label); ?></strong>
                                <code class="iwp-lang-code"><?php echo esc_html($langCode); ?></code>
                            </td>
                            <td class="iwp-col-num iwp-num--green"><?php echo $complete; ?></td>
                            <td class="iwp-col-num <?php echo $outdated > 0 ? 'iwp-num--yellow' : 'iwp-num--green'; ?>"><?php echo $outdated; ?></td>
                            <td class="iwp-col-num <?php echo $other > 0 ? 'iwp-num--grey' : 'iwp-num--green'; ?>"><?php echo $other; ?></td>
                            <td class="iwp-col-coverage">
                                <div class="iwp-coverage-bar">
                                    <div class="iwp-coverage-fill" style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr($barColor); ?>;"></div>
                                </div>
                                <span class="iwp-coverage-pct"><?php echo $pct; ?>%</span>
                            </td>
                            <!-- Improvement 2: inline action links replacing the old single button -->
                            <td>
                                <div class="iwp-coverage-actions">
                                    <?php if ( $outdated > 0 ) : ?>
                                        <a href="<?php echo $outdatedUrl; ?>" class="iwp-action-outdated">
                                            ↻ <?php echo esc_html( (string) $outdated ); ?> <?php esc_html_e( 'outdated', 'idiomattic-wp' ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ( $pct < 100 ) : ?>
                                        <a href="<?php echo esc_url( $contentUrl ); ?>" class="iwp-action-missing">
                                            <?php esc_html_e( 'Translate missing →', 'idiomattic-wp' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderRecentActivity(): void
    {
        $latest = $this->repository->getLatest(10);
        ?>
        <div class="iwp-card iwp-dashboard-section">
            <h2 class="iwp-section-title"><?php esc_html_e('Recent Activity', 'idiomattic-wp'); ?></h2>

            <?php if (empty($latest)) : ?>
                <!-- Improvement 3: styled empty state -->
                <div class="iwp-empty-state-box">
                    <div class="iwp-empty-state-box__icon">🌍</div>
                    <h3 class="iwp-empty-state-box__title"><?php esc_html_e( 'No translations yet', 'idiomattic-wp' ); ?></h3>
                    <p class="iwp-empty-state-box__desc"><?php esc_html_e( 'Start by adding a language in Settings, then translate your first post.', 'idiomattic-wp' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-settings&tab=languages' ) ); ?>" class="iwp-btn iwp-btn--primary">
                        <?php esc_html_e( 'Add a language →', 'idiomattic-wp' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <table class="iwp-data-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Title', 'idiomattic-wp'); ?></th>
                            <th class="iwp-col-lang"><?php esc_html_e('Language', 'idiomattic-wp'); ?></th>
                            <th class="iwp-col-status"><?php esc_html_e('Status', 'idiomattic-wp'); ?></th>
                            <th class="iwp-col-date"><?php esc_html_e('Date', 'idiomattic-wp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($latest as $row) : ?>
                            <?php
                            $post = get_post((int) $row['translated_post_id']);
                            if (!$post) continue;
                            $status = $row['status'] ?? 'draft';
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url((string) get_edit_post_link($post->ID)); ?>">
                                        <strong><?php echo esc_html($post->post_title); ?></strong>
                                    </a>
                                </td>
                                <td class="iwp-col-lang">
                                    <code class="iwp-lang-code"><?php echo esc_html($row['target_lang'] ?? ''); ?></code>
                                </td>
                                <td class="iwp-col-status">
                                    <span class="idiomatticwp-status-badge idiomatticwp-status-badge--<?php echo esc_attr($status); ?>">
                                        <?php echo esc_html(ucfirst($status)); ?>
                                    </span>
                                </td>
                                <td class="iwp-col-date">
                                    <?php echo esc_html($row['translated_at'] ?? $row['created_at'] ?? ''); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
