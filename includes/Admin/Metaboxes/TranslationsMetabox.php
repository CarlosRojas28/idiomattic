<?php
/**
 * TranslationsMetabox — renders the translation management panel in the sidebar.
 *
 * Shows each active language, its translation status for the current post,
 * and buttons to edit or create translations.
 *
 * @package IdiomatticWP\Admin\Metaboxes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Metaboxes;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class TranslationsMetabox {

	public function __construct(
		private LanguageManager $languageManager,
		private TranslationRepositoryInterface $repository,
	) {}

	/**
	 * Render the metabox content.
	 */
	public function render( \WP_Post $post ): void {
		$activeLanguages = $this->languageManager->getActiveLanguages();
		$defaultLang     = (string) $this->languageManager->getDefaultLanguage();

		wp_nonce_field( 'idiomatticwp_metabox', 'idiomatticwp_metabox_nonce' );

		echo '<div class="idiomatticwp-metabox-content">';
		echo '<ul class="idiomatticwp-translation-list">';

		foreach ( $activeLanguages as $lang ) {
			$langCode = (string) $lang;
			if ( $langCode === $defaultLang ) {
				continue;
			}

			$translation = $this->repository->findBySourceAndLang( $post->ID, $lang );
			$status      = $translation ? $translation['status'] : 'missing';

			$this->renderLanguageRow(
				$post->ID,
				$langCode,
				$status,
				(int) ( $translation['translated_post_id'] ?? 0 )
			);
		}

		echo '</ul>';
		echo '</div>';
	}

	private function renderLanguageRow(
		int $sourcePostId,
		string $langCode,
		string $status,
		int $translatedId
	): void {
		$lang = \IdiomatticWP\ValueObjects\LanguageCode::from( $langCode );
		$name = $this->languageManager->getLanguageName( $lang );

		echo '<li class="idiomatticwp-translation-row">';

		// Flag and language name
		echo '<div class="idiomatticwp-translation-row__lang">';
		$this->renderFlag( $langCode );
		echo '<strong>' . esc_html( $name ) . '</strong>';
		echo '</div>';

		// Status badge + action buttons
		echo '<div class="idiomatticwp-translation-row__actions">';

		if ( $translatedId ) {
			// Link always points to the Translation Editor
			$editorUrl   = add_query_arg(
				[ 'post' => $translatedId, 'action' => 'idiomatticwp_translate' ],
				admin_url( 'post.php' )
			);
			$statusLabel = $this->getStatusLabel( $status );

			printf(
				'<span class="idiomatticwp-status-badge idiomatticwp-status-badge--%s">%s</span>',
				esc_attr( $status ),
				esc_html( $statusLabel )
			);

			printf(
				'<a href="%s" class="button button-small">%s</a>',
				esc_url( $editorUrl ),
				esc_html__( 'Edit', 'idiomattic-wp' )
			);
		} else {
			printf(
				'<button type="button"
					class="button button-secondary button-small idiomatticwp-create-translation"
					data-idiomatticwp-action="create-translation"
					data-post-id="%d"
					data-lang="%s">%s</button>',
				$sourcePostId,
				esc_attr( $langCode ),
				esc_html__( 'Add', 'idiomattic-wp' )
			);
		}

		echo '</div>';
		echo '</li>';
	}

	/**
	 * Render a flag image for a language code.
	 * Falls back to a text badge if the SVG is not found.
	 */
	private function renderFlag( string $langCode ): void {
		$flagUrl  = IDIOMATTICWP_ASSETS_URL . 'flags/' . $langCode . '.svg';
		$flagPath = IDIOMATTICWP_PATH . 'assets/flags/' . $langCode . '.svg';

		if ( file_exists( $flagPath ) ) {
			printf(
				'<img src="%s" alt="%s" class="idiomatticwp-flag" width="18" height="13" loading="lazy">',
				esc_url( $flagUrl ),
				esc_attr( $langCode )
			);
		} else {
			// Text-only fallback — no hardcoded inline styles on the flag element
			printf(
				'<span class="idiomatticwp-flag-fallback">%s</span>',
				esc_html( strtoupper( substr( $langCode, 0, 2 ) ) )
			);
		}
	}

	/**
	 * Return a human-readable, translatable label for a translation status.
	 */
	private function getStatusLabel( string $status ): string {
		return match ( $status ) {
			'complete'    => __( 'Done', 'idiomattic-wp' ),
			'outdated'    => __( 'Outdated', 'idiomattic-wp' ),
			'draft'       => __( 'Draft', 'idiomattic-wp' ),
			'in_progress' => __( 'In progress', 'idiomattic-wp' ),
			'failed'      => __( 'Failed', 'idiomattic-wp' ),
			default       => ucfirst( $status ),
		};
	}
}
