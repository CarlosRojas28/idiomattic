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
use IdiomatticWP\Repositories\StringRepository;
use IdiomatticWP\ValueObjects\LanguageCode;

class RestApiIntegration implements IntegrationInterface {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
		private LicenseChecker                 $licenseChecker,
		private StringRepository               $stringRepository,
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

		// 5. Register public idiomatticwp/v1 namespace routes
		add_action( 'rest_api_init', [ $this, 'registerPublicRoutes' ] );

		// 6. Filter posts by language when ?lang= is passed to core endpoints
		add_filter( 'rest_post_query', [ $this, 'filterPostsByLang' ], 10, 2 );
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

		// GET /idiomattic-wp/v1/strings?lang=X[&domain=Y]
		register_rest_route( $ns, '/strings', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handleGetStrings' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'lang'   => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'domain' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
	}

	/**
	 * Register routes under the public `idiomatticwp/v1` namespace.
	 *
	 * Kept separate from the legacy `idiomattic-wp/v1` namespace so headless
	 * consumers have a stable, hyphen-free base URL.
	 */
	public function registerPublicRoutes(): void {
		$ns = 'idiomatticwp/v1';

		// GET /idiomatticwp/v1/languages
		register_rest_route( $ns, '/languages', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handlePublicGetLanguages' ],
			'permission_callback' => '__return_true',
		] );

