<?php
/**
 * TranslationMemoryHooks — populates the Translation Memory whenever a
 * translation is saved via the Translation Editor.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Memory\TranslationMemory;
use IdiomatticWP\ValueObjects\LanguageCode;

class TranslationMemoryHooks implements HookRegistrarInterface {

	public function __construct( private TranslationMemory $memory ) {}

	public function register(): void {
		add_action(
			'idiomatticwp_translation_editor_saved',
			[ $this, 'onEditorSaved' ],
			10,
			4
		);
	}

	/**
	 * Save title and excerpt segments to the Translation Memory.
	 *
	 * @param int      $translatedPostId
	 * @param int      $sourcePostId
	 * @param array    $record   Translation record (includes source_lang, target_lang).
	 * @param \WP_Post $translated
	 */
	public function onEditorSaved(
		int $translatedPostId,
		int $sourcePostId,
		array $record,
		\WP_Post $translated
	): void {
		$source = get_post( $sourcePostId );
		if ( ! $source ) {
			return;
		}

		try {
			$sourceLang = LanguageCode::from( $record['source_lang'] );
			$targetLang = LanguageCode::from( $record['target_lang'] );
		} catch ( \Throwable $e ) {
			return;
		}

		// Save title if both sides are non-empty
		if ( $source->post_title && $translated->post_title ) {
			$this->memory->save(
				$source->post_title,
				$translated->post_title,
				$sourceLang,
				$targetLang,
				'manual'
			);
		}

		// Save excerpt if both sides are non-empty
		if ( $source->post_excerpt && $translated->post_excerpt ) {
			$this->memory->save(
				$source->post_excerpt,
				$translated->post_excerpt,
				$sourceLang,
				$targetLang,
				'manual'
			);
		}
	}
}
