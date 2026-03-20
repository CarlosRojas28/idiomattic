<?php
/**
 * StringScanner — scans PHP files for translatable strings.
 *
 * @package IdiomatticWP\Strings
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Strings;

class StringScanner
{

    /**
     * Scan a directory for translatable strings for a specific domain.
     *
     * @return TranslatableString[]
     */
    public function scan(string $directory, string $domain): array
    {
        if (!is_dir($directory))
            return [];

        $strings = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            // Regex to find: __( 'string', 'domain' ) and _e( 'string', 'domain' )
            $pattern = '/(?:__|esc_html__|esc_attr__|_e)\(\s*[\'"](.+?)[\'"]\s*,\s*[\'"]' . preg_quote($domain) . '[\'"]/';

            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $original) {
                    $string = TranslatableString::create($domain, $original);
                    $strings[$string->hash] = $string;
                }
            }

            // Regex for _x( 'string', 'context', 'domain' )
            $contextPattern = '/(?:_x|esc_html_x|esc_attr_x)\(\s*[\'"](.+?)[\'"]\s*,\s*[\'"](.+?)[\'"]\s*,\s*[\'"]' . preg_quote($domain) . '[\'"]/';
            if (preg_match_all($contextPattern, $content, $matches)) {
                foreach ($matches[1] as $index => $original) {
                    $context = $matches[2][$index];
                    $string = TranslatableString::create($domain, $original, $context);
                    $strings[$string->hash] = $string;
                }
            }
        }

        return array_values($strings);
    }

    public function scanTheme(string $domain): array
    {
        return $this->scan(get_stylesheet_directory(), $domain);
    }

    public function scanPlugin(string $pluginDir, string $domain): array
    {
        return $this->scan($pluginDir, $domain);
    }
}
