<?php
/**
 * AdminLanguageBar — language switcher in the WordPress admin bar.
 *
 * Adds a top-level node with the current admin language flag + name,
 * and a dropdown with all active languages. Clicking a language saves
 * the selection to user meta and redirects back to the same page.
 *
 * The selected language is stored in user meta `idiomatticwp_admin_lang`
 * and respected by AdminLanguageFilter to filter post list queries.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\ValueObjects\LanguageCode;

class AdminLanguageBar implements HookRegistrarInterface {

	private const USER_META_KEY = 'idiomatticwp_admin_lang';
	private const NONCE_ACTION  = 'idiomatticwp_switch_admin_lang';

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Handle language switch POST before output starts
		add_action( 'admin_init', [ $this, 'handleSwitch' ] );

		// Add admin bar node — fires for both admin and front-end admin bar
		add_action( 'admin_bar_menu', [ $this, 'addNode' ], 80 );

		// Inline styles for the admin bar node
		add_action( 'admin_head', [ $this, 'inlineStyles' ] );
		add_action( 'wp_head',    [ $this, 'inlineStyles' ] );
	}

	// ── Public callbacks ──────────────────────────────────────────────────

	/**
	 * Handle language switch request (GET param + nonce).
	 * Runs on admin_init — before any output.
	 */
	public function handleSwitch(): void {
		if ( empty( $_GET['idiomatticwp_admin_lang'] ) ) {
			return;
		}

		$nonce = $_GET['idiomatticwp_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'idiomattic-wp' ) );
		}

		$langCode = sanitize_key( $_GET['idiomatticwp_admin_lang'] );
		$userId   = get_current_user_id();

		if ( $langCode === 'all' ) {
			delete_user_meta( $userId, self::USER_META_KEY );
		} elseif ( $this->isValidLang( $langCode ) ) {
			update_user_meta( $userId, self::USER_META_KEY, $langCode );
		}

		// Redirect back to the same page without the switch params
		$redirect = remove_query_arg( [ 'idiomatticwp_admin_lang', 'idiomatticwp_nonce' ] );

		// On a post edit screen, redirect to the equivalent post in the new language
		$action = sanitize_key( $_GET['action'] ?? '' );
		$postId = (int) ( $_GET['post'] ?? 0 );

		if ( $postId > 0 && $langCode !== 'all' && in_array( $action, [ 'edit', 'idiomatticwp_translate' ], true ) ) {
			$targetPostId = $this->resolvePostForLang( $postId, $langCode );
			if ( $targetPostId !== null ) {
				$redirect = admin_url( 'post.php?post=' . $targetPostId . '&action=edit&idiomatticwp_direct_edit=1' );
			}
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Add the language switcher node to the admin bar.
	 */
	public function addNode( \WP_Admin_Bar $bar ): void {
		if ( ! is_admin() ) {
			return;
		}

		$active   = $this->languageManager->getActiveLanguages();
		$default  = $this->languageManager->getDefaultLanguage();

		// Need at least one non-default language to be useful
		if ( count( $active ) < 2 ) {
			return;
		}

		$currentCode = $this->getCurrentAdminLang();
		$nonce       = wp_create_nonce( self::NONCE_ACTION );
		$baseUrl     = remove_query_arg( [ 'idiomatticwp_admin_lang', 'idiomatticwp_nonce' ] );

		// Build current language display
		if ( $currentCode === null ) {
			$label    = __( 'All languages', 'idiomattic-wp' );
			$flagHtml = $this->flagHtml( null );
		} else {
			try {
				$lang     = LanguageCode::from( $currentCode );
				$label    = $this->languageManager->getLanguageName( $lang );
				$flagHtml = $this->flagHtml( $currentCode );
			} catch ( \Throwable $e ) {
				$label    = strtoupper( $currentCode );
				$flagHtml = '';
			}
		}

		// ── Parent node ───────────────────────────────────────────────────
		$bar->add_node( [
			'id'    => 'idiomatticwp-lang-switcher',
			'title' => sprintf(
				'<span class="idiomatticwp-ab-wrap">%s<span class="idiomatticwp-ab-label">%s</span><span class="idiomatticwp-ab-caret">▾</span></span>',
				$flagHtml,
				esc_html( $label )
			),
			'href'  => '#',
			'meta'  => [ 'class' => 'idiomatticwp-lang-bar' ],
		] );

		// ── "All languages" option ─────────────────────────────────────────
		$allUrl = add_query_arg( [
			'idiomatticwp_admin_lang' => 'all',
			'idiomatticwp_nonce'      => $nonce,
		], $baseUrl );

		$bar->add_node( [
			'parent' => 'idiomatticwp-lang-switcher',
			'id'     => 'idiomatticwp-lang-all',
			'title'  => sprintf(
				'<span class="idiomatticwp-ab-item%s">%s%s</span>',
				$currentCode === null ? ' idiomatticwp-ab-active' : '',
				$this->flagHtml( null ),
				esc_html__( 'All languages', 'idiomattic-wp' )
			),
			'href'   => esc_url( $allUrl ),
		] );

		// ── Separator ─────────────────────────────────────────────────────
		$bar->add_node( [
			'parent' => 'idiomatticwp-lang-switcher',
			'id'     => 'idiomatticwp-lang-sep',
			'title'  => '<hr style="margin:4px 0;border-color:#444;">',
			'href'   => false,
		] );

		// ── One item per active language (default first, then the rest) ─────
		// getActiveLanguages() may not include the default language if it was
		// configured separately, so we prepend it explicitly and deduplicate.
		$defaultCode = (string) $default;
		$hasDefault  = false;
		foreach ( $active as $l ) {
			if ( (string) $l === $defaultCode ) {
				$hasDefault = true;
				break;
			}
		}
		$allLangs = $hasDefault ? $active : array_merge( [ $default ], $active );

		foreach ( $allLangs as $lang ) {
			$code    = (string) $lang;
			$name    = $this->languageManager->getLanguageName( $lang );
			$isDefault = $lang->equals( $default );
			$isActive  = $currentCode === $code;

			$langUrl = add_query_arg( [
				'idiomatticwp_admin_lang' => $code,
				'idiomatticwp_nonce'      => $nonce,
			], $baseUrl );

			$bar->add_node( [
				'parent' => 'idiomatticwp-lang-switcher',
				'id'     => 'idiomatticwp-lang-' . $code,
				'title'  => sprintf(
					'<span class="idiomatticwp-ab-item%s">%s%s%s</span>',
					$isActive ? ' idiomatticwp-ab-active' : '',
					$this->flagHtml( $code ),
					esc_html( $name ),
					$isDefault
						? '<span class="idiomatticwp-ab-badge">' . esc_html__( 'default', 'idiomattic-wp' ) . '</span>'
						: ''
				),
				'href'   => esc_url( $langUrl ),
			] );
		}
	}

	/**
	 * Output CSS + JS for the admin bar node.
	 *
	 * WordPress strips <img> tags from admin bar node titles via wp_kses,
	 * so we render a placeholder <span data-flag="xx"> in the title HTML,
	 * then use JavaScript (after DOMContentLoaded) to replace each span
	 * with a real <img> — identical markup to the Languages column flags.
	 */
	public function inlineStyles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		// Build JS map: { "fr": "https://.../flags/fr.svg", ... }
		$flagMap     = [];
		$allLangs    = $this->languageManager->getActiveLanguages();
		$allLangs[]  = $this->languageManager->getDefaultLanguage(); // include default
		foreach ( $allLangs as $lang ) {
			$code            = (string) $lang;
			$flagMap[ $code ] = IDIOMATTICWP_ASSETS_URL . 'flags/' . $code . '.svg';
		}
		$flagMapJson = wp_json_encode( $flagMap );
		?>
		<style id="idiomatticwp-ab-styles">
		#wpadminbar #wp-admin-bar-idiomatticwp-lang-switcher > .ab-item {
			padding: 0 12px;
		}
		.idiomatticwp-ab-wrap {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			height: 32px;
		}
		.idiomatticwp-ab-label {
			font-weight: 500;
			max-width: 120px;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.idiomatticwp-ab-caret {
			font-size: 9px;
			opacity: .7;
			margin-left: 2px;
		}
		#wpadminbar #wp-admin-bar-idiomatticwp-lang-switcher .ab-sub-wrapper {
			min-width: 220px;
		}
		/* Flag wrapper — 20x15px, same as the Languages column flags.
		   Use #wpadminbar prefix for specificity to override WP admin bar defaults. */
		#wpadminbar .idiomatticwp-ab-flag {
			display: inline-flex !important;
			align-items: center !important;
			justify-content: center !important;
			width: 20px !important;
			height: 15px !important;
			min-width: 20px !important;
			min-height: 15px !important;
			max-width: 20px !important;
			max-height: 15px !important;
			border-radius: 2px !important;
			overflow: hidden !important;
			border: 1px solid rgba(255,255,255,.25) !important;
			background: #555 !important;
			flex-shrink: 0 !important;
			font-size: 6px !important;
			font-weight: 700 !important;
			color: #fff !important;
			vertical-align: middle !important;
			box-sizing: border-box !important;
			padding: 0 !important;
			margin: 0 !important;
			line-height: 1 !important;
		}
		/* The <img> injected by JS fills the wrapper exactly */
		#wpadminbar .idiomatticwp-ab-flag img {
			width: 20px !important;
			height: 15px !important;
			min-width: 20px !important;
			min-height: 15px !important;
			max-width: 20px !important;
			max-height: 15px !important;
			object-fit: cover !important;
			display: block !important;
			border: none !important;
			padding: 0 !important;
			margin: 0 !important;
			border-radius: 0 !important;
			box-shadow: none !important;
		}
		.idiomatticwp-ab-flag-globe {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 18px;
			height: 18px;
			font-size: 15px;
			flex-shrink: 0;
			vertical-align: middle;
		}
		/* Dropdown items */
		.idiomatticwp-ab-item {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 2px 0;
			width: 100%;
			min-height: 26px;
		}
		.idiomatticwp-ab-item.idiomatticwp-ab-active {
			font-weight: 600;
		}
		.idiomatticwp-ab-item.idiomatticwp-ab-active::after {
			content: '✓';
			margin-left: auto;
			padding-left: 8px;
			color: #00b9eb;
			font-size: 12px;
		}
		.idiomatticwp-ab-badge {
			font-size: 9px;
			background: #3c434a;
			color: #bbc8d4;
			padding: 1px 5px;
			border-radius: 3px;
			margin-left: 4px;
			text-transform: uppercase;
			letter-spacing: .4px;
		}
		</style>
		<script>
		(function () {
			var flagMap = <?php echo $flagMapJson; ?>;

			function injectFlags() {
				// Match spans by class idiomatticwp-flag-{code} since wp_kses strips data-* attributes.
				document.querySelectorAll('.idiomatticwp-ab-flag').forEach(function (span) {
					// Extract code from class list: idiomatticwp-flag-{code}
					var code = null;
					span.classList.forEach(function (cls) {
						if (cls.indexOf('idiomatticwp-flag-') === 0) {
							code = cls.replace('idiomatticwp-flag-', '');
						}
					});
					if (!code) return;

					var src = flagMap[code];
					if (!src) return;

					var img = document.createElement('img');
					img.src    = src;
					img.alt    = code.toUpperCase();
					img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border:none;padding:0;margin:0;';
					img.onerror = function () {
						span.textContent = code.substring(0, 2).toUpperCase();
					};

					span.textContent = '';
					span.appendChild(img);
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', injectFlags);
			} else {
				injectFlags();
			}
		})();
		</script>
		<?php
	}

	// ── Public helpers (used by AdminLanguageFilter) ──────────────────────

	/**
	 * Get the currently selected admin language code for the current user,
	 * or null if "All languages" is selected.
	 */
	public function getCurrentAdminLang(): ?string {
		$stored = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
		if ( ! $stored || ! $this->isValidLang( (string) $stored ) ) {
			return null; // "All languages"
		}
		return (string) $stored;
	}

	// ── Private helpers ───────────────────────────────────────────────────

	private function flagHtml( ?string $code ): string {
		if ( $code === null ) {
			return '<span class="idiomatticwp-ab-flag-globe">🌐</span>';
		}

		// wp_kses strips both <img> and data-* attributes from admin bar titles.
		// Workaround: encode the language code into a CSS class (idiomatticwp-flag-XX)
		// and let the JS in inlineStyles() match on that class to inject the <img>.
		$initials  = strtoupper( substr( $code, 0, 2 ) );
		$flagClass = 'idiomatticwp-flag-' . sanitize_html_class( $code );
		return sprintf(
			'<span class="idiomatticwp-ab-flag %s">%s</span>',
			esc_attr( $flagClass ),
			esc_html( $initials ) // Fallback text visible until JS runs
		);
	}

	/**
	 * Given a post ID (source or translated) and a target language code,
	 * return the post ID that corresponds to that language.
	 *
	 * Returns null if no matching post exists (no redirect needed or no translation yet).
	 */
	private function resolvePostForLang( int $postId, string $langCode ): ?int {
		$default = (string) $this->languageManager->getDefaultLanguage();

		// Determine source post ID (unwrap translated posts to their source)
		$record   = $this->repository->findByTranslatedPost( $postId );
		$sourceId = $record !== null ? (int) $record['source_post_id'] : $postId;

		// Target is the default language → go to the source post
		if ( $langCode === $default ) {
			// Already on the source post — no redirect needed
			return $sourceId !== $postId ? $sourceId : null;
		}

		// Target is a non-default language → find its translation
		try {
			$lang = LanguageCode::from( $langCode );
			$row  = $this->repository->findBySourceAndLang( $sourceId, $lang );
			if ( $row && ! empty( $row['translated_post_id'] ) ) {
				$targetId = (int) $row['translated_post_id'];
				// Already on that post — no redirect needed
				return $targetId !== $postId ? $targetId : null;
			}
		} catch ( \Throwable $e ) {
			// Invalid lang — ignore
		}

		return null;
	}

	private function isValidLang( string $code ): bool {
		try {
			$lang = LanguageCode::from( $code );
			return $this->languageManager->isActive( $lang )
				|| $lang->equals( $this->languageManager->getDefaultLanguage() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
