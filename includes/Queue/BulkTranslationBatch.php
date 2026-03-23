<?php
/**
 * BulkTranslationBatch — persistent background batch processor for bulk AI translation.
 *
 * Problem solved:
 *   Translating hundreds of posts in one HTTP request exhausts PHP's execution
 *   time limit. This class separates enqueueing (instant) from processing (async).
 *
 * Flow:
 *   1. Caller collects (source_post_id, source_lang, target_lang) tuples and calls
 *      enqueue(). The tuples are appended to a persistent wp_options entry and a
 *      single WP-Cron event is scheduled to fire immediately.
 *
 *   2. Each cron tick calls processNextBatch():
 *        a. Acquires a transient lock to prevent concurrent execution.
 *        b. Pulls the next BATCH_SIZE jobs from the front of the queue.
 *        c. Calls CreateTranslation (DB write, fast).
 *        d. If Action Scheduler is available, dispatches each job to AS for async
 *           AI translation (very fast — just enqueues). Otherwise calls AutoTranslate
 *           directly (slower, but bounded to BATCH_SIZE × API call time).
 *        e. Removes processed jobs from the persistent queue.
 *        f. If jobs remain, schedules the next cron tick in RESCHEDULE_DELAY seconds.
 *
 * Concurrency:
 *   A 90-second transient lock (`idiomatticwp_bulk_lock`) prevents two cron ticks
 *   from processing the same jobs simultaneously. If the lock exists the tick exits
 *   immediately and WP-Cron will retry on the next page load.
 *
 * Cancellation:
 *   cancel() empties the queue and clears any scheduled cron events.
 *
 * @package IdiomatticWP\Queue
 */

declare( strict_types=1 );

namespace IdiomatticWP\Queue;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Exceptions\TranslationAlreadyExistsException;
use IdiomatticWP\Translation\AutoTranslate;
use IdiomatticWP\Translation\CreateTranslation;
use IdiomatticWP\ValueObjects\LanguageCode;

class BulkTranslationBatch {

	/** wp_options key for the persistent queue. */
	public const OPTION_KEY = 'idiomatticwp_bulk_queue';

	/** WP-Cron hook name. */
	public const CRON_HOOK = 'idiomatticwp_bulk_batch';

	/** Transient key for the concurrency lock. */
	private const LOCK_KEY = 'idiomatticwp_bulk_lock';

	/** Default number of jobs processed per cron tick. */
	private const DEFAULT_BATCH_SIZE = 25;

	/** Seconds to wait before scheduling the next cron tick. */
	private const RESCHEDULE_DELAY = 10;

	/** Transient TTL for the concurrency lock (seconds). */
	private const LOCK_TTL = 90;

