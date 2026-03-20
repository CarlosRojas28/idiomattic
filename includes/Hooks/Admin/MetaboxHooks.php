<?php
/**
 * MetaboxHooks — registers metaboxes for the post editor.
 *
 * Depending on whether the post is a source or a translation,
 * it registers either the TranslationsMetabox or TranslationOriginMetabox.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Admin\Metaboxes\TranslationsMetabox;
use IdiomatticWP\Admin\Metaboxes\TranslationOriginMetabox;
use IdiomatticWP\Core\LanguageManager;

class MetaboxHooks implements HookRegistrarInterface
{

    public function __construct(private
        TranslationRepositoryInterface $repository, private
        LanguageManager $languageManager, private
        TranslationsMetabox $translationsMetabox, private
        TranslationOriginMetabox $originMetabox
        )
    {
    }

    // ── HookRegistrarInterface ────────────────────────────────────────────

    public function register(): void
    {
        add_action( 'add_meta_boxes', [ $this, 'registerMetaboxes' ] );

        // Gutenberg renders metaboxes in a separate iframe (WP 6.3+).
        // We need our admin script + localisation inside that iframe too,
        // so enqueue it on both the main editor document and the meta-box iframe.
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueInBlockEditor' ] );
    }

    // ── Callbacks ─────────────────────────────────────────────────────────

    /**
     * Decide which metabox to show based on the post.
     */
    public function registerMetaboxes(string $postType): void
    {
        if (!$this->isTranslatable($postType)) {
            return;
        }

        $post = get_post();
        if (!$post) {
            return;
        }

        $translationRecord = $this->repository->findByTranslatedPost($post->ID);

        // '__back_compat_meta_box' => false tells Gutenberg this metabox
        // IS compatible with the block editor — it will be rendered in the
        // block editor's meta-box iframe, which receives our enqueued script.
        $metaboxArgs = [ '__back_compat_meta_box' => false ];

        if ( $translationRecord ) {
            // This is a translated post
            add_meta_box(
                'idiomatticwp_translation_origin',
                __( 'Translation Details', 'idiomattic-wp' ),
                [ $this->originMetabox, 'render' ],
                $postType,
                'side',
                'high',
                $metaboxArgs
            );
        } else {
            // This is a source post
            add_meta_box(
                'idiomatticwp_translations',
                __( 'Translations', 'idiomattic-wp' ),
                [ $this->translationsMetabox, 'render' ],
                $postType,
                'side',
                'high',
                $metaboxArgs
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Enqueue the admin script inside the Gutenberg block editor context.
	 *
	 * Gutenberg renders metaboxes in an iframe that has its own document.
	 * WordPress automatically copies scripts enqueued via
	 * `enqueue_block_editor_assets` into that iframe, so this ensures
	 * `idiomatticwpAdmin` (ajaxUrl, nonce, i18n) is available when the
	 * "Add" button is clicked inside the metabox.
	 *
	 * We use `wp_script_is` to avoid double-enqueue when `AssetHooks` has
	 * already registered the script on the same request.
	 */
	public function enqueueInBlockEditor(): void {
		if ( ! wp_script_is( 'idiomatticwp-admin', 'enqueued' ) ) {
			wp_enqueue_script(
				'idiomatticwp-admin',
				IDIOMATTICWP_ASSETS_URL . 'js/admin/admin.js',
				[],  // No jQuery dependency — the script uses native fetch()
				IDIOMATTICWP_VERSION,
				true
			);
		}

		// Always (re-)localize so the data is available in the iframe document
		wp_localize_script( 'idiomatticwp-admin', 'idiomatticwpAdmin', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'idiomatticwp_nonce' ),
			'upgradeUrl' => idiomatticwp_upgrade_url(),
			'i18n'       => [
				'creating'       => __( 'Creating...', 'idiomattic-wp' ),
				'error'          => __( 'Error creating translation. Please try again.', 'idiomattic-wp' ),
				'confirm_delete' => __( 'Are you sure you want to delete this translation?', 'idiomattic-wp' ),
			],
		] );
	}

	private function isTranslatable( string $postType ): bool {
		$config = get_option( 'idiomatticwp_post_type_config', [] );

		if ( empty( $config ) ) {
			$all = get_post_types( [ 'public' => true ] );
			unset( $all['attachment'] );
			$types = array_keys( $all );
		} else {
			$types = array_keys( array_filter(
				$config,
				fn( $mode ) => in_array( $mode, [ 'translate', 'show_as_translated' ], true )
			) );
		}

		$types = (array) apply_filters( 'idiomatticwp_translatable_post_types', $types );
		return in_array( $postType, $types, true );
	}
}
