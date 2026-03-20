<?php
/**
 * YoastIntegration — SEO metadata and sitemap support.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Integrations\SEO;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class YoastIntegration implements IntegrationInterface
{

    public function __construct(private
        CustomElementRegistry $registry, private
        TranslationRepositoryInterface $repository
        )
    {
    }

    public function isAvailable(): bool
    {
        return defined('WPSEO_VERSION');
    }

    public function register(): void
    {
        // Disable our hreflang if Yoast is active (let Yoast handle it or use our presenter)
        add_filter('idiomatticwp_hreflang_links', '__return_empty_array');

        // Register Yoast meta fields
        add_action('init', [$this, 'registerYoastFields'], 25);

        // Extend sitemap
        add_filter('wpseo_sitemap_entry', [$this, 'addAlternatesToSitemapEntry'], 10, 3);
    }

    public function registerYoastFields(): void
    {
        $this->registry->registerPostField('*', '_yoast_wpseo_title', ['label' => 'SEO Title', 'field_type' => 'text']);
        $this->registry->registerPostField('*', '_yoast_wpseo_metadesc', ['label' => 'Meta Description', 'field_type' => 'textarea']);
        $this->registry->registerPostField('*', '_yoast_wpseo_opengraph-description', ['label' => 'OG Description', 'field_type' => 'textarea']);
    }

    public function addAlternatesToSitemapEntry(array $url, string $type, $object): array
    {
        if ($type !== 'post')
            return $url;

        $translations = $this->repository->findBySourcePostId((int)$object->ID);
        if (empty($translations))
            return $url;

        $url['alternates'] = [];
        foreach ($translations as $t) {
            $url['alternates'][] = [
                'hreflang' => $t['target_lang'],
                'href' => get_permalink((int)$t['translated_post_id'])
            ];
        }

        return $url;
    }
}
