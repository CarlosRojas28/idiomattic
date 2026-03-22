<?php
/**
 * SettingsPage — renders the plugin settings with a tabbed interface.
 *
 * Tabs:
 *   languages   — active languages + default language
 *   url         — URL structure (parameter / directory / subdomain)
 *   translation — AI provider + behaviour (Pro)
 *   glossary    — glossary CRUD (Pro)
 *   content     — post types, taxonomies, custom fields configuration
 *   advanced    — data management, export/import
 *   troubleshooting — debug logs, system info
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Glossary\WpdbGlossaryRepository;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Providers\ProviderRegistry;
use IdiomatticWP\Support\EncryptionService;
use IdiomatticWP\Core\CustomElementRegistry;

class SettingsPage {

	public function __construct(
		private LanguageManager          $languageManager,
		private LicenseChecker           $licenseChecker,
		private ProviderRegistry         $providerRegistry,
		private EncryptionService        $encryption,
		private CustomElementRegistry    $elementRegistry,
		private WpdbGlossaryRepository   $glossaryRepo,
	) {}

	// ── Entry point ───────────────────────────────────────────────────────

	public function render(): void {
		$currentTab = sanitize_key( $_GET['tab'] ?? 'languages' );

		$tabs = [
			'languages'       => __( 'Languages',       'idiomattic-wp' ),
			'url'             => __( 'URL Structure',    'idiomattic-wp' ),
			'translation'     => __( 'Translation',      'idiomattic-wp' ),
			'menus'           => __( 'Menus',            'idiomattic-wp' ),
			'glossary'        => __( 'Glossary',         'idiomattic-wp' ),
			'content'         => __( 'Content',          'idiomattic-wp' ),
			'advanced'        => __( 'Advanced',         'idiomattic-wp' ),
			'troubleshooting' => __( 'Troubleshooting',  'idiomattic-wp' ),
		];
		?>
		<div class="wrap idiomatticwp-settings">
			<h1><?php esc_html_e( 'Idiomattic WP Settings', 'idiomattic-wp' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-settings&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $currentTab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			// Troubleshooting and Content tabs have their own form handling
			$noFormTabs = [ 'troubleshooting' ];
			if ( in_array( $currentTab, $noFormTabs, true ) ) {
				$this->renderTab( $currentTab );
			} else {
				?>
				<form method="post" action="options.php" style="margin-top: 20px;">
					<?php
					settings_fields( 'idiomatticwp_settings_' . $currentTab );
					$this->renderTab( $currentTab );

					// Hide the standard submit button on pro-locked tabs
					$hideSubmit = ( $currentTab === 'translation' && ! $this->licenseChecker->isPro() )
					           || ( $currentTab === 'glossary'    && ! $this->licenseChecker->isPro() );
					if ( ! $hideSubmit ) {
						submit_button();
					}
					?>
				</form>
				<?php
				// Custom languages section renders outside the main form (languages tab only)
				if ( $currentTab === 'languages' ) {
					$this->renderCustomLanguagesSection();
				}
			}
			?>
		</div>
		<?php
		$this->renderInlineStyles();
	}

	private function renderTab( string $tab ): void {
		switch ( $tab ) {
			case 'languages':       $this->renderLanguagesTab();       break;
			case 'url':             $this->renderUrlTab();             break;
			case 'translation':     $this->renderTranslationTab();     break;
			case 'glossary':        $this->renderGlossaryTab();        break;
			case 'content':         $this->renderContentTab();         break;
			case 'menus':           $this->renderMenusTab();           break;
			case 'advanced':        $this->renderAdvancedTab();        break;
			case 'troubleshooting': $this->renderTroubleshootingTab(); break;
		}
	}

	// ── Tab: Languages ────────────────────────────────────────────────────

	private function renderLanguagesTab(): void {
		$allLangs    = $this->languageManager->getAllSupportedLanguages();
		$activeLangs = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		?>

		<div class="iwp-languages-page">

			<?php /* ── Default Language card ─────────────────────────────── */ ?>
			<div class="iwp-card iwp-default-lang-card">
				<div class="iwp-default-lang-card__info">
					<strong class="iwp-default-lang-card__title">
						<?php esc_html_e( 'Site Default Language', 'idiomattic-wp' ); ?>
					</strong>
					<p class="iwp-default-lang-card__desc">
						<?php esc_html_e( 'Choose the primary language your website content will be served in by default.', 'idiomattic-wp' ); ?>
					</p>
				</div>
				<div class="iwp-default-lang-card__control">
					<span class="iwp-field-label"><?php esc_html_e( 'Primary Language', 'idiomattic-wp' ); ?></span>
					<select name="idiomatticwp_default_lang" class="iwp-select">
						<?php foreach ( $allLangs as $code => $data ) : ?>
							<?php if ( ! in_array( (string) $code, $activeLangs, true ) ) continue; ?>
							<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( (string) $code, $defaultLang ); ?>>
								<?php echo esc_html( $data['native_name'] . ' — ' . $data['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<?php /* ── Section header: title + search ─────────────────────── */ ?>
			<div class="iwp-section-row">
				<h2 class="iwp-section-title"><?php esc_html_e( 'Active Languages', 'idiomattic-wp' ); ?></h2>
				<div class="iwp-lang-search-wrap">
					<span class="dashicons dashicons-search iwp-lang-search-icon"></span>
					<input type="text" id="iwp-lang-filter" class="iwp-lang-search"
						placeholder="<?php esc_attr_e( 'Filter languages...', 'idiomattic-wp' ); ?>">
				</div>
			</div>

			<?php /* ── Active language chips ────────────────────────────────── */ ?>
			<div class="iwp-active-chips" id="iwp-active-chips">
				<?php foreach ( $activeLangs as $code ) : ?>
					<?php
					$data      = $allLangs[ $code ] ?? null;
					if ( ! $data ) continue;
					$flagUrl   = $this->getFlagUrl( $code, $data['flag'] ?? '' );
					$isDefault = ( $code === $defaultLang );
					?>
					<div class="iwp-lang-chip<?php echo $isDefault ? ' iwp-lang-chip--default' : ''; ?>"
						 data-code="<?php echo esc_attr( $code ); ?>">
						<?php if ( $flagUrl ) : ?>
							<img src="<?php echo esc_url( $flagUrl ); ?>" class="iwp-chip-flag" alt="" width="24" height="16">
						<?php else : ?>
							<span class="iwp-chip-flag iwp-flag-fallback"><?php echo esc_html( strtoupper( substr( $code, 0, 2 ) ) ); ?></span>
						<?php endif; ?>
						<span class="iwp-chip-name"><?php echo esc_html( $data['name'] ); ?></span>
						<?php if ( $isDefault ) : ?>
							<span class="iwp-chip-check dashicons dashicons-yes-alt"></span>
						<?php else : ?>
							<button type="button" class="iwp-chip-remove" data-code="<?php echo esc_attr( $code ); ?>" title="<?php esc_attr_e( 'Remove', 'idiomattic-wp' ); ?>">&#x00D7;</button>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<button type="button" class="iwp-chip-add" id="iwp-lang-add-btn" aria-expanded="false">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'ADD', 'idiomattic-wp' ); ?>
				</button>
			</div>

			<?php /* ── Language picker (full grid, hidden by default) ────────── */ ?>
			<div class="iwp-lang-picker" id="iwp-lang-picker" hidden aria-hidden="true">
				<div class="iwp-lang-picker__grid">
					<?php foreach ( $allLangs as $code => $data ) : ?>
						<?php
						$isActive    = in_array( (string) $code, $activeLangs, true );
						$flagUrl     = $this->getFlagUrl( (string) $code, $data['flag'] ?? '' );
						$searchTerms = strtolower( $data['name'] . ' ' . $data['native_name'] . ' ' . $code );
						?>
						<label class="iwp-picker-item<?php echo $isActive ? ' is-active' : ''; ?>"
							   data-search="<?php echo esc_attr( $searchTerms ); ?>"
							   data-code="<?php echo esc_attr( (string) $code ); ?>"
							   data-name="<?php echo esc_attr( $data['name'] ); ?>"
							   data-native="<?php echo esc_attr( $data['native_name'] ); ?>"
							   data-flag="<?php echo esc_attr( $flagUrl ); ?>"
							   data-flagfallback="<?php echo esc_attr( strtoupper( substr( (string) $code, 0, 2 ) ) ); ?>">
							<input type="checkbox"
								   name="idiomatticwp_active_langs[]"
								   value="<?php echo esc_attr( (string) $code ); ?>"
								   <?php checked( $isActive ); ?>>
							<?php if ( $flagUrl ) : ?>
								<img src="<?php echo esc_url( $flagUrl ); ?>" class="iwp-picker-item__flag" alt="" width="24" height="16">
							<?php else : ?>
								<span class="iwp-picker-item__flag iwp-flag-fallback"><?php echo esc_html( strtoupper( substr( (string) $code, 0, 2 ) ) ); ?></span>
							<?php endif; ?>
							<span class="iwp-picker-item__native"><?php echo esc_html( $data['native_name'] ); ?></span>
							<span class="iwp-picker-item__english"><?php echo esc_html( $data['name'] ); ?></span>
							<span class="iwp-picker-item__check dashicons dashicons-yes"></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Render the "Custom Languages" add/delete section (outside the main settings form).
	 */
	private function renderCustomLanguagesSection(): void {
		$custom      = get_option( 'idiomatticwp_custom_languages', [] );
		$customLangs = is_array( $custom ) ? $custom : [];

		$error = sanitize_key( $_GET['idiomatticwp_error'] ?? '' );
		?>
		<div class="iwp-custom-langs-section">

			<h2 class="iwp-custom-langs-title">
				<?php esc_html_e( 'Custom', 'idiomattic-wp' ); ?>
				<span class="iwp-custom-langs-title__accent"><?php esc_html_e( 'Languages', 'idiomattic-wp' ); ?></span>
			</h2>
			<p class="iwp-custom-langs-desc">
				<?php esc_html_e( "Can't find your language in the list? Define your own custom parameters below.", 'idiomattic-wp' ); ?>
			</p>

			<?php if ( $error === 'invalid_code' ) : ?>
				<div class="notice notice-error inline iwp-notice"><p>
					<?php esc_html_e( 'Invalid language code. Use a 2-letter ISO 639-1 code (e.g. "eo") or a region variant like "zh-TW".', 'idiomattic-wp' ); ?>
				</p></div>
			<?php elseif ( $error === 'missing_fields' ) : ?>
				<div class="notice notice-error inline iwp-notice"><p>
					<?php esc_html_e( 'Code, native name, and English name are required.', 'idiomattic-wp' ); ?>
				</p></div>
			<?php endif; ?>

			<?php /* ── Existing custom languages ─────────────────────────── */ ?>
			<?php if ( ! empty( $customLangs ) ) : ?>
				<div class="iwp-card iwp-custom-langs-list">
					<?php foreach ( $customLangs as $code => $data ) : ?>
						<?php
						$deleteUrl = wp_nonce_url(
							add_query_arg(
								[ 'action' => 'idiomatticwp_delete_custom_lang', 'code' => $code ],
								admin_url( 'admin-post.php' )
							),
							'idiomatticwp_delete_custom_lang_' . $code
						);
						$flagUrl = $this->getFlagUrl( (string) $code, $data['flag'] ?? '' );
						?>
						<div class="iwp-custom-lang-row">
							<div class="iwp-custom-lang-row__flag">
								<?php if ( $flagUrl ) : ?>
									<img src="<?php echo esc_url( $flagUrl ); ?>" alt="" width="24" height="16">
								<?php else : ?>
									<span class="iwp-flag-fallback"><?php echo esc_html( strtoupper( substr( (string) $code, 0, 2 ) ) ); ?></span>
								<?php endif; ?>
							</div>
							<div class="iwp-custom-lang-row__info">
								<strong><?php echo esc_html( $data['native_name'] ?? $code ); ?></strong>
								<span><?php echo esc_html( $data['name'] ?? '' ); ?></span>
								<code><?php echo esc_html( (string) $code ); ?></code>
							</div>
							<a href="<?php echo esc_url( $deleteUrl ); ?>"
							   class="iwp-custom-lang-row__delete"
							   onclick="return confirm('<?php echo esc_js( __( 'Delete this custom language?', 'idiomattic-wp' ) ); ?>')">
								<span class="dashicons dashicons-trash"></span>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php /* ── Add form ──────────────────────────────────────────── */ ?>
			<div class="iwp-card iwp-custom-lang-form">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'idiomatticwp_add_custom_lang' ); ?>
					<input type="hidden" name="action" value="idiomatticwp_add_custom_lang">

					<div class="iwp-form-grid">
						<div class="iwp-form-field">
							<label class="iwp-field-label" for="custom_lang_code">
								<?php esc_html_e( 'Language Code', 'idiomattic-wp' ); ?>
							</label>
							<input type="text" id="custom_lang_code" name="custom_lang_code"
								   class="iwp-input" placeholder="e.g. pt_BR"
								   pattern="[a-z]{2}(-[A-Z]{2})?" maxlength="6" required>
							<p class="iwp-field-hint"><?php esc_html_e( 'Standard ISO code for localization', 'idiomattic-wp' ); ?></p>
						</div>

						<div class="iwp-form-field">
							<label class="iwp-field-label" for="custom_lang_native">
								<?php esc_html_e( 'Native Name', 'idiomattic-wp' ); ?>
							</label>
							<input type="text" id="custom_lang_native" name="custom_lang_native"
								   class="iwp-input" placeholder="e.g. Português" required>
						</div>

						<div class="iwp-form-field">
							<label class="iwp-field-label" for="custom_lang_name">
								<?php esc_html_e( 'English Name', 'idiomattic-wp' ); ?>
							</label>
							<input type="text" id="custom_lang_name" name="custom_lang_name"
								   class="iwp-input" placeholder="e.g. Portuguese (Brazil)" required>
						</div>

						<div class="iwp-form-field">
							<label class="iwp-field-label" for="custom_lang_flag">
								<?php esc_html_e( 'Flag Country Code', 'idiomattic-wp' ); ?>
							</label>
							<div class="iwp-flag-input-wrap">
								<input type="text" id="custom_lang_flag" name="custom_lang_flag"
									   class="iwp-input" placeholder="e.g. br" maxlength="6">
								<button type="button" class="iwp-flag-preview-btn" id="iwp-flag-preview-btn"
										title="<?php esc_attr_e( 'Preview flag', 'idiomattic-wp' ); ?>">
									<span class="dashicons dashicons-flag"></span>
								</button>
							</div>
						</div>
					</div>

					<div class="iwp-form-footer">
						<label class="iwp-rtl-label">
							<input type="checkbox" name="custom_lang_rtl" value="1">
							<?php esc_html_e( 'Right-to-left language', 'idiomattic-wp' ); ?>
						</label>
						<button type="submit" name="submit_custom_lang" class="iwp-btn iwp-btn--primary">
							<?php esc_html_e( 'Add Custom Language', 'idiomattic-wp' ); ?>
						</button>
					</div>
				</form>
			</div>

		</div>
		<?php
	}

	/**
	 * Return the URL for a language flag SVG, or empty string if not found.
	 *
	 * Flag files are named after the BCP-47 language code (e.g. es.svg, pt-BR.svg).
	 */
	private function getFlagUrl( string $code, string $flagCode = '' ): string {
		$flagsPath = IDIOMATTICWP_PATH . 'assets/flags/';
		$flagsUrl  = IDIOMATTICWP_ASSETS_URL . 'flags/';

		// 1. Use the explicit flag code from language data (e.g. 'jp' for 'ja').
		if ( $flagCode !== '' && file_exists( $flagsPath . $flagCode . '.svg' ) ) {
			return $flagsUrl . $flagCode . '.svg';
		}

		// 2. Try the language code directly (e.g. 'zh-CN.svg').
		if ( file_exists( $flagsPath . $code . '.svg' ) ) {
			return $flagsUrl . $code . '.svg';
		}

		// 3. Try the base language code without region (e.g. 'zh' for 'zh-CN').
		$base = explode( '-', $code )[0];
		if ( $base !== $code && file_exists( $flagsPath . $base . '.svg' ) ) {
			return $flagsUrl . $base . '.svg';
		}

		return '';
	}

	// ── Tab: URL Structure ────────────────────────────────────────────────

	private function renderUrlTab(): void {
		$mode            = get_option( 'idiomatticwp_url_mode', 'parameter' );
		$isPro           = $this->licenseChecker->isPro();
		$plainPermalinks = get_option( 'permalink_structure', '' ) === '';

		if ( $plainPermalinks ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 20px;"><p>';
			printf(
				esc_html__( 'Directory and Subdomain modes require pretty permalinks. %s', 'idiomattic-wp' ),
				'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Configure permalinks →', 'idiomattic-wp' ) . '</a>'
			);
			echo '</p></div>';
		}

		$options = [
			'parameter' => [
				'label'          => __( 'Query Parameter', 'idiomattic-wp' ),
				'example'        => 'example.com/my-post/?lang=es',
				'description'    => __( 'Works with any permalink structure. Easiest to set up.', 'idiomattic-wp' ),
				'pro'            => false,
				'needs_pretty'   => false,
			],
			'directory' => [
				'label'          => __( 'Directory', 'idiomattic-wp' ),
				'example'        => 'example.com/es/my-post/',
				'description'    => __( 'Clean URLs with the language code as a path prefix. Requires pretty permalinks.', 'idiomattic-wp' ),
				'pro'            => false,
				'needs_pretty'   => true,
			],
			'subdomain' => [
				'label'          => __( 'Subdomain', 'idiomattic-wp' ),
				'example'        => 'es.example.com/my-post/',
				'description'    => __( 'Each language on its own subdomain. Requires wildcard DNS and SSL.', 'idiomattic-wp' ),
				'pro'            => true,
				'needs_pretty'   => false,
			],
		];

		echo '<div class="iwp-url-options">';
		foreach ( $options as $val => $opt ) {
			$isProLocked   = $opt['pro'] && ! $isPro;
			$isPlainLocked = $opt['needs_pretty'] && $plainPermalinks;
			$isLocked      = $isProLocked || $isPlainLocked;
			$isSelected    = $mode === $val;

			$classes = 'iwp-url-option';
			if ( $isSelected ) $classes .= ' is-selected';
			if ( $isLocked )   $classes .= ' is-locked';

			printf(
				'<label class="%s" for="url_mode_%s">
					<input type="radio" name="idiomatticwp_url_mode" value="%s" id="url_mode_%s" %s %s>
					<div class="iwp-url-option__body">
						<div class="iwp-url-option__label">
							%s
							%s
							<code class="iwp-url-option__example">%s</code>
						</div>
						<p class="iwp-url-option__desc">%s</p>
					</div>
				</label>',
				esc_attr( $classes ),
				esc_attr( $val ),
				esc_attr( $val ),
				esc_attr( $val ),
				$isSelected ? 'checked' : '',
				$isLocked   ? 'disabled' : '',
				esc_html( $opt['label'] ),
				$isProLocked ? '<span class="iwp-pro-badge">PRO</span>' : '',
				esc_html( $opt['example'] ),
				esc_html( $opt['description'] )
			);
		}
		echo '</div>';

		if ( ! $isPro ) {
			echo '<p style="margin-top:20px;"><a href="' . esc_url( idiomatticwp_upgrade_url( 'url-mode' ) ) . '" target="_blank" class="iwp-btn iwp-btn--secondary">';
			esc_html_e( 'Upgrade to Pro to unlock Subdomain mode →', 'idiomattic-wp' );
			echo '</a></p>';
		}
	}

	// ── Tab: Translation ──────────────────────────────────────────────────

	private function renderTranslationTab(): void {
		if ( ! $this->licenseChecker->isPro() ) {
			?>
			<div class="iwp-card iwp-pro-banner">
				<div class="iwp-pro-banner__icon">🤖</div>
				<h3><?php esc_html_e( 'AI Translation — Pro Feature', 'idiomattic-wp' ); ?></h3>
				<p><?php esc_html_e( 'Automated translations and AI provider configuration are available in the Pro version. Bring Your Own Key — connect directly to OpenAI, Claude, or DeepL.', 'idiomattic-wp' ); ?></p>
				<a href="<?php echo esc_url( idiomatticwp_upgrade_url( 'translation-tab' ) ); ?>" target="_blank" class="iwp-btn iwp-btn--primary">
					<?php esc_html_e( 'Upgrade to Pro →', 'idiomattic-wp' ); ?>
				</a>
			</div>
			<?php
			return;
		}

		$this->maybeHandleProviderSave();

		$activeProviderId = get_option( 'idiomatticwp_active_provider', 'openai' );
		$providers        = $this->providerRegistry->getProviders();

		echo '<h3>' . esc_html__( 'AI Translation Provider', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Bring Your Own Key (BYOK): connect directly to your chosen AI provider. Your API key is stored encrypted and never sent to Idiomattic servers.', 'idiomattic-wp' ) . '</p>';

		echo '<div class="idiomatticwp-provider-cards">';
		foreach ( $providers as $providerId => $providerClass ) {
			if ( ! class_exists( $providerClass ) ) continue;
			$instance   = new $providerClass( new \IdiomatticWP\Support\HttpClient(), $this->encryption );
			$isActive   = $providerId === $activeProviderId;
			$isConfigured = $instance->isConfigured();

			printf(
				'<label class="idiomatticwp-provider-card%s" for="provider_radio_%s">
					<input type="radio" id="provider_radio_%s" name="idiomatticwp_active_provider" value="%s" %s>
					<span class="card-name">%s</span>
					<span class="card-status %s">%s</span>
				</label>',
				$isActive ? ' is-active' : '',
				esc_attr( $providerId ),
				esc_attr( $providerId ),
				esc_attr( $providerId ),
				checked( $isActive, true, false ),
				esc_html( $instance->getName() ),
				$isConfigured ? 'is-configured' : 'not-configured',
				$isConfigured ? esc_html__( '✓ Configured', 'idiomattic-wp' ) : esc_html__( 'Not configured', 'idiomattic-wp' )
			);
		}
		echo '</div>';

		foreach ( $providers as $providerId => $providerClass ) {
			if ( ! class_exists( $providerClass ) ) continue;
			$instance     = new $providerClass( new \IdiomatticWP\Support\HttpClient(), $this->encryption );
			$fields       = $instance->getConfigFields();
			$sectionStyle = $providerId === $activeProviderId ? '' : 'display:none;';

			printf( '<div class="idiomatticwp-provider-fields" id="provider-fields-%s" style="%s">', esc_attr( $providerId ), esc_attr( $sectionStyle ) );
			echo '<table class="form-table" style="max-width:680px;"><tbody>';

			foreach ( $fields as $field ) {
				$optionKey    = 'idiomatticwp_' . $field['key'];
				$currentValue = get_option( $optionKey, '' );
				$isPassword   = $field['type'] === 'password';
				$displayValue = $isPassword ? '' : esc_attr( $currentValue );
				$placeholder  = $isPassword
					? ( $currentValue !== '' ? __( '(saved — enter a new key to replace)', 'idiomattic-wp' ) : ( $field['placeholder'] ?? 'sk-...' ) )
					: ( $field['placeholder'] ?? '' );

				echo '<tr>';
				printf( '<th scope="row"><label for="field_%s">%s</label></th>', esc_attr( $field['key'] ), esc_html( $field['label'] ) );
				echo '<td>';

				if ( $field['type'] === 'select' ) {
					printf( '<select id="field_%s" name="%s" class="regular-text">', esc_attr( $field['key'] ), esc_attr( $optionKey ) );
					foreach ( $field['options'] as $val => $label ) {
						printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $currentValue, $val, false ), esc_html( $label ) );
					}
					echo '</select>';
				} elseif ( $isPassword ) {
					printf(
						'<input type="password" id="field_%s" name="%s" value="" placeholder="%s" class="regular-text" autocomplete="new-password">
						 <input type="hidden" name="%s_keep" value="1">',
						esc_attr( $field['key'] ), esc_attr( $optionKey ), esc_attr( $placeholder ), esc_attr( $optionKey )
					);
				} else {
					printf(
						'<input type="text" id="field_%s" name="%s" value="%s" placeholder="%s" class="regular-text">',
						esc_attr( $field['key'] ), esc_attr( $optionKey ), $displayValue, esc_attr( $placeholder )
					);
				}
				echo '</td></tr>';
			}

			echo '</tbody></table></div>';
		}

		$providerIds = wp_json_encode( array_keys( $providers ) );
		?>
		<script>
		(function() {
			var providers = <?php echo $providerIds; ?>;
			function showFields(activeId) {
				providers.forEach(function(id) {
					var el = document.getElementById('provider-fields-' + id);
					if (el) el.style.display = (id === activeId) ? '' : 'none';
				});
			}
			document.querySelectorAll('input[name="idiomatticwp_active_provider"]').forEach(function(r) {
				r.addEventListener('change', function() { showFields(this.value); });
			});
		})();
		</script>
		<?php

		$tmEnabled     = get_option( 'idiomatticwp_tm_enabled', false );
		$autoTranslate = get_option( 'idiomatticwp_auto_translate', false );

		echo '<h3 style="margin-top:28px;">' . esc_html__( 'Behaviour', 'idiomattic-wp' ) . '</h3>';
		echo '<table class="form-table" style="max-width:680px;"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Translation Memory', 'idiomattic-wp' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="idiomatticwp_tm_enabled" value="1" %s> %s</label>',
			checked( $tmEnabled, '1', false ),
			esc_html__( 'Re-use previously translated segments to reduce API costs', 'idiomattic-wp' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Auto-translate on creation', 'idiomattic-wp' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="idiomatticwp_auto_translate" value="1" %s> %s</label>
			 <p class="description">%s</p>',
			checked( $autoTranslate, '1', false ),
			esc_html__( 'Automatically start AI translation when a new translation is created', 'idiomattic-wp' ),
			esc_html__( 'If disabled, new translations start as drafts with the original content copied in.', 'idiomattic-wp' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	private function maybeHandleProviderSave(): void {
		if ( empty( $_POST['option_page'] ) || $_POST['option_page'] !== 'idiomatticwp_settings' ) return;
		if ( ! check_admin_referer( 'idiomatticwp_settings-options' ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;

		foreach ( $this->providerRegistry->getProviders() as $providerId => $providerClass ) {
			if ( ! class_exists( $providerClass ) ) continue;
			$instance = new $providerClass( new \IdiomatticWP\Support\HttpClient(), $this->encryption );

			foreach ( $instance->getConfigFields() as $field ) {
				if ( $field['type'] !== 'password' ) continue;
				$optionKey = 'idiomatticwp_' . $field['key'];
				$newValue  = sanitize_text_field( wp_unslash( $_POST[ $optionKey ] ?? '' ) );
				$keep      = ! empty( $_POST[ $optionKey . '_keep' ] );

				if ( $newValue !== '' ) {
					update_option( $optionKey, $this->encryption->encrypt( $newValue ) );
				} elseif ( ! $keep ) {
					delete_option( $optionKey );
				}
			}
		}
	}

	// ── Tab: Glossary ─────────────────────────────────────────────────────

	private function renderGlossaryTab(): void {
		if ( ! $this->licenseChecker->isPro() ) {
			?>
			<div class="iwp-card iwp-pro-banner">
				<div class="iwp-pro-banner__icon">📖</div>
				<h3><?php esc_html_e( 'Glossary — Pro Feature', 'idiomattic-wp' ); ?></h3>
				<p><?php esc_html_e( 'Define terms that must always be translated a specific way, or kept unchanged. Glossary rules are automatically applied during AI translation.', 'idiomattic-wp' ); ?></p>
				<a href="<?php echo esc_url( idiomatticwp_upgrade_url( 'glossary-tab' ) ); ?>" target="_blank" class="iwp-btn iwp-btn--primary">
					<?php esc_html_e( 'Upgrade to Pro →', 'idiomattic-wp' ); ?>
				</a>
			</div>
			<?php
			return;
		}

		$this->handleGlossaryFormActions();

		$defaultLang      = (string) $this->languageManager->getDefaultLanguage();
		$activeLangs      = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$nonDefaultLangs  = array_values( array_filter( $activeLangs, fn( $l ) => $l !== $defaultLang ) );
		$targetLangFilter = sanitize_key( $_GET['glossary_lang'] ?? ( $nonDefaultLangs[0] ?? '' ) );

		try {
			$sourceLang = \IdiomatticWP\ValueObjects\LanguageCode::from( $defaultLang );
			$targetLang = \IdiomatticWP\ValueObjects\LanguageCode::from( $targetLangFilter );
			$terms      = $this->glossaryRepo->getTerms( $sourceLang, $targetLang );
		} catch ( \Throwable ) {
			$terms = [];
		}
		?>
		<p class="description"><?php esc_html_e( 'Define terms that must always be translated a specific way, or kept unchanged by the AI.', 'idiomattic-wp' ); ?></p>

		<div class="iwp-glossary-filter" style="margin-bottom:12px;">
			<label class="iwp-field-label"><?php esc_html_e( 'Show terms for:', 'idiomattic-wp' ); ?></label>
			<select id="glossary-lang-filter" class="iwp-select" style="width:auto;"
				onchange="location.href='<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-settings&tab=glossary&glossary_lang=' ) ); ?>'+this.value">
				<?php foreach ( $nonDefaultLangs as $lang ) : ?>
					<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $targetLangFilter, $lang ); ?>>
						<?php echo esc_html( $defaultLang . ' → ' . $lang ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<table class="wp-list-table widefat fixed striped" style="max-width:960px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source Term', 'idiomattic-wp' ); ?></th>
					<th><?php esc_html_e( 'Translation / Rule', 'idiomattic-wp' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'idiomattic-wp' ); ?></th>
					<th style="width:280px;"><?php esc_html_e( 'Edit / Delete', 'idiomattic-wp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $terms ) ) : ?>
					<tr><td colspan="4"><em><?php esc_html_e( 'No glossary terms yet. Add one below.', 'idiomattic-wp' ); ?></em></td></tr>
				<?php else : ?>
					<?php foreach ( $terms as $term ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $term->sourceTerm ); ?></strong></td>
							<td>
								<?php if ( $term->forbidden ) : ?>
									<em><?php esc_html_e( 'Keep unchanged', 'idiomattic-wp' ); ?></em>
								<?php else : ?>
									<?php echo esc_html( $term->translatedTerm ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $term->notes ?? '' ); ?></td>
							<td>
								<?php /* inline edit form */ ?>
								<form method="post" style="display:inline;margin-right:6px;">
									<?php wp_nonce_field( 'idiomatticwp_glossary_edit_' . $term->id, '_gl_edit_nonce' ); ?>
									<input type="hidden" name="glossary_action" value="edit">
									<input type="hidden" name="term_id" value="<?php echo (int) $term->id; ?>">
									<input type="hidden" name="glossary_lang" value="<?php echo esc_attr( $targetLangFilter ); ?>">
									<input type="text" name="translated_term" value="<?php echo esc_attr( $term->translatedTerm ); ?>" style="width:100px;" placeholder="<?php esc_attr_e( 'Translation', 'idiomattic-wp' ); ?>">
									<input type="text" name="notes" value="<?php echo esc_attr( $term->notes ?? '' ); ?>" style="width:80px;" placeholder="<?php esc_attr_e( 'Notes', 'idiomattic-wp' ); ?>">
									<label style="font-size:12px;white-space:nowrap;">
										<input type="checkbox" name="forbidden" value="1" <?php checked( $term->forbidden ); ?>>
										<?php esc_html_e( 'Forbidden', 'idiomattic-wp' ); ?>
									</label>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'idiomattic-wp' ); ?></button>
								</form>
								<?php /* delete */ ?>
								<form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Delete this term?', 'idiomattic-wp' ); ?>');">
									<?php wp_nonce_field( 'idiomatticwp_glossary_delete_' . $term->id, '_gl_del_nonce' ); ?>
									<input type="hidden" name="glossary_action" value="delete">
									<input type="hidden" name="term_id" value="<?php echo (int) $term->id; ?>">
									<input type="hidden" name="glossary_lang" value="<?php echo esc_attr( $targetLangFilter ); ?>">
									<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'idiomattic-wp' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<h4 style="margin-top:28px;"><?php esc_html_e( 'Add Term', 'idiomattic-wp' ); ?></h4>
		<form method="post">
			<?php wp_nonce_field( 'idiomatticwp_glossary_add', '_gl_add_nonce' ); ?>
			<input type="hidden" name="glossary_action" value="add">
			<input type="hidden" name="glossary_lang" value="<?php echo esc_attr( $targetLangFilter ); ?>">
			<table class="form-table" style="max-width:700px;"><tbody>
				<tr>
					<th><label for="gl_src"><?php esc_html_e( 'Source Term', 'idiomattic-wp' ); ?></label></th>
					<td><input type="text" id="gl_src" name="glossary_source_term" class="regular-text" required></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Translation', 'idiomattic-wp' ); ?></th>
					<td>
						<input type="text" name="glossary_translated_term" class="regular-text">
						<label style="margin-left:12px;">
							<input type="checkbox" name="glossary_forbidden" value="1">
							<?php esc_html_e( 'Keep unchanged (do not translate)', 'idiomattic-wp' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="gl_notes"><?php esc_html_e( 'Notes', 'idiomattic-wp' ); ?></label></th>
					<td><input type="text" id="gl_notes" name="glossary_notes" class="regular-text"></td>
				</tr>
			</tbody></table>
			<?php submit_button( __( 'Add Term', 'idiomattic-wp' ), 'secondary', 'add_glossary_term', false ); ?>
		</form>
		<?php
	}

	/**
	 * Process glossary CRUD form submissions (add / edit / delete).
	 * Called at the top of renderGlossaryTab() before any output.
	 */
	private function handleGlossaryFormActions(): void {
		$action = sanitize_key( $_POST['glossary_action'] ?? '' );

		if ( ! $action || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$defaultLang      = (string) $this->languageManager->getDefaultLanguage();
		$targetLangFilter = sanitize_key( $_POST['glossary_lang'] ?? '' );

		if ( $action === 'add' ) {
			if ( ! isset( $_POST['_gl_add_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gl_add_nonce'] ) ), 'idiomatticwp_glossary_add' ) ) {
				return;
			}

			$sourceTerm = sanitize_text_field( wp_unslash( $_POST['glossary_source_term'] ?? '' ) );
			if ( ! $sourceTerm || ! $targetLangFilter ) {
				return;
			}

			try {
				$this->glossaryRepo->addTerm(
					$sourceTerm,
					sanitize_text_field( wp_unslash( $_POST['glossary_translated_term'] ?? '' ) ),
					\IdiomatticWP\ValueObjects\LanguageCode::from( $defaultLang ),
					\IdiomatticWP\ValueObjects\LanguageCode::from( $targetLangFilter ),
					! empty( $_POST['glossary_forbidden'] ),
					sanitize_text_field( wp_unslash( $_POST['glossary_notes'] ?? '' ) ) ?: null
				);
			} catch ( \Throwable ) {}

			return;
		}

		$termId = absint( $_POST['term_id'] ?? 0 );
		if ( ! $termId ) {
			return;
		}

		if ( $action === 'edit' ) {
			if ( ! isset( $_POST['_gl_edit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gl_edit_nonce'] ) ), 'idiomatticwp_glossary_edit_' . $termId ) ) {
				return;
			}

			$this->glossaryRepo->updateTerm( $termId, [
				'translated_term' => sanitize_text_field( wp_unslash( $_POST['translated_term'] ?? '' ) ),
				'forbidden'       => ! empty( $_POST['forbidden'] ) ? 1 : 0,
				'notes'           => sanitize_text_field( wp_unslash( $_POST['notes'] ?? '' ) ) ?: null,
			] );
			return;
		}

		if ( $action === 'delete' ) {
			if ( ! isset( $_POST['_gl_del_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gl_del_nonce'] ) ), 'idiomatticwp_glossary_delete_' . $termId ) ) {
				return;
			}

			$this->glossaryRepo->deleteTerm( $termId );
		}
	}

	// ── Tab: Content ──────────────────────────────────────────────────────

	private function renderContentTab(): void {
		$this->maybeHandleContentSave();

		$ptConfig  = get_option( 'idiomatticwp_post_type_config', [] );
		$taxConfig = get_option( 'idiomatticwp_taxonomy_config',  [] );

		$allPostTypes = get_post_types( [ 'public' => true ], 'objects' );
		unset( $allPostTypes['attachment'] );

		// Full Site Editing post types are not public but should still be translatable.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			foreach ( [ 'wp_template', 'wp_template_part', 'wp_navigation' ] as $fseSlug ) {
				if ( ! isset( $allPostTypes[ $fseSlug ] ) ) {
					$ptObj = get_post_type_object( $fseSlug );
					if ( $ptObj ) {
						$allPostTypes[ $fseSlug ] = $ptObj;
					}
				}
			}
		}

		$allTaxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

		// Built-in WordPress core fields — excluded from the custom fields UI
		// because they are intrinsic to every post type and not third-party fields.
		$corePostFields = [ 'post_title', 'post_content', 'post_excerpt' ];

		$elements     = $this->elementRegistry->getElements();
		$customFields = array_filter(
			$elements,
			fn( $el ) => $el['type'] === 'post_meta'
				&& ! in_array( $el['key'], $corePostFields, true )
				&& ( $el['source'] ?? 'manual' ) !== 'core'
		);

		$modeOptions = [
			'translate'        => [
				'label' => __( 'Translatable',        'idiomattic-wp' ),
				'desc'  => __( 'Each language has its own version of this content', 'idiomattic-wp' ),
				'icon'  => '🌐',
				'color' => '#2271b1',
			],
			'show_as_translated' => [
				'label' => __( 'Show as translated',  'idiomattic-wp' ),
				'desc'  => __( 'Shown in secondary languages even without a translation (uses default language content)', 'idiomattic-wp' ),
				'icon'  => '👁',
				'color' => '#00a0d2',
			],
			'ignore'           => [
				'label' => __( 'Not translatable',    'idiomattic-wp' ),
				'desc'  => __( 'Excluded from translation workflows entirely', 'idiomattic-wp' ),
				'icon'  => '⊘',
				'color' => '#646970',
			],
		];

		// ── Legend ────────────────────────────────────────────────────────
		echo '<div class="idiomatticwp-content-legend">';
		foreach ( $modeOptions as $modeKey => $modeData ) {
			printf(
				'<span class="legend-item"><span class="legend-badge" style="background:%s;">%s %s</span><span class="legend-desc">%s</span></span>',
				esc_attr( $modeData['color'] ),
				$modeData['icon'],
				esc_html( $modeData['label'] ),
				esc_html( $modeData['desc'] )
			);
		}
		echo '</div>';

		// ── Post Types ────────────────────────────────────────────────────
		echo '<h3>' . esc_html__( 'Post Types', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Configure how each post type behaves across languages.', 'idiomattic-wp' ) . '</p>';

		$discoveredPostTypes  = $this->elementRegistry->getDiscoveredPostTypes();
		$discoveredTaxonomies = $this->elementRegistry->getDiscoveredTaxonomies();

		echo '<table class="wp-list-table widefat fixed idiomatticwp-config-table">';
		echo '<thead><tr>';
		echo '<th class="col-name">'  . esc_html__( 'Post Type', 'idiomattic-wp' ) . '</th>';
		echo '<th class="col-slug">'  . esc_html__( 'Slug', 'idiomattic-wp' ) . '</th>';
		echo '<th class="col-mode">'  . esc_html__( 'Translation Mode', 'idiomattic-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $allPostTypes as $ptSlug => $ptObj ) {
			$defaultMode = $this->elementRegistry->getPostTypeDefaultMode( $ptSlug );
			$saved       = $ptConfig[ $ptSlug ] ?? $defaultMode;
			$sourceLabel = $discoveredPostTypes[ $ptSlug ]['source'] ?? null;
			echo '<tr>';
			echo '<td class="col-name"><strong>' . esc_html( $ptObj->labels->name ) . '</strong>';
			if ( $sourceLabel ) {
				printf(
					' <abbr class="idiomatticwp-config-source" title="%s" style="cursor:help; text-decoration:none; font-size:11px; color:#2271b1;">&#128196; %s</abbr>',
					esc_attr( sprintf( __( 'Default configured by %s', 'idiomattic-wp' ), $sourceLabel ) ),
					esc_html( $sourceLabel )
				);
			}
			echo '</td>';
			echo '<td class="col-slug"><code>' . esc_html( $ptSlug ) . '</code></td>';
			echo '<td class="col-mode">';
			$this->renderModeSelector( "idiomatticwp_post_type_config[{$ptSlug}]", $saved, $modeOptions, 'pt_' . $ptSlug );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// ── Translate on Publish ──────────────────────────────────────────
		$translateOnPublish = (array) get_option( 'idiomatticwp_translate_on_publish', [] );

		echo '<h3 style="margin-top:32px;">' . esc_html__( 'Translate on Publish', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'When a post is published or updated, automatically queue AI translation into all active languages. Requires a Pro license and a configured AI provider.', 'idiomattic-wp' ) . '</p>';
		echo '<table class="wp-list-table widefat fixed idiomatticwp-config-table">';
		echo '<thead><tr>';
		echo '<th class="col-name">' . esc_html__( 'Post Type', 'idiomattic-wp' ) . '</th>';
		echo '<th class="col-slug">' . esc_html__( 'Slug', 'idiomattic-wp' ) . '</th>';
		echo '<th class="col-mode" style="width:120px;">' . esc_html__( 'Auto-translate', 'idiomattic-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $allPostTypes as $ptSlug => $ptObj ) {
			$checked = in_array( $ptSlug, $translateOnPublish, true );
			echo '<tr>';
			printf( '<td class="col-name"><strong>%s</strong></td>', esc_html( $ptObj->labels->name ) );
			printf( '<td class="col-slug"><code>%s</code></td>', esc_html( $ptSlug ) );
			printf(
				'<td class="col-mode"><label><input type="checkbox" name="idiomatticwp_translate_on_publish[]" value="%s"%s> %s</label></td>',
				esc_attr( $ptSlug ),
				checked( $checked, true, false ),
				esc_html__( 'Enabled', 'idiomattic-wp' )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';

		// ── Taxonomies ────────────────────────────────────────────────────
		echo '<h3 style="margin-top:32px;">' . esc_html__( 'Taxonomies', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Configure how taxonomy terms behave across languages.', 'idiomattic-wp' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed idiomatticwp-config-table">';
		echo '<thead><tr>';
		echo '<th class="col-name">' . esc_html__( 'Taxonomy',    'idiomattic-wp' ) . '</th>';
		echo '<th class="col-slug">' . esc_html__( 'Slug',        'idiomattic-wp' ) . '</th>';
		echo '<th class="col-mode">' . esc_html__( 'Translation Mode', 'idiomattic-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $allTaxonomies as $taxSlug => $taxObj ) {
			$defaultMode = $this->elementRegistry->getTaxonomyDefaultMode( $taxSlug );
			$saved       = $taxConfig[ $taxSlug ] ?? $defaultMode;
			$sourceLabel = $discoveredTaxonomies[ $taxSlug ]['source'] ?? null;
			echo '<tr>';
			echo '<td class="col-name"><strong>' . esc_html( $taxObj->labels->name ) . '</strong>';
			if ( $sourceLabel ) {
				printf(
					' <abbr class="idiomatticwp-config-source" title="%s" style="cursor:help; text-decoration:none; font-size:11px; color:#2271b1;">&#128196; %s</abbr>',
					esc_attr( sprintf( __( 'Default configured by %s', 'idiomattic-wp' ), $sourceLabel ) ),
					esc_html( $sourceLabel )
				);
			}
			echo '</td>';
			echo '<td class="col-slug"><code>' . esc_html( $taxSlug ) . '</code></td>';
			echo '<td class="col-mode">';
			$this->renderModeSelector( "idiomatticwp_taxonomy_config[{$taxSlug}]", $saved, $modeOptions, 'tax_' . $taxSlug );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// ── Custom Fields ────────────────────────────────────────────────
		echo '<h3 style="margin-top:32px;">' . esc_html__( 'Custom Fields', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Configure translation mode for registered custom fields. New fields can be added via the Compatibility tab or programmatically.', 'idiomattic-wp' ) . '</p>';

		if ( empty( $customFields ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No custom fields registered yet. Install a plugin with an idiomattic-elements.json or wpml-config.xml file to register fields automatically.', 'idiomattic-wp' ) . '</p>';
		} else {
			$cfConfig = get_option( 'idiomatticwp_custom_field_config', [] );

			echo '<table class="wp-list-table widefat fixed idiomatticwp-config-table">';
			echo '<thead><tr>';
			echo '<th class="col-name">' . esc_html__( 'Field Label',   'idiomattic-wp' ) . '</th>';
			echo '<th class="col-slug">' . esc_html__( 'Meta Key',      'idiomattic-wp' ) . '</th>';
			echo '<th class="col-pt">'   . esc_html__( 'Post Type(s)',   'idiomattic-wp' ) . '</th>';
			echo '<th class="col-mode">' . esc_html__( 'Translation Mode', 'idiomattic-wp' ) . '</th>';
			echo '</tr></thead><tbody>';

			$seen = [];
			foreach ( $customFields as $el ) {
				$key   = $el['key'];
				$id    = $el['id'];
				if ( in_array( $key, $seen, true ) ) continue;
				$seen[] = $key;

				$saved = $cfConfig[ $key ] ?? ( $el['mode'] ?? 'translate' );
				$pts   = is_array( $el['post_types'] ?? null )
					? implode( ', ', $el['post_types'] )
					: ( $el['post_types'] ?? '*' );

				echo '<tr>';
				printf( '<td class="col-name"><strong>%s</strong></td>', esc_html( $el['label'] ?? $key ) );
				printf( '<td class="col-slug"><code>%s</code></td>', esc_html( $key ) );
				printf( '<td class="col-pt"><small>%s</small></td>', esc_html( $pts ) );
				echo '<td class="col-mode">';
				$this->renderModeSelector( "idiomatticwp_custom_field_config[{$key}]", $saved, $modeOptions, 'cf_' . sanitize_key( $key ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Renders a compact 3-way radio button group for translation mode selection.
	 */
	private function renderModeSelector( string $fieldName, string $currentValue, array $modeOptions, string $idPrefix ): void {
		echo '<div class="idiomatticwp-mode-selector">';
		foreach ( $modeOptions as $modeKey => $modeData ) {
			$radioId = esc_attr( $idPrefix . '_' . $modeKey );
			$checked = checked( $currentValue, $modeKey, false );
			printf(
				'<label class="mode-option mode-%s%s" for="%s" title="%s">
					<input type="radio" id="%s" name="%s" value="%s" %s>
					<span class="mode-icon">%s</span>
					<span class="mode-label">%s</span>
				</label>',
				esc_attr( $modeKey ),
				$currentValue === $modeKey ? ' is-selected' : '',
				$radioId,
				esc_attr( $modeData['desc'] ),
				$radioId,
				esc_attr( $fieldName ),
				esc_attr( $modeKey ),
				$checked,
				$modeData['icon'],
				esc_html( $modeData['label'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Handle saving of content configuration options.
	 *
	 * The Content tab form submits to options.php like all other tabs, so
	 * WordPress Settings API handles persistence automatically once the options
	 * are registered (done in SettingsHooks::registerSettings).
	 *
	 * After saving, clear the setup wizard flag — if the user has reached the
	 * Content tab, they have already configured their languages.
	 */
	private function maybeHandleContentSave(): void {
		// Auto-clear needs_setup when languages are already configured
		$defaultLang = get_option( 'idiomatticwp_default_lang', '' );
		$activeLangs = get_option( 'idiomatticwp_active_langs', [] );
		if ( $defaultLang !== '' && ! empty( $activeLangs ) ) {
			delete_option( 'idiomatticwp_needs_setup' );
		}
	}

	// ── Tab: Menus ────────────────────────────────────────────────────────

	private function renderMenusTab(): void {
		$activeLangs = $this->languageManager->getActiveLanguages();
		$navMenus    = wp_get_nav_menus();

		if ( empty( $activeLangs ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'No active languages configured. Go to the Languages tab first.', 'idiomattic-wp' )
				. '</p></div>';
			return;
		}

		if ( empty( $navMenus ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				esc_html__( 'No navigation menus found. %s', 'idiomattic-wp' ),
				'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Create a menu →', 'idiomattic-wp' ) . '</a>'
			);
			echo '</p></div>';
			return;
		}

		$savedMenus  = (array) get_option( 'idiomatticwp_nav_menus', [] );
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$allLangs    = $this->languageManager->getAllSupportedLanguages();
		?>
		<div class="iwp-card" style="max-width:640px;">
			<table class="iwp-menus-table">
				<tbody>
				<?php foreach ( $activeLangs as $lang ) :
					$code        = (string) $lang;
					$langData    = $allLangs[ $code ] ?? [];
					$savedMenuId = (int) ( $savedMenus[ $code ] ?? 0 );
					$flagUrl     = $this->getFlagUrl( $code, $langData['flag'] ?? '' );
					$isDefault   = $code === $defaultLang;
				?>
					<tr>
						<td style="width:220px;">
							<div class="iwp-menus-lang-cell">
								<?php if ( $flagUrl ) : ?>
									<img src="<?php echo esc_url( $flagUrl ); ?>" alt="" class="iwp-menus-flag">
								<?php else : ?>
									<span class="iwp-flag-fallback" style="width:22px;height:16px;"><?php echo esc_html( strtoupper( substr( $code, 0, 2 ) ) ); ?></span>
								<?php endif; ?>
								<strong><?php echo esc_html( $langData['native_name'] ?? $code ); ?></strong>
								<?php if ( $isDefault ) : ?>
									<span class="idiomatticwp-status-badge idiomatticwp-status-badge--draft" style="font-size:10px;"><?php esc_html_e( 'default', 'idiomattic-wp' ); ?></span>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<select name="idiomatticwp_nav_menus[<?php echo esc_attr( $code ); ?>]" class="iwp-select">
								<option value="0"><?php esc_html_e( '— Not assigned —', 'idiomattic-wp' ); ?></option>
								<?php foreach ( $navMenus as $menu ) : ?>
									<option value="<?php echo (int) $menu->term_id; ?>" <?php selected( $savedMenuId, $menu->term_id ); ?>>
										<?php echo esc_html( $menu->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="description" style="margin-top:12px;">
			<?php printf(
				esc_html__( 'To create or edit navigation menus visit %s.', 'idiomattic-wp' ),
				'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Appearance → Menus', 'idiomattic-wp' ) . '</a>'
			); ?>
		</p>
		<?php
	}

	// ── Tab: Advanced ─────────────────────────────────────────────────────

	private function renderAdvancedTab(): void {
		$retention    = get_option( 'idiomatticwp_uninstall_retention', '1' );
		$cacheEnabled = get_option( 'idiomatticwp_cache_lang_detect', '1' );
		$activeLangs  = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang  = (string) $this->languageManager->getDefaultLanguage();
		?>

		<?php /* ── Data Management ── */ ?>
		<div class="iwp-card iwp-tab-section">
			<h3 class="iwp-tab-section__title"><?php esc_html_e( 'Data Management', 'idiomattic-wp' ); ?></h3>
			<p class="iwp-tab-section__desc"><?php esc_html_e( 'Control how the plugin handles its data.', 'idiomattic-wp' ); ?></p>
			<div class="iwp-form-grid" style="max-width:640px;">
				<div class="iwp-check-field">
					<label class="iwp-check-label">
						<input type="checkbox" name="idiomatticwp_uninstall_retention" value="1" <?php checked( $retention, '1' ); ?>>
						<strong><?php esc_html_e( 'Retain data on uninstall', 'idiomattic-wp' ); ?></strong>
					</label>
					<p class="iwp-check-hint"><?php esc_html_e( 'Keep all translation data when the plugin is deleted. Disable for a clean uninstall.', 'idiomattic-wp' ); ?></p>
				</div>
				<div class="iwp-check-field">
					<label class="iwp-check-label">
						<input type="checkbox" name="idiomatticwp_cache_lang_detect" value="1" <?php checked( $cacheEnabled, '1' ); ?>>
						<strong><?php esc_html_e( 'Language detection cache', 'idiomattic-wp' ); ?></strong>
					</label>
					<p class="iwp-check-hint"><?php esc_html_e( 'Cache language detection results for improved performance.', 'idiomattic-wp' ); ?></p>
				</div>
			</div>
		</div>

		<?php /* ── Export / Import ── */ ?>
		<div class="iwp-card iwp-tab-section">
			<h3 class="iwp-tab-section__title"><?php esc_html_e( 'Export / Import', 'idiomattic-wp' ); ?></h3>
			<p class="iwp-tab-section__desc">
				<?php esc_html_e( 'Export translations as XLIFF files or import from professional CAT tools.', 'idiomattic-wp' ); ?>
			</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-import-export' ) ); ?>" class="iwp-btn iwp-btn--secondary">
				<span class="dashicons dashicons-upload" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
				<?php esc_html_e( 'Go to Import / Export', 'idiomattic-wp' ); ?>
			</a>
		</div>
		<?php

		// ── Notifications ─────────────────────────────────────────────────
		$notifyEnabled = get_option( 'idiomatticwp_notify_outdated', '' );
		$notifyEmail   = get_option( 'idiomatticwp_notify_email', get_option( 'admin_email' ) );
		$notifyMode    = get_option( 'idiomatticwp_notify_mode', 'immediate' );
		?>

		<div class="iwp-card iwp-tab-section">
			<h3 class="iwp-tab-section__title"><?php esc_html_e( 'Email Notifications', 'idiomattic-wp' ); ?></h3>
			<p class="iwp-tab-section__desc"><?php esc_html_e( 'Get notified when translations become outdated.', 'idiomattic-wp' ); ?></p>
			<div class="iwp-form-grid" style="max-width:640px;">
				<div class="iwp-check-field" style="grid-column:1/-1;">
					<label class="iwp-check-label">
						<input type="checkbox" name="idiomatticwp_notify_outdated" value="1" <?php checked( $notifyEnabled, '1' ); ?>>
						<strong><?php esc_html_e( 'Notify on outdated translations', 'idiomattic-wp' ); ?></strong>
					</label>
					<p class="iwp-check-hint"><?php esc_html_e( 'Triggered when you update a post that has translations.', 'idiomattic-wp' ); ?></p>
				</div>
				<div class="iwp-form-field">
					<label class="iwp-field-label" for="idiomatticwp_notify_email"><?php esc_html_e( 'Recipient Email', 'idiomattic-wp' ); ?></label>
					<input type="email" id="idiomatticwp_notify_email" name="idiomatticwp_notify_email"
						   value="<?php echo esc_attr( $notifyEmail ); ?>" class="iwp-input">
				</div>
				<div class="iwp-form-field">
					<span class="iwp-field-label"><?php esc_html_e( 'Notification Mode', 'idiomattic-wp' ); ?></span>
					<label class="iwp-check-label" style="margin-bottom:6px;">
						<input type="radio" name="idiomatticwp_notify_mode" value="immediate" <?php checked( $notifyMode, 'immediate' ); ?>>
						<?php esc_html_e( 'Immediate — one email per event', 'idiomattic-wp' ); ?>
					</label>
					<label class="iwp-check-label">
						<input type="radio" name="idiomatticwp_notify_mode" value="digest" <?php checked( $notifyMode, 'digest' ); ?>>
						<?php esc_html_e( 'Daily digest — one email per day', 'idiomattic-wp' ); ?>
					</label>
				</div>
			</div>
		</div>

		<?php
		// ── Webhooks ─────────────────────────────────────────────────────
		$webhookUrl    = get_option( 'idiomatticwp_webhook_url', '' );
		$webhookSecret = get_option( 'idiomatticwp_webhook_secret', '' );
		$webhookEvents = (array) get_option( 'idiomatticwp_webhook_events', [ 'translation.completed', 'translation.outdated' ] );
		$allEvents     = [
			'translation.completed' => __( 'Translation completed (AI finished)', 'idiomattic-wp' ),
			'translation.outdated'  => __( 'Translation outdated (source post updated)', 'idiomattic-wp' ),
			'translation.queued'    => __( 'Translation queued (job dispatched)', 'idiomattic-wp' ),
		];
		?>

		<div class="iwp-card iwp-tab-section">
			<h3 class="iwp-tab-section__title"><?php esc_html_e( 'Webhooks', 'idiomattic-wp' ); ?></h3>
			<p class="iwp-tab-section__desc"><?php esc_html_e( 'Send HTTP POST notifications to an external URL when translation events occur. Payloads are signed with HMAC-SHA256.', 'idiomattic-wp' ); ?></p>
			<div class="iwp-form-grid" style="max-width:640px;">
				<div class="iwp-form-field">
					<label class="iwp-field-label" for="idiomatticwp_webhook_url"><?php esc_html_e( 'Endpoint URL', 'idiomattic-wp' ); ?></label>
					<input type="url" id="idiomatticwp_webhook_url" name="idiomatticwp_webhook_url"
						   value="<?php echo esc_attr( $webhookUrl ); ?>" class="iwp-input"
						   placeholder="https://your-app.com/webhook">
				</div>
				<div class="iwp-form-field">
					<label class="iwp-field-label" for="idiomatticwp_webhook_secret"><?php esc_html_e( 'Signing Secret', 'idiomattic-wp' ); ?></label>
					<input type="text" id="idiomatticwp_webhook_secret" name="idiomatticwp_webhook_secret"
						   value="<?php echo esc_attr( $webhookSecret ); ?>" class="iwp-input"
						   placeholder="<?php esc_attr_e( 'Optional HMAC-SHA256 secret', 'idiomattic-wp' ); ?>">
				</div>
				<div class="iwp-form-field" style="grid-column:1/-1;">
					<span class="iwp-field-label"><?php esc_html_e( 'Events to Send', 'idiomattic-wp' ); ?></span>
					<?php foreach ( $allEvents as $eventKey => $eventLabel ) : ?>
						<label class="iwp-check-label" style="margin-bottom:6px;">
							<input type="checkbox" name="idiomatticwp_webhook_events[]"
								   value="<?php echo esc_attr( $eventKey ); ?>"
								   <?php checked( in_array( $eventKey, $webhookEvents, true ) ); ?>>
							<?php echo esc_html( $eventLabel ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Tab: Troubleshooting ──────────────────────────────────────────────

	private function renderTroubleshootingTab(): void {
		$this->maybeHandleTroubleshootingActions();

		// ── System Info ───────────────────────────────────────────────────
		echo '<div class="iwp-card iwp-tab-section">';
		echo '<h3 class="iwp-tab-section__title">' . esc_html__( 'System Information', 'idiomattic-wp' ) . '</h3>';

		global $wpdb;
		$info = [
			__( 'Plugin Version',     'idiomattic-wp' ) => IDIOMATTICWP_VERSION,
			__( 'WordPress Version',  'idiomattic-wp' ) => get_bloginfo( 'version' ),
			__( 'PHP Version',        'idiomattic-wp' ) => PHP_VERSION,
			__( 'MySQL Version',      'idiomattic-wp' ) => $wpdb->db_version(),
			__( 'Active Theme',       'idiomattic-wp' ) => wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
			__( 'URL Mode',           'idiomattic-wp' ) => get_option( 'idiomatticwp_url_mode', 'parameter' ),
			__( 'Active Languages',   'idiomattic-wp' ) => implode( ', ', array_map( 'strval', $this->languageManager->getActiveLanguages() ) ) ?: '—',
			__( 'Default Language',   'idiomattic-wp' ) => (string) $this->languageManager->getDefaultLanguage(),
			__( 'License',            'idiomattic-wp' ) => $this->licenseChecker->isPro() ? __( 'Pro', 'idiomattic-wp' ) : __( 'Free', 'idiomattic-wp' ),
			__( 'Permalink Structure','idiomattic-wp' ) => get_option( 'permalink_structure', '— (plain)' ) ?: '— (plain)',
			__( 'Memory Limit',       'idiomattic-wp' ) => ini_get( 'memory_limit' ),
			__( 'Max Execution Time', 'idiomattic-wp' ) => ini_get( 'max_execution_time' ) . 's',
			__( 'WP Debug',           'idiomattic-wp' ) => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'Enabled', 'idiomattic-wp' ) : __( 'Disabled', 'idiomattic-wp' ),
			__( 'WP Debug Log',       'idiomattic-wp' ) => ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? __( 'Enabled', 'idiomattic-wp' ) : __( 'Disabled', 'idiomattic-wp' ),
		];

		// DB table status
		$tables = [
			'idiomatticwp_translations',
			'idiomatticwp_field_translations',
			'idiomatticwp_translation_memory',
			'idiomatticwp_strings',
			'idiomatticwp_glossary',
		];
		foreach ( $tables as $table ) {
			$fullTable = $wpdb->prefix . $table;
			$exists    = $wpdb->get_var( "SHOW TABLES LIKE '{$fullTable}'" ) === $fullTable;
			$info[ sprintf( __( 'Table: %s', 'idiomattic-wp' ), $table ) ] = $exists ? '✅ ' . __( 'Exists', 'idiomattic-wp' ) : '❌ ' . __( 'Missing', 'idiomattic-wp' );
		}

		echo '<table class="iwp-sysinfo-table">';
		foreach ( $info as $label => $value ) {
			printf(
				'<tr><th>%s</th><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}
		echo '</table>';
		echo '</div>';

		// ── Active Plugins ────────────────────────────────────────────────
		echo '<div class="iwp-card iwp-tab-section">';
		echo '<h3 class="iwp-tab-section__title">' . esc_html__( 'Active Plugins', 'idiomattic-wp' ) . '</h3>';
		$activePlugins = get_option( 'active_plugins', [] );
		$pluginData    = [];
		foreach ( $activePlugins as $pluginFile ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $pluginFile, false, false );
			$pluginData[] = ( $data['Name'] ?? $pluginFile ) . ' ' . ( $data['Version'] ?? '' );
		}
		echo '<details style="margin-top:8px;"><summary style="cursor:pointer;user-select:none;">' . sprintf( esc_html__( '%d active plugins — click to expand', 'idiomattic-wp' ), count( $pluginData ) ) . '</summary>';
		echo '<ul style="margin-top:8px;column-count:2;column-gap:20px;">';
		foreach ( $pluginData as $p ) {
			echo '<li style="font-size:12px;margin-bottom:3px;">' . esc_html( $p ) . '</li>';
		}
		echo '</ul></details>';
		echo '</div>';

		// ── Cache & Transients ────────────────────────────────────────────
		echo '<div class="iwp-card iwp-tab-section">';
		echo '<h3 class="iwp-tab-section__title">' . esc_html__( 'Cache & Transients', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Clear cached data if you are seeing stale content or unexpected behaviour.', 'idiomattic-wp' ) . '</p>';

		$clearUrl = wp_nonce_url(
			admin_url( 'admin.php?page=idiomatticwp-settings&tab=troubleshooting&ts_action=clear_transients' ),
			'idiomatticwp_ts_clear_transients'
		);
		echo '<p><a href="' . esc_url( $clearUrl ) . '" class="button button-secondary">' . esc_html__( '🗑 Clear Idiomattic Transients', 'idiomattic-wp' ) . '</a></p>';
		echo '</div>';

		// ── Rewrite Rules ─────────────────────────────────────────────────
		echo '<div class="iwp-card iwp-tab-section">';
		echo '<h3 class="iwp-tab-section__title">' . esc_html__( 'Rewrite Rules', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'If language URLs are returning 404 errors, try flushing rewrite rules.', 'idiomattic-wp' ) . '</p>';

		$flushUrl = wp_nonce_url(
			admin_url( 'admin.php?page=idiomatticwp-settings&tab=troubleshooting&ts_action=flush_rewrites' ),
			'idiomatticwp_ts_flush_rewrites'
		);
		echo '<p><a href="' . esc_url( $flushUrl ) . '" class="button button-secondary">' . esc_html__( '🔁 Flush Rewrite Rules', 'idiomattic-wp' ) . '</a></p>';
		echo '</div>';

		// ── Debug Log ─────────────────────────────────────────────────────
		echo '<div class="iwp-card iwp-tab-section">';
		echo '<h3 class="iwp-tab-section__title">' . esc_html__( 'Debug Log', 'idiomattic-wp' ) . '</h3>';

		$debugEnabled = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		if ( ! $debugEnabled ) {
			echo '<div class="notice notice-info inline" style="margin:8px 0;"><p>';
			esc_html_e( 'WP_DEBUG and WP_DEBUG_LOG are not both enabled. Add the following to wp-config.php to activate debug logging:', 'idiomattic-wp' );
			echo '</p>';
			echo '<pre style="background:#f6f7f7;padding:10px;border-left:3px solid #2271b1;font-size:12px;overflow:auto;">define( \'WP_DEBUG\', true );
define( \'WP_DEBUG_LOG\', true );
define( \'WP_DEBUG_DISPLAY\', false );</pre>';
			echo '</div>';
		}

		// Show last 50 lines of debug.log if it exists
		$logFile = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $logFile ) && is_readable( $logFile ) ) {
			$logSize  = filesize( $logFile );
			$logLines = $this->tailFile( $logFile, 80 );
			$filtered = array_filter( $logLines, fn( $l ) => stripos( $l, 'idiomattic' ) !== false );

			echo '<p style="color:#646970;font-size:12px;">' . sprintf(
				esc_html__( 'debug.log size: %s — showing last 80 lines filtered for "idiomattic"', 'idiomattic-wp' ),
				esc_html( size_format( $logSize ) )
			) . '</p>';

			if ( empty( $filtered ) ) {
				echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No Idiomattic-related entries in the recent log.', 'idiomattic-wp' ) . '</p>';
			} else {
				echo '<textarea readonly class="iwp-log-area" spellcheck="false">';
				echo esc_textarea( implode( "\n", array_values( $filtered ) ) );
				echo '</textarea>';
			}
		} else {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'debug.log not found or not readable.', 'idiomattic-wp' ) . '</p>';
		}
		echo '</div>';

		// ── Copy-to-clipboard system report ──────────────────────────────
		echo '<div class="iwp-card iwp-tab-section">';
		echo '<h3 class="iwp-tab-section__title">' . esc_html__( 'System Report', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Copy this report and paste it when requesting support.', 'idiomattic-wp' ) . '</p>';

		$report = $this->buildSystemReport( $info, $activePlugins );
		echo '<textarea id="idiomatticwp-sys-report" readonly class="iwp-log-area" style="background:#f6f7f7;color:#1d2327;height:180px;" spellcheck="false">' . esc_textarea( $report ) . '</textarea>';
		echo '<p style="margin-top:12px;"><button type="button" class="iwp-btn iwp-btn--secondary" onclick="(function(){var t=document.getElementById(\'idiomatticwp-sys-report\');t.select();document.execCommand(\'copy\');})();">' . esc_html__( 'Copy to clipboard', 'idiomattic-wp' ) . '</button></p>';
		echo '</div>';
	}

	private function maybeHandleTroubleshootingActions(): void {
		$action = sanitize_key( $_GET['ts_action'] ?? '' );
		if ( ! $action ) return;

		switch ( $action ) {
			case 'clear_transients':
				if ( ! check_admin_referer( 'idiomatticwp_ts_clear_transients' ) ) break;
				global $wpdb;
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
						'_transient_idiomatticwp%',
						'_transient_timeout_idiomatticwp%'
					)
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
					esc_html__( '✅ Cleared %d Idiomattic transients.', 'idiomattic-wp' ),
					(int) $deleted
				) . '</p></div>';
				break;

			case 'flush_rewrites':
				if ( ! check_admin_referer( 'idiomatticwp_ts_flush_rewrites' ) ) break;
				flush_rewrite_rules( false );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '✅ Rewrite rules flushed.', 'idiomattic-wp' ) . '</p></div>';
				break;
		}
	}

	/**
	 * Read the last $lines lines from a file without loading it all into memory.
	 */
	private function tailFile( string $path, int $lines = 50 ): array {
		$file = new \SplFileObject( $path, 'r' );
		$file->seek( PHP_INT_MAX );
		$total = $file->key();
		$start = max( 0, $total - $lines );
		$file->seek( $start );
		$result = [];
		while ( ! $file->eof() ) {
			$line = rtrim( (string) $file->fgets() );
			if ( $line !== '' ) $result[] = $line;
		}
		return $result;
	}

	private function buildSystemReport( array $info, array $activePlugins ): string {
		$lines = [ '=== Idiomattic WP System Report ===' ];
		$lines[] = 'Generated: ' . current_time( 'c' );
		$lines[] = '';
		$lines[] = '-- System Info --';
		foreach ( $info as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		$lines[] = '';
		$lines[] = '-- Active Plugins (' . count( $activePlugins ) . ') --';
		foreach ( $activePlugins as $plugin ) {
			$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
			$lines[] = ( $data['Name'] ?? $plugin ) . ' ' . ( $data['Version'] ?? '' );
		}
		return implode( "\n", $lines );
	}

	// ── Inline styles ─────────────────────────────────────────────────────

	private function renderInlineStyles(): void {
		?>
		<style>
		/* ── Languages page: reset WP form margin so cards sit flush ── */
		.iwp-languages-page {
			margin-top: 4px;
		}
		/* Suppress WP's default h2/h3 tab-nav bottom border on languages tab */
		.iwp-section-title {
			border-bottom: none !important;
		}

		/* ── Provider cards ── */
		.idiomatticwp-provider-cards {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			margin: 16px 0 24px;
		}
		.idiomatticwp-provider-card {
			display: flex;
			flex-direction: column;
			gap: 6px;
			padding: 14px 18px;
			border: 2px solid #dcdcde;
			border-radius: 6px;
			background: #fff;
			cursor: pointer;
			min-width: 150px;
			transition: border-color .15s;
		}
		.idiomatticwp-provider-card:hover { border-color: #8c8f94; }
		.idiomatticwp-provider-card.is-active { border-color: #2271b1; background: #f0f6fc; }
		.idiomatticwp-provider-card input[type="radio"] { display: none; }
		.idiomatticwp-provider-card .card-name { font-weight: 600; font-size: 13px; color: #1d2327; }
		.idiomatticwp-provider-card .card-status { font-size: 11px; padding: 1px 7px; border-radius: 10px; width: fit-content; }
		.idiomatticwp-provider-card .card-status.is-configured  { background:#edfaef;color:#1a8a24;border:1px solid #c3e6c6; }
		.idiomatticwp-provider-card .card-status.not-configured { background:#f6f7f7;color:#646970;border:1px solid #dcdcde; }
		.idiomatticwp-provider-fields { padding:16px 18px;background:#f9f9f9;border:1px solid #e2e4e7;border-radius:4px;margin-bottom:8px; }

		/* ── Content tab ── */
		.idiomatticwp-content-legend {
			display: flex;
			flex-wrap: wrap;
			gap: 16px;
			margin: 16px 0 24px;
			padding: 14px 18px;
			background: #f9f9f9;
			border: 1px solid #e2e4e7;
			border-radius: 4px;
		}
		.legend-item { display: flex; align-items: center; gap: 8px; }
		.legend-badge {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 3px 10px;
			border-radius: 12px;
			font-size: 11px;
			font-weight: 600;
			color: #fff;
			white-space: nowrap;
		}
		.legend-desc { font-size: 12px; color: #646970; }

		.idiomatticwp-config-table { max-width: 960px; margin-top: 12px !important; }
		.idiomatticwp-config-table .col-name { width: 200px; }
		.idiomatticwp-config-table .col-slug { width: 160px; }
		.idiomatticwp-config-table .col-pt   { width: 180px; }
		.idiomatticwp-config-table .col-mode { }
		.idiomatticwp-config-table code { font-size: 11px; background: #f0f0f1; padding: 1px 5px; border-radius: 3px; }

		/* Mode selector — inline radio button group */
		.idiomatticwp-mode-selector {
			display: inline-flex;
			gap: 0;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			overflow: hidden;
			background: #fff;
		}
		.mode-option {
			display: flex;
			align-items: center;
			gap: 5px;
			padding: 5px 11px;
			cursor: pointer;
			font-size: 12px;
			border-right: 1px solid #c3c4c7;
			background: #fff;
			color: #3c434a;
			transition: background .12s, color .12s;
			white-space: nowrap;
		}
		.mode-option:last-child { border-right: none; }
		.mode-option:hover { background: #f6f7f7; }
		.mode-option input[type="radio"] { display: none; }
		.mode-icon { font-size: 13px; line-height: 1; }

		.mode-option.mode-translate.is-selected     { background: #2271b1; color: #fff; }
		.mode-option.mode-show_as_translated.is-selected { background: #00a0d2; color: #fff; }
		.mode-option.mode-ignore.is-selected        { background: #646970; color: #fff; }

		/* Mode selector live update via JS */

		/* ── Troubleshooting tab ── */
		.idiomatticwp-ts-section {
			background: #fff;
			border: 1px solid #e2e4e7;
			border-radius: 4px;
			padding: 18px 22px;
			margin-bottom: 20px;
			max-width: 960px;
		}
		.idiomatticwp-ts-section h4 {
			margin: 0 0 10px;
			font-size: 14px;
			font-weight: 600;
			color: #1d2327;
		}
		.idiomatticwp-info-table td { font-size: 13px; padding: 5px 10px !important; }
		</style>
		<script>
		// Mode selector: update is-selected class on radio change
		document.addEventListener('DOMContentLoaded', function () {
			document.querySelectorAll('.idiomatticwp-mode-selector').forEach(function (selector) {
				selector.querySelectorAll('input[type="radio"]').forEach(function (radio) {
					radio.addEventListener('change', function () {
						selector.querySelectorAll('.mode-option').forEach(function (opt) {
							opt.classList.remove('is-selected');
						});
						radio.closest('.mode-option').classList.add('is-selected');
					});
				});
			});
		});
		</script>
		<?php
	}
}
