<?php
/**
 * WpmlMigrator — orchestrates the migration from WPML.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Migration;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class WpmlMigrator
{

    public function __construct(private
        \wpdb $wpdb, private
        WpmlDetector $detector, private
        LanguageManager $languageManager, private
        TranslationRepositoryInterface $repository
        )
    {
    }

    public function migrateBatch(int $offset, int $limit = 100): MigrationReport
    {
        $report = new MigrationReport();
        $report->startedAt = current_time('mysql');

        $table = $this->wpdb->prefix . 'icl_translations';

        // Get batch of translation groups (trid)
        $trids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT DISTINCT trid FROM {$table} WHERE element_type LIKE 'post_%%' LIMIT %d OFFSET %d",
            $limit, $offset
        ));

        foreach ($trids as $trid) {
            $this->migrateTrid((int)$trid, $report);
        }

        $report->completedAt = current_time('mysql');
        return $report;
    }

    private function migrateTrid(int $trid, MigrationReport $report): void
    {
        $table = $this->wpdb->prefix . 'icl_translations';
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT element_id, language_code, source_language_code 
             FROM {$table} WHERE trid = %d",
            $trid
        ));

        if (count($rows) <= 1)
            return;

        // Find source (the one with no source_language_code or the one matching current default)
        $sourceRow = null;
        foreach ($rows as $row) {
            if (empty($row->source_language_code)) {
                $sourceRow = $row;
                break;
            }
        }

        if (!$sourceRow)
            return;

        foreach ($rows as $row) {
            if ($row->element_id === $sourceRow->element_id)
                continue;

            try {
                $this->repository->save( [
                    'source_post_id'     => (int) $sourceRow->element_id,
                    'translated_post_id' => (int) $row->element_id,
                    'source_lang'        => $sourceRow->language_code,
                    'target_lang'        => $row->language_code,
                    'status'             => 'complete',
                    'translation_mode'   => 'duplicate',
                    'needs_update'       => 0,
                ] );
                $report->translationsMigrated++;
            }
            catch (\Exception $e) {
                $report->addError("Failed to migrate ID {$row->element_id}: " . $e->getMessage());
            }
        }
    }
}
