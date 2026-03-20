<?php
/**
 * JsonFormat — simple JSON structure for data exchange.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\ImportExport;

use IdiomatticWP\ValueObjects\LanguageCode;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class JsonFormat
{

    public function __construct(private TranslationRepositoryInterface $repository)
    {
    }

    public function export(LanguageCode $targetLang, array $postIds = []): string
    {
        $data = [
            'version' => '1.0',
            'target_lang' => (string)$targetLang,
            'posts' => []
        ];

        foreach ($postIds as $postId) {
            $translations = $this->repository->findBySourcePostId($postId);
            $data['posts'][] = [
                'post_id' => $postId,
                'translations' => $translations
            ];
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