		// GET /idiomatticwp/v1/translations/{post_id}
		register_rest_route( $ns, '/translations/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handlePublicGetTranslations' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id' => [ 'validate_callback' => 'is_numeric' ],
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

	/** GET /idiomattic-wp/v1/strings */
	public function handleGetStrings( \WP_REST_Request $request ): \WP_REST_Response {
		$lang   = sanitize_key( $request->get_param( 'lang' ) );
		$domain = sanitize_key( $request->get_param( 'domain' ) ?: '' );

		$rows = $this->stringRepository->getStrings( $lang, $domain, '', 1000, 0 );

		// Return a flat source_string => translated_string map for easy consumption.
		$strings = [];
		foreach ( $rows as $row ) {
			$translated = (string) ( $row->translated_string ?? '' );
			if ( $translated !== '' ) {
				$strings[ (string) $row->source_string ] = $translated;
			}
		}

		return rest_ensure_response( [
			'lang'    => $lang,
			'domain'  => $domain ?: 'all',
			'strings' => $strings,
			'count'   => count( $strings ),
		] );
	}

	// ── Public namespace handlers ─────────────────────────────────────────

	/**
	 * GET /idiomatticwp/v1/languages
	 *
	 * Returns all active languages with the spec-defined shape:
	 * { default, languages: [{ code, name, native_name, rtl, flag_url }] }
	 */
	public function handlePublicGetLanguages( \WP_REST_Request $request ): \WP_REST_Response {
		$default   = (string) $this->languageManager->getDefaultLanguage();
		$languages = [];

		foreach ( $this->languageManager->getActiveLanguages() as $lang ) {
			$code = (string) $lang;
			$data = $this->languageManager->getLanguageData( $lang );

			$flagCode = $data['flag'] ?? $lang->getBase();
			$flagUrl  = defined( 'IDIOMATTICWP_URL' )
				? IDIOMATTICWP_URL . 'assets/flags/' . $flagCode . '.svg'
				: '';

			$languages[] = [
				'code'        => $code,
				'name'        => $data['name'] ?? $code,
				'native_name' => $data['native_name'] ?? $code,
				'rtl'         => (bool) ( $data['rtl'] ?? $lang->isRtl() ),
				'flag_url'    => $flagUrl,
			];
		}

		return rest_ensure_response( [
			'default'   => $default,
			'languages' => $languages,
		] );
	}

	/**
	 * GET /idiomatticwp/v1/translations/{post_id}
	 *
	 * Returns translation records for a source post with the spec-defined shape:
	 * { source_post_id, source_lang, translations: [{ lang, post_id, status, permalink, title }] }
	 */
	public function handlePublicGetTranslations( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request->get_param( 'id' );
		$post   = get_post( $postId );

		if ( ! $post ) {
			return new \WP_REST_Response( [ 'message' => 'Post not found.' ], 404 );
		}

		// If this post is itself a translation, resolve back to its source.
		$sourceRecord = $this->repository->findByTranslatedPost( $postId );
		if ( $sourceRecord ) {
			$postId = (int) $sourceRecord['source_post_id'];
		}

		$sourceLang   = $sourceRecord ? $sourceRecord['source_lang']
			: (string) $this->languageManager->getDefaultLanguage();
		$records      = $this->repository->findAllForSource( $postId );
		$translations = [];

		foreach ( $records as $row ) {
			$translatedId  = (int) $row['translated_post_id'];
			$translatedPost = get_post( $translatedId );
			$translations[] = [
				'lang'      => $row['target_lang'],
				'post_id'   => $translatedId,
				'status'    => $row['status'],
				'permalink' => get_permalink( $translatedId ) ?: '',
				'title'     => $translatedPost ? $translatedPost->post_title : '',
			];
		}

		return rest_ensure_response( [
			'source_post_id' => $postId,
			'source_lang'    => $sourceLang,
			'translations'   => $translations,
		] );
	}

	// ── Language filtering for core endpoints ─────────────────────────────

	/**
	 * Filter posts to only those belonging to the requested language.
	 *
	 * When a REST request to /wp/v2/posts (or any post type) includes ?lang=XX,
	 * posts that are translated copies of another post (i.e. they are a
	 * translated_post_id in the translations table) and whose language does NOT
	 * match the requested language are excluded via a NOT IN clause.
	 *
	 * Posts that are source posts in the default language pass through, as do
	 * posts that are translations for the requested language.
	 *
	 * @param array            $args    WP_Query args being built.
	 * @param \WP_REST_Request $request The current REST request.
	 * @return array Modified query args.
	 */
	public function filterPostsByLang( array $args, \WP_REST_Request $request ): array {
		$lang = sanitize_key( $request->get_param( 'lang' ) ?: '' );
		if ( ! $lang ) {
			return $args;
		}

		try {
			$langCode = LanguageCode::from( $lang );
		} catch ( \Throwable $e ) {
			return $args;
		}

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$requestedLang = (string) $langCode;

		if ( $requestedLang === $defaultLang ) {
			// For the default language: exclude all translated posts (any lang),
			// so only source posts remain.
			$this->excludeAllTranslatedPosts( $args );
		} else {
			// For a non-default language: include only posts that are a
			// translation record with the matching target language.
			$this->includeOnlyTranslatedPostsForLang( $args, $requestedLang );
		}

		return $args;
	}

	/**
	 * Exclude all posts that exist as translated_post_id in any translation record.
	 */
	private function excludeAllTranslatedPosts( array &$args ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'idiomatticwp_translations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT translated_post_id FROM {$table}" );

		if ( ! empty( $ids ) ) {
			$ids = array_map( 'intval', $ids );
			$existing = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : [];
			$args['post__not_in'] = array_unique( array_merge( $existing, $ids ) );
		}
	}

	/**
	 * Restrict results to only translated posts for a specific target language.
	 */
	private function includeOnlyTranslatedPostsForLang( array &$args, string $lang ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'idiomatticwp_translations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT translated_post_id FROM {$table} WHERE target_lang = %s",
				$lang
			)
		);

