<?php
/**
 * AutoTranslateAjax — handles AJAX requests for AI translation in the Translation Editor.
 *
 * Registered actions:
 *   wp_ajax_idiomatticwp_ai_translate_all   — translate all fields of a post
 *   wp_ajax_idiomatticwp_ai_translate_field — translate a single field
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Translation\AIOrchestrator;
use IdiomatticWP\Translation\FieldTranslator;
use IdiomatticWP\ValueObjects\LanguageCode;

class AutoTranslateAjax {

	public function __construct(
		private AIOrchestrator $orchestrator,
		private TranslationRepositoryInterface $repository,
		private LicenseChecker $licenseChecker,
		private FieldTranslator $fieldTranslator,
	) {}

	// ── Translate ALL fields ──────────────────────────────────────────────

	/**
	 * Handle wp_ajax_idiomatticwp_ai_translate_all.
	 *
	 * Translates every field of the given translated post via the AI orchestrator,
	 * saves the results to the database, then returns the freshly-updated field
	 * values so the Translation Editor can refresh without a page reload.
	 *
	 * Expected POST parameters:
	 *   nonce   — wp_nonce value for 'idiomatticwp_nonce'
	 *   post_id — (int) translated post ID (NOT the source post ID)
	 *
	 * On success responds with:
	 *   { success: true, data: { title, content, excerpt, custom_fields, stats } }
	 *
	 * On failure responds with:
	 *   { success: false, data: { message, code } }
	 *   code: 'invalid_api_key' | 'rate_limit' | 'provider_unavailable' | 'runtime_error' | 'unknown'
	 *
	 * Sends JSON and exits — never returns normally.
	 */
	public function handleAll(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! $this->licenseChecker->isPro() ) {
			wp_send_json_error( [ 'message' => __( 'AI translation requires a Pro license.', 'idiomattic-wp' ) ] );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		$translatedPostId = absint( $_POST['post_id'] ?? 0 );
		if ( ! $translatedPostId ) {
			wp_send_json_error( [ 'message' => __( 'Missing post ID.', 'idiomattic-wp' ) ] );
		}

		$record = $this->repository->findByTranslatedPost( $translatedPostId );
		if ( ! $record ) {
			wp_send_json_error( [ 'message' => __( 'Translation record not found.', 'idiomattic-wp' ) ] );
		}

		$sourcePost = get_post( (int) $record['source_post_id'] );
		if ( ! $sourcePost instanceof \WP_Post ) {
			wp_send_json_error( [ 'message' => __( 'Source post not found.', 'idiomattic-wp' ) ] );
		}

		try {
			$sourceLang = LanguageCode::from( $record['source_lang'] );
			$targetLang = LanguageCode::from( $record['target_lang'] );

			$result = $this->orchestrator->translate(
				$sourcePost->ID,
				(int) $record['id'],
				$sourceLang,
				$targetLang
			);

			// Read back the freshly-saved translated post so the editor can update
			$updatedPost = get_post( $translatedPostId );

			// Collect all translated custom field values as well
			$customFields = $this->fieldTranslator->getFieldTranslations( (int) $record['id'] );
			$customByKey  = array_column( $customFields, 'translated_value', 'field_key' );

			wp_send_json_success( [
				'title'        => $updatedPost->post_title ?? '',
				'content'      => $updatedPost->post_content ?? '',
				'excerpt'      => $updatedPost->post_excerpt ?? '',
				'custom_fields' => $customByKey,
				'stats'        => $result,
			] );

		} catch ( \IdiomatticWP\Exceptions\InvalidApiKeyException $e ) {
			wp_send_json_error( [
				'message' => __( 'Invalid API key. Please check your provider configuration in Settings → Translation.', 'idiomattic-wp' ),
				'code'    => 'invalid_api_key',
			] );

		} catch ( \IdiomatticWP\Exceptions\RateLimitException $e ) {
			wp_send_json_error( [
				'message' => __( 'Rate limit reached. Please wait a moment and try again.', 'idiomattic-wp' ),
				'code'    => 'rate_limit',
			] );

		} catch ( \IdiomatticWP\Exceptions\ProviderUnavailableException $e ) {
			wp_send_json_error( [
				'message' => __( 'Translation provider is temporarily unavailable. Please try again.', 'idiomattic-wp' ),
				'code'    => 'provider_unavailable',
			] );

		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [
				'message' => $e->getMessage(),
				'code'    => 'runtime_error',
			] );

		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message' => __( 'An unexpected error occurred during translation.', 'idiomattic-wp' ),
				'code'    => 'unknown',
			] );
		}
	}

	// ── Translate ONE field ───────────────────────────────────────────────

	/**
	 * Handle wp_ajax_idiomatticwp_ai_translate_field.
	 *
	 * Translates a single field of the given translated post and returns the
	 * translated value. Supports core fields (title / content / excerpt) and
	 * any custom meta key registered via the CustomElementRegistry.
	 *
	 * Expected POST parameters:
	 *   nonce   — wp_nonce value for 'idiomatticwp_nonce'
	 *   post_id — (int) translated post ID
	 *   field   — (string) field name: 'title', 'content', 'excerpt', or a meta key
	 *
	 * On success responds with:
	 *   { success: true, data: { value: string } }
	 *   When the source field is empty, value is an empty string (no API call is made).
	 *
	 * On failure responds with:
	 *   { success: false, data: { message, code } }
	 *   code: 'invalid_api_key' | 'rate_limit' | 'error'
	 *
	 * Sends JSON and exits — never returns normally.
	 */
	public function handleField(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! $this->licenseChecker->isPro() ) {
			wp_send_json_error( [ 'message' => __( 'AI translation requires a Pro license.', 'idiomattic-wp' ) ] );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		$translatedPostId = absint( $_POST['post_id'] ?? 0 );
		$field            = sanitize_text_field( wp_unslash( $_POST['field'] ?? '' ) );

		if ( ! $translatedPostId || ! $field ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'idiomattic-wp' ) ] );
		}

		$record = $this->repository->findByTranslatedPost( $translatedPostId );
		if ( ! $record ) {
			wp_send_json_error( [ 'message' => __( 'Translation record not found.', 'idiomattic-wp' ) ] );
		}

		$sourcePost = get_post( (int) $record['source_post_id'] );
		if ( ! $sourcePost instanceof \WP_Post ) {
			wp_send_json_error( [ 'message' => __( 'Source post not found.', 'idiomattic-wp' ) ] );
		}

		// Map the UI field name to the actual source value and content type
		[ $sourceValue, $contentType ] = $this->resolveSourceField( $field, $sourcePost );

		if ( trim( $sourceValue ) === '' ) {
			wp_send_json_success( [ 'value' => '' ] );
		}

		try {
			$sourceLang = LanguageCode::from( $record['source_lang'] );
			$targetLang = LanguageCode::from( $record['target_lang'] );

			$translated = $this->orchestrator->translateField(
				$sourceValue,
				$contentType,
				$sourceLang,
				$targetLang
			);

			wp_send_json_success( [ 'value' => $translated ] );

		} catch ( \IdiomatticWP\Exceptions\InvalidApiKeyException $e ) {
			wp_send_json_error( [
				'message' => __( 'Invalid API key. Please check your provider configuration in Settings → Translation.', 'idiomattic-wp' ),
				'code'    => 'invalid_api_key',
			] );

		} catch ( \IdiomatticWP\Exceptions\RateLimitException $e ) {
			wp_send_json_error( [
				'message' => __( 'Rate limit reached. Please wait a moment and try again.', 'idiomattic-wp' ),
				'code'    => 'rate_limit',
			] );

		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message' => $e->getMessage() ?: __( 'Translation failed.', 'idiomattic-wp' ),
				'code'    => 'error',
			] );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Resolve a UI field name to [sourceValue, contentType].
	 *
	 * Core fields (title/content/excerpt) are read from the WP_Post object.
	 * Custom fields are read from post meta on the source post.
	 *
	 * @return array{string, string} [source_value, content_type]
	 */
	private function resolveSourceField( string $field, \WP_Post $sourcePost ): array {
		$coreMap = [
			'title'   => [ $sourcePost->post_title,   'text' ],
			'content' => [ $sourcePost->post_content, 'html' ],
			'excerpt' => [ $sourcePost->post_excerpt, 'text' ],
		];

		if ( isset( $coreMap[ $field ] ) ) {
			return $coreMap[ $field ];
		}

		// Custom meta field — look up the registered content type
		$metaValue = (string) get_post_meta( $sourcePost->ID, $field, true );

		// Try to detect content type from registered field definition
		$registeredFields = $this->fieldTranslator->getTranslatableFields( $sourcePost->ID );
		$fieldType        = 'text'; // default

		foreach ( $registeredFields['custom'] ?? [] as $registeredField ) {
			if ( ( $registeredField['key'] ?? '' ) === $field ) {
				$fieldType = $registeredField['field_type'] ?? 'text';
				break;
			}
		}

		$contentType = $fieldType === 'html' ? 'html' : 'text';

		return [ $metaValue, $contentType ];
	}
}
