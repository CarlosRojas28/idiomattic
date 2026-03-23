<?php
/**
 * AttachmentTranslationHooks — admin hooks for media/attachment metadata translation.
 *
 * Adds per-language translation fields (alt text, title, caption) to the
 * attachment edit screen. Translations are stored in the idiomatticwp_strings
 * table under the domain `idiomatticwp_attachment`.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Repositories\StringRepository;

class AttachmentTranslationHooks implements HookRegistrarInterface {

	private const DOMAIN = 'idiomatticwp_attachment';

	public function __construct(
		private LanguageManager $lm,
		private StringRepository $repo,
	) {}

	// ── register ──────────────────────────────────────────────────────────

	public function register(): void {
		add_filter( 'attachment_fields_to_edit', [ $this, 'addTranslationFields' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ $this, 'saveTranslationFields' ], 10, 2 );
	}

	// ── add fields ────────────────────────────────────────────────────────

	/**
	 * Append per-language translation inputs to the attachment edit form.
	 *
	 * @param array    $fields Existing attachment fields.
	 * @param \WP_Post $post   The attachment post object.
	 * @return array
	 */
	public function addTranslationFields( array $fields, \WP_Post $post ): array {
		$nonDefaultLangs = $this->getNonDefaultLanguages();
		if ( empty( $nonDefaultLangs ) ) {
			return $fields;
		}

		$attachmentId = $post->ID;

		// Retrieve source strings so we can look up existing translations by hash.
		$sourceAlt     = (string) ( get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ) ?: '' );
		$sourceTitle   = (string) ( $post->post_title ?? '' );
		$sourceCaption = (string) ( $post->post_excerpt ?? '' );

		ob_start();
		echo '<div class="iwp-attachment-translations">';
		echo '<style>';
		echo '.iwp-att-lang{margin-bottom:12px;padding:8px;background:#f9f9f9;border:1px solid #ddd;}';
		echo '.iwp-att-lang strong{display:block;margin-bottom:6px;}';
		echo '.iwp-att-field{margin-bottom:6px;}';
		echo '.iwp-att-field label{display:block;font-weight:600;margin-bottom:2px;}';
		echo '</style>';

		foreach ( $nonDefaultLangs as $lang ) {
			$langCode = (string) $lang;
			$langName = esc_html( $this->lm->getLanguageName( $lang ) );

			$altVal     = $sourceAlt     !== '' ? ( $this->repo->getTranslation( self::DOMAIN, md5( $sourceAlt ),     $langCode ) ?? '' ) : '';
			$titleVal   = $sourceTitle   !== '' ? ( $this->repo->getTranslation( self::DOMAIN, md5( $sourceTitle ),   $langCode ) ?? '' ) : '';
			$captionVal = $sourceCaption !== '' ? ( $this->repo->getTranslation( self::DOMAIN, md5( $sourceCaption ), $langCode ) ?? '' ) : '';

			echo '<div class="iwp-att-lang">';
			echo '<strong>' . $langName . ' (' . esc_html( $langCode ) . ')</strong>';

			// Alt text
			echo '<div class="iwp-att-field">';
			echo '<label for="iwp_att_trans_' . esc_attr( $langCode ) . '_alt">' . esc_html__( 'Alt Text', 'idiomattic-wp' ) . '</label>';
			echo '<input type="text"'
				. ' id="iwp_att_trans_' . esc_attr( $langCode ) . '_alt"'
				. ' name="iwp_att_trans[' . esc_attr( $langCode ) . '][alt]"'
				. ' value="' . esc_attr( $altVal ) . '"'
				. ' class="widefat" />';
			echo '</div>';

			// Title
			echo '<div class="iwp-att-field">';
			echo '<label for="iwp_att_trans_' . esc_attr( $langCode ) . '_title">' . esc_html__( 'Title', 'idiomattic-wp' ) . '</label>';
			echo '<input type="text"'
				. ' id="iwp_att_trans_' . esc_attr( $langCode ) . '_title"'
				. ' name="iwp_att_trans[' . esc_attr( $langCode ) . '][title]"'
				. ' value="' . esc_attr( $titleVal ) . '"'
				. ' class="widefat" />';
			echo '</div>';

			// Caption
			echo '<div class="iwp-att-field">';
			echo '<label for="iwp_att_trans_' . esc_attr( $langCode ) . '_caption">' . esc_html__( 'Caption', 'idiomattic-wp' ) . '</label>';
			echo '<input type="text"'
				. ' id="iwp_att_trans_' . esc_attr( $langCode ) . '_caption"'
				. ' name="iwp_att_trans[' . esc_attr( $langCode ) . '][caption]"'
				. ' value="' . esc_attr( $captionVal ) . '"'
				. ' class="widefat" />';
			echo '</div>';

			echo '</div>'; // .iwp-att-lang
		}

		echo '</div>'; // .iwp-attachment-translations

		$fields['iwp_translations'] = [
			'label' => __( 'Translations', 'idiomattic-wp' ),
			'input' => 'html',
			'html'  => ob_get_clean(),
		];

		return $fields;
	}

	// ── save fields ───────────────────────────────────────────────────────

	/**
	 * Persist per-language attachment translations.
	 *
	 * @param array $post       Post data array (contains 'ID').
	 * @param array $attachment Raw $_POST attachment data.
	 * @return array Unchanged $post array (required by filter contract).
	 */
	public function saveTranslationFields( array $post, array $attachment ): array {
		if ( empty( $attachment['iwp_att_trans'] ) || ! is_array( $attachment['iwp_att_trans'] ) ) {
			return $post;
		}

		$attachmentId    = (int) $post['ID'];
		$defaultLangCode = (string) $this->lm->getDefaultLanguage();
		$postObj         = get_post( $attachmentId );

		if ( ! $postObj instanceof \WP_Post ) {
			return $post;
		}

		$sourceAlt     = (string) ( get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ) ?: '' );
		$sourceTitle   = (string) ( $postObj->post_title ?? '' );
		$sourceCaption = (string) ( $postObj->post_excerpt ?? '' );

		$nonDefaultCodes = array_map(
			fn( $l ) => (string) $l,
			$this->getNonDefaultLanguages()
		);

		foreach ( $attachment['iwp_att_trans'] as $lang => $fieldValues ) {
			$lang = sanitize_key( (string) $lang );

			if ( '' === $lang || $lang === $defaultLangCode ) {
				continue;
			}

			// Only process known active non-default languages.
			if ( ! in_array( $lang, $nonDefaultCodes, true ) ) {
				continue;
			}

			if ( ! is_array( $fieldValues ) ) {
				continue;
			}

			$altVal     = sanitize_text_field( wp_unslash( $fieldValues['alt']     ?? '' ) );
			$titleVal   = sanitize_text_field( wp_unslash( $fieldValues['title']   ?? '' ) );
			$captionVal = sanitize_text_field( wp_unslash( $fieldValues['caption'] ?? '' ) );

			$this->upsertField( $attachmentId, 'alt',     $lang, $sourceAlt,     $altVal );
			$this->upsertField( $attachmentId, 'title',   $lang, $sourceTitle,   $titleVal );
			$this->upsertField( $attachmentId, 'caption', $lang, $sourceCaption, $captionVal );
		}

		return $post;
	}

	// ── helpers ───────────────────────────────────────────────────────────

	/**
	 * Upsert a single attachment field translation.
	 * Skips when source is empty (nothing to translate against).
	 */
	private function upsertField( int $attachmentId, string $field, string $lang, string $source, string $translation ): void {
		if ( '' === $source ) {
			return;
		}

		$context = $field . ':' . $attachmentId;
		$this->repo->upsertWithTranslation( self::DOMAIN, $source, $context, $lang, $translation );
	}

	/**
	 * Return all active languages except the site default.
	 *
	 * @return \IdiomatticWP\ValueObjects\LanguageCode[]
	 */
	private function getNonDefaultLanguages(): array {
		$default = (string) $this->lm->getDefaultLanguage();

		return array_filter(
			$this->lm->getActiveLanguages(),
			fn( $lang ) => (string) $lang !== $default
		);
	}
}
