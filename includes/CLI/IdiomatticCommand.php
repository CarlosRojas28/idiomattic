<?php
/**
 * IdiomatticCommand — WP-CLI command group for Idiomattic WP.
 *
 * Usage:
 *   wp idiomattic status
 *   wp idiomattic languages list
 *   wp idiomattic languages set-default <code>
 *   wp idiomattic translations status [--post_id=<id>] [--status=<status>] [--format=<format>]
 *   wp idiomattic translations sync <post_id> [--lang=<lang>] [--dry-run]
 *   wp idiomattic translations mark-outdated <post_id>
 *   wp idiomattic flush-rewrite
 *
 * @package IdiomatticWP\CLI
 */

declare( strict_types=1 );

namespace IdiomatticWP\CLI;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Translation\CreateTranslation;
use IdiomatticWP\Translation\MarkAsOutdated;
use IdiomatticWP\ValueObjects\LanguageCode;
use WP_CLI;

/**
 * Manage Idiomattic WP multilingual settings and translations.
 */
class IdiomatticCommand {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
		private CreateTranslation              $createTranslation,
	) {}

	// ── status ────────────────────────────────────────────────────────────

	/**
	 * Show a summary of the plugin's current configuration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic status
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc ): void {
		$default  = (string) $this->languageManager->getDefaultLanguage();
		$active   = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$urlMode  = get_option( 'idiomatticwp_url_mode', 'parameter' );
		$total    = $this->repository->countAll();
		$complete = $this->repository->countByStatus( 'complete' );
		$outdated = $this->repository->countByStatus( 'outdated' );
		$draft    = $this->repository->countByStatus( 'draft' );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%BIdiomattic WP%n — Status' ) );
		WP_CLI::line( str_repeat( '─', 40 ) );
		WP_CLI::line( sprintf( '  Default language : %s', $default ) );
		WP_CLI::line( sprintf( '  Active languages : %s', implode( ', ', $active ) ) );
		WP_CLI::line( sprintf( '  URL mode         : %s', $urlMode ) );
		WP_CLI::line( '' );
		WP_CLI::line( '  Translations' );
		WP_CLI::line( sprintf( '    Total    : %d', $total ) );
		WP_CLI::line( sprintf( '    Complete : %d', $complete ) );
		WP_CLI::line( sprintf( '    Outdated : %d', $outdated ) );
		WP_CLI::line( sprintf( '    Draft    : %d', $draft ) );
		WP_CLI::line( '' );
	}

	// ── languages ─────────────────────────────────────────────────────────

	/**
	 * List all active languages.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic languages list
	 *     wp idiomattic languages list --format=json
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function languages__list( array $args, array $assoc ): void {
		$format  = $assoc['format'] ?? 'table';
		$default = (string) $this->languageManager->getDefaultLanguage();
		$rows    = [];

		foreach ( $this->languageManager->getActiveLanguages() as $lang ) {
			$code   = (string) $lang;
			$rows[] = [
				'code'       => $code,
				'name'       => $this->languageManager->getLanguageName( $lang ),
				'native'     => $this->languageManager->getNativeLanguageName( $lang ),
				'locale'     => $lang->toLocale(),
				'rtl'        => $lang->isRtl() ? 'yes' : 'no',
				'is_default' => $code === $default ? 'yes' : '',
			];
		}

		WP_CLI\Utils\format_items( $format, $rows, [ 'code', 'name', 'native', 'locale', 'rtl', 'is_default' ] );
	}

	/**
	 * Set the default language.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code to set as default (e.g. en, es, fr).
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic languages set-default es
	 *
	 * @subcommand set-default
	 * @when after_wp_load
	 */
	public function languages__set_default( array $args, array $assoc ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp idiomattic languages set-default <code>' );
		}

		$code = sanitize_key( $args[0] );

		try {
			$lang = LanguageCode::from( $code );
		} catch ( \Throwable $e ) {
			WP_CLI::error( sprintf( 'Invalid language code: %s', $code ) );
		}

		if ( ! $this->languageManager->isActive( $lang ) ) {
			WP_CLI::warning( sprintf( '%s is not in the active language list. Adding it.', $code ) );
			$active   = $this->languageManager->getActiveLanguages();
			$active[] = $lang;
			$this->languageManager->setActiveLanguages( $active );
		}

		$this->languageManager->setDefaultLanguage( $lang );
		WP_CLI::success( sprintf( 'Default language set to: %s', $code ) );
	}

	/**
	 * Add a language to the active list.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code to add (e.g. es, fr, de).
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic languages add de
	 *
	 * @subcommand add
	 * @when after_wp_load
	 */
	public function languages__add( array $args, array $assoc ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp idiomattic languages add <code>' );
		}

		$code = sanitize_key( $args[0] );

		try {
			$lang = LanguageCode::from( $code );
		} catch ( \Throwable $e ) {
			WP_CLI::error( sprintf( 'Invalid language code: %s', $code ) );
		}

		if ( $this->languageManager->isActive( $lang ) ) {
			WP_CLI::warning( sprintf( 'Language %s is already active.', $code ) );
			return;
		}

		$active   = $this->languageManager->getActiveLanguages();
		$active[] = $lang;
		$this->languageManager->setActiveLanguages( $active );
		WP_CLI::success( sprintf( 'Language added: %s', $code ) );
	}

	/**
	 * Remove a language from the active list.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code to remove.
	 *
	 * [--force]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic languages remove de
	 *
	 * @subcommand remove
	 * @when after_wp_load
	 */
	public function languages__remove( array $args, array $assoc ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp idiomattic languages remove <code>' );
		}

		$code = sanitize_key( $args[0] );

		if ( $code === (string) $this->languageManager->getDefaultLanguage() ) {
			WP_CLI::error( 'Cannot remove the default language.' );
		}

		if ( empty( $assoc['force'] ) ) {
			WP_CLI::confirm( sprintf( 'Are you sure you want to remove language %s?', $code ) );
		}

		$active  = $this->languageManager->getActiveLanguages();
		$filtered = array_filter( $active, fn( $l ) => (string) $l !== $code );
		$this->languageManager->setActiveLanguages( array_values( $filtered ) );
		WP_CLI::success( sprintf( 'Language removed: %s', $code ) );
	}

	// ── translations ──────────────────────────────────────────────────────

	/**
	 * Show translation status for posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post_id=<id>]
	 * : Filter by source post ID.
	 *
	 * [--status=<status>]
	 * : Filter by translation status (complete, outdated, draft, in_progress).
	 *
	 * [--lang=<lang>]
	 * : Filter by target language code.
	 *
	 * [--limit=<n>]
	 * : Maximum number of rows to display (default: 25).
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic translations status
	 *     wp idiomattic translations status --status=outdated
	 *     wp idiomattic translations status --post_id=42 --format=json
	 *
	 * @subcommand status
	 * @when after_wp_load
	 */
	public function translations__status( array $args, array $assoc ): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'idiomatticwp_translations';
		$where   = [];
		$prepare = [];

		if ( ! empty( $assoc['post_id'] ) ) {
			$where[]   = 'source_post_id = %d';
			$prepare[] = (int) $assoc['post_id'];
		}

		if ( ! empty( $assoc['status'] ) ) {
			$where[]   = 'status = %s';
			$prepare[] = sanitize_key( $assoc['status'] );
		}

		if ( ! empty( $assoc['lang'] ) ) {
			$where[]   = 'target_lang = %s';
			$prepare[] = sanitize_key( $assoc['lang'] );
		}

		$limit = min( (int) ( $assoc['limit'] ?? 25 ), 500 );
		$sql   = "SELECT id, source_post_id, translated_post_id, source_lang, target_lang, status, translated_at FROM $table";

		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= " ORDER BY id DESC LIMIT $limit";

		$rows = $prepare
			? $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			WP_CLI::line( 'No translations found.' );
			return;
		}

		// Add post title for readability
		foreach ( $rows as &$row ) {
			$row['source_title'] = get_the_title( (int) $row['source_post_id'] ) ?: '(no title)';
		}
		unset( $row );

		WP_CLI\Utils\format_items(
			$assoc['format'] ?? 'table',
			$rows,
			[ 'id', 'source_post_id', 'source_title', 'target_lang', 'status', 'translated_at' ]
		);
	}

	/**
	 * Create a translation for a post (or all missing translations).
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Source post ID.
	 *
	 * [--lang=<lang>]
	 * : Target language. Omit to create translations for ALL active languages.
	 *
	 * [--dry-run]
	 * : Show what would be created without actually creating anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic translations sync 42 --lang=es
	 *     wp idiomattic translations sync 42
	 *     wp idiomattic translations sync 42 --dry-run
	 *
	 * @subcommand sync
	 * @when after_wp_load
	 */
	public function translations__sync( array $args, array $assoc ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp idiomattic translations sync <post_id> [--lang=<lang>] [--dry-run]' );
		}

		$postId  = (int) $args[0];
		$dryRun  = ! empty( $assoc['dry-run'] );
		$default = (string) $this->languageManager->getDefaultLanguage();

		$post = get_post( $postId );
		if ( ! $post ) {
			WP_CLI::error( sprintf( 'Post %d not found.', $postId ) );
		}

		// Determine target languages
		if ( ! empty( $assoc['lang'] ) ) {
			try {
				$targetLangs = [ LanguageCode::from( sanitize_key( $assoc['lang'] ) ) ];
			} catch ( \Throwable $e ) {
				WP_CLI::error( sprintf( 'Invalid language code: %s', $assoc['lang'] ) );
			}
		} else {
			// All active non-default languages
			$targetLangs = array_filter(
				$this->languageManager->getActiveLanguages(),
				fn( $l ) => (string) $l !== $default
			);
		}

		$created = 0;
		$skipped = 0;

		foreach ( $targetLangs as $lang ) {
			$langStr = (string) $lang;
			$exists  = $this->repository->existsForSourceAndLang( $postId, $lang );

			if ( $exists ) {
				WP_CLI::line( sprintf( '  %-6s SKIP  (translation already exists)', $langStr ) );
				$skipped++;
				continue;
			}

			if ( $dryRun ) {
				WP_CLI::line( sprintf( '  %-6s WOULD CREATE', $langStr ) );
				$created++;
				continue;
			}

			try {
				( $this->createTranslation )( $postId, $lang );
				WP_CLI::line( WP_CLI::colorize( sprintf( '  %-6s %sCREATED%n', $langStr, '%G' ) ) );
				$created++;
			} catch ( \Throwable $e ) {
				WP_CLI::warning( sprintf( '  %-6s FAILED — %s', $langStr, $e->getMessage() ) );
			}
		}

		WP_CLI::line( '' );
		WP_CLI::success( sprintf(
			'%s: %d created, %d skipped.',
			$dryRun ? '[dry-run]' : 'Done',
			$created,
			$skipped
		) );
	}

	/**
	 * Mark all complete translations of a post as outdated.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Source post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic translations mark-outdated 42
	 *
	 * @subcommand mark-outdated
	 * @when after_wp_load
	 */
	public function translations__mark_outdated( array $args, array $assoc ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp idiomattic translations mark-outdated <post_id>' );
		}

		$postId = (int) $args[0];
		$this->repository->markOutdated( $postId );
		WP_CLI::success( sprintf( 'Marked all complete translations of post %d as outdated.', $postId ) );
	}

	// ── flush-rewrite ─────────────────────────────────────────────────────

	/**
	 * Flush WordPress rewrite rules (useful after changing URL mode).
	 *
	 * ## EXAMPLES
	 *
	 *     wp idiomattic flush-rewrite
	 *
	 * @subcommand flush-rewrite
	 * @when after_wp_load
	 */
	public function flush_rewrite( array $args, array $assoc ): void {
		flush_rewrite_rules( false );
		WP_CLI::success( 'Rewrite rules flushed.' );
	}
}
