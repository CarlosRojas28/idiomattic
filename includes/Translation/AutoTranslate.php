<?php
/**
 * AutoTranslate — use case: automatically translate a post when created.
 *
 * Invoked after a translation record is created (via the
 * `idiomatticwp_after_create_translation` action) when the site option
 * `idiomatticwp_auto_translate` is enabled.
 *
 * Delegates the full translation pipeline to AIOrchestrator. Errors are
 * caught and logged rather than surfaced to the user — auto-translate is
 * a background convenience, not a blocking operation.
 *
 * @package IdiomatticWP\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Translation;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\ValueObjects\LanguageCode;

class AutoTranslate {

	public function __construct(
		private AIOrchestrator               $orchestrator,
		private TranslationRepositoryInterface $repository,
	) {}

	/**
	 * Trigger automatic translation for a newly created translation record.
	 *
	 * @param int          $translationId  The idiomatticwp_translations row ID.
	 * @param int          $sourcePostId   Original post ID.
	 * @param int          $targetPostId   The newly created translated post ID.
	 * @param LanguageCode $targetLang     Target language.
	 */
	public function __invoke(
		int          $translationId,
		int          $sourcePostId,
		int          $targetPostId,
		LanguageCode $targetLang
	): void {
		// Guard: only run when auto-translate is enabled
		if ( ! get_option( 'idiomatticwp_auto_translate', false ) ) {
			return;
		}

		// Guard: source post must exist
		$sourcePost = get_post( $sourcePostId );
		if ( ! $sourcePost instanceof \WP_Post ) {
			return;
		}

		// Guard: get source language from the translation record
		$record = $this->repository->findBySourceAndLang( $sourcePostId, $targetLang );
		if ( ! $record ) {
			return;
		}

		try {
			$sourceLang = LanguageCode::from( $record['source_lang'] );

			$this->repository->updateStatus( $translationId, 'in_progress' );

			$this->orchestrator->translate(
				$sourcePostId,
				$translationId,
				$sourceLang,
				$targetLang
			);

			// Status is set to 'complete' by AIOrchestrator on success

		} catch ( \IdiomatticWP\Exceptions\InvalidApiKeyException $e ) {
			// Bad API key — mark failed so the UI can surface the issue
			$this->repository->updateStatus( $translationId, 'failed' );
			error_log( '[IdiomatticWP] AutoTranslate: invalid API key — ' . $e->getMessage() );

		} catch ( \IdiomatticWP\Exceptions\RateLimitException $e ) {
			// Rate limited — reset to draft so the user can retry
			$this->repository->updateStatus( $translationId, 'draft' );
			error_log( '[IdiomatticWP] AutoTranslate: rate limit hit — ' . $e->getMessage() );

		} catch ( \Throwable $e ) {
			$this->repository->updateStatus( $translationId, 'draft' );
			error_log( '[IdiomatticWP] AutoTranslate: unexpected error — ' . $e->getMessage() );
		}
	}
}