		if ( ! empty( $ids ) ) {
			$ids = array_map( 'intval', $ids );
			$existing = isset( $args['post__in'] ) ? (array) $args['post__in'] : [];
			// Intersect if post__in already set, otherwise replace.
			$args['post__in'] = empty( $existing )
				? $ids
				: array_values( array_intersect( $existing, $ids ) );
		} else {
			// No translations for this language — return nothing.
			$args['post__in'] = [ 0 ];
		}
	}

	// ── Post type extra fields ────────────────────────────────────────────

	/**
	 * Expose `translations` and `translation_status` on all translatable
	 * post types so REST clients can read them without extra requests.
	 */
	public function registerPostFields(): void {
		$postTypes = get_post_types( [ 'show_in_rest' => true ], 'names' );

		foreach ( $postTypes as $postType ) {
			// Language of this post (source lang for source posts, target lang for translations).
			register_rest_field( $postType, 'idiomatticwp_lang', [
				'get_callback'    => [ $this, 'getLangField' ],
				'update_callback' => null,
				'schema'          => null,
			] );

			// Translation relationships for this post.
			register_rest_field( $postType, 'idiomatticwp_translations', [
				'get_callback'    => [ $this, 'getTranslationsField' ],
				'update_callback' => null,
				'schema'          => [
					'description' => 'Available translations for this post.',
					'type'        => 'object',
				],
			] );

			// Swap translated content when ?lang= is provided.
			add_filter( 'rest_prepare_' . $postType, [ $this, 'swapTranslatedContent' ], 10, 3 );
		}
	}

	/**
	 * When a REST response includes a ?lang= param and a published translation
	 * exists for that language, swap the core content fields (title, content,
	 * excerpt) with the translated post's values.
	 *
	 * This enables headless setups to call /wp/v2/posts/123?lang=es and receive
	 * the Spanish content without needing to know the translated post's ID.
	 */
	public function swapTranslatedContent(
		\WP_REST_Response $response,
		\WP_Post          $post,
		\WP_REST_Request  $request
	): \WP_REST_Response {
		$lang = sanitize_key( $request->get_param( 'lang' ) ?: '' );
		if ( ! $lang ) {
			return $response;
		}

		try {
			$langCode = LanguageCode::from( $lang );
		} catch ( \Throwable $e ) {
			return $response;
		}

		// Don't swap if this post is itself a translation.
		if ( $this->repository->findByTranslatedPost( $post->ID ) ) {
			return $response;
		}

		$record = $this->repository->findBySourceAndLang( $post->ID, $langCode );
		if ( ! $record || ( $record['status'] ?? '' ) === 'draft' ) {
			return $response;
		}

		$translatedPost = get_post( (int) $record['translated_post_id'] );
		if ( ! $translatedPost ) {
			return $response;
		}

		$data = $response->get_data();

		// Swap core rendered fields.
		if ( isset( $data['title']['rendered'] ) ) {
			$data['title']['rendered'] = apply_filters( 'the_title', $translatedPost->post_title, $translatedPost->ID );
		}
		if ( isset( $data['content']['rendered'] ) ) {
			$data['content']['rendered'] = apply_filters( 'the_content', $translatedPost->post_content );
		}
		if ( isset( $data['excerpt']['rendered'] ) ) {
			$data['excerpt']['rendered'] = apply_filters( 'the_excerpt', $translatedPost->post_excerpt );
		}

		// Annotate response with the translated post ID for reference.
		$data['idiomatticwp_translated_post_id'] = $translatedPost->ID;
		$data['idiomatticwp_source_post_id']      = $post->ID;

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Callback for the `idiomatticwp_lang` REST field.
	 *
	 * Returns the language code that owns this post:
	 *  - Source posts  → the site default language.
	 *  - Translated posts → the target language from their translation record.
	 */
	public function getLangField( array $postData ): string {
		$postId = (int) ( $postData['id'] ?? $postData['ID'] ?? 0 );
		if ( $postId <= 0 ) {
			return (string) $this->languageManager->getDefaultLanguage();
		}

		$record = $this->repository->findByTranslatedPost( $postId );
		if ( $record ) {
			return (string) ( $record['target_lang'] ?? '' );
		}

		// Check if it is a source post with any translation record.
		$sourceRecords = $this->repository->findAllForSource( $postId );
		if ( ! empty( $sourceRecords ) ) {
			return (string) ( $sourceRecords[0]['source_lang'] ?? $this->languageManager->getDefaultLanguage() );
		}

		return (string) $this->languageManager->getDefaultLanguage();
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
