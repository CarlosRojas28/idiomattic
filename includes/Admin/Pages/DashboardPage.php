<?php
/**
 * DashboardPage — renders the main overview of translations.
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class DashboardPage
{

    public function __construct(private
        TranslationRepositoryInterface $repository, private
        LanguageManager $languageManager
        )
    {
    }

    /**
     * Render the dashboard page.
     */
    public function render(): void
    {
        $stats = $this->getStats();
?>
		<div class="wrap idiomatticwp-dashboard">
			<h1><?php esc_html_e('Idiomattic WP Dashboard', 'idiomattic-wp'); ?></h1>

			<div class="idiomatticwp-summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
				<div class="card" style="padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
					<h2 style="margin: 0; font-size: 14px; color: #646970;"><?php esc_html_e('Total Translations', 'idiomattic-wp'); ?></h2>
					<p style="font-size: 32px; font-weight: bold; margin: 10px 0 0;"><?php echo (int)$stats['total']; ?></p>
				</div>
				<div class="card" style="padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
					<h2 style="margin: 0; font-size: 14px; color: #646970;"><?php esc_html_e('Complete', 'idiomattic-wp'); ?></h2>
					<p style="font-size: 32px; font-weight: bold; margin: 10px 0 0; color: #46b450;"><?php echo (int)$stats['complete']; ?></p>
				</div>
				<div class="card" style="padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
					<h2 style="margin: 0; font-size: 14px; color: #646970;"><?php esc_html_e('Outdated', 'idiomattic-wp'); ?></h2>
					<p style="font-size: 32px; font-weight: bold; margin: 10px 0 0; color: <?php echo $stats['outdated'] > 0 ? '#ffb900' : '#46b450'; ?>;">
						<?php echo (int)$stats['outdated']; ?>
					</p>
				</div>
			</div>

			<div style="margin-top: 40px;">
				<h2><?php esc_html_e('Recent Activity', 'idiomattic-wp'); ?></h2>
				<?php $this->renderTranslationsTable(); ?>
			</div>
		</div>
		<?php
    }

    private function getStats(): array
    {
        // In a real implementation, we'd add count methods to the repository.
        // For now, we'll fetch them (or assume mock values if repository isn't ready for counts).
        return [
            'total' => $this->repository->countAll(),
            'complete' => $this->repository->countByStatus('complete'),
            'outdated' => $this->repository->countByStatus('outdated'),
        ];
    }

    private function renderTranslationsTable(): void
    {
        // Simplified table for now
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Title', 'idiomattic-wp') . '</th>';
        echo '<th>' . esc_html__('Language', 'idiomattic-wp') . '</th>';
        echo '<th>' . esc_html__('Status', 'idiomattic-wp') . '</th>';
        echo '<th>' . esc_html__('Date', 'idiomattic-wp') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        // For Phase 1, we don't have pagination yet
        $latest = $this->repository->getLatest(10);
        if (empty($latest)) {
            echo '<tr><td colspan="4">' . esc_html__('No translations found.', 'idiomattic-wp') . '</td></tr>';
        }
        else {
            foreach ($latest as $row) {
                $post = get_post((int)$row['translated_post_id']);
                if (!$post)
                    continue;

                printf(
                    '<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_url(get_edit_post_link($post->ID)),
                    esc_html($post->post_title),
                    esc_html($row['target_lang']),
                    esc_html(ucfirst($row['status'])),
                    esc_html($row['translated_at'] ?? $row['created_at'] ?? '')
                );
            }
        }

        echo '</tbody></table>';
    }
}
