<?php
/**
 * StringTranslationPage — translate plugin and theme strings into all active
 * languages from a single table view.
 *
 * Columns: Original string | Lang 1 | Lang 2 | … | Lang N
 * Each translation cell has a "copy source" button that fills the textarea
 * with the original string. A "copy to all" button on the source column
 * fills every empty translation in that row at once.
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Repositories\StringRepository;
use IdiomatticWP\Strings\MoCompiler;
use IdiomatticWP\ValueObjects\LanguageCode;

class StringTranslationPage {

	public function __construct(
		private LanguageManager  $languageManager,
		private StringRepository $stringRepo,
		private MoCompiler       $moCompiler,
		private LicenseChecker   $licenseChecker,
	) {}

	// ── Entry point ───────────────────────────────────────────────────────

	public function render(): void {
		$this->maybeSave();

		$activeLangs   = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang   = (string) $this->languageManager->getDefaultLanguage();
		$targetLangs   = array_values( array_filter( $activeLangs, fn( $l ) => $l !== $defaultLang ) );
		$allLangData   = $this->languageManager->getAllSupportedLanguages();

		$currentDomain = sanitize_text_field( wp_unslash( $_GET['str_domain'] ?? '' ) );
		$search        = sanitize_text_field( wp_unslash( $_GET['str_search'] ?? '' ) );
		$exact         = ! empty( $_GET['str_exact'] );
		$statusFilter  = in_array( $_GET['str_status'] ?? '', [ 'pending', 'translated' ], true ) ? $_GET['str_status'] : '';
		$paged         = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$perPage       = 25;
		$offset        = ( $paged - 1 ) * $perPage;

		$domains    = $this->stringRepo->getDomains();
		$strings    = empty( $targetLangs ) ? [] : $this->stringRepo->getStringsMultiLang( $targetLangs, $currentDomain, $search, $perPage, $offset, $exact, $statusFilter );
		$total      = empty( $targetLangs ) ? 0 : $this->stringRepo->countDistinctStrings( $targetLangs, $currentDomain, $search, $exact, $statusFilter );
		$totalPages = $perPage > 0 ? (int) ceil( $total / $perPage ) : 1;

		$pageUrl = admin_url( 'admin.php?page=idiomatticwp-strings' );

		$saved = ! empty( $_GET['saved'] );

		// Build subtitle: generic or domain-specific.
		if ( $currentDomain !== '' ) {
			/* translators: %s: text domain name */
			$subtitle = sprintf( __( 'Showing strings from: %s', 'idiomattic-wp' ), $currentDomain );
		} else {
			$subtitle = __( 'Translate interface strings from plugins and themes', 'idiomattic-wp' );
		}
		?>
		<div class="wrap iwp-str-wrap">

			<div class="iwp-page-header">
				<div class="iwp-page-header__text">
					<h1 class="iwp-page-title"><?php esc_html_e( 'String Translation', 'idiomattic-wp' ); ?></h1>
					<p class="iwp-page-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				</div>
				<div class="iwp-page-header__actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-compatibility' ) ); ?>" class="iwp-btn iwp-btn--secondary">
						&#8594; <?php esc_html_e( 'Scan plugins &amp; themes', 'idiomattic-wp' ); ?>
					</a>
					<?php if ( ! empty( $targetLangs ) ) : ?>
						<button type="button" id="iwp-add-string-btn" class="iwp-btn iwp-btn--secondary">
							+ <?php esc_html_e( 'Add string manually', 'idiomattic-wp' ); ?>
						</button>
						<button type="button"
							id="iwp-auto-translate-strings-btn"
							class="iwp-btn iwp-btn--primary"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'idiomatticwp_nonce' ) ); ?>"
							data-langs="<?php echo esc_attr( wp_json_encode( $targetLangs ) ); ?>"
							data-domain="<?php echo esc_attr( $currentDomain ); ?>">
							&#10022; <?php esc_html_e( 'Auto-translate missing', 'idiomattic-wp' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. Changes will appear on the frontend immediately.', 'idiomattic-wp' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $activeLangs ) || count( $activeLangs ) < 2 ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Please configure at least two active languages in Settings → Languages before using String Translation.', 'idiomattic-wp' ); ?>
				</p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php /* ── Filters ── */ ?>
			<div class="iwp-card iwp-str-filters">
				<form method="get" class="iwp-filter-form">
					<input type="hidden" name="page" value="idiomatticwp-strings">
					<div class="iwp-filter-row">

						<div class="iwp-filter-field">
							<span class="iwp-field-label"><?php esc_html_e( 'Package / Domain', 'idiomattic-wp' ); ?></span>
							<select name="str_domain" class="iwp-select" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( 'All packages', 'idiomattic-wp' ); ?></option>
								<?php foreach ( $domains as $domain ) : ?>
									<option value="<?php echo esc_attr( $domain ); ?>" <?php selected( $currentDomain, $domain ); ?>>
										<?php echo esc_html( $domain ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="iwp-filter-field">
							<span class="iwp-field-label"><?php esc_html_e( 'Status', 'idiomattic-wp' ); ?></span>
							<select name="str_status" class="iwp-select" onchange="this.form.submit()">
								<option value="" <?php selected( $statusFilter, '' ); ?>><?php esc_html_e( 'All', 'idiomattic-wp' ); ?></option>
								<option value="pending" <?php selected( $statusFilter, 'pending' ); ?>><?php esc_html_e( 'Pending', 'idiomattic-wp' ); ?></option>
								<option value="translated" <?php selected( $statusFilter, 'translated' ); ?>><?php esc_html_e( 'Translated', 'idiomattic-wp' ); ?></option>
							</select>
						</div>

						<div class="iwp-filter-field iwp-filter-field--grow">
							<span class="iwp-field-label"><?php esc_html_e( 'Search strings', 'idiomattic-wp' ); ?></span>
							<div class="iwp-lang-search-wrap">
								<span class="dashicons dashicons-search iwp-lang-search-icon"></span>
								<input type="text" name="str_search" class="iwp-lang-search"
									   value="<?php echo esc_attr( $search ); ?>"
									   placeholder="<?php esc_attr_e( 'Search in source and translations…', 'idiomattic-wp' ); ?>">
							</div>
							<label class="iwp-exact-label">
								<input type="checkbox" name="str_exact" value="1" <?php checked( $exact ); ?>>
								<?php esc_html_e( 'Exact match', 'idiomattic-wp' ); ?>
							</label>
						</div>

						<div class="iwp-filter-field iwp-filter-field--btn">
							<span class="iwp-field-label">&nbsp;</span>
							<button type="submit" class="iwp-btn iwp-btn--secondary"><?php esc_html_e( 'Search', 'idiomattic-wp' ); ?></button>
						</div>

					</div>
				</form>
			</div>

			<?php if ( empty( $domains ) ) : ?>
				<div class="iwp-card iwp-str-empty">
					<div class="iwp-str-empty__icon">&#127760;</div>
					<h3><?php esc_html_e( 'No strings registered yet', 'idiomattic-wp' ); ?></h3>
					<p><?php esc_html_e( 'Go to Compatibility and click "Scan strings" on each plugin or theme to discover translatable strings.', 'idiomattic-wp' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-compatibility' ) ); ?>" class="iwp-btn iwp-btn--primary">
						&#8594; <?php esc_html_e( 'Scan plugins &amp; themes', 'idiomattic-wp' ); ?>
					</a>
				</div>

			<?php elseif ( empty( $strings ) ) : ?>
				<div style="text-align:center;padding:60px 24px;">
					<div style="font-size:48px;margin-bottom:16px;">&#128269;</div>
					<h3 style="margin:0 0 8px;"><?php esc_html_e( 'No strings found', 'idiomattic-wp' ); ?></h3>
					<p style="color:#50575e;margin:0 0 20px;">
						<?php esc_html_e( 'Scan a plugin or theme to register its strings, or add one manually.', 'idiomattic-wp' ); ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=idiomatticwp-compatibility' ) ); ?>" class="iwp-btn iwp-btn--secondary">
						&#8594; <?php esc_html_e( 'Scan sources', 'idiomattic-wp' ); ?>
					</a>
				</div>

			<?php else : ?>

				<form method="post" action="">
					<?php wp_nonce_field( 'idiomatticwp_save_strings' ); ?>
					<input type="hidden" name="iwp_str_action" value="save_strings">

					<div class="iwp-card iwp-str-table-wrap">

						<?php /* ── Table header bar ── */ ?>
						<div class="iwp-str-table-header">
							<span class="iwp-str-count">
								<?php
								$showing = count( $strings );
								printf(
									/* translators: 1: strings on page, 2: total strings */
									esc_html__( 'Showing %1$d of %2$d strings', 'idiomattic-wp' ),
									$showing,
									$total
								);
								?>
							</span>
							<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
								<?php $this->renderPagination( $paged, $totalPages, $currentDomain, $search, $pageUrl, $exact, $statusFilter ); ?>
								<button type="submit" class="iwp-btn iwp-btn--primary">
									<?php esc_html_e( 'Save', 'idiomattic-wp' ); ?>
								</button>
							</div>
						</div>

						<?php /* ── Multi-language table ── */ ?>
						<div class="iwp-str-table-scroll">
							<table class="iwp-data-table iwp-str-table iwp-str-table--multi">
								<thead>
									<tr>
										<th class="iwp-str-col-source">
											<?php
											$defaultData = $allLangData[ $defaultLang ] ?? [];
											echo esc_html( $defaultData['native_name'] ?? $defaultLang );
											if ( ! empty( $defaultData['name'] ) && $defaultData['name'] !== ( $defaultData['native_name'] ?? '' ) ) {
												echo ' <span class="iwp-lang-en">(' . esc_html( $defaultData['name'] ) . ')</span>';
											}
											?>
										</th>
										<?php foreach ( $targetLangs as $lang ) :
											$ld = $allLangData[ $lang ] ?? [];
										?>
											<th class="iwp-str-col-lang">
												<?php echo esc_html( $ld['native_name'] ?? $lang ); ?>
												<?php if ( ! empty( $ld['name'] ) && $ld['name'] !== ( $ld['native_name'] ?? '' ) ) : ?>
													<span class="iwp-lang-en">(<?php echo esc_html( $ld['name'] ); ?>)</span>
												<?php endif; ?>
											</th>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $strings as $idx => $row ) :
										$sourceText   = $row['source_string'];
										$sourceJs     = esc_js( $sourceText );
										$rowId        = 'iwp-row-' . $idx;
									?>
										<tr class="iwp-str-row"
										id="<?php echo esc_attr( $rowId ); ?>"
										data-hash="<?php echo esc_attr( $row['source_hash'] ); ?>"
										data-domain="<?php echo esc_attr( $row['domain'] ); ?>"
										data-source="<?php echo esc_attr( $sourceText ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'idiomatticwp_nonce' ) ); ?>"
									>

											<?php /* Source column */ ?>
											<td class="iwp-str-col-source">
												<div class="iwp-source-wrap">
													<span class="iwp-str-source-text"><?php echo esc_html( $sourceText ); ?></span>
													<?php if ( ! empty( $row['context'] ) ) : ?>
														<span class="iwp-str-context" title="<?php esc_attr_e( 'Context', 'idiomattic-wp' ); ?>">
															<?php echo esc_html( $row['context'] ); ?>
														</span>
													<?php endif; ?>
													<code class="iwp-domain-badge"><?php echo esc_html( $row['domain'] ); ?></code>
												</div>
												<button type="button"
													class="iwp-copy-all-btn"
													data-row="<?php echo esc_attr( $rowId ); ?>"
													data-source="<?php echo esc_attr( $sourceText ); ?>"
													title="<?php esc_attr_e( 'Copy source to all empty translations', 'idiomattic-wp' ); ?>">
													<?php esc_html_e( 'Copy to all', 'idiomattic-wp' ); ?>
												</button>
											</td>

											<?php /* One column per target language */ ?>
											<?php foreach ( $targetLangs as $lang ) :
												$tr     = $row['translations'][ $lang ] ?? null;
												$trId   = $tr ? (int) $tr['id'] : 0;
												$trVal  = $tr['translated_string'] ?? '';
												$status = $tr['status'] ?? 'pending';
												$inputId = 'iwp-ta-' . $idx . '-' . $lang;
											?>
						<td class="iwp-str-col-lang iwp-str-col-lang--<?php echo esc_attr( $status ); ?>">
							<?php if ( $trId > 0 ) : ?>
								<textarea
									id="<?php echo esc_attr( $inputId ); ?>"
									name="iwp_translations[<?php echo $trId; ?>]"
									class="iwp-str-input"
									rows="2"
								><?php echo esc_textarea( $trVal ); ?></textarea>
							<?php else : ?>
								<div class="iwp-unregistered-cell">
									<span class="iwp-unregistered-label">
										<?php esc_html_e( 'Not registered', 'idiomattic-wp' ); ?>
									</span>
									<button type="button"
										class="iwp-register-lang-btn"
										data-lang="<?php echo esc_attr( $lang ); ?>"
										data-input-id="<?php echo esc_attr( $inputId ); ?>"
										title="<?php esc_attr_e( 'Register this string for this language', 'idiomattic-wp' ); ?>">
										<?php esc_html_e( '+ Register', 'idiomattic-wp' ); ?>
									</button>
									<textarea
										id="<?php echo esc_attr( $inputId ); ?>"
										class="iwp-str-input"
										rows="2"
										style="display:none"
									></textarea>
								</div>
							<?php endif; ?>
							<?php if ( $trId > 0 ) : ?>
								<button type="button"
									class="iwp-copy-btn"
									data-target="<?php echo esc_attr( $inputId ); ?>"
									data-source="<?php echo esc_attr( $sourceText ); ?>"
									title="<?php esc_attr_e( 'Copy source string', 'idiomattic-wp' ); ?>">
									<span class="dashicons dashicons-clipboard"></span>
								</button>
							<?php endif; ?>
								<button type="button"
									class="iwp-ai-cell-btn"
									data-row-id="<?php echo esc_attr( (string) $trId ); ?>"
									data-lang="<?php echo esc_attr( $lang ); ?>"
									data-hash="<?php echo esc_attr( $row['source_hash'] ); ?>"
									data-domain="<?php echo esc_attr( $row['domain'] ); ?>"
									data-target="<?php echo esc_attr( $inputId ); ?>"
									title="<?php esc_attr_e( 'Translate with AI', 'idiomattic-wp' ); ?>">
									AI
								</button>
						</td>
											<?php endforeach; ?>

										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<div class="iwp-str-table-footer">
							<?php $this->renderPagination( $paged, $totalPages, $currentDomain, $search, $pageUrl, $exact, $statusFilter ); ?>
							<button type="submit" class="iwp-btn iwp-btn--primary">
								<?php esc_html_e( 'Save Translations', 'idiomattic-wp' ); ?>
							</button>
						</div>

					</div>
				</form>

			<?php endif; ?>

			<?php /* ── Add string modal ── */ ?>
			<div id="iwp-add-string-modal" class="iwp-modal" style="display:none;" aria-modal="true" role="dialog">
				<div class="iwp-modal__backdrop"></div>
				<div class="iwp-modal__box">
					<div class="iwp-modal__header">
						<h2><?php esc_html_e( 'Add String', 'idiomattic-wp' ); ?></h2>
						<button type="button" class="iwp-modal__close" aria-label="<?php esc_attr_e( 'Close', 'idiomattic-wp' ); ?>">&#10005;</button>
					</div>
					<form method="post" class="iwp-modal__body">
						<?php wp_nonce_field( 'idiomatticwp_add_string' ); ?>
						<input type="hidden" name="iwp_str_action" value="add_string">

						<div class="iwp-form-row">
							<label class="iwp-form-label" for="iwp_add_domain"><?php esc_html_e( 'Package / Domain', 'idiomattic-wp' ); ?></label>
							<?php if ( ! empty( $domains ) ) : ?>
								<input list="iwp-domains-list" id="iwp_add_domain" name="iwp_add_domain" class="iwp-input"
									   placeholder="<?php esc_attr_e( 'e.g. my-plugin', 'idiomattic-wp' ); ?>" required>
								<datalist id="iwp-domains-list">
									<?php foreach ( $domains as $d ) : ?>
										<option value="<?php echo esc_attr( $d ); ?>">
									<?php endforeach; ?>
								</datalist>
							<?php else : ?>
								<input type="text" id="iwp_add_domain" name="iwp_add_domain" class="iwp-input"
									   placeholder="<?php esc_attr_e( 'e.g. my-plugin', 'idiomattic-wp' ); ?>" required>
							<?php endif; ?>
						</div>

						<div class="iwp-form-row">
							<label class="iwp-form-label" for="iwp_add_context"><?php esc_html_e( 'Context (optional)', 'idiomattic-wp' ); ?></label>
							<input type="text" id="iwp_add_context" name="iwp_add_context" class="iwp-input"
								   placeholder="<?php esc_attr_e( 'e.g. button, menu', 'idiomattic-wp' ); ?>">
						</div>

						<div class="iwp-form-row">
							<label class="iwp-form-label" for="iwp_add_source"><?php esc_html_e( 'Source string', 'idiomattic-wp' ); ?></label>
							<textarea id="iwp_add_source" name="iwp_add_source" class="iwp-input iwp-str-input" rows="3" required></textarea>
						</div>

						<div class="iwp-modal__footer">
							<button type="submit" class="iwp-btn iwp-btn--primary"><?php esc_html_e( 'Register string', 'idiomattic-wp' ); ?></button>
							<button type="button" class="iwp-btn iwp-btn--secondary iwp-modal__close"><?php esc_html_e( 'Cancel', 'idiomattic-wp' ); ?></button>
						</div>
					</form>
				</div>
			</div>

		</div>

		<?php $this->renderAssets( $targetLangs ); ?>
		<?php
	}

	// ── Pagination helper ─────────────────────────────────────────────────

	private function renderPagination( int $paged, int $totalPages, string $domain, string $search, string $pageUrl, bool $exact = false, string $status = '' ): void {
		if ( $totalPages <= 1 ) {
			return;
		}
		$args = [ 'str_domain' => $domain, 'str_search' => $search, 'str_exact' => $exact ? '1' : null, 'str_status' => $status !== '' ? $status : null ];
		echo '<div class="iwp-str-pagination">';
		if ( $paged > 1 ) {
			echo '<a href="' . esc_url( add_query_arg( array_merge( $args, [ 'paged' => $paged - 1 ] ), $pageUrl ) ) . '" class="iwp-btn iwp-btn--secondary iwp-btn--sm">&#8592; ' . esc_html__( 'Previous', 'idiomattic-wp' ) . '</a>';
		} else {
			echo '<span class="iwp-btn iwp-btn--secondary iwp-btn--sm iwp-pagination-disabled">&#8592; ' . esc_html__( 'Previous', 'idiomattic-wp' ) . '</span>';
		}
		echo '<span class="iwp-str-page-info">' . sprintf( esc_html__( 'Page %1$d of %2$d', 'idiomattic-wp' ), $paged, $totalPages ) . '</span>';
		if ( $paged < $totalPages ) {
			echo '<a href="' . esc_url( add_query_arg( array_merge( $args, [ 'paged' => $paged + 1 ] ), $pageUrl ) ) . '" class="iwp-btn iwp-btn--secondary iwp-btn--sm">' . esc_html__( 'Next', 'idiomattic-wp' ) . ' &#8594;</a>';
		} else {
			echo '<span class="iwp-btn iwp-btn--secondary iwp-btn--sm iwp-pagination-disabled">' . esc_html__( 'Next', 'idiomattic-wp' ) . ' &#8594;</span>';
		}
		echo '</div>';
	}

	// ── Save handler ──────────────────────────────────────────────────────

	private function maybeSave(): void {
		$action = sanitize_key( $_POST['iwp_str_action'] ?? '' );

		if ( $action === 'add_string' ) {
			$this->handleAddString();
			return;
		}

		if ( $action !== 'save_strings' ) {
			return;
		}
		if ( ! check_admin_referer( 'idiomatticwp_save_strings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$translations = $_POST['iwp_translations'] ?? [];
		if ( ! is_array( $translations ) ) {
			return;
		}

		$savedIds = [];
		foreach ( $translations as $id => $value ) {
			$id    = (int) $id;
			$value = sanitize_textarea_field( wp_unslash( (string) $value ) );
			if ( $id > 0 ) {
				$this->stringRepo->saveTranslation( $id, $value, $value !== '' ? 'translated' : 'pending' );
				$savedIds[] = $id;
			}
		}

		// Recompile .mo files for every affected domain + language pair.
		foreach ( $this->stringRepo->getDomainsAndLangsByIds( $savedIds ) as $pair ) {
			try {
				$this->moCompiler->compile( $pair['domain'], LanguageCode::from( $pair['lang'] ) );
			} catch ( \Throwable $e ) {
				// Non-fatal — continue without blocking the redirect.
			}
		}

		wp_safe_redirect( add_query_arg( [
			'page'       => 'idiomatticwp-strings',
			'str_domain' => sanitize_text_field( wp_unslash( $_GET['str_domain'] ?? '' ) ),
			'str_search' => sanitize_text_field( wp_unslash( $_GET['str_search'] ?? '' ) ),
			'paged'      => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
			'saved'      => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Manual string registration ────────────────────────────────────────

	private function handleAddString(): void {
		if ( ! check_admin_referer( 'idiomatticwp_add_string' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$domain  = sanitize_text_field( wp_unslash( $_POST['iwp_add_domain'] ?? '' ) );
		$context = sanitize_text_field( wp_unslash( $_POST['iwp_add_context'] ?? '' ) );
		$source  = sanitize_textarea_field( wp_unslash( $_POST['iwp_add_source'] ?? '' ) );

		if ( $domain === '' || $source === '' ) {
			return;
		}

		$activeLangs = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$targetLangs = array_filter( $activeLangs, fn( $l ) => $l !== $defaultLang );

		foreach ( $targetLangs as $lang ) {
			$this->stringRepo->register( $domain, $source, $context, $lang );
		}

		wp_safe_redirect( add_query_arg( [
			'page'   => 'idiomatticwp-strings',
			'saved'  => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Inline assets ─────────────────────────────────────────────────────

	private function renderAssets( array $targetLangs ): void {
		?>
		<style>
		.iwp-str-wrap { max-width: 100%; }

		/* Scrollable table container */
		.iwp-str-table-scroll {
			overflow-x: auto;
			-webkit-overflow-scrolling: touch;
		}

		/* Multi-language table */
		.iwp-str-table--multi {
			min-width: 700px;
			table-layout: fixed;
		}
		.iwp-str-table--multi .iwp-str-col-source {
			width: 28%;
			min-width: 220px;
			vertical-align: top;
		}
		.iwp-str-table--multi .iwp-str-col-lang {
			min-width: 200px;
			vertical-align: top;
			position: relative;
		}

		/* Source column */
		.iwp-source-wrap {
			display: flex;
			flex-direction: column;
			gap: 4px;
			margin-bottom: 6px;
		}
		.iwp-str-source-text {
			font-size: 13px;
			color: #1d2327;
			word-break: break-word;
			line-height: 1.5;
		}
		.iwp-str-context {
			font-size: 11px;
			color: #8c8f94;
			font-style: italic;
		}
		.iwp-domain-badge {
			font-size: 10px;
			background: #f0f0f1;
			color: #50575e;
			padding: 1px 5px;
			border-radius: 3px;
			align-self: flex-start;
		}
		.iwp-lang-en {
			font-size: 11px;
			color: #8c8f94;
			font-weight: 400;
		}

		/* Textarea */
		.iwp-str-input {
			width: 100%;
			box-sizing: border-box;
			font-size: 13px;
			resize: vertical;
			min-height: 52px;
		}

		/* Status tint on cell */
		.iwp-str-col-lang--translated { background: #f5fbf5; }
		.iwp-str-col-lang--pending    { background: #fefefe; }

		/* Copy button per cell */
		.iwp-str-col-lang {
			padding-right: 60px !important;
		}
		.iwp-copy-btn {
			position: absolute;
			top: 8px;
			right: 32px;
			background: none;
			border: 1px solid #c3c4c7;
			border-radius: 3px;
			padding: 2px 4px;
			cursor: pointer;
			color: #787c82;
			line-height: 1;
		}
		.iwp-copy-btn:hover { border-color: #0073aa; color: #0073aa; background: #f0f6fc; }
		.iwp-copy-btn .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: middle; }
		.iwp-ai-cell-btn {
			position: absolute;
			top: 8px;
			right: 8px;
			background: none;
			border: 1px solid #c3c4c7;
			border-radius: 3px;
			padding: 2px 4px;
			cursor: pointer;
			color: #787c82;
			line-height: 1;
			font-size: 10px;
			font-weight: 600;
			letter-spacing: 0.5px;
		}
		.iwp-ai-cell-btn:hover { border-color: #8c5fc7; color: #8c5fc7; background: #f8f5ff; }
		.iwp-ai-cell-btn.iwp-ai-cell-btn--loading { opacity: 0.5; pointer-events: none; }

		/* Copy-to-all button */
		.iwp-copy-all-btn {
			font-size: 11px;
			background: none;
			border: 1px solid #c3c4c7;
			border-radius: 3px;
			padding: 2px 8px;
			cursor: pointer;
			color: #50575e;
			align-self: flex-start;
		}
		.iwp-copy-all-btn:hover { border-color: #0073aa; color: #0073aa; background: #f0f6fc; }

		/* Unregistered cell */
		.iwp-unregistered-cell {
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			gap: 6px;
			padding: 4px 0;
		}
		.iwp-unregistered-label {
			font-size: 11px;
			color: #a7aaad;
			font-style: italic;
		}
		.iwp-register-lang-btn {
			font-size: 11px;
			background: none;
			border: 1px dashed #a7aaad;
			border-radius: 3px;
			padding: 3px 10px;
			cursor: pointer;
			color: #50575e;
		}
		.iwp-register-lang-btn:hover { border-color: #0073aa; color: #0073aa; border-style: solid; background: #f0f6fc; }
		.iwp-register-lang-btn:disabled { opacity: 0.5; cursor: wait; }

		/* Table header */
		.iwp-str-table-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 8px;
			padding: 12px 16px;
			border-bottom: 1px solid #f0f0f1;
		}
		.iwp-str-count { font-size: 13px; color: #646970; }

		/* Table footer */
		.iwp-str-table-footer {
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 8px;
			padding: 12px 16px;
			border-top: 1px solid #f0f0f1;
		}

		/* Pagination */
		.iwp-str-pagination {
			display: flex;
			align-items: center;
			gap: 6px;
		}
		.iwp-str-page-info {
			font-size: 13px;
			color: #1d2327;
			font-weight: 600;
			padding: 0 4px;
		}
		.iwp-pagination-disabled {
			opacity: 0.4;
			pointer-events: none;
			cursor: default;
		}

		/* Exact-match checkbox */
		.iwp-exact-label {
			display: flex;
			align-items: center;
			gap: 5px;
			font-size: 12px;
			color: #50575e;
			cursor: pointer;
			margin-top: 4px;
		}

		/* Modal */
		.iwp-modal { position:fixed; inset:0; z-index:100000; display:flex; align-items:center; justify-content:center; }
		.iwp-modal__backdrop { position:absolute; inset:0; background:rgba(0,0,0,.5); }
		.iwp-modal__box { position:relative; background:#fff; border-radius:6px; box-shadow:0 4px 24px rgba(0,0,0,.2); width:480px; max-width:95vw; max-height:90vh; overflow:auto; }
		.iwp-modal__header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #f0f0f1; }
		.iwp-modal__header h2 { margin:0; font-size:16px; }
		.iwp-modal__close { background:none; border:none; font-size:18px; cursor:pointer; color:#646970; line-height:1; padding:4px; }
		.iwp-modal__close:hover { color:#d63638; }
		.iwp-modal__body { padding:20px; }
		.iwp-modal__footer { display:flex; gap:8px; padding-top:16px; }
		.iwp-form-row { margin-bottom:16px; }
		.iwp-form-label { display:block; font-weight:600; margin-bottom:6px; font-size:13px; }
		.iwp-input { width:100%; box-sizing:border-box; }

		/* Badge for manually registered strings */
		.iwp-manual-badge { font-size:10px; background:#e8f0fe; color:#1967d2; padding:1px 5px; border-radius:3px; margin-left:4px; vertical-align:middle; }
		</style>

		<script>
		(function () {
			'use strict';

			// Copy source string into a single language textarea.
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.iwp-copy-btn');
				if ( ! btn ) { return; }
				var ta = document.getElementById( btn.dataset.target );
				if ( ! ta ) { return; }
				ta.value = btn.dataset.source;
				ta.focus();
				btn.style.color = '#46b450';
				setTimeout( function () { btn.style.color = ''; }, 700 );
			});

			// AI translate a single cell on demand.
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.iwp-ai-cell-btn');
				if ( ! btn ) { return; }
				var ta = document.getElementById( btn.dataset.target );
				if ( ! ta ) { return; }
				btn.classList.add('iwp-ai-cell-btn--loading');
				btn.textContent = '…';
				var fd = new FormData();
				fd.append('action',   'idiomatticwp_translate_single_string');
				fd.append('nonce',    btn.closest('tr').dataset.nonce || btn.closest('[data-nonce]').dataset.nonce);
				fd.append('row_id',   btn.dataset.rowId);
				fd.append('lang',     btn.dataset.lang);
				fd.append('source_hash', btn.dataset.hash);
				fd.append('domain',   btn.dataset.domain);
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(data) {
						if ( data.success ) {
							ta.value = data.data.translated;
							btn.textContent = 'AI';
							btn.classList.remove('iwp-ai-cell-btn--loading');
							btn.style.color = '#46b450';
							setTimeout(function() { btn.style.color = ''; }, 1000);
						} else {
							btn.textContent = 'AI';
							btn.classList.remove('iwp-ai-cell-btn--loading');
							alert(data.data.message || 'AI translation failed.');
						}
					})
					.catch(function() {
						btn.textContent = 'AI';
						btn.classList.remove('iwp-ai-cell-btn--loading');
						alert('Request failed. Please try again.');
					});
			});

			// Copy source string into all empty translation textareas in a row.
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.iwp-copy-all-btn');
				if ( ! btn ) { return; }
				var row = document.getElementById( btn.dataset.row );
				if ( ! row ) { return; }
				var source = btn.dataset.source;
				// Select only visible registered textareas (those with a name attribute).
				var inputs = row.querySelectorAll('.iwp-str-col-lang textarea.iwp-str-input[name]');
				var filled = 0;
				inputs.forEach( function (ta) {
					if ( ta.value.trim() === '' ) {
						ta.value = source;
						filled++;
					}
				} );
				if ( filled === 0 ) {
					inputs.forEach( function (ta) { ta.value = source; } );
				}
				btn.textContent = <?php echo wp_json_encode( __( 'Copied!', 'idiomattic-wp' ) ); ?>;
				setTimeout( function () {
					btn.textContent = <?php echo wp_json_encode( __( 'Copy to all', 'idiomattic-wp' ) ); ?>;
				}, 1000 );
			});

			// Register a source string for a language it was not yet registered for.
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.iwp-register-lang-btn');
				if ( ! btn ) { return; }
				var row    = btn.closest('.iwp-str-row');
				var lang   = btn.dataset.lang;
				var taId   = btn.dataset.inputId;
				var hash   = row.dataset.hash;
				var domain = row.dataset.domain;
				var nonce  = row.dataset.nonce;
				var cell   = btn.closest('.iwp-str-col-lang');

				btn.disabled    = true;
				btn.textContent = 'Registering\u2026';

				var fd = new FormData();
				fd.append( 'action',      'idiomatticwp_register_string_lang' );
				fd.append( 'nonce',       nonce );
				fd.append( 'source_hash', hash );
				fd.append( 'domain',      domain );
				fd.append( 'lang',        lang );

				fetch( ajaxurl, { method: 'POST', body: fd } )
					.then( function (r) { return r.json(); } )
					.then( function (res) {
						if ( ! res.success ) {
							btn.disabled    = false;
							btn.textContent = '+ Register';
							alert( ( res.data && res.data.message ) ? res.data.message : 'Error registering string.' );
							return;
						}
						var id  = res.data.id;
						var div = btn.closest('.iwp-unregistered-cell');

						// Replace the placeholder div with a live textarea.
						var ta          = document.createElement('textarea');
						ta.id           = taId;
						ta.name         = 'iwp_translations[' + id + ']';
						ta.className    = 'iwp-str-input';
						ta.rows         = 2;
						div.replaceWith(ta);

						// Append the clipboard copy button.
						var cb           = document.createElement('button');
						cb.type          = 'button';
						cb.className     = 'iwp-copy-btn';
						cb.dataset.target = taId;
						cb.dataset.source = row.dataset.source;
						cb.title          = 'Copy source string';
						cb.innerHTML      = '<span class="dashicons dashicons-clipboard"></span>';
						cell.appendChild(cb);

						cell.classList.remove('iwp-str-col-lang--pending');
						cell.classList.add('iwp-str-col-lang--pending');
						ta.focus();
					} )
					.catch( function () {
						btn.disabled    = false;
						btn.textContent = '+ Register';
					} );
			});

		// ── Add String modal ──────────────────────────────────────────────────
	var addStrBtn   = document.getElementById('iwp-add-string-btn');
	var addStrModal = document.getElementById('iwp-add-string-modal');
	if ( addStrBtn && addStrModal ) {
		addStrBtn.addEventListener('click', function() { addStrModal.style.display = 'flex'; });
		addStrModal.addEventListener('click', function(e) {
			if ( e.target.classList.contains('iwp-modal__backdrop') ||
				 e.target.classList.contains('iwp-modal__close') ) {
				addStrModal.style.display = 'none';
			}
		});
	}

	// ── Auto-translate strings button ─────────────────────────────────────
	var atBtn = document.getElementById('iwp-auto-translate-strings-btn');
	if ( atBtn ) {
		atBtn.addEventListener('click', function() {
			var nonce  = atBtn.dataset.nonce;
			var langs  = JSON.parse(atBtn.dataset.langs || '[]');
			var domain = atBtn.dataset.domain;

			if ( ! langs.length ) { return; }

			atBtn.disabled    = true;
			var labelDone     = <?php echo wp_json_encode( __( 'Auto-translate missing', 'idiomattic-wp' ) ); ?>;
			var totalTranslated = 0;

			function translateLang(idx) {
				if ( idx >= langs.length ) {
					atBtn.disabled    = false;
					atBtn.textContent = labelDone;
					window.location.reload();
					return;
				}
				var lang = langs[idx];
				atBtn.textContent = <?php echo wp_json_encode( __( 'Translating…', 'idiomattic-wp' ) ); ?> + ' (' + (idx + 1) + '/' + langs.length + ')';

				var fd = new FormData();
				fd.append('action', 'idiomatticwp_auto_translate_strings');
				fd.append('nonce',  nonce);
				fd.append('lang',   lang);
				fd.append('domain', domain);

				fetch(ajaxurl, { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(res) {
						if ( ! res.success ) {
							atBtn.disabled    = false;
							atBtn.textContent = labelDone;
							alert((res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Auto-translate failed.', 'idiomattic-wp' ) ); ?>);
							return;
						}
						totalTranslated += (res.data && res.data.translated) ? res.data.translated : 0;
						translateLang(idx + 1);
					})
					.catch(function() {
						atBtn.disabled    = false;
						atBtn.textContent = labelDone;
					});
			}

			translateLang(0);
		});
	}

	}());
		</script>
		<?php
	}
}
