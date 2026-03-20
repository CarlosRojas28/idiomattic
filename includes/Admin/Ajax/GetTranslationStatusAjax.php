<?php
/**
 * GetTranslationStatusAjax — returns translation statuses for a post.
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class GetTranslationStatusAjax
{

    public function __construct(private
        LanguageManager $languageManager, private
        TranslationRepositoryInterface $repository
        )
    {
    }

    /**
     * Handle the AJAX request.
     */
    public function handle(): void
    {
        check_ajax_referer('idiomatticwp_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'idiomattic-wp')]);
        }

        $postId = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$postId) {
            $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        }

        if (!$postId) {
            wp_send_json_error(['message' => __('Missing post ID.', 'idiomattic-wp')]);
        }

        $activeLanguages = $this->languageManager->getActiveLanguages();
        $defaultLang = (string)$this->languageManager->getDefaultLanguage();
        $statuses = [];

        foreach ($activeLanguages as $lang) {
            $langCode = (string)$lang;
            if ($langCode === $defaultLang) {
                continue;
            }

            $translation = $this->repository->findBySourceAndLang($postId, $lang);

            $statuses[] = [
                'lang' => $langCode,
                'status' => $translation ? $translation['status'] : 'missing',
                'edit_url' => $translation ? get_edit_post_link((int)$translation['translated_post_id'], 'raw') : '',
            ];
        }

        wp_send_json_success(['statuses' => $statuses]);
    }
}
