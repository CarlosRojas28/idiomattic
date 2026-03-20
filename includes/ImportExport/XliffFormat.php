<?php
/**
 * XliffFormat — industry standard translation interchange (XLIFF 2.0).
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\ImportExport;

use IdiomatticWP\ValueObjects\LanguageCode;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class XliffFormat
{

    public function __construct(private TranslationRepositoryInterface $repository)
    {
    }

    public function export(LanguageCode $targetLang, array $postIds = []): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $xliff = $dom->createElement('xliff');
        $xliff->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:2.0');
        $xliff->setAttribute('version', '2.0');
        $xliff->setAttribute('srcLang', 'en'); // Hardcoded default for now
        $xliff->setAttribute('trgLang', (string)$targetLang);
        $dom->appendChild($xliff);

        // Fetch translations per post
        foreach ($postIds as $postId) {
            $file = $dom->createElement('file');
            $file->setAttribute('id', 'post-' . $postId);
            $file->setAttribute('original', get_permalink($postId));

            $translations = $this->repository->findBySourcePostId($postId);
            foreach ($translations as $t) {
                if ($t['target_lang'] !== (string)$targetLang)
                    continue;

                $unit = $dom->createElement('unit');
                $unit->setAttribute('id', 'trans-' . $t['id']);

                $segment = $dom->createElement('segment');
                $source = $dom->createElement('source', htmlspecialchars(get_the_title($postId))); // Simplified
                $target = $dom->createElement('target', '');

                $segment->appendChild($source);
                $segment->appendChild($target);
                $unit->appendChild($segment);
                $file->appendChild($unit);
            }
            $xliff->appendChild($file);
        }

        return $dom->saveXML();
    }
}
