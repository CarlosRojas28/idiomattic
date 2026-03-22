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

	/**
	 * Maximum number of pending + running jobs allowed in the queue at once.
	 * Prevents memory exhaustion when bulk-translating large sites.
	 * Filterable via `idiomatticwp_queue_max_pending`.
	 */
	private const DEFAULT_MAX_PENDING = 200;

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
	 * When the queue is full (pending + running ≥ max), the job is rejected
	 * and false is returned so callers can skip or schedule a retry.
	 *
	 * @param int    $translationId  Translation record ID.
	 * @param int    $sourcePostId   Source post ID.
	 * @param string $sourceLang     Source language code string.
	 * @param string $targetLang     Target language code string.
	 * @return int|false|null  AS action ID, false if queue is full, or null for sync execution.
	 */
	public function dispatch(
		int    $translationId,
		int    $sourcePostId,
		string $sourceLang,
		string $targetLang
	): int|false|null {
		$args = [
			'translation_id' => $translationId,
			'source_post_id' => $sourcePostId,
			'source_lang'    => $sourceLang,
			'target_lang'    => $targetLang,
		];

		if ( $this->actionSchedulerAvailable() ) {
			if ( $this->countPending() >= $this->getMaxPending() ) {
				do_action( 'idiomatticwp_translation_queue_full', $translationId );
				return false;
			}

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
	 * Count the total number of pending + running jobs in our group.
	 * Used to enforce the concurrency cap before enqueuing a new job.
	 */
	public function countPending(): int {
		if ( ! $this->actionSchedulerAvailable() ) {
			return 0;
		}

		$pending = as_get_scheduled_actions( [
			'hook'     => self::ACTION_HOOK,
			'group'    => self::AS_GROUP,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		], 'ids' );

		$running = as_get_scheduled_actions( [
			'hook'     => self::ACTION_HOOK,
			'group'    => self::AS_GROUP,
			'status'   => \ActionScheduler_Store::STATUS_RUNNING,
			'per_page' => -1,
		], 'ids' );

		return count( $pending ) + count( $running );
	}

	/**
	 * Return the maximum allowed pending jobs.
	 * Filterable so site owners can tune it per their server resources.
	 */
	public function getMaxPending(): int {
		return (int) apply_filters( 'idiomatticwp_queue_max_pending', self::DEFAULT_MAX_PENDING );
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
			error_log( '[IdiomatticWP] TranslationQueue: processJob called with incomplete args — ' . wp_json_encode( $args ) );
			return;
		}

		try {
			$targetLang = LanguageCode::from( $targetLangStr );
			// AutoTranslate handles all status updates, API errors, and \Throwable internally.
			( $this->autoTranslate )( $translationId, $sourcePostId, 0, $targetLang );
		} catch ( \IdiomatticWP\Exceptions\InvalidLanguageCodeException $e ) {
			error_log( '[IdiomatticWP] TranslationQueue: invalid language code — ' . $targetLangStr );
		} catch ( \Throwable $e ) {
			// Unexpected error outside AutoTranslate (e.g. container resolution failure).
			error_log( '[IdiomatticWP] TranslationQueue: unexpected error for translation ' . $translationId . ' — ' . $e->getMessage() );
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