	public function __construct(
		private CreateTranslation              $createTranslation,
		private AutoTranslate                  $autoTranslate,
		private TranslationRepositoryInterface $repository,
		private TranslationQueue               $translationQueue,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Append jobs to the persistent queue and schedule the cron tick.
	 *
	 * Each $job must be an array with keys:
	 *   - post_id      (int)    Source post ID.
	 *   - source_lang  (string) Source language code (e.g. "en").
	 *   - target_lang  (string) Target language code (e.g. "es").
	 *
	 * Duplicate jobs (same post_id + target_lang already in queue) are silently
	 * de-duplicated to avoid redundant API calls.
	 *
	 * @param array<int, array{post_id: int, source_lang: string, target_lang: string}> $jobs
	 * @return int Number of new jobs actually enqueued.
	 */
	public function enqueue( array $jobs ): int {
		if ( empty( $jobs ) ) {
			return 0;
		}

		$existing = $this->loadQueue();

		// Build a dedup index: "post_id:target_lang" → true
		$index = [];
		foreach ( $existing as $job ) {
			$index[ $job['post_id'] . ':' . $job['target_lang'] ] = true;
		}

		$added = 0;
		foreach ( $jobs as $job ) {
			$key = ( (int) $job['post_id'] ) . ':' . (string) ( $job['target_lang'] ?? '' );
			if ( isset( $index[ $key ] ) ) {
				continue;
			}
			$existing[] = [
				'post_id'     => (int) $job['post_id'],
				'source_lang' => (string) ( $job['source_lang'] ?? '' ),
				'target_lang' => (string) ( $job['target_lang'] ?? '' ),
			];
			$index[ $key ] = true;
			$added++;
		}

		$this->saveQueue( $existing );
		$this->scheduleNextTick( 0 );

		return $added;
	}

	/**
	 * Process the next batch of jobs from the queue.
	 * Called exclusively by the WP-Cron tick or Action Scheduler.
	 *
	 * Returns the number of jobs processed in this tick.
	 */
	public function processNextBatch(): int {
		// Concurrency guard
		if ( get_transient( self::LOCK_KEY ) ) {
			return 0;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			return $this->runBatch();
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Return the number of jobs currently waiting in the queue.
	 */
	public function count(): int {
		return count( $this->loadQueue() );
	}

	/**
	 * Return true if there is at least one cron event scheduled for our hook.
	 */
	public function isScheduled(): bool {
		return (bool) wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Empty the queue and clear any pending cron events.
	 */
	public function cancel(): void {
		$this->saveQueue( [] );
		$this->clearScheduled();
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Return configurable batch size.
	 * Filterable via `idiomatticwp_bulk_batch_size`.
	 */
	public function getBatchSize(): int {
		return max( 1, (int) apply_filters( 'idiomatticwp_bulk_batch_size', self::DEFAULT_BATCH_SIZE ) );
	}

	// ── Internal ─────────────────────────────────────────────────────────

	/**
	 * Pull and process the next batch, then update the queue option.
	 */
	private function runBatch(): int {
		$queue = $this->loadQueue();
		if ( empty( $queue ) ) {
			return 0;
		}

		$batchSize = $this->getBatchSize();
		$batch     = array_splice( $queue, 0, $batchSize );

		// Save the trimmed queue immediately so other processes see remaining work.
		$this->saveQueue( $queue );

		$processed = 0;

		foreach ( $batch as $job ) {
			$postId     = (int) $job['post_id'];
			$sourceLang = (string) $job['source_lang'];
			$targetLang = (string) $job['target_lang'];

			try {
				$langCode = LanguageCode::from( $targetLang );
			} catch ( \Throwable $e ) {
				continue;
			}

			// Step 1: Create the translation record (WP post duplicate + DB row).
			// If it already exists, fetch the existing record instead.
			try {
				$result        = ( $this->createTranslation )( $postId, $langCode );
				$translationId = (int) $result['translation_id'];
			} catch ( TranslationAlreadyExistsException $e ) {
				// Fetch the existing translation ID so we can still (re-)translate it.
				$record = $this->repository->findBySourceAndLang( $postId, $langCode );
				if ( $record === null ) {
					continue;
				}
				$translationId = (int) $record['id'];
			} catch ( \Throwable $e ) {
				error_log( '[IdiomatticWP] BulkBatch: CreateTranslation failed for post ' . $postId . ' → ' . $targetLang . ': ' . $e->getMessage() );
				continue;
			}

			// Step 2: Dispatch AI translation — use AS when available, otherwise inline.
			$dispatched = $this->translationQueue->dispatch(
				$translationId,
				$postId,
				$sourceLang,
				$targetLang
			);

			// If AS queue is full, fall back to inline translation.
			if ( $dispatched === false ) {
				try {
					( $this->autoTranslate )( $translationId, $postId, 0, $langCode );
				} catch ( \Throwable $e ) {
					error_log( '[IdiomatticWP] BulkBatch: AutoTranslate failed for translation ' . $translationId . ': ' . $e->getMessage() );
				}
			}

			$processed++;
		}

		// Schedule the next tick if there is more work.
		if ( ! empty( $queue ) ) {
			$this->scheduleNextTick( self::RESCHEDULE_DELAY );
		}

		do_action( 'idiomatticwp_bulk_batch_processed', $processed, count( $queue ) );

		return $processed;
	}

	// ── Queue persistence ─────────────────────────────────────────────────

	/** @return array<int, array{post_id: int, source_lang: string, target_lang: string}> */
	private function loadQueue(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/** @param array<int, array{post_id: int, source_lang: string, target_lang: string}> $queue */
	private function saveQueue( array $queue ): void {
		if ( empty( $queue ) ) {
			delete_option( self::OPTION_KEY );
		} else {
			update_option( self::OPTION_KEY, $queue, false ); // autoload = false
		}
	}

	// ── Cron scheduling ───────────────────────────────────────────────────

	private function scheduleNextTick( int $delaySeconds ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delaySeconds, self::CRON_HOOK );
		}
	}

	private function clearScheduled(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}
}
