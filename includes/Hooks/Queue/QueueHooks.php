<?php
/**
 * QueueHooks — registers Action Scheduler callbacks for async translation jobs.
 *
 * Also hooks AutoTranslate into `idiomatticwp_after_create_translation`
 * so that new translations are automatically queued when the option is enabled.
 *
 * @package IdiomatticWP\Hooks\Queue
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Queue;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Queue\TranslationQueue;
use IdiomatticWP\Translation\AutoTranslate;
use IdiomatticWP\ValueObjects\LanguageCode;

class QueueHooks implements HookRegistrarInterface {

	public function __construct(
		private TranslationQueue $queue,
		private AutoTranslate    $autoTranslate,
	) {}

	public function register(): void {
		// Register the Action Scheduler processor hook
		$this->queue->registerHooks();

		// When a translation is created, dispatch a job if auto-translate is on
		add_action(
			'idiomatticwp_after_create_translation',
			[ $this, 'onAfterCreateTranslation' ],
			20, // after FieldTranslationHooks (priority 10)
			4
		);
	}

	/**
	 * Queue an async translation job after a translation record is created.
	 *
	 * @param int          $translationId
	 * @param int          $sourcePostId
	 * @param int          $targetPostId
	 * @param LanguageCode $targetLang
	 */
	public function onAfterCreateTranslation(
		int          $translationId,
		int          $sourcePostId,
		int          $targetPostId,
		LanguageCode $targetLang
	): void {
		if ( ! get_option( 'idiomatticwp_auto_translate', false ) ) {
			return;
		}

		// Get the source language from the post's translation record
		// (stored in the DB by CreateTranslation use case)
		$sourceLangStr = get_post_meta( $sourcePostId, '_idiomatticwp_lang', true );
		if ( ! $sourceLangStr ) {
			// Fall back to the default language
			$sourceLangStr = get_option( 'idiomatticwp_default_lang', 'en' );
		}

		$this->queue->dispatch(
			$translationId,
			$sourcePostId,
			$sourceLangStr,
			(string) $targetLang
		);
	}
}
