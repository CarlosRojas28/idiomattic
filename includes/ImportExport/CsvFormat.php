<?php
/**
 * CsvFormat — standard CSV export/import.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\ImportExport;

use IdiomatticWP\ValueObjects\LanguageCode;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class CsvFormat
{

    public function __construct(private TranslationRepositoryInterface $repository)
    {
    }

    public function export(LanguageCode $targetLang, array $postIds = []): string
    {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, ['post_id', 'source_text', 'translated_text', 'status']);

        foreach ($postIds as $postId) {
            $translations = $this->repository->findBySourcePostId($postId);
            foreach ($translations as $t) {
                if ($t['target_lang'] === (string)$targetLang) {
                    fputcsv($output, [$postId, '', '', $t['status']]);
                }
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }
}
