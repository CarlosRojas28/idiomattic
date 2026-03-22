<?php
/**
 * StringRepository — manages translatable UI strings.
 *
 * Strings are registered by plugins/themes via idiomatticwp_register_string()
 * and stored in wp_idiomatticwp_strings. This repository provides the CRUD
 * layer for the String Translation admin page.
 *
 * @package IdiomatticWP\Repositories
 */

declare(strict_types=1);

namespace IdiomatticWP\Repositories;

class StringRepository
{
    private string $table;

    public function __construct(private \wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'idiomatticwp_strings';
    }

    /**
     * Register a string for translation (insert if not exists, per target lang).
     */
    public function register(string $domain, string $value, string $context, string $targetLang): void
    {
        $hash = md5($value);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->table}
                 (domain, context, source_string, source_hash, lang, status)
                 VALUES (%s, %s, %s, %s, %s, 'pending')",
                $domain,
                $context,
                $value,
                $hash,
                $targetLang
            )
        );
    }

    /**
     * Get unique source strings for a domain and target language.
     * Returns rows: [id, domain, context, source_string, source_hash, translated_string, status]
     */
    public function getStrings(string $lang, string $domain = '', string $search = '', int $limit = 50, int $offset = 0): array
    {
        $where  = ['lang = %s'];
        $params = [$lang];

        if ($domain !== '') {
            $where[]  = 'domain = %s';
            $params[] = $domain;
        }

        if ($search !== '') {
            $where[]  = '(source_string LIKE %s OR translated_string LIKE %s)';
            $like     = '%' . $this->wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);
        $params[]    = $limit;
        $params[]    = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, domain, context, source_string, source_hash, translated_string, status
                 FROM {$this->table}
                 WHERE {$whereClause}
                 ORDER BY domain ASC, source_string ASC
                 LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count strings matching filters.
     */
    public function countStrings(string $lang, string $domain = '', string $search = ''): int
    {
        $where  = ['lang = %s'];
        $params = [$lang];

        if ($domain !== '') {
            $where[]  = 'domain = %s';
            $params[] = $domain;
        }

        if ($search !== '') {
            $where[]  = '(source_string LIKE %s OR translated_string LIKE %s)';
            $like     = '%' . $this->wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}",
                ...$params
            )
        );
    }

    /**
     * Get strings grouped by source, with translations for every target language.
     *
     * Returns:
     *   [
     *     [ 'source_string' => '...', 'source_hash' => '...', 'domain' => '...', 'context' => '...',
     *       'translations' => [ 'es' => ['id'=>1,'translated_string'=>'...','status'=>'pending'], ... ] ],
     *     ...
     *   ]
     *
     * @param string[] $langs  Target language codes.
     */
    public function getStringsMultiLang(
        array $langs,
        string $domain = '',
        string $search = '',
        int $limit = 25,
        int $offset = 0,
        bool $exact = false,
        string $status = ''
    ): array {
        if ( empty( $langs ) ) {
            return [];
        }

        $langPlaceholders = implode( ',', array_fill( 0, count( $langs ), '%s' ) );

        $where  = [ "lang IN ({$langPlaceholders})" ];
        $params = $langs;

        if ( $domain !== '' ) {
            $where[]  = 'domain = %s';
            $params[] = $domain;
        }
        if ( $search !== '' ) {
            if ( $exact ) {
                $where[]  = '(source_string = %s OR translated_string = %s)';
                $params[] = $search;
                $params[] = $search;
            } else {
                $like     = '%' . $this->wpdb->esc_like( $search ) . '%';
                $where[]  = '(source_string LIKE %s OR translated_string LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }
        }
        if ( $status === 'pending' || $status === 'translated' ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        $whereClause = implode( ' AND ', $where );

        // Step 1: paginated unique (source_hash, domain) pairs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sources = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT source_hash, source_string, domain, context
                 FROM {$this->table}
                 WHERE {$whereClause}
                 GROUP BY source_hash, domain
                 ORDER BY domain ASC, source_string ASC
                 LIMIT %d OFFSET %d",
                ...array_merge( $params, [ $limit, $offset ] )
            ),
            ARRAY_A
        ) ?: [];

        if ( empty( $sources ) ) {
            return [];
        }

        // Step 2: fetch all translations for those hashes.
        $hashes          = array_unique( array_column( $sources, 'source_hash' ) );
        $hashPlaceholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, source_hash, domain, lang, translated_string, status
                 FROM {$this->table}
                 WHERE source_hash IN ({$hashPlaceholders})
                   AND lang IN ({$langPlaceholders})",
                ...array_merge( $hashes, $langs )
            ),
            ARRAY_A
        ) ?: [];

        // Index by [hash][domain][lang].
        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ $row['source_hash'] ][ $row['domain'] ][ $row['lang'] ] = $row;
        }

        // Build result.
        $result = [];
        foreach ( $sources as $source ) {
            $source['translations'] = $indexed[ $source['source_hash'] ][ $source['domain'] ] ?? [];
            $result[] = $source;
        }

        return $result;
    }

    /**
     * Count distinct source strings across all target languages.
     *
     * @param string[] $langs
     */
    public function countDistinctStrings( array $langs, string $domain = '', string $search = '', bool $exact = false, string $status = '' ): int {
        if ( empty( $langs ) ) {
            return 0;
        }

        $langPlaceholders = implode( ',', array_fill( 0, count( $langs ), '%s' ) );
        $where  = [ "lang IN ({$langPlaceholders})" ];
        $params = $langs;

        if ( $domain !== '' ) {
            $where[]  = 'domain = %s';
            $params[] = $domain;
        }
        if ( $search !== '' ) {
            if ( $exact ) {
                $where[]  = '(source_string = %s OR translated_string = %s)';
                $params[] = $search;
                $params[] = $search;
            } else {
                $like     = '%' . $this->wpdb->esc_like( $search ) . '%';
                $where[]  = '(source_string LIKE %s OR translated_string LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }
        }
        if ( $status === 'pending' || $status === 'translated' ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        $whereClause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT source_hash, domain
                    FROM {$this->table}
                    WHERE {$whereClause}
                    GROUP BY source_hash, domain
                 ) AS _distinct",
                ...$params
            )
        );
    }

    /**
     * Get distinct domains in the table.
     */
    public function getDomains(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $this->wpdb->get_col(
            "SELECT DISTINCT domain FROM {$this->table} ORDER BY domain ASC"
        ) ?: [];
    }

    /**
     * Save a translation for a string row.
     */
    public function saveTranslation(int $id, string $translated, string $status = 'translated'): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $this->wpdb->update(
            $this->table,
            ['translated_string' => $translated, 'status' => $status],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
        return $result !== false;
    }

    /**
     * Insert or update a string row with a translation already set.
     *
     * On duplicate (domain + source_hash + lang), updates the translation and
     * status — so existing rows are upgraded from 'pending' to 'translated'.
     */
    public function upsertWithTranslation(
        string $domain,
        string $value,
        string $context,
        string $targetLang,
        string $translation
    ): void {
        $hash   = md5( $value );
        $status = $translation !== '' ? 'translated' : 'pending';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->table}
                 (domain, context, source_string, source_hash, lang, translated_string, status)
                 VALUES (%s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                     translated_string = VALUES(translated_string),
                     status            = VALUES(status)",
                $domain,
                $context,
                $value,
                $hash,
                $targetLang,
                $translation,
                $status
            )
        );
    }

    /**
     * Return distinct (domain, lang) pairs for a set of row ids.
     * Used after saving to know which .mo files need recompiling.
     *
     * @param  int[]  $ids
     * @return array<array{domain: string, lang: string}>
     */
    public function getDomainsAndLangsByIds( array $ids ): array {
        if ( empty( $ids ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT domain, lang FROM {$this->table} WHERE id IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get the row id for a specific (source_hash, domain, lang) triple.
     */
    public function getRowId(string $hash, string $domain, string $lang): ?int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table}
                 WHERE source_hash = %s AND domain = %s AND lang = %s LIMIT 1",
                $hash,
                $domain,
                $lang
            )
        );
        return $id !== null ? (int) $id : null;
    }

    /**
     * Look up source_string and context for a (source_hash, domain) pair.
     * Returns null if no row exists for that hash + domain combination.
     *
     * @return array{source_string:string,context:string}|null
     */
    public function getSourceByHash(string $hash, string $domain): ?array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT source_string, context FROM {$this->table}
                 WHERE source_hash = %s AND domain = %s LIMIT 1",
                $hash,
                $domain
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Get translated string for a given source hash, domain, and lang.
     * Used by the frontend filter to serve translations.
     */
    public function getTranslation(string $domain, string $hash, string $lang): ?string
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT translated_string FROM {$this->table}
                 WHERE domain = %s AND source_hash = %s AND lang = %s AND status = 'translated'",
                $domain,
                $hash,
                $lang
            ),
            ARRAY_A
        );
        return $row ? ($row['translated_string'] ?: null) : null;
    }
}
