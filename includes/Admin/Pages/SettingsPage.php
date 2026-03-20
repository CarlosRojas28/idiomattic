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
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Providers\ProviderRegistry;
use IdiomatticWP\Support\EncryptionService;
use IdiomatticWP\Core\CustomElementRegistry;

class SettingsPage {

	public function __construct(
		private LanguageManager       $languageManager,
		private LicenseChecker        $licenseChecker,
		private ProviderRegistry      $providerRegistry,
		private EncryptionService     $encryption,
		private CustomElementRegistry $elementRegistry,
	) {}

	// ── Entry point ───────────────────────────────────────────────────────

	public function render(): void {
		$currentTab = sanitize_key( $_GET['tab'] ?? 'languages' );

		$tabs = [
			'languages'       => __( 'Languages',       'idiomattic-wp' ),
			'url'             => __( 'URL Structure',    'idiomattic-wp' ),
			'translation'     => __( 'Translation',      'idiomattic-wp' ),
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
					settings_fields( 'idiomatticwp_settings' );
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
			case 'advanced':        $this->renderAdvancedTab();        break;
			case 'troubleshooting': $this->renderTroubleshootingTab(); break;
		}
	}

	// ── Tab: Languages ────────────────────────────────────────────────────

	private function renderLanguagesTab(): void {
		$allLangs    = $this->languageManager->getAllSupportedLanguages();
		$activeLangs = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();

		echo '<h3>' . esc_html__( 'Active Languages', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Select which languages you want to translate your content into.', 'idiomattic-wp' ) . '</p>';

		echo '<div class="idiomatticwp-language-selector">';
		foreach ( $allLangs as $code => $data ) {
			$checked = in_array( (string) $code, $activeLangs, true ) ? 'checked' : '';
			printf(
				'<label>
					<input type="checkbox" name="idiomatticwp_active_langs[]" value="%s" %s>
					<span>%s <em>(%s)</em></span>
				</label>',
				esc_attr( (string) $code ),
				$checked,
				esc_html( $data['native_name'] ),
				esc_html( $data['name'] )
			);
		}
		echo '</div>';

		echo '<h3>' . esc_html__( 'Default Language', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'The language your content is originally written in.', 'idiomattic-wp' ) . '</p>';
		echo '<select name="idiomatticwp_default_lang" style="margin-top:6px;">';
		foreach ( $allLangs as $code => $data ) {
			if ( ! in_array( (string) $code, $activeLangs, true ) ) continue;
			$selected = ( (string) $code === $defaultLang ) ? 'selected' : '';
			printf(
				'<option value="%s" %s>%s (%s)</option>',
				esc_attr( (string) $code ),
				$selected,
				esc_html( $data['native_name'] ),
				esc_html( $data['name'] )
			);
		}
		echo '</select>';
	}

	// ── Tab: URL Structure ────────────────────────────────────────────────

	private function renderUrlTab(): void {
		$mode            = get_option( 'idiomatticwp_url_mode', 'parameter' );
		$isPro           = $this->licenseChecker->isPro();
		$plainPermalinks = get_option( 'permalink_structure', '' ) === '';

		echo '<h3>' . esc_html__( 'URL Structure', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Choose how the language is represented in the URL. Changes take effect immediately.', 'idiomattic-wp' ) . '</p>';

		if ( $plainPermalinks ) {
			echo '<div class="notice notice-warning inline" style="margin:12px 0;"><p>';
			printf(
				esc_html__( 'Directory and Subdomain modes require pretty permalinks. %s', 'idiomattic-wp' ),
				'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Configure permalinks →', 'idiomattic-wp' ) . '</a>'
			);
			echo '</p></div>';
		}

		$options = [
			'parameter' => [
				'label'       => __( 'Query Parameter', 'idiomattic-wp' ),
				'example'     => 'example.com/my-post/?lang=es',
				'description' => __( 'Works with any permalink structure. Easiest to set up.', 'idiomattic-wp' ),
				'pro'         => false,
			],
			'directory' => [
				'label'       => __( 'Directory', 'idiomattic-wp' ),
				'example'     => 'example.com/es/my-post/',
				'description' => __( 'Clean URLs with the language code as a path prefix. Requires pretty permalinks.', 'idiomattic-wp' ),
				'pro'         => true,
			],
			'subdomain' => [
				'label'       => __( 'Subdomain', 'idiomattic-wp' ),
				'example'     => 'es.example.com/my-post/',
				'description' => __( 'Each language on its own subdomain. Requires wildcard DNS and SSL.', 'idiomattic-wp' ),
				'pro'         => true,
			],
		];

		echo '<table class="form-table" style="max-width:700px;"><tbody>';
		foreach ( $options as $val => $opt ) {
			$isProLocked  = $opt['pro'] && ! $isPro;
			$isPlainLocked = $opt['pro'] && $plainPermalinks;
			$disabled = ( $isProLocked || $isPlainLocked ) ? 'disabled' : '';
			$checked  = ( $mode === $val ) ? 'checked' : '';
			$badge    = $isProLocked
				? ' <span style="background:#e07b00;color:#fff;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;margin-left:6px;">PRO</span>'
				: '';

			printf(
				'<tr style="%s">
					<th scope="row" style="padding:10px 0;width:20px;">
						<input type="radio" name="idiomatticwp_url_mode" value="%s" id="url_mode_%s" %s %s>
					</th>
					<td style="padding:10px 0 10px 8px;">
						<label for="url_mode_%s" style="cursor:%s;">
							<strong>%s</strong>%s
							<code style="margin-left:10px;background:#f0f0f1;padding:2px 8px;border-radius:3px;font-size:12px;">%s</code>
						</label>
						<p class="description" style="margin:3px 0 0 0;">%s</p>
					</td>
				</tr>',
				$isProLocked ? 'opacity:.6;' : '',
				esc_attr( $val ),
				esc_attr( $val ),
				$checked,
				$disabled,
				esc_attr( $val ),
				$isProLocked ? 'not-allowed' : 'pointer',
				esc_html( $opt['label'] ),
				$badge,
				esc_html( $opt['example'] ),
				esc_html( $opt['description'] )
			);
		}
		echo '</tbody></table>';

		if ( ! $isPro ) {
			echo '<p style="margin-top:16px;"><a href="' . esc_url( idiomatticwp_upgrade_url( 'url-mode' ) ) . '" target="_blank" class="button button-secondary">';
			esc_html_e( 'Upgrade to Pro to unlock Directory and Subdomain modes →', 'idiomattic-wp' );
			echo '</a></p>';
		}
	}

	// ── Tab: Translation ──────────────────────────────────────────────────

	private function renderTranslationTab(): void {
		if ( ! $this->licenseChecker->isPro() ) {
			echo '<div class="notice notice-info inline" style="margin:20px 0 0;"><p>';
			printf(
				esc_html__( 'Automated translations and AI provider configuration are available in the %s version.', 'idiomattic-wp' ),
				'<strong>Pro</strong>'
			);
			echo '</p></div>';
			echo '<p style="margin-top:16px;"><a href="' . esc_url( idiomatticwp_upgrade_url( 'translation-tab' ) ) . '" target="_blank" class="button button-primary">';
			esc_html_e( 'Upgrade to Pro →', 'idiomattic-wp' );
			echo '</a></p>';
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
			echo '<div class="notice notice-info inline" style="margin:20px 0 0;"><p>';
			printf( esc_html__( 'Glossary management is available in the %s version.', 'idiomattic-wp' ), '<strong>Pro</strong>' );
			echo '</p></div>';
			echo '<p style="margin-top:16px;"><a href="' . esc_url( idiomatticwp_upgrade_url( 'glossary-tab' ) ) . '" target="_blank" class="button button-primary">';
			esc_html_e( 'Upgrade to Pro →', 'idiomattic-wp' );
			echo '</a></p>';
			return;
		}

		$defaultLang      = (string) $this->languageManager->getDefaultLanguage();
		$activeLangs      = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$targetLangFilter = sanitize_key( $_GET['glossary_lang'] ?? ( $activeLangs[1] ?? '' ) );

		echo '<h3>' . esc_html__( 'Glossary', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Define terms that must always be translated a specific way, or kept unchanged by the AI.', 'idiomattic-wp' ) . '</p>';

		echo '<p><label>' . esc_html__( 'Show terms for:', 'idiomattic-wp' ) . ' <select id="glossary-lang-filter" onchange="window.location.search+=\'&glossary_lang=\'+this.value">';
		foreach ( $activeLangs as $lang ) {
			if ( $lang === $defaultLang ) continue;
			printf( '<option value="%s" %s>%s → %s</option>', esc_attr( $lang ), selected( $targetLangFilter, $lang, false ), esc_html( $defaultLang ), esc_html( $lang ) );
		}
		echo '</select></label></p>';

		global $wpdb;
		$table = $wpdb->prefix . 'idiomatticwp_glossary';
		$terms = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE source_lang = %s AND target_lang = %s ORDER BY source_term ASC",
			$defaultLang, $targetLangFilter
		), ARRAY_A ) ?: [];

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;max-width:900px;">';
		echo '<thead><tr><th>' . esc_html__( 'Source Term', 'idiomattic-wp' ) . '</th><th>' . esc_html__( 'Translation', 'idiomattic-wp' ) . '</th><th>' . esc_html__( 'Type', 'idiomattic-wp' ) . '</th><th>' . esc_html__( 'Notes', 'idiomattic-wp' ) . '</th><th>' . esc_html__( 'Actions', 'idiomattic-wp' ) . '</th></tr></thead><tbody>';

		if ( empty( $terms ) ) {
			echo '<tr><td colspan="5"><em>' . esc_html__( 'No glossary terms yet.', 'idiomattic-wp' ) . '</em></td></tr>';
		} else {
			foreach ( $terms as $term ) {
				$deleteUrl = wp_nonce_url(
					admin_url( 'admin.php?page=idiomatticwp-settings&tab=glossary&glossary_lang=' . $targetLangFilter . '&delete_term=' . $term['id'] ),
					'idiomatticwp_delete_term_' . $term['id']
				);
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">' . esc_html__( 'Delete', 'idiomattic-wp' ) . '</a></td></tr>',
					esc_html( $term['source_term'] ),
					esc_html( $term['translated_term'] ),
					$term['forbidden'] ? esc_html__( 'Keep unchanged', 'idiomattic-wp' ) : esc_html__( 'Translate as', 'idiomattic-wp' ),
					esc_html( $term['notes'] ?? '' ),
					esc_url( $deleteUrl ),
					esc_js( __( 'Delete this term?', 'idiomattic-wp' ) )
				);
			}
		}
		echo '</tbody></table>';

