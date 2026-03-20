<?php
/**
 * TranslationEditorInterface — contract for rendering the translation editor UI.
 *
 * Implementations:
 *   - TranslationEditor  (side-by-side editor, Free & Pro)
 *
 * @package IdiomatticWP\Contracts
 */

declare( strict_types=1 );

namespace IdiomatticWP\Contracts;

use IdiomatticWP\ValueObjects\LanguageCode;

interface TranslationEditorInterface {

	/**
	 * Render the full editor UI for a source post and target language.
	 *
	 * @param int          $postId     Source post ID.
	 * @param LanguageCode $targetLang Target language to translate into.
	 * @return string HTML markup ready to echo.
	 */
	public function render( int $postId, LanguageCode $targetLang ): string;

	/**
	 * Return the admin URL that opens the editor for a given post + language.
	 *
	 * @param int          $postId     Source post ID.
	 * @param LanguageCode $targetLang Target language.
	 * @return string Absolute admin URL.
	 */
	public function getUrl( int $postId, LanguageCode $targetLang ): string;
}
