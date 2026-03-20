<?php
/**
 * TranslationOriginMetabox — renders the origin info for a translated post.
 *
 * Shows "This is a translation of: [source]" with a link to the source post
 * and the name of the source language.
 *
 * @package IdiomatticWP\Admin\Metaboxes
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Admin\Metaboxes;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class TranslationOriginMetabox
{

    public function __construct(private
        TranslationRepositoryInterface $repository, private
        LanguageManager $languageManager
        )
    {
    }

    /**
     * Render the metabox content.
     */
    public function render(\WP_Post $post): void
    {
        $record = $this->repository->findByTranslatedPost($post->ID);

        if (!$record) {
            printf('<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__('Original post not found.', 'idiomattic-wp'));
            return;
        }

        $sourcePost = get_post((int)$record['source_post_id']);
        $sourceLang = \IdiomatticWP\ValueObjects\LanguageCode::from($record['source_lang']);
        $langName = $this->languageManager->getLanguageName($sourceLang);

        echo '<div class="idiomatticwp-metabox-content">';
        echo '<p>';
        printf(
            __('This is a translation of: %s', 'idiomattic-wp'),
            '<strong>' . esc_html($langName) . '</strong>'
        );
        echo '</p>';

        if ($sourcePost instanceof \WP_Post) {
            printf(
                '<p><a href="%s" class="button button-small">%s</a></p>',
                esc_url(get_edit_post_link($sourcePost->ID)),
                esc_html__('Edit Original', 'idiomattic-wp')
            );
        }
        else {
            printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html__('Original post has been deleted.', 'idiomattic-wp'));
        }

        echo '</div>';
    }
}
