<?php
/**
 * PostListHooks — adds a "Languages" column to WordPress post list tables.
 *
 * Shows translation status (complete, outdated, draft, missing) for each
 * active language using flag icons.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Queue\BulkTranslationBatch;
use IdiomatticWP\ValueObjects\LanguageCode;

class PostListHooks implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
		private CustomElementRegistry          $registry,
		private BulkTranslationBatch           $bulkBatch,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		foreach ( $this->getTranslatablePostTypes() as $postType ) {
			add_filter( "manage_{$postType}_posts_columns",       [ $this, 'addLanguageColumn'    ] );
			add_action( "manage_{$postType}_posts_custom_column", [ $this, 'renderLanguageColumn' ], 10, 2 );
			add_filter( "bulk_actions-edit-{$postType}",          [ $this, 'addBulkActions'       ] );
			add_filter( "handle_bulk_actions-edit-{$postType}",   [ $this, 'handleBulkTranslate' ], 10, 3 );
		}

		add_action( 'admin_head',    [ $this, 'inlineColumnStyles' ] );
		add_action( 'admin_notices', [ $this, 'maybeBulkQueueNotice' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	public function addLanguageColumn( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $title ) {
			if ( $key === 'date' ) {
				$new['idiomatticwp_languages'] = __( 'Languages', 'idiomattic-wp' );
			}
			$new[ $key ] = $title;
		}

		if ( ! isset( $new['idiomatticwp_languages'] ) ) {
			$new['idiomatticwp_languages'] = __( 'Languages', 'idiomattic-wp' );
		}

		return $new;
	}

	public function renderLanguageColumn( string $column, int $postId ): void {
		if ( 'idiomatticwp_languages' !== $column ) {
			return;
		}

		$activeLanguages = $this->languageManager->getActiveLanguages();
		$defaultLang     = (string) $this->languageManager->getDefaultLanguage();
		$nonDefault      = array_filter( $activeLanguages, fn( $l ) => (string) $l !== $defaultLang );
		$total           = count( $nonDefault );
		$limit           = 5;
		$count           = 0;

		echo '<div class="idiomatticwp-flag-list" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">';

		foreach ( $nonDefault as $lang ) {
			if ( $count >= $limit && $total > $limit ) {
				printf(
					'<span class="idiomatticwp-more-badge" title="%s" style="font-size:10px;color:#666;">+%d</span>',
					esc_attr__( 'More languages', 'idiomattic-wp' ),
					$total - $limit
				);
				break;
			}

			$langCode    = (string) $lang;
			$translation = $this->repository->findBySourceAndLang( $postId, $lang );
			$status      = $translation ? $translation['status'] : 'missing';
			$translatedId = isset( $translation['translated_post_id'] ) ? (int) $translation['translated_post_id'] : 0;

			$this->renderFlag( $langCode, $status, $translatedId, $postId );
			$count++;
		}

		echo '</div>';
	}

	public function inlineColumnStyles(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}
		?>
		<style>
		.idiomatticwp-flag-wrapper { text-decoration:none; position:relative; display:inline-block; }
		.idiomatticwp-flag { width:20px; height:15px; border-radius:2px; overflow:hidden; border:1px solid rgba(0,0,0,.15); background:#eee; display:flex; align-items:center; justify-content:center; font-size:7px; font-weight:700; color:#333; }
		.idiomatticwp-flag img { width:100%; height:100%; object-fit:cover; }
		.idiomatticwp-flag.status-complete  { outline:2px solid #46b450; outline-offset:1px; }
		.idiomatticwp-flag.status-outdated  { outline:2px solid #ffb900; outline-offset:1px; }
		.idiomatticwp-flag.status-missing   { opacity:.45; }
		.idiomatticwp-status-overlay { position:absolute; bottom:-3px; right:-3px; font-size:8px; line-height:1; background:#fff; border-radius:50%; width:10px; height:10px; display:flex; align-items:center; justify-content:center; border:1px solid #ccc; }
		</style>
		<?php
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private function renderFlag( string $langCode, string $status, int $translatedId, int $sourcePostId ): void {
		$flagUrl = IDIOMATTICWP_ASSETS_URL . 'flags/' . $langCode . '.svg';
		$title   = sprintf( __( '%s: %s', 'idiomattic-wp' ), strtoupper( $langCode ), ucfirst( $status ) );

		// If translation exists → link to edit. If missing → trigger AJAX creation.
		if ( $translatedId > 0 ) {
			$href           = get_edit_post_link( $translatedId, 'raw' );
			$dataAttributes = ''; // No AJAX action — the link goes directly to the edit screen
		} else {
			$href           = '#';
			$dataAttributes = sprintf(
				' data-idiomatticwp-action="create-translation" data-post-id="%d" data-lang="%s"',
				$sourcePostId,
				esc_attr( $langCode )
			);
		}

		printf(
			'<a href="%s" class="idiomatticwp-flag-wrapper" title="%s"%s>',
			esc_url( $href ),
			esc_attr( $title ),
			$dataAttributes
		);

		printf(
			'<div class="idiomatticwp-flag status-%s"><img src="%s" alt="%s" width="20" height="15" onerror="this.parentNode.innerHTML=\'%s\'"/></div>',
			esc_attr( $status ),
			esc_url( $flagUrl ),
			esc_attr( strtoupper( $langCode ) ),
			esc_js( strtoupper( substr( $langCode, 0, 2 ) ) )
		);

		if ( $status === 'complete' ) {
			echo '<span class="idiomatticwp-status-overlay" style="color:#46b450;">✓</span>';
		} elseif ( $status === 'outdated' ) {
			echo '<span class="idiomatticwp-status-overlay" style="color:#ffb900;">!</span>';
		} elseif ( $status === 'missing' ) {
			echo '<span class="idiomatticwp-status-overlay" style="color:#999;">+</span>';
		}

		echo '</a>';
	}

	// ── Bulk translation ──────────────────────────────────────────────────

	public function addBulkActions( array $actions ): array {
		$actions['idiomatticwp_translate_all'] = __( 'Translate to all languages', 'idiomattic-wp' );
		return $actions;
	}

	/**
	 * Handle the "Translate to all languages" bulk action.
	 *
	 * @param string   $sendback Redirect URL.
	 * @param string   $doaction The action slug.
	 * @param int[]    $postIds  Selected post IDs.
	 * @return string  Modified redirect URL.
	 */
	public function handleBulkTranslate( string $sendback, string $doaction, array $postIds ): string {
		if ( $doaction !== 'idiomatticwp_translate_all' ) {
			return $sendback;
		}

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$targetLangs = array_filter(
			$this->languageManager->getActiveLanguages(),
			fn( $l ) => (string) $l !== $defaultLang
		);

		$jobs = [];

		foreach ( $postIds as $rawId ) {
			$postId = (int) $rawId;
			foreach ( $targetLangs as $lang ) {
				try {
					$langCode = LanguageCode::from( (string) $lang );
				} catch ( \Throwable ) {
					continue;
				}
				if ( $this->repository->existsForSourceAndLang( $postId, $langCode ) ) {
					continue;
				}
				$jobs[] = [
					'post_id'     => $postId,
					'source_lang' => $defaultLang,
					'target_lang' => (string) $lang,
				];
			}
		}

		$queued = $this->bulkBatch->enqueue( $jobs );

		return add_query_arg( 'iwp_bulk_queued', $queued, $sendback );
	}

	public function maybeBulkQueueNotice(): void {
		$queued = isset( $_GET['iwp_bulk_queued'] ) ? (int) $_GET['iwp_bulk_queued'] : -1;
		if ( $queued < 0 ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				$queued > 0
					/* translators: %d: number of translation jobs queued */
					? sprintf( __( 'Idiomattic WP: %d translation job(s) queued.', 'idiomattic-wp' ), $queued )
					: __( 'Idiomattic WP: All selected posts already have translations for every active language.', 'idiomattic-wp' )
			)
		);
	}

	private function getTranslatablePostTypes(): array {
		$config   = get_option( 'idiomatticwp_post_type_config', [] );
		$allTypes = get_post_types( [ 'public' => true ] );
		unset( $allTypes['attachment'] );

		$types = [];
		foreach ( array_keys( $allTypes ) as $postType ) {
			// User-saved option always wins.
			$mode = $config[ $postType ] ?? $this->registry->getPostTypeDefaultMode( $postType );
			if ( in_array( $mode, [ 'translate', 'show_as_translated' ], true ) ) {
				$types[] = $postType;
			}
		}

		return (array) apply_filters( 'idiomatticwp_translatable_post_types', $types );
	}
}
