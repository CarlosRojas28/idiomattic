<?php
/**
 * WpmlDetector — detects WPML environment.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Migration;

class WpmlDetector
{

    public function __construct(private \wpdb $wpdb)
    {
    }

    public function isWpmlActive(): bool
    {
        return defined('ICL_SITEPRESS_VERSION');
    }

    public function getTranslationCount(): int
    {
        if (!$this->isWpmlActive())
            return 0;

        $table = $this->wpdb->prefix . 'icl_translations';
        $result = $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE element_type LIKE 'post_%'");

        return (int)$result;
    }

    public function getActiveLanguages(): array
    {
        if (!$this->isWpmlActive())
            return [];

        $table = $this->wpdb->prefix . 'icl_languages';
        return $this->wpdb->get_results("SELECT code, english_name, active FROM {$table} WHERE active = 1");
    }
}
