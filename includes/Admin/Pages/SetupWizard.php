<?php
/**
 * SetupWizard — guided onboarding after plugin activation.
 *
 * Shown automatically on the first admin page load after activation
 * (when idiomatticwp_needs_setup = 1 and no languages are configured).
 * The wizard walks the admin through:
 *   Step 1 — Select default language
 *   Step 2 — Select active languages
 *   Step 3 — Choose URL structure
 *   Step 4 — Done + quick links
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\License\LicenseChecker;

class SetupWizard {

	public function __construct(
		private LanguageManager $languageManager,
		private LicenseChecker  $licenseChecker,
	) {}

	// ── Entry point ───────────────────────────────────────────────────────

	/**
	 * Register all hooks needed by the wizard.
	 */
	public function register(): void {
		add_action( 'admin_menu',    [ $this, 'registerPage'    ] );
		add_action( 'admin_init',    [ $this, 'maybeRedirect'   ] );
		add_action( 'admin_init',    [ $this, 'handleFormPost'  ] );
	}

	/**
	 * Register the hidden wizard page (no menu entry).
	 */
	public function registerPage(): void {
		add_dashboard_page(
			__( 'Idiomattic Setup', 'idiomattic-wp' ),
			__( 'Idiomattic Setup', 'idiomattic-wp' ),
			'manage_options',
			'idiomatticwp-setup',
			[ $this, 'render' ]
		);
	}

	/**
	 * Redirect to the wizard on fresh activation.
	 * Only redirects once — the option is deleted after redirect.
	 */
	public function maybeRedirect(): void {
		// Never redirect when languages are already configured.
		$defaultLang = get_option( 'idiomatticwp_default_lang', '' );
		$activeLangs = get_option( 'idiomatticwp_active_langs', [] );

		if ( $defaultLang !== '' && ! empty( $activeLangs ) ) {
			delete_option( 'idiomatticwp_needs_setup' );
			return;
		}

		if ( ! get_option( 'idiomatticwp_needs_setup' ) ) {
			return;
		}

		// Don't redirect during bulk activation or AJAX/CLI
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		// Only redirect if not already on the wizard
		$currentPage = $_GET['page'] ?? '';
		if ( $currentPage === 'idiomatticwp-setup' ) {
			return;
		}

		wp_safe_redirect( admin_url( 'index.php?page=idiomatticwp-setup' ) );
		exit;
	}

	/**
	 * Process wizard form submissions.
	 */
	public function handleFormPost(): void {
		if ( empty( $_POST['idiomatticwp_wizard_step'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$step = (int) $_POST['idiomatticwp_wizard_step'];
		check_admin_referer( 'idiomatticwp_wizard_step_' . $step );

		switch ( $step ) {
			case 1:
				$defaultLang = preg_replace( '/[^a-zA-Z0-9-]/', '', (string) ( $_POST['default_lang'] ?? 'en' ) );
				update_option( 'idiomatticwp_default_lang', $defaultLang );
				// Prime active langs with at least the default
				$active = get_option( 'idiomatticwp_active_langs', [] );
				if ( ! in_array( $defaultLang, $active, true ) ) {
					$active[] = $defaultLang;
					update_option( 'idiomatticwp_active_langs', $active );
				}
				wp_safe_redirect( admin_url( 'index.php?page=idiomatticwp-setup&step=2' ) );
				exit;

			case 2:
				$langs = array_values( array_filter( array_map( fn( $v ) => preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $v ), (array) ( $_POST['active_langs'] ?? [] ) ), fn( $v ) => $v !== '' ) );
				$defaultLang = get_option( 'idiomatticwp_default_lang', 'en' );
				// Always keep default lang
				if ( ! in_array( $defaultLang, $langs, true ) ) {
					$langs[] = $defaultLang;
				}
				update_option( 'idiomatticwp_active_langs', array_values( array_unique( $langs ) ) );
				wp_safe_redirect( admin_url( 'index.php?page=idiomatticwp-setup&step=3' ) );
				exit;

			case 3:
				$urlMode = sanitize_key( $_POST['url_mode'] ?? 'parameter' );
				if ( ! in_array( $urlMode, [ 'parameter', 'directory', 'subdomain' ], true ) ) {
					$urlMode = 'parameter';
				}
				update_option( 'idiomatticwp_url_mode', $urlMode );
				// Mark setup complete
				delete_option( 'idiomatticwp_needs_setup' );
				flush_rewrite_rules( false );
				wp_safe_redirect( admin_url( 'index.php?page=idiomatticwp-setup&step=4' ) );
				exit;
		}
	}

	// ── Renderer ──────────────────────────────────────────────────────────

	public function render(): void {
		$step        = max( 1, min( 4, (int) ( $_GET['step'] ?? 1 ) ) );
		$allLangs    = $this->languageManager->getAllSupportedLanguages();
		$activeLangs = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang = get_option( 'idiomatticwp_default_lang', 'en' );
		$urlMode     = get_option( 'idiomatticwp_url_mode', 'parameter' );
		$isPro       = $this->licenseChecker->isPro();

		$this->renderStyles();
		?>
		<div class="idiomatticwp-wizard-wrap">

			<!-- Header -->
			<div class="wizard-header">
				<div class="wizard-logo">
					<svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="16" fill="#2271b1"/><text x="16" y="21" text-anchor="middle" fill="white" font-size="16" font-weight="bold" font-family="sans-serif">i</text></svg>
					<span><?php esc_html_e( 'Idiomattic WP', 'idiomattic-wp' ); ?></span>
				</div>
				<div class="wizard-steps">
					<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
					<div class="wizard-step <?php echo $step > $i ? 'done' : ( $step === $i ? 'active' : '' ); ?>">
						<div class="step-circle">
							<?php if ( $step > $i ) : ?>
								<svg width="12" height="12" viewBox="0 0 12 12"><path d="M2 6l3 3 5-5" stroke="white" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
							<?php else : ?>
								<?php echo $i; ?>
							<?php endif; ?>
						</div>
						<span><?php echo esc_html( [ 1 => __( 'Default Language', 'idiomattic-wp' ), 2 => __( 'Active Languages', 'idiomattic-wp' ), 3 => __( 'URL Structure', 'idiomattic-wp' ) ][ $i ] ); ?></span>
					</div>
					<?php if ( $i < 3 ) : ?><div class="step-connector"></div><?php endif; ?>
					<?php endfor; ?>
				</div>
			</div>

			<!-- Card -->
			<div class="wizard-card">

				<?php if ( $step === 1 ) : ?>
				<!-- STEP 1: Default language -->
				<h2><?php esc_html_e( 'What language is your content written in?', 'idiomattic-wp' ); ?></h2>
				<p class="wizard-desc"><?php esc_html_e( 'This is the original language of your posts and pages.', 'idiomattic-wp' ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'idiomatticwp_wizard_step_1' ); ?>
					<input type="hidden" name="idiomatticwp_wizard_step" value="1">

					<div class="lang-grid">
						<?php
						$popular = [ 'en', 'es', 'fr', 'de', 'pt', 'it', 'nl', 'pl', 'ru', 'ja', 'zh', 'ar', 'ko', 'sv', 'da', 'fi', 'nb', 'tr', 'cs', 'ro' ];
						$sorted  = array_merge(
							array_intersect_key( $allLangs, array_flip( $popular ) ),
							array_diff_key( $allLangs, array_flip( $popular ) )
						);
						foreach ( $sorted as $code => $data ) :
							$flagUrl = IDIOMATTICWP_ASSETS_URL . 'flags/' . $code . '.svg';
						?>
						<label class="lang-option <?php echo $code === $defaultLang ? 'selected' : ''; ?>">
							<input type="radio" name="default_lang" value="<?php echo esc_attr( $code ); ?>" <?php checked( $code, $defaultLang ); ?>>
							<img src="<?php echo esc_url( $flagUrl ); ?>" alt="" width="24" height="18" loading="lazy" onerror="this.style.display='none'">
							<span class="lang-native"><?php echo esc_html( $data['native_name'] ); ?></span>
							<span class="lang-en"><?php echo esc_html( $data['name'] ); ?></span>
						</label>
						<?php endforeach; ?>
					</div>

					<div class="wizard-actions">
						<button type="submit" class="button button-primary button-hero">
							<?php esc_html_e( 'Continue →', 'idiomattic-wp' ); ?>
						</button>
					</div>
				</form>

				<?php elseif ( $step === 2 ) : ?>
				<!-- STEP 2: Active languages -->
				<h2><?php esc_html_e( 'Which languages do you want to translate into?', 'idiomattic-wp' ); ?></h2>
				<p class="wizard-desc"><?php esc_html_e( 'You can add or remove languages at any time from Settings.', 'idiomattic-wp' ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'idiomatticwp_wizard_step_2' ); ?>
					<input type="hidden" name="idiomatticwp_wizard_step" value="2">

					<div class="wizard-search-wrap">
						<input type="text" id="wizard-lang-search" class="wizard-search" placeholder="<?php esc_attr_e( 'Search languages…', 'idiomattic-wp' ); ?>">
					</div>

					<div class="lang-grid" id="wizard-lang-grid">
						<?php
						$sorted = array_merge(
							array_intersect_key( $allLangs, array_flip( $popular ) ),
							array_diff_key( $allLangs, array_flip( $popular ) )
						);
						foreach ( $sorted as $code => $data ) :
							if ( $code === $defaultLang ) continue;
							$flagUrl  = IDIOMATTICWP_ASSETS_URL . 'flags/' . $code . '.svg';
							$checked  = in_array( $code, $activeLangs, true );
						?>
						<label class="lang-option <?php echo $checked ? 'selected' : ''; ?>" data-name="<?php echo esc_attr( strtolower( $data['native_name'] . ' ' . $data['name'] ) ); ?>">
							<input type="checkbox" name="active_langs[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( $checked ); ?>>
							<img src="<?php echo esc_url( $flagUrl ); ?>" alt="" width="24" height="18" loading="lazy" onerror="this.style.display='none'">
							<span class="lang-native"><?php echo esc_html( $data['native_name'] ); ?></span>
							<span class="lang-en"><?php echo esc_html( $data['name'] ); ?></span>
						</label>
						<?php endforeach; ?>
					</div>

					<div class="wizard-actions">
						<a href="<?php echo esc_url( admin_url( 'index.php?page=idiomatticwp-setup&step=1' ) ); ?>" class="button button-secondary">← <?php esc_html_e( 'Back', 'idiomattic-wp' ); ?></a>
						<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Continue →', 'idiomattic-wp' ); ?></button>
					</div>
				</form>

				<script>
				document.getElementById('wizard-lang-search').addEventListener('input', function() {
					var q = this.value.toLowerCase();
					document.querySelectorAll('#wizard-lang-grid .lang-option').forEach(function(el) {
						el.style.display = el.dataset.name.includes(q) ? '' : 'none';
					});
				});
				// Toggle selected class on click
				document.querySelectorAll('#wizard-lang-grid .lang-option').forEach(function(label) {
					label.addEventListener('change', function() {
						label.classList.toggle('selected', label.querySelector('input').checked);
					});
				});
				</script>

				<?php elseif ( $step === 3 ) : ?>
				<!-- STEP 3: URL structure -->
				<h2><?php esc_html_e( 'How should language appear in your URLs?', 'idiomattic-wp' ); ?></h2>
				<p class="wizard-desc"><?php esc_html_e( 'This affects how visitors and search engines find your translated content.', 'idiomattic-wp' ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'idiomatticwp_wizard_step_3' ); ?>
					<input type="hidden" name="idiomatticwp_wizard_step" value="3">

					<div class="url-options">
						<?php
						$urlOptions = [
							'parameter' => [
								'label'   => __( 'Query parameter', 'idiomattic-wp' ),
								'example' => 'example.com/about/?lang=es',
								'desc'    => __( 'Works with any permalink structure. Recommended for most sites.', 'idiomattic-wp' ),
								'pro'     => false,
								'icon'    => '?',
							],
							'directory' => [
								'label'   => __( 'Language directory', 'idiomattic-wp' ),
								'example' => 'example.com/es/about/',
								'desc'    => __( 'Clean URLs with the language code as a path prefix. Requires pretty permalinks.', 'idiomattic-wp' ),
								'pro'     => true,
								'icon'    => '/',
							],
							'subdomain' => [
								'label'   => __( 'Subdomain', 'idiomattic-wp' ),
								'example' => 'es.example.com/about/',
								'desc'    => __( 'Each language on its own subdomain. Requires wildcard DNS.', 'idiomattic-wp' ),
								'pro'     => true,
								'icon'    => '.',
							],
						];
						foreach ( $urlOptions as $key => $opt ) :
							$disabled = $opt['pro'] && ! $isPro;
						?>
						<label class="url-option <?php echo $urlMode === $key ? 'selected' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>">
							<input type="radio" name="url_mode" value="<?php echo esc_attr( $key ); ?>"
								<?php checked( $urlMode, $key ); ?>
								<?php echo $disabled ? 'disabled' : ''; ?>>
							<div class="url-option-content">
								<div class="url-option-header">
									<span class="url-icon"><?php echo esc_html( $opt['icon'] ); ?></span>
									<strong><?php echo esc_html( $opt['label'] ); ?></strong>
									<?php if ( $opt['pro'] && ! $isPro ) : ?>
										<span class="pro-badge">PRO</span>
									<?php endif; ?>
								</div>
								<code class="url-example"><?php echo esc_html( $opt['example'] ); ?></code>
								<p class="url-desc"><?php echo esc_html( $opt['desc'] ); ?></p>
							</div>
						</label>
						<?php endforeach; ?>
					</div>

					<div class="wizard-actions">
						<a href="<?php echo esc_url( admin_url( 'index.php?page=idiomatticwp-setup&step=2' ) ); ?>" class="button button-secondary">← <?php esc_html_e( 'Back', 'idiomattic-wp' ); ?></a>
						<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Finish setup →', 'idiomattic-wp' ); ?></button>
					</div>
				</form>
				<script>
				document.querySelectorAll('.url-option:not(.disabled)').forEach(function(label) {
					label.addEventListener('change', function() {
						document.querySelectorAll('.url-option').forEach(function(l) { l.classList.remove('selected'); });
						label.classList.add('selected');
					});
				});
				</script>

				<?php elseif ( $step === 4 ) : ?>
				<!-- STEP 4: Done -->
				<div class="wizard-done">
					<div class="done-icon">🎉</div>
					<h2><?php esc_html_e( "You're all set!", 'idiomattic-wp' ); ?></h2>
					<p class="wizard-desc"><?php
						printf(
							esc_html__( 'Idiomattic WP is configured with %d active language(s). Start by translating your first post.', 'idiomattic-wp' ),
							count( $this->languageManager->getActiveLanguages() )
						);
					?></p>

					<div class="done-actions">
						<a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="button button-primary button-hero">
							<?php esc_html_e( 'Go to Posts →', 'idiomattic-wp' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>" class="button button-secondary button-hero">
							<?php esc_html_e( 'Go to Pages', 'idiomattic-wp' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-settings' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Settings', 'idiomattic-wp' ); ?>
						</a>
					</div>

					<?php if ( ! $isPro ) : ?>
					<div class="done-upgrade">
						<p><?php esc_html_e( 'Want AI-powered translations? Upgrade to Pro for BYOK (Bring Your Own Key) support.', 'idiomattic-wp' ); ?></p>
						<a href="<?php echo esc_url( idiomatticwp_upgrade_url( 'setup-wizard' ) ); ?>" target="_blank" class="button button-primary">
							<?php esc_html_e( 'Upgrade to Pro →', 'idiomattic-wp' ); ?>
						</a>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

			</div><!-- .wizard-card -->

		</div><!-- .wizard-wrap -->
		<?php
	}

	// ── Styles ────────────────────────────────────────────────────────────

	private function renderStyles(): void {
		?>
		<style>
		body.wp-admin { background: #f0f0f1; }
		#wpcontent, #wpfooter { margin-left: 0 !important; }
		#adminmenuwrap, #adminmenuback { display: none; }
		#wpbody-content { padding: 0; }

		.idiomatticwp-wizard-wrap {
			max-width: 780px;
			margin: 40px auto;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
		}

		/* Header */
		.wizard-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 28px;
			flex-wrap: wrap;
			gap: 16px;
		}
		.wizard-logo {
			display: flex;
			align-items: center;
			gap: 10px;
			font-size: 18px;
			font-weight: 700;
			color: #1d2327;
		}
		.wizard-steps {
			display: flex;
			align-items: center;
			gap: 0;
		}
		.wizard-step {
			display: flex;
			align-items: center;
			gap: 8px;
			font-size: 12px;
			color: #8c8f94;
			font-weight: 500;
		}
		.wizard-step.active { color: #2271b1; }
		.wizard-step.done   { color: #46b450; }
		.step-circle {
			width: 26px; height: 26px;
			border-radius: 50%;
			border: 2px solid currentColor;
			display: flex; align-items: center; justify-content: center;
			font-size: 12px; font-weight: 700;
			flex-shrink: 0;
		}
		.wizard-step.active .step-circle { background: #2271b1; color: #fff; border-color: #2271b1; }
		.wizard-step.done   .step-circle { background: #46b450; color: #fff; border-color: #46b450; }
		.step-connector { width: 32px; height: 2px; background: #dcdcde; margin: 0 6px; }

		/* Card */
		.wizard-card {
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 6px;
			padding: 40px 48px;
		}
		.wizard-card h2 { margin: 0 0 8px; font-size: 22px; color: #1d2327; }
		.wizard-desc    { color: #646970; margin: 0 0 28px; font-size: 14px; }

		/* Language grid */
		.wizard-search-wrap { margin-bottom: 16px; }
		.wizard-search { width: 100%; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px; }
		.lang-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
			gap: 8px;
			max-height: 380px;
			overflow-y: auto;
			padding: 4px 2px;
			margin-bottom: 28px;
		}
		.lang-option {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 9px 12px;
			border: 2px solid #dcdcde;
			border-radius: 5px;
			cursor: pointer;
			transition: border-color .12s, background .12s;
			user-select: none;
		}
		.lang-option:hover { border-color: #2271b1; background: #f0f6fc; }
		.lang-option.selected { border-color: #2271b1; background: #f0f6fc; }
		.lang-option input { display: none; }
		.lang-native { font-weight: 600; font-size: 13px; color: #1d2327; }
		.lang-en     { font-size: 11px; color: #8c8f94; }

		/* URL options */
		.url-options { display: flex; flex-direction: column; gap: 12px; margin-bottom: 28px; }
		.url-option {
			display: flex;
			align-items: flex-start;
			gap: 12px;
			padding: 16px 18px;
			border: 2px solid #dcdcde;
			border-radius: 5px;
			cursor: pointer;
			transition: border-color .12s, background .12s;
		}
		.url-option:hover:not(.disabled) { border-color: #2271b1; background: #f0f6fc; }
		.url-option.selected             { border-color: #2271b1; background: #f0f6fc; }
		.url-option.disabled             { opacity: .6; cursor: not-allowed; }
		.url-option input                { margin-top: 3px; flex-shrink: 0; }
		.url-option-content              { flex: 1; }
		.url-option-header               { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
		.url-icon  { width: 22px; height: 22px; background: #f0f6fc; border: 1px solid #c3d4e5; border-radius: 4px; display:flex; align-items:center; justify-content:center; font-size: 12px; font-weight: 700; color: #2271b1; }
		.url-example { font-size: 12px; color: #2271b1; background: #f0f6fc; padding: 2px 8px; border-radius: 3px; display: inline-block; margin-bottom: 4px; }
		.url-desc    { margin: 0; font-size: 12px; color: #646970; }
		.pro-badge { background: #e07b00; color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 3px; text-transform: uppercase; }

		/* Actions */
		.wizard-actions { display: flex; align-items: center; gap: 12px; justify-content: flex-end; }
		.button-hero    { padding: 8px 22px !important; font-size: 14px !important; height: auto !important; }

		/* Done screen */
		.wizard-done { text-align: center; padding: 20px 0; }
		.done-icon   { font-size: 56px; margin-bottom: 16px; }
		.done-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin: 32px 0; }
		.done-upgrade {
			background: #f6f7f7;
			border: 1px solid #dcdcde;
			border-radius: 5px;
			padding: 20px 24px;
			max-width: 420px;
			margin: 0 auto;
		}
		.done-upgrade p { margin: 0 0 12px; color: #646970; font-size: 13px; }
		</style>
		<?php
	}
}
