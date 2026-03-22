<?php
/**
 * MoCompiler — compiles translations to binary .mo files.
 *
 * @package IdiomatticWP\Strings
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Strings;

use IdiomatticWP\ValueObjects\LanguageCode;

class MoCompiler
{

    public function __construct(private \wpdb $wpdb)
    {
    }

    /**
     * Compile translations for a domain and language to a .mo file.
     */
    public function compile(string $domain, LanguageCode $lang): bool
    {
        $translations = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT source_string, translated_string FROM {$this->wpdb->prefix}idiomatticwp_strings
             WHERE domain = %s AND lang = %s AND status = 'translated'",
            $domain, (string)$lang
        ), OBJECT_K);

        if (empty($translations))
            return false;

        $moData = $this->generateMoBinary($translations);

        $uploadDir = wp_upload_dir();
        $baseDir = $uploadDir['basedir'] . '/idiomattic-wp/languages';

        if (!file_exists($baseDir)) {
            wp_mkdir_p($baseDir);
        }

        $filename = "{$baseDir}/{$domain}-{$lang}.mo";
        return file_put_contents($filename, $moData) !== false;
    }

    /**
     * Generate GNU gettext .mo binary format.
     */
    private function generateMoBinary(array $translations): string
    {
        ksort($translations);
        $count = count($translations);

        // Header (7 x 4 bytes)
        // Magic, Revision, Count, OffsetOriginal, OffsetTranslated, OffsetHashtable, SizeHashtable
        $magic = 0x950412de; // Little-endian

        $ids = array_keys($translations);
        $tstrs = array_values(array_map(fn($t) => $t->translated_string, $translations));

        $headerSize = 7 * 4;
        $tableOriginalOffset = $headerSize;
        $tableTranslatedOffset = $tableOriginalOffset + ($count * 8);
        $stringOffset = $tableTranslatedOffset + ($count * 8);

        $header = pack('V7', $magic, 0, $count, $tableOriginalOffset, $tableTranslatedOffset, 0, 0);

        $tableOriginal = '';
        $tableTranslated = '';
        $stringsOriginal = '';
        $stringsTranslated = '';

        // Build Original Strings Table
        $currOffset = $stringOffset;
        foreach ($ids as $str) {
            $len = strlen($str);
            $tableOriginal .= pack('VV', $len, $currOffset);
            $stringsOriginal .= $str . "\0";
            $currOffset += $len + 1;
        }

        // Build Translated Strings Table
        foreach ($tstrs as $str) {
            $len = strlen($str);
            $tableTranslated .= pack('VV', $len, $currOffset);
            $stringsTranslated .= $str . "\0";
            $currOffset += $len + 1;
        }

        return $header . $tableOriginal . $tableTranslated . $stringsOriginal . $stringsTranslated;
    }
}
