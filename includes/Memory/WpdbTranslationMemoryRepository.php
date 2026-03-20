<?php
/**
 * WpdbTranslationMemoryRepository — DB repository for TM segments.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Memory;

use IdiomatticWP\ValueObjects\LanguageCode;

class WpdbTranslationMemoryRepository
{

    private string $table;

    public function __construct(private \wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'idiomatticwp_translation_memory';
    }

    /**
     * Look up a translation in the memory.
     */
    public function lookup(string $text, LanguageCode $source, LanguageCode $target, int $minScore = 100): ?MemoryMatch
    {
        $hash = md5(trim(strtolower($text)));

        // Exact match (Standard)
        if ($minScore === 100) {
            $row = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT source_text, translated_text, provider_used 
                 FROM {$this->table} 
                 WHERE source_lang = %s AND target_lang = %s AND source_hash = %s",
                (string)$source, (string)$target, $hash
            ));

            if ($row) {
                return new MemoryMatch(
                    $row->source_text,
                    $row->translated_text,
                    100,
                    'exact',
                    (string)$row->provider_used
                    );
            }
            return null;
        }

        // Fuzzy match (Pro feature mock - simplified implementation)
        // In a real scenario, this would use Levenshtein or FULLTEXT search
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT source_text, translated_text, provider_used 
             FROM {$this->table} 
             WHERE source_lang = %s AND target_lang = %s",
            (string)$source, (string)$target
        ));

        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $row) {
            similar_text($text, $row->source_text, $score);
            if ($score >= $minScore && $score > $bestScore) {
                $bestScore = (int)$score;
                $bestMatch = $row;
            }
        }

        if ($bestMatch) {
            return new MemoryMatch(
                $bestMatch->source_text,
                $bestMatch->translated_text,
                $bestScore,
                'fuzzy',
                (string)$bestMatch->provider_used
                );
        }

        return null;
    }

    /**
     * Save a translation to the memory.
     */
    public function save(string $sourceText, string $translatedText, LanguageCode $source, LanguageCode $target, string $provider = ''): void
    {
        $sourceText = trim($sourceText);
        $hash = md5(strtolower($sourceText));

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->table} 
            (source_lang, target_lang, source_hash, source_text, translated_text, provider_used, usage_count, last_used_at, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, 1, %s, %s)
            ON DUPLICATE KEY UPDATE 
                translated_text = VALUES(translated_text),
                provider_used = VALUES(provider_used),
                usage_count = usage_count + 1,
                last_used_at = VALUES(last_used_at)",
            (string)$source,
            (string)$target,
            $hash,
            $sourceText,
            $translatedText,
            $provider,
            current_time('mysql'),
            current_time('mysql')
        ));
    }

    /**
     * Get statistics for this language pair.
     */
    public function getSavingsReport(LanguageCode $source, LanguageCode $target): SavingsReport
    {
        $stats = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(usage_count) as hits
             FROM {$this->table} 
             WHERE source_lang = %s AND target_lang = %s",
            (string)$source, (string)$target
        ));

        $total = (int)($stats->total ?? 0);
        $hits = (int)($stats->hits ?? 0);

        // Mock calculation for report
        return new SavingsReport(
            $total + $hits,
            $hits,
            0,
            $hits * 0.01, // Mock saved USD
            $total > 0 ? ($hits / ($total + $hits)) * 100 : 0
            );
    }
}
