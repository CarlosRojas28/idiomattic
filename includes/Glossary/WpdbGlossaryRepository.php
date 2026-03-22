<?php
/**
 * WpdbGlossaryRepository — database storage for glossary terms.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Glossary;

use IdiomatticWP\ValueObjects\LanguageCode;

class WpdbGlossaryRepository
{

    private string $table;

    public function __construct(private \wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'idiomatticwp_glossary';
    }

    public function getTerms(LanguageCode $source, LanguageCode $target): array
    {
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE source_lang = %s AND target_lang = %s",
            (string)$source, (string)$target
        ), ARRAY_A);

        return array_map([$this, 'mapToTerm'], $results);
    }

    /**
     * Get all glossary terms, optionally filtered by source and/or target lang.
     *
     * @param string $sourceLang Filter by source language code, or '' for all.
     * @param string $targetLang Filter by target language code, or '' for all.
     * @return GlossaryTerm[]
     */
    public function getAllTerms(string $sourceLang = '', string $targetLang = ''): array
    {
        $where  = [];
        $params = [];

        if ($sourceLang !== '') {
            $where[]  = 'source_lang = %s';
            $params[] = $sourceLang;
        }

        if ($targetLang !== '') {
            $where[]  = 'target_lang = %s';
            $params[] = $targetLang;
        }

        $sql = "SELECT * FROM {$this->table}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY source_lang, target_lang, source_term ASC';

        $results = $params
            ? $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A)
            : $this->wpdb->get_results($sql, ARRAY_A);

        return array_map([$this, 'mapToTerm'], $results ?: []);
    }

    public function addTerm(string $sourceTerm, string $translatedTerm, LanguageCode $source, LanguageCode $target, bool $forbidden = false, ?string $notes = null): int
    {
        $this->wpdb->insert($this->table, [
            'source_lang'     => (string) $source,
            'target_lang'     => (string) $target,
            'source_term'     => $sourceTerm,
            'translated_term' => $translatedTerm,
            'forbidden'       => $forbidden ? 1 : 0,
            'notes'           => $notes,
            'created_at'      => current_time('mysql', true),
        ]);

        return (int)$this->wpdb->insert_id;
    }

    public function updateTerm(int $id, array $data): void
    {
        $this->wpdb->update($this->table, $data, ['id' => $id]);
    }

    public function deleteTerm(int $id): void
    {
        $this->wpdb->delete($this->table, ['id' => $id]);
    }

    private function mapToTerm(array $data): GlossaryTerm
    {
        return new GlossaryTerm(
            (int)$data['id'],
            $data['source_lang'],
            $data['target_lang'],
            $data['source_term'],
            $data['translated_term'],
            (bool)$data['forbidden'],
            $data['notes']
            );
    }
}