		if ( ! empty( $_GET['delete_term'] ) ) {
			$termId = absint( $_GET['delete_term'] );
			if ( check_admin_referer( 'idiomatticwp_delete_term_' . $termId ) ) {
				$wpdb->delete( $table, [ 'id' => $termId ], [ '%d' ] );
				echo '<div class="notice notice-success inline" style="margin-top:12px;"><p>' . esc_html__( 'Term deleted.', 'idiomattic-wp' ) . '</p></div>';
			}
		}

		echo '<h4 style="margin-top:28px;">' . esc_html__( 'Add Term', 'idiomattic-wp' ) . '</h4>';
		$addNonce = wp_nonce_field( 'idiomatticwp_add_glossary_term', '_wpnonce', true, false );

		if ( ! empty( $_POST['glossary_source_term'] ) && check_admin_referer( 'idiomatticwp_add_glossary_term' ) ) {
			$wpdb->insert( $table, [
				'source_lang'     => $defaultLang,
				'target_lang'     => $targetLangFilter,
				'source_term'     => sanitize_text_field( wp_unslash( $_POST['glossary_source_term'] ) ),
				'translated_term' => sanitize_text_field( wp_unslash( $_POST['glossary_translated_term'] ?? '' ) ),
				'forbidden'       => ! empty( $_POST['glossary_forbidden'] ) ? 1 : 0,
				'notes'           => sanitize_text_field( wp_unslash( $_POST['glossary_notes'] ?? '' ) ),
			] );
			echo '<div class="notice notice-success inline" style="margin-top:8px;"><p>' . esc_html__( 'Term added.', 'idiomattic-wp' ) . '</p></div>';
		}

