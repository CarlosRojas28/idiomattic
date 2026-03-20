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
use IdiomatticWP\Core\LanguageManager;

class PostListHooks implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager $languageManager,
		private TranslationRepositoryInterface $repository
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		foreach ( $this->getTranslatablePostTypes() as $postType ) {
			add_filter( "manage_{$postType}_posts_columns",       [ $this, 'addLanguageColumn'    ] );
			add_action( "manage_{$postType}_posts_custom_column", [ $this, 'renderLanguageColumn' ], 10, 2 );
		}

		add_action( 'admin_head', [ $this, 'inlineColumnStyles' ] );
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

	private function getTranslatablePostTypes(): array {
		$config = get_option( 'idiomatticwp_post_type_config', [] );

		if ( empty( $config ) ) {
			// Default: all public post types are translatable
			$all = get_post_types( [ 'public' => true ] );
			unset( $all['attachment'] );
			$types = array_keys( $all );
		} else {
			// Only include post types configured as 'translate' or 'show_as_translated'
			$types = array_keys( array_filter(
				$config,
				fn( $mode ) => in_array( $mode, [ 'translate', 'show_as_translated' ], true )
			) );
		}

		return (array) apply_filters( 'idiomatticwp_translatable_post_types', $types );
	}
}
