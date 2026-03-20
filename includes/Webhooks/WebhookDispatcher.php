<?php
/**
 * WebhookDispatcher — signs and POSTs translation event payloads to a
 * user-configured endpoint.
 *
 * Options consumed:
 *   idiomatticwp_webhook_url     — endpoint URL (string)
 *   idiomatticwp_webhook_secret  — HMAC-SHA256 signing secret (string)
 *   idiomatticwp_webhook_events  — enabled event slugs (string[])
 *
 * All HTTP calls are fire-and-forget (blocking=false, timeout=5) so they
 * never delay page rendering or background jobs.
 *
 * @package IdiomatticWP\Webhooks
 */

declare( strict_types=1 );

namespace IdiomatticWP\Webhooks;

class WebhookDispatcher {

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Main entry point.  Called by the on* handlers below, or directly.
	 *
	 * @param string               $event   Dot-separated slug, e.g. "translation.completed".
	 * @param array<string, mixed> $payload Event-specific data.
	 */
	public function dispatch( string $event, array $payload ): void {
		$url = (string) get_option( 'idiomatticwp_webhook_url', '' );

		if ( $url === '' ) {
			return;
		}

		$enabledEvents = (array) get_option( 'idiomatticwp_webhook_events', [] );

		if ( ! in_array( $event, $enabledEvents, true ) ) {
			return;
		}

		$this->sendRequest( $url, $event, $payload );
	}

	// ── Event handlers ────────────────────────────────────────────────────

	/**
	 * Hooked to `idiomatticwp_translation_completed`.
	 *
	 * @param int    $translationId  Translation record ID.
	 * @param string $targetLang     Target language code (e.g. "es").
	 */
	public function onTranslationCompleted( int $translationId, string $targetLang ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'idiomatticwp_translations';

		/** @var array{source_post_id: string, translated_post_id: string}|null $row */
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source_post_id, translated_post_id FROM {$table} WHERE id = %d LIMIT 1",
				$translationId
			),
			ARRAY_A
		);

		$sourcePostId = $row ? (int) $row['source_post_id'] : 0;
		$sourcePost   = $sourcePostId ? get_post( $sourcePostId ) : null;

		$this->dispatch( 'translation.completed', [
			'translation_id' => $translationId,
			'target_lang'    => $targetLang,
			'source_post_id' => $sourcePostId,
			'post_title'     => $sourcePost instanceof \WP_Post ? $sourcePost->post_title : '',
			'edit_url'       => $sourcePostId ? (string) get_edit_post_link( $sourcePostId, 'raw' ) : '',
		] );
	}

	/**
	 * Hooked to `idiomatticwp_translation_marked_outdated`.
	 *
	 * @param int $sourcePostId  The post whose translations were flagged.
	 */
	public function onTranslationOutdated( int $sourcePostId ): void {
		global $wpdb;

		$post = get_post( $sourcePostId );

		// Collect language codes of every outdated translation.
		$table = $wpdb->prefix . 'idiomatticwp_translations';

		/** @var array<array{target_lang: string}>|null $rows */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_lang FROM {$table} WHERE source_post_id = %d AND status = 'outdated'",
				$sourcePostId
			),
			ARRAY_A
		);

		$languagesAffected = array_column( is_array( $rows ) ? $rows : [], 'target_lang' );

		$this->dispatch( 'translation.outdated', [
			'source_post_id'    => $sourcePostId,
			'post_title'        => $post instanceof \WP_Post ? $post->post_title : '',
			'edit_url'          => (string) get_edit_post_link( $sourcePostId, 'raw' ),
			'languages_affected' => $languagesAffected,
		] );
	}

	/**
	 * Hooked to `idiomatticwp_translation_job_queued`.
	 *
	 * @param int $translationId  Translation record ID.
	 * @param int $actionId       Action Scheduler action ID.
	 */
	public function onTranslationQueued( int $translationId, int $actionId ): void {
		$this->dispatch( 'translation.queued', [
			'translation_id' => $translationId,
			'action_id'      => $actionId,
		] );
	}

	// ── Test helper ───────────────────────────────────────────────────────

	/**
	 * Send a test ping to an arbitrary URL.
	 *
	 * Uses blocking mode so the caller can check the response code.
	 *
	 * @param string $url  Target endpoint.
	 * @return bool  True when the server replied with a 2xx status.
	 */
	public function test( string $url ): bool {
		$body = wp_json_encode( [
			'event'    => 'ping',
			'timestamp' => time(),
			'site_url' => get_site_url(),
			'data'     => [ 'message' => 'IdiomatticWP webhook test' ],
		] );

		if ( $body === false ) {
			return false;
		}

		$secret    = (string) get_option( 'idiomatticwp_webhook_secret', '' );
		$signature = $secret !== ''
			? 'sha256=' . hash_hmac( 'sha256', $body, $secret )
			: '';

		$args = [
			'method'    => 'POST',
			'timeout'   => 10,
			'blocking'  => true,
			'body'      => $body,
			'headers'   => array_filter( [
				'Content-Type'             => 'application/json',
				'X-IdiomatticWP-Event'     => 'ping',
				'X-IdiomatticWP-Signature' => $signature !== '' ? $signature : null,
			] ),
		];

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Build the full envelope, sign it, and fire the HTTP request.
	 *
	 * @param string               $url
	 * @param string               $event
	 * @param array<string, mixed> $payload
	 */
	private function sendRequest( string $url, string $event, array $payload ): void {
		$body = wp_json_encode( [
			'event'     => $event,
			'timestamp' => time(),
			'site_url'  => get_site_url(),
			'data'      => $payload,
		] );

		if ( $body === false ) {
			$this->log( "Failed to JSON-encode payload for event '{$event}'." );
			return;
		}

		$secret  = (string) get_option( 'idiomatticwp_webhook_secret', '' );
		$headers = [
			'Content-Type'         => 'application/json',
			'X-IdiomatticWP-Event' => $event,
		];

		if ( $secret !== '' ) {
			$headers['X-IdiomatticWP-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$args = [
			'method'    => 'POST',
			'timeout'   => 5,
			'blocking'  => false, // fire-and-forget
			'body'      => $body,
			'headers'   => $headers,
		];

		$response = wp_remote_post( $url, $args );

		// With blocking=false wp_remote_post always returns [] on success, or
		// a WP_Error if the request could not even be initiated.
		if ( is_wp_error( $response ) ) {
			$this->log(
				"Webhook delivery failed for event '{$event}': " . $response->get_error_message()
			);
		}
	}

	/**
	 * Write a debug message to the PHP error log when WP_DEBUG is active.
	 *
	 * @param string $message
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[IdiomatticWP] WebhookDispatcher: ' . $message );
		}
	}
}