		echo '<table class="form-table" style="max-width:700px;"><tbody>';
		echo '<tr><th>' . esc_html__( 'Source Term', 'idiomattic-wp' ) . '</th><td><input type="text" name="glossary_source_term" class="regular-text" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Translation', 'idiomattic-wp' ) . '</th><td><input type="text" name="glossary_translated_term" class="regular-text"> <label style="margin-left:12px;"><input type="checkbox" name="glossary_forbidden" value="1"> ' . esc_html__( 'Keep unchanged (do not translate)', 'idiomattic-wp' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Notes', 'idiomattic-wp' ) . '</th><td><input type="text" name="glossary_notes" class="regular-text"></td></tr>';
		echo '</tbody></table>';
		echo $addNonce;
		echo '<input type="hidden" name="glossary_target_lang" value="' . esc_attr( $targetLangFilter ) . '">';
		echo '<p>' . get_submit_button( __( 'Add Term', 'idiomattic-wp' ), 'secondary', 'add_glossary_term', false ) . '</p>';
	}

	// ── Tab: Content ──────────────────────────────────────────────────────

	private function renderContentTab(): void {
		$this->maybeHandleContentSave();

		$ptConfig  = get_option( 'idiomatticwp_post_type_config', [] );
		$taxConfig = get_option( 'idiomatticwp_taxonomy_config',  [] );

		$allPostTypes = get_post_types( [ 'public' => true ], 'objects' );
		unset( $allPostTypes['attachment'] );

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

		echo '<table class="wp-list-table widefat fixed idiomatticwp-config-table">';
		echo '<thead><tr>';
		echo '<th class="col-name">'  . esc_html__( 'Post Type', 'idiomattic-wp' ) . '</th>';
		echo '<th class="col-slug">'  . esc_html__( 'Slug', 'idiomattic-wp' ) . '</th>';
		echo '<th class="col-mode">'  . esc_html__( 'Translation Mode', 'idiomattic-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $allPostTypes as $ptSlug => $ptObj ) {
			$saved = $ptConfig[ $ptSlug ] ?? 'translate';
			echo '<tr>';
			echo '<td class="col-name"><strong>' . esc_html( $ptObj->labels->name ) . '</strong></td>';
			echo '<td class="col-slug"><code>' . esc_html( $ptSlug ) . '</code></td>';
			echo '<td class="col-mode">';
			$this->renderModeSelector( "idiomatticwp_post_type_config[{$ptSlug}]", $saved, $modeOptions, 'pt_' . $ptSlug );
			echo '</td></tr>';
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
			$saved = $taxConfig[ $taxSlug ] ?? 'translate';
			echo '<tr>';
			echo '<td class="col-name"><strong>' . esc_html( $taxObj->labels->name ) . '</strong></td>';
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

	// ── Tab: Advanced ─────────────────────────────────────────────────────

	private function renderAdvancedTab(): void {
		$retention    = get_option( 'idiomatticwp_uninstall_retention', '1' );
		$cacheEnabled = get_option( 'idiomatticwp_cache_lang_detect', '1' );
		$activeLangs  = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang  = (string) $this->languageManager->getDefaultLanguage();

		echo '<h3>' . esc_html__( 'Data Management', 'idiomattic-wp' ) . '</h3>';
		echo '<table class="form-table" style="max-width:680px;"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Retain data on uninstall', 'idiomattic-wp' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="idiomatticwp_uninstall_retention" value="1" %s> %s</label><p class="description">%s</p>',
			checked( $retention, '1', false ),
			esc_html__( 'Keep all translation data when the plugin is deleted', 'idiomattic-wp' ),
			esc_html__( 'Disable to perform a clean uninstall that removes all plugin tables and settings.', 'idiomattic-wp' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Language detection cache', 'idiomattic-wp' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="idiomatticwp_cache_lang_detect" value="1" %s> %s</label>',
			checked( $cacheEnabled, '1', false ),
			esc_html__( 'Cache language detection results for improved performance', 'idiomattic-wp' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Export / Import', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Download all translations as XLIFF files for use with professional CAT tools.', 'idiomattic-wp' ) . '</p>';
		echo '<p>';
		foreach ( $activeLangs as $lang ) {
			if ( $lang === $defaultLang ) continue;
			$exportUrl = wp_nonce_url(
				admin_url( 'admin.php?page=idiomatticwp-settings&tab=advanced&export_lang=' . $lang ),
				'idiomatticwp_export_' . $lang
			);
			printf(
				'<a href="%s" class="button button-secondary" style="margin-right:8px;">%s</a>',
				esc_url( $exportUrl ),
				sprintf( esc_html__( 'Export %s (XLIFF)', 'idiomattic-wp' ), strtoupper( $lang ) )
			);
		}
		echo '</p>';

		if ( ! empty( $_GET['export_lang'] ) ) {
			$exportLangStr = sanitize_key( $_GET['export_lang'] );
			if ( check_admin_referer( 'idiomatticwp_export_' . $exportLangStr ) ) {
				try {
					$exportLang = \IdiomatticWP\ValueObjects\LanguageCode::from( $exportLangStr );
					$exporter   = \IdiomatticWP\Core\Plugin::getInstance()->getContainer()->get( \IdiomatticWP\ImportExport\Exporter::class );
					$exporter->downloadZip( $exportLang );
				} catch ( \Throwable $e ) {
					echo '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
				}
			}
		}
	}

	// ── Tab: Troubleshooting ──────────────────────────────────────────────

	private function renderTroubleshootingTab(): void {
		$this->maybeHandleTroubleshootingActions();

		echo '<h3>' . esc_html__( 'Troubleshooting', 'idiomattic-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Tools to help identify and resolve issues with Idiomattic WP.', 'idiomattic-wp' ) . '</p>';

		// ── System Info ───────────────────────────────────────────────────
		echo '<div class="idiomatticwp-ts-section">';
		echo '<h4>' . esc_html__( '🖥 System Information', 'idiomattic-wp' ) . '</h4>';

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

		echo '<table class="widefat striped idiomatticwp-info-table" style="max-width:700px;">';
		echo '<tbody>';
		foreach ( $info as $label => $value ) {
			printf(
				'<tr><td style="width:220px;font-weight:500;">%s</td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}
		echo '</tbody></table>';
		echo '</div>';

		// ── Active Plugins ────────────────────────────────────────────────
		echo '<div class="idiomatticwp-ts-section">';
		echo '<h4>' . esc_html__( '🔌 Active Plugins', 'idiomattic-wp' ) . '</h4>';
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
		echo '<div class="idiomatticwp-ts-section">';
		echo '<h4>' . esc_html__( '🗑 Cache & Transients', 'idiomattic-wp' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Clear cached data if you are seeing stale content or unexpected behaviour.', 'idiomattic-wp' ) . '</p>';

		$clearUrl = wp_nonce_url(
			admin_url( 'admin.php?page=idiomatticwp-settings&tab=troubleshooting&ts_action=clear_transients' ),
			'idiomatticwp_ts_clear_transients'
		);
		echo '<p><a href="' . esc_url( $clearUrl ) . '" class="button button-secondary">' . esc_html__( '🗑 Clear Idiomattic Transients', 'idiomattic-wp' ) . '</a></p>';
		echo '</div>';

		// ── Rewrite Rules ─────────────────────────────────────────────────
		echo '<div class="idiomatticwp-ts-section">';
		echo '<h4>' . esc_html__( '🔁 Rewrite Rules', 'idiomattic-wp' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'If language URLs are returning 404 errors, try flushing rewrite rules.', 'idiomattic-wp' ) . '</p>';

		$flushUrl = wp_nonce_url(
			admin_url( 'admin.php?page=idiomatticwp-settings&tab=troubleshooting&ts_action=flush_rewrites' ),
			'idiomatticwp_ts_flush_rewrites'
		);
		echo '<p><a href="' . esc_url( $flushUrl ) . '" class="button button-secondary">' . esc_html__( '🔁 Flush Rewrite Rules', 'idiomattic-wp' ) . '</a></p>';
		echo '</div>';

		// ── Debug Log ─────────────────────────────────────────────────────
		echo '<div class="idiomatticwp-ts-section">';
		echo '<h4>' . esc_html__( '📋 Debug Log', 'idiomattic-wp' ) . '</h4>';

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
				echo '<textarea readonly style="width:100%;max-width:900px;height:220px;font-family:monospace;font-size:11px;background:#1e1e2e;color:#cdd6f4;padding:10px;border:1px solid #444;border-radius:4px;" spellcheck="false">';
				echo esc_textarea( implode( "\n", array_values( $filtered ) ) );
				echo '</textarea>';
			}
		} else {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'debug.log not found or not readable.', 'idiomattic-wp' ) . '</p>';
		}
		echo '</div>';

		// ── Copy-to-clipboard system report ──────────────────────────────
		echo '<div class="idiomatticwp-ts-section">';
		echo '<h4>' . esc_html__( '📎 System Report', 'idiomattic-wp' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Copy this report and paste it when requesting support.', 'idiomattic-wp' ) . '</p>';

		$report = $this->buildSystemReport( $info, $activePlugins );
		echo '<textarea id="idiomatticwp-sys-report" readonly style="width:100%;max-width:900px;height:180px;font-family:monospace;font-size:11px;background:#f6f7f7;padding:10px;" spellcheck="false">' . esc_textarea( $report ) . '</textarea>';
		echo '<p><button type="button" class="button button-secondary" onclick="(function(){var t=document.getElementById(\'idiomatticwp-sys-report\');t.select();document.execCommand(\'copy\');})();">' . esc_html__( '📋 Copy to clipboard', 'idiomattic-wp' ) . '</button></p>';
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
		/* ── Language selector ── */
		.idiomatticwp-language-selector {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
			gap: 8px;
			margin: 16px 0;
			max-height: 400px;
			overflow-y: auto;
			padding: 14px;
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 3px;
		}
		.idiomatticwp-language-selector label {
			display: flex;
			align-items: center;
			gap: 7px;
			cursor: pointer;
			padding: 3px 5px;
			border-radius: 3px;
		}
		.idiomatticwp-language-selector label:hover { background: #f6f7f7; }
		.idiomatticwp-language-selector input { margin: 0; flex-shrink: 0; }
		.idiomatticwp-language-selector em { color: #646970; font-size: 12px; }

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
