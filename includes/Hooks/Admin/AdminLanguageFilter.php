<?php
/**
 * AdminLanguageFilter — filters post list queries in wp-admin by language.
 *
 * Logic:
 *
 *   Selected = "All languages" (null)
 *     → No filter applied. Default WordPress behaviour.
 *
 *   Selected = default language (e.g. "en")
 *     → Show ONLY source/original posts.
 *       Exclude any post_id that appears in translated_post_id column.
 *
 *   Selected = a translation language (e.g. "fr")
 *     → Show ONLY translated posts for that language.
 *       Include only post_ids that appear in translated_post_id WHERE target_lang = 'fr'.
 *
 * The filter is applied to post lists (edit.php) and search within the admin.
 * It is NOT applied to the Gutenberg post editor, media library, or any
 * non-public post types.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\ValueObjects\LanguageCode;

class AdminLanguageFilter implements HookRegistrarInterface {

	public function __construct(
		private LanguageManager $languageManager,
		private AdminLanguageBar $bar,
		private \wpdb $wpdb,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_action( 'pre_get_posts', [ $this, 'filterPostList' ] );
		add_action( 'admin_head',    [ $this, 'addCurrentLangIndicator' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Modify WP_Query on post list screens to show only posts in the
	 * currently selected admin language.
	 */
	public function filterPostList( \WP_Query $query ): void {
		// Only act on the main query inside the admin
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Only filter on list table screens (edit.php)
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}

		// Only filter public translatable post types
		$postType = $query->get( 'post_type' ) ?: 'post';
		if ( ! $this->isTranslatablePostType( (string) $postType ) ) {
			return;
		}

		$selectedLang = $this->bar->getCurrentAdminLang();

		// Null = "All languages" — no filter
		if ( $selectedLang === null ) {
			return;
		}

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$table       = $this->wpdb->prefix . 'idiomatticwp_translations';

		if ( $selectedLang === $defaultLang ) {
			// ── Default language: show only ORIGINAL posts ─────────────────
			// Exclude post IDs that are themselves translations
			$translatedIds = $this->wpdb->get_col(
				"SELECT translated_post_id FROM {$table} WHERE translated_post_id IS NOT NULL AND translated_post_id > 0"
			);

			if ( ! empty( $translatedIds ) ) {
				$translatedIds = array_map( 'intval', $translatedIds );
				$existing      = (array) $query->get( 'post__not_in' );
				$query->set( 'post__not_in', array_unique( array_merge( $existing, $translatedIds ) ) );
			}
		} else {
			// ── Secondary language: show only posts translated into that lang ─
			$translatedIds = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT translated_post_id FROM {$table} WHERE target_lang = %s AND translated_post_id IS NOT NULL AND translated_post_id > 0",
					$selectedLang
				)
			);

			if ( empty( $translatedIds ) ) {
				// No translations exist for this language — show empty list
				$query->set( 'post__in', [ 0 ] );
			} else {
				$translatedIds = array_map( 'intval', $translatedIds );
				$query->set( 'post__in', $translatedIds );
			}
		}
	}

	/**
	 * Add a visible notice under the list table title showing the active filter.
	 */
	public function addCurrentLangIndicator(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}

		$selectedLang = $this->bar->getCurrentAdminLang();
		if ( $selectedLang === null ) {
			return; // No filter active — no indicator needed
		}

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();

		try {
			$lang  = LanguageCode::from( $selectedLang );
			$name  = $this->languageManager->getLanguageName( $lang );
			$flag  = IDIOMATTICWP_ASSETS_URL . 'flags/' . $selectedLang . '.svg';
			$isDefault = ( $selectedLang === $defaultLang );

			$description = $isDefault
				? __( 'Showing original posts only', 'idiomattic-wp' )
				: sprintf( __( 'Showing translations into: %s', 'idiomattic-wp' ), $name );
		} catch ( \Throwable $e ) {
			return;
		}

		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var wrap = document.querySelector('.wrap h1.wp-heading-inline');
			if (!wrap) return;

			var bar = document.createElement('span');
			bar.className = 'idiomatticwp-lang-filter-badge';
			bar.innerHTML =
				'<img src="<?php echo esc_js( $flag ); ?>" width="16" height="12" alt="">'
				+ ' <?php echo esc_js( $name ); ?>'
				+ ' <a href="<?php echo esc_js( $this->buildSwitchUrl( 'all' ) ); ?>" title="<?php echo esc_js( __( 'Clear language filter', 'idiomattic-wp' ) ); ?>">✕</a>';

			wrap.parentNode.insertBefore(bar, wrap.nextSibling);

			// Also add inline notice under the filters row
			var filtersRow = document.querySelector('.tablenav.top');
			if (filtersRow) {
				var notice = document.createElement('div');
				notice.className = 'idiomatticwp-filter-notice';
				notice.innerHTML = '<?php echo esc_js( $description ); ?>'
					+ ' &nbsp;<a href="<?php echo esc_js( $this->buildSwitchUrl( 'all' ) ); ?>">'
					+ '<?php echo esc_js( __( 'Show all', 'idiomattic-wp' ) ); ?></a>';
				filtersRow.parentNode.insertBefore(notice, filtersRow);
			}
		});
		</script>
		<style>
		.idiomatticwp-lang-filter-badge {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			background: #0073aa;
			color: #fff;
			font-size: 12px;
			padding: 2px 8px;
			border-radius: 10px;
			margin-left: 12px;
			vertical-align: middle;
		}
		.idiomatticwp-lang-filter-badge a {
			color: rgba(255,255,255,.8);
			text-decoration: none;
			margin-left: 4px;
			font-size: 10px;
		}
		.idiomatticwp-lang-filter-badge a:hover { color: #fff; }
		.idiomatticwp-filter-notice {
			background: #f0f6fc;
			border-left: 3px solid #0073aa;
			padding: 6px 12px;
			margin: 0 0 8px;
			font-size: 13px;
			color: #1d2327;
		}
		.idiomatticwp-filter-notice a {
			margin-left: 8px;
			color: #0073aa;
		}
		</style>
		<?php
	}

	// ── Private helpers ───────────────────────────────────────────────────

	private function isTranslatablePostType( string $postType ): bool {
		$types = get_post_types( [ 'public' => true ] );
		unset( $types['attachment'] );
		$translatable = (array) apply_filters(
			'idiomatticwp_translatable_post_types',
			array_keys( $types )
		);
		return in_array( $postType, $translatable, true );
	}

	private function buildSwitchUrl( string $lang ): string {
		$nonce = wp_create_nonce( 'idiomatticwp_switch_admin_lang' );
		return add_query_arg( [
			'idiomatticwp_admin_lang' => $lang,
			'idiomatticwp_nonce'      => $nonce,
		], remove_query_arg( [ 'idiomatticwp_admin_lang', 'idiomatticwp_nonce' ] ) );
	}
}
