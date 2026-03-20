<?php
/**
 * CreateTranslationAjax — handles the AJAX request to create a translation.
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Translation\CreateTranslation;
use IdiomatticWP\ValueObjects\LanguageCode;

class CreateTranslationAjax
{

    public function __construct(private CreateTranslation $createTranslation)
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

        $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $lang = isset($_POST['lang']) ? sanitize_key($_POST['lang']) : '';

        if (!$postId || !$lang) {
            wp_send_json_error(['message' => __('Missing parameters.', 'idiomattic-wp')]);
        }

        try {
            $result = ($this->createTranslation)($postId, LanguageCode::from($lang));

            // Redirect to our Translation Editor, not the standard WP post editor
            $editorUrl = add_query_arg(
            [ 'post' => $result['translated_post_id'], 'action' => 'idiomatticwp_translate' ],
				admin_url( 'post.php' )
			);
			wp_send_json_success([
					'redirect_url' => $editorUrl,
				]);
        }
        catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
