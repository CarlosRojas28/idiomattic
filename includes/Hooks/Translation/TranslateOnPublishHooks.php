<?php
/**
 * TranslateOnPublishHooks — auto-queue AI translation when a post is published.
 *
 * When a post type has "Translate on publish" enabled in Settings → Content,
 * this hook fires on transition_post_status and enqueues translation jobs for
 * all active non-default languages that don't already have a translation.
 *
 * Requires: Pro license + configured AI provider.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Exceptions\TranslationAlreadyExistsException;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Queue\TranslationQueue;
use IdiomatticWP\Translation\CreateTranslation;

class TranslateOnPublishHooks implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
		private CreateTranslation              $createTranslation,
		private TranslationQueue               $queue,
		private LicenseChecker                 $licenseChecker,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'maybeQueueTranslations' ], 10, 3 );
	}

	// ── Callback ──────────────────────────────────────────────────────────

	/**
	 * Queue translation jobs when a post transitions to 'publish'.
	 *
	 * Only fires when:
	 *  - The new status is 'publish'
	 *  - The post type has translate-on-publish enabled
	 *  - The site has a Pro license
	 *  - The post is not already a translation (i.e., it is a source post)
	 */
	public function maybeQueueTranslations( string $newStatus, string $oldStatus, \WP_Post $post ): void {
		if ( $newStatus !== 'publish' ) {
			return;
		}

		if ( ! $this->licenseChecker->isPro() ) {
			return;
		}

		$enabledTypes = (array) get_option( 'idiomatticwp_translate_on_publish', [] );
		if ( ! in_array( $post->post_type, $enabledTypes, true ) ) {
			return;
		}

		// Skip if this post is itself a translation.
		if ( $this->repository->findByTranslatedPost( $post->ID ) !== null ) {
			return;
		}

		$default     = $this->languageManager->getDefaultLanguage();
		$sourceLang  = (string) $default;
		$targetLangs = array_filter(
			$this->languageManager->getActiveLanguages(),
			fn( $lang ) => ! $lang->equals( $default )
		);

		foreach ( $targetLangs as $targetLang ) {
			// Check if a translation already exists for this language.
			$existing = $this->repository->findBySourceAndLang( $post->ID, $targetLang );
			if ( $existing !== null ) {
				continue;
			}

			try {
				$result = ( $this->createTranslation )( $post->ID, $targetLang );
				$this->queue->dispatch(
					$result['translation_id'],
					$post->ID,
					$sourceLang,
					(string) $targetLang
				);
			} catch ( TranslationAlreadyExistsException ) {
				// Race condition — already exists, skip.
			} catch ( \Throwable $e ) {
				error_log( '[IdiomatticWP] TranslateOnPublish error for post ' . $post->ID . ': ' . $e->getMessage() );
			}
		}
	}
}
