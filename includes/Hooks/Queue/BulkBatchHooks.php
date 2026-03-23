<?php
/**
 * BulkBatchHooks — wires BulkTranslationBatch into WordPress Cron.
 *
 * Registers the `idiomatticwp_bulk_batch` cron action and delegates to
 * BulkTranslationBatch::processNextBatch() when it fires.
 *
 * Also exposes an AJAX endpoint so the admin UI can poll the queue size
 * and display a live progress bar without a full page reload.
 *
 * @package IdiomatticWP\Hooks\Queue
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Queue;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Queue\BulkTranslationBatch;

class BulkBatchHooks implements HookRegistrarInterface {

	public function __construct( private BulkTranslationBatch $batch ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// WP-Cron batch processor.
		add_action( BulkTranslationBatch::CRON_HOOK, [ $this, 'runBatch' ] );

		// AJAX: queue status poll (progress bar in admin UI).
		add_action( 'wp_ajax_idiomatticwp_bulk_status',  [ $this, 'handleStatus' ] );
		add_action( 'wp_ajax_idiomatticwp_bulk_cancel',  [ $this, 'handleCancel' ] );
	}

	// ── Cron callback ─────────────────────────────────────────────────────

	public function runBatch(): void {
		$this->batch->processNextBatch();
	}

	// ── AJAX: status ──────────────────────────────────────────────────────

	/**
	 * Return the current queue depth and scheduled state.
	 * Used by the admin progress bar to poll without a full page reload.
	 */
	public function handleStatus(): void {
		check_ajax_referer( 'idiomatticwp_bulk_status' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		wp_send_json_success( [
			'pending'     => $this->batch->count(),
			'scheduled'   => $this->batch->isScheduled(),
			'batch_size'  => $this->batch->getBatchSize(),
		] );
	}

	// ── AJAX: cancel ──────────────────────────────────────────────────────

	public function handleCancel(): void {
		check_ajax_referer( 'idiomatticwp_bulk_cancel' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		$this->batch->cancel();
		wp_send_json_success( [ 'message' => __( 'Bulk translation queue cancelled.', 'idiomattic-wp' ) ] );
	}
}
