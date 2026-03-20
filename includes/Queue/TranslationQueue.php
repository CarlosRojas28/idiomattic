<?php
/**
 * TranslationQueue — async translation job queue (Pro).
 *
 * Wraps Action Scheduler (bundled with WooCommerce, or standalone) to
 * dispatch translation jobs as background tasks. Falls back gracefully
 * to synchronous execution when Action Scheduler is not available.
 *
 * Usage:
 *   $queue->dispatch($translationId, $sourcePostId, $sourceLang, $targetLang);
 *
 * The scheduled action calls AutoTranslate via the registered hook:
 *   `idiomatticwp_process_translation_job`
 *
 * @package IdiomatticWP\Queue
 */

declare( strict_types=1 );

namespace IdiomatticWP\Queue;

use IdiomatticWP\Translation\AutoTranslate;
use IdiomatticWP\ValueObjects\LanguageCode;

class TranslationQueue {

	/** Action Scheduler hook name for translation jobs. */
	private const ACTION_HOOK = 'idiomatticwp_process_translation_job';

	/** Group name used to identify our jobs in the AS admin screen. */
	private const AS_GROUP = 'idiomatticwp';

	public function __construct(
		private AutoTranslate $autoTranslate,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Dispatch a translation job.
	 *
	 * If Action Scheduler is available the job is queued as a single async
	 * action. Otherwise it runs synchronously in the current request
	 * (suitable for small sites or testing).
	 *
	 * @param int    $translationId  Translation record ID.
	 * @param int    $sourcePostId   Source post ID.
	 * @param string $sourceLang     Source language code string.
	 * @param string $targetLang     Target language code string.
	 * @return int|null  Action Scheduler action ID, or null for sync execution.
	 */
	public function dispatch(
		int    $translationId,
		int    $sourcePostId,
		string $sourceLang,
		string $targetLang
	): ?int {
		$args = [
			'translation_id' => $translationId,
			'source_post_id' => $sourcePostId,
			'source_lang'    => $sourceLang,
			'target_lang'    => $targetLang,
		];

		if ( $this->actionSchedulerAvailable() ) {
			$actionId = as_enqueue_async_action(
				self::ACTION_HOOK,
				[ $args ],
				self::AS_GROUP
			);

			do_action( 'idiomatticwp_translation_job_queued', $translationId, $actionId );

			return (int) $actionId;
		}

		// Synchronous fallback — run immediately
		$this->processJob( $args );
		return null;
	}

	/**
	 * Cancel all pending jobs for a translation ID.
	 * Called when a translation is deleted before it completes.
	 */
	public function cancel( int $translationId ): void {
		if ( ! $this->actionSchedulerAvailable() ) {
			return;
		}

		as_unschedule_all_actions(
			self::ACTION_HOOK,
			[ [ 'translation_id' => $translationId ] ],
			self::AS_GROUP
		);
	}

	/**
	 * Returns true when there is a pending or in-progress job for
	 * a given translation record.
	 */
	public function isPending( int $translationId ): bool {
		if ( ! $this->actionSchedulerAvailable() ) {
			return false;
		}

		$pending = as_get_scheduled_actions( [
			'hook'   => self::ACTION_HOOK,
			'args'   => [ [ 'translation_id' => $translationId ] ],
			'group'  => self::AS_GROUP,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
		], 'ids' );

		$running = as_get_scheduled_actions( [
			'hook'   => self::ACTION_HOOK,
			'args'   => [ [ 'translation_id' => $translationId ] ],
			'group'  => self::AS_GROUP,
			'status' => \ActionScheduler_Store::STATUS_RUNNING,
		], 'ids' );

		return ! empty( $pending ) || ! empty( $running );
	}

	// ── Hook processor ────────────────────────────────────────────────────

	/**
	 * Process a single translation job.
	 * Registered as a WordPress action — called by Action Scheduler.
	 *
	 * @param array $args { translation_id, source_post_id, source_lang, target_lang }
	 */
	public function processJob( array $args ): void {
		$translationId = (int) ( $args['translation_id'] ?? 0 );
		$sourcePostId  = (int) ( $args['source_post_id'] ?? 0 );
		$targetLangStr = (string) ( $args['target_lang'] ?? '' );

		if ( ! $translationId || ! $sourcePostId || ! $targetLangStr ) {
			return;
		}

		try {
			$targetLang = LanguageCode::from( $targetLangStr );
			// Reuse AutoTranslate which handles all status updates + error catching
			( $this->autoTranslate )( $translationId, $sourcePostId, 0, $targetLang );
		} catch ( \IdiomatticWP\Exceptions\InvalidLanguageCodeException $e ) {
			error_log( '[IdiomatticWP] TranslationQueue: invalid language code — ' . $targetLangStr );
		}
	}

	/**
	 * Register the Action Scheduler hook so it can call processJob().
	 * Called from QueueHooks::register().
	 */
	public function registerHooks(): void {
		add_action( self::ACTION_HOOK, [ $this, 'processJob' ] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Check whether Action Scheduler is available on this site.
	 */
	private function actionSchedulerAvailable(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}
}
