<?php
/**
 * RestApiIntegration — adds multilingual support to the WordPress REST API.
 *
 * What this does:
 *
 *   1. Accepts a `lang` query parameter on all core endpoints so that
 *      requests like GET /wp/v2/posts?lang=es return translated posts.
 *
 *   2. Adds an `X-IdiomatticWP-Language` response header with the
 *      resolved language for the request.
 *
 *   3. Registers a dedicated `/idiomattic-wp/v1/` namespace with:
 *        GET  /idiomattic-wp/v1/languages        — active languages list
 *        GET  /idiomattic-wp/v1/translations/{id} — translation relationships
 *        POST /idiomattic-wp/v1/translations/{id}/translate — trigger AI translation
 *
 *   4. Exposes `translations` and `translation_status` as extra fields on
 *      all translatable post type REST responses.
 *
 * @package IdiomatticWP\Integrations\REST
 */

declare( strict_types=1 );

namespace IdiomatticWP\Integrations\REST;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\ValueObjects\LanguageCode;

class RestApiIntegration implements IntegrationInterface {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
		private LicenseChecker                 $licenseChecker,
	) {}

	// ── IntegrationInterface ──────────────────────────────────────────────

	public function isAvailable(): bool {
		return true; // REST API is always available in WP 4.7+
	}

	public function register(): void {
		// 1. Accept ?lang= on all REST requests
		add_filter( 'rest_request_before_callbacks', [ $this, 'applyLangParam' ], 10, 3 );

		// 2. Add X-IdiomatticWP-Language response header
		add_filter( 'rest_post_dispatch', [ $this, 'addLanguageHeader' ], 10, 3 );

		// 3. Register our own namespace/endpoints
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );

		// 4. Add translation fields to post responses
		add_action( 'rest_api_init', [ $this, 'registerPostFields' ] );
	}

	// ── Lang param ────────────────────────────────────────────────────────

	/**
	 * Set the current language from the ?lang= query parameter.
	 * Runs before any REST callback so all subsequent code sees the right lang.
	 */
	public function applyLangParam(
		$response,
		$handler,
		\WP_REST_Request $request
	) {
		$lang = sanitize_key( $request->get_param( 'lang' ) ?? '' );
		if ( ! $lang ) return $response;

		try {
			$langCode = LanguageCode::from( $lang );
			if ( in_array( $langCode, $this->languageManager->getActiveLanguages(), false ) ) {
				$this->languageManager->setCurrentLanguage( $langCode );
			}
		} catch ( \Throwable $e ) {
			// Invalid lang code — ignore, proceed with default
		}

		return $response;
	}

	/**
	 * Append X-IdiomatticWP-Language header to every REST response.
	 */
	public function addLanguageHeader(
		\WP_REST_Response $response,
		\WP_REST_Server   $server,
		\WP_REST_Request  $request
	): \WP_REST_Response {
		$response->header(
			'X-IdiomatticWP-Language',
			(string) $this->languageManager->getCurrentLanguage()
		);
		return $response;
	}

	// ── Routes ────────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		$ns = 'idiomattic-wp/v1';

		// GET /idiomattic-wp/v1/languages
		register_rest_route( $ns, '/languages', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handleGetLanguages' ],
			'permission_callback' => '__return_true',
		] );

		// GET /idiomattic-wp/v1/translations/{post_id}
		register_rest_route( $ns, '/translations/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handleGetTranslations' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id' => [ 'validate_callback' => 'is_numeric' ],
			],
		] );

		// POST /idiomattic-wp/v1/translations/{post_id}/translate  (Pro)
		register_rest_route( $ns, '/translations/(?P<id>\d+)/translate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handleTriggerTranslation' ],
			'permission_callback' => [ $this, 'permissionEditPosts' ],
			'args'                => [
				'id'   => [ 'validate_callback' => 'is_numeric' ],
				'lang' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
	}

	// ── Route handlers ────────────────────────────────────────────────────

	/** GET /idiomattic-wp/v1/languages */
	public function handleGetLanguages( \WP_REST_Request $request ): \WP_REST_Response {
		$default  = (string) $this->languageManager->getDefaultLanguage();
		$current  = (string) $this->languageManager->getCurrentLanguage();
		$languages = [];

		foreach ( $this->languageManager->getActiveLanguages() as $lang ) {
			$code        = (string) $lang;
			$languages[] = [
				'code'       => $code,
				'name'       => $this->languageManager->getLanguageName( $lang ),
				'native'     => $this->languageManager->getNativeLanguageName( $lang ),
				'is_default' => $code === $default,
				'is_current' => $code === $current,
				'locale'     => $lang->toLocale(),
				'is_rtl'     => $lang->isRtl(),
			];
		}

		return rest_ensure_response( $languages );
	}

	/** GET /idiomattic-wp/v1/translations/{post_id} */
	public function handleGetTranslations( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request->get_param( 'id' );
		$post   = get_post( $postId );

		if ( ! $post ) {
			return new \WP_REST_Response( [ 'message' => 'Post not found.' ], 404 );
		}

		$records      = $this->repository->findAllForSource( $postId );
		$translations = [];

		foreach ( $records as $row ) {
			$translations[] = [
				'id'                 => (int) $row['id'],
				'source_post_id'     => (int) $row['source_post_id'],
				'translated_post_id' => (int) $row['translated_post_id'],
				'source_lang'        => $row['source_lang'],
				'target_lang'        => $row['target_lang'],
				'status'             => $row['status'],
				'edit_url'           => get_edit_post_link( (int) $row['translated_post_id'], 'raw' ),
				'view_url'           => get_permalink( (int) $row['translated_post_id'] ),
			];
		}

		return rest_ensure_response( [
			'post_id'      => $postId,
			'translations' => $translations,
		] );
	}

	/** POST /idiomattic-wp/v1/translations/{post_id}/translate */
	public function handleTriggerTranslation( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->licenseChecker->isPro() ) {
			return new \WP_REST_Response(
				[ 'message' => 'AI translation requires a Pro license.' ],
				403
			);
		}

		$postId  = (int) $request->get_param( 'id' );
		$langStr = sanitize_key( $request->get_param( 'lang' ) );

		try {
			$targetLang = LanguageCode::from( $langStr );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'message' => 'Invalid language code.' ], 400 );
		}

		$record = $this->repository->findBySourceAndLang( $postId, $targetLang );
		if ( ! $record ) {
			return new \WP_REST_Response( [ 'message' => 'Translation not found. Create it first.' ], 404 );
		}

		// Dispatch via the action so QueueHooks can pick it up asynchronously
		do_action(
			'idiomatticwp_after_create_translation',
			(int) $record['id'],
			$postId,
			(int) $record['translated_post_id'],
			$targetLang
		);

		return rest_ensure_response( [
			'message'        => 'Translation job dispatched.',
			'translation_id' => (int) $record['id'],
			'status'         => 'in_progress',
		] );
	}

	// ── Post type extra fields ────────────────────────────────────────────

	/**
	 * Expose `translations` and `translation_status` on all translatable
	 * post types so REST clients can read them without extra requests.
	 */
	public function registerPostFields(): void {
		$postTypes = get_post_types( [ 'show_in_rest' => true ], 'names' );

		foreach ( $postTypes as $postType ) {
			register_rest_field( $postType, 'idiomatticwp_translations', [
				'get_callback'    => [ $this, 'getTranslationsField' ],
				'update_callback' => null,
				'schema'          => [
					'description' => 'Available translations for this post.',
					'type'        => 'object',
				],
			] );
		}
	}

	/**
	 * Callback for the `idiomatticwp_translations` REST field.
	 *
	 * Returns a map of lang → { translated_post_id, status, view_url } or,
	 * if the post is itself a translation, a `source` key pointing back.
	 */
	public function getTranslationsField( array $postData ): array {
		// The REST field callback receives the prepared post data array.
		// The key is 'id' (lowercase) in WP REST responses, but guard against
		// edge cases where the array might not be fully normalised yet.
		$postId = (int) ( $postData['id'] ?? $postData['ID'] ?? 0 );

		if ( $postId <= 0 ) {
			return [ 'is_translation' => false, 'languages' => [] ];
		}

		// Check if this is a translated post
		$sourceRecord = $this->repository->findByTranslatedPost( $postId );
		if ( $sourceRecord ) {
			return [
				'is_translation' => true,
				'source'         => [
					'post_id'   => (int) $sourceRecord['source_post_id'],
					'lang'      => $sourceRecord['source_lang'],
					'view_url'  => get_permalink( (int) $sourceRecord['source_post_id'] ),
				],
			];
		}

		// This is a source post — return all its translations
		$records = $this->repository->findAllForSource( $postId );
		$result  = [ 'is_translation' => false, 'languages' => [] ];

		foreach ( $records as $row ) {
			$result['languages'][ $row['target_lang'] ] = [
				'translated_post_id' => (int) $row['translated_post_id'],
				'status'             => $row['status'],
				'view_url'           => get_permalink( (int) $row['translated_post_id'] ),
			];
		}

		return $result;
	}

	// ── Permission callbacks ──────────────────────────────────────────────

	public function permissionEditPosts(): bool {
		return current_user_can( 'edit_posts' );
	}
}
