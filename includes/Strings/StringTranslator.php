<?php
/**
 * StringTranslator — manages string translations in the database.
 *
 * @package IdiomatticWP\Strings
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Strings;

use IdiomatticWP\ValueObjects\LanguageCode;

class StringTranslator
{

    private string $table;

    public function __construct(private \wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'idiomatticwp_strings';
    }

    /**
     * Register strings for active languages if not already present.
     */
    public function register(string $string, string $domain, string $context = ''): void
    {
        $hash = md5($domain . $string . $context);

        // This is simplified. In a real scenario, we'd ensure records for all active languages.
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (source_hash, domain, original_string, context, status) 
             VALUES (%s, %s, %s, %s, 'pending')",
            $hash, $domain, $string, $context
        ));
    }

    /**
     * Translate a string using the database.
     */
    public function translate(string $string, string $domain, LanguageCode $lang, string $context = ''): string
    {
        $hash = md5($domain . $string . $context);

        $translated = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT translated_string FROM {$this->table} 
             WHERE source_hash = %s AND domain = %s AND lang = %s AND status = 'translated'",
            $hash, $domain, (string)$lang
        ));

        return $translated ?: $string;
    }

    /**
     * Save a translation record.
     */
    public function saveTranslation(string $hash, string $domain, LanguageCode $lang, string $translated): void
    {
        $this->wpdb->update($this->table, [
            'translated_string' => $translated,
            'status' => 'translated'
        ], [
            'source_hash' => $hash,
            'domain' => $domain,
            'lang' => (string)$lang
        ]);
    }

    public function getPendingStrings(string $domain, LanguageCode $lang): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT source_hash, original_string, context FROM {$this->table} 
             WHERE domain = %s AND lang = %s AND status = 'pending'",
            $domain, (string)$lang
        ), ARRAY_A);
    }
}
