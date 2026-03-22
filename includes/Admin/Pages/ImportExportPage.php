<?php
/**
 * ImportExportPage — export translations as XLIFF and import them back.
 *
 * Export flow: choose language → download ZIP with all XLIFF files.
 * Import flow: upload an XLIFF file → preview result → confirm.
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\ImportExport\Exporter;
use IdiomatticWP\ImportExport\ImportResult;
use IdiomatticWP\ImportExport\Importer;
use IdiomatticWP\ValueObjects\LanguageCode;

class ImportExportPage {

	public function __construct(
		private LanguageManager $languageManager,
		private Exporter        $exporter,
		private Importer        $importer,
	) {}

	// ── Entry point ───────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'idiomattic-wp' ) );
		}

		// Handle export (downloads a ZIP — must happen before any output).
		if ( ! empty( $_POST['iwp_ie_action'] ) && $_POST['iwp_ie_action'] === 'export' ) {
			$this->handleExport();
		}

		$importResult = null;
		if ( ! empty( $_POST['iwp_ie_action'] ) && $_POST['iwp_ie_action'] === 'import' ) {
			$importResult = $this->handleImport();
		}

		$activeLangs = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$targetLangs = array_values( array_filter( $activeLangs, fn( $l ) => $l !== $defaultLang ) );
		$allLangData = $this->languageManager->getAllSupportedLanguages();
		?>
		<div class="wrap">

			<div class="iwp-page-header">
				<div class="iwp-page-header__text">
					<h1 class="iwp-page-title"><?php esc_html_e( 'Import / Export', 'idiomattic-wp' ); ?></h1>
					<p class="iwp-page-subtitle"><?php esc_html_e( 'Exchange translations with CAT tools using XLIFF format', 'idiomattic-wp' ); ?></p>
				</div>
			</div>

			<?php if ( empty( $targetLangs ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Please configure at least two active languages in Settings → Languages before using Import / Export.', 'idiomattic-wp' ); ?>
				</p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( $importResult !== null ) : ?>
				<?php if ( $importResult->isSuccess() && $importResult->imported > 0 ) : ?>
					<div class="notice notice-success is-dismissible"><p>
						<?php
						printf(
							/* translators: %d: number of translations applied */
							esc_html__( 'Done. %d translations applied to your database.', 'idiomattic-wp' ),
							$importResult->imported
						);
						if ( $importResult->skipped > 0 ) {
							echo ' ';
							printf(
								/* translators: %d: number of skipped entries */
								esc_html__( '%d entries skipped.', 'idiomattic-wp' ),
								$importResult->skipped
							);
						}
						?>
					</p></div>
				<?php elseif ( ! empty( $importResult->errors ) ) : ?>
					<div class="notice notice-error is-dismissible"><p>
						<?php echo esc_html( implode( ' | ', $importResult->errors ) ); ?>
					</p></div>
				<?php elseif ( $importResult->imported === 0 ) : ?>
					<div class="notice notice-warning is-dismissible"><p>
						<?php esc_html_e( 'No posts were updated. Check that the file matches existing translation records.', 'idiomattic-wp' ); ?>
					</p></div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="iwp-ie-grid">

				<?php /* ── Export card ─────────────────────────────────────── */ ?>
				<div class="iwp-ie-card">
					<div class="iwp-ie-card__icon">
						<span class="dashicons dashicons-download"></span>
					</div>
					<h2 class="iwp-ie-card__title"><?php esc_html_e( 'Export Translations', 'idiomattic-wp' ); ?></h2>
					<p class="iwp-ie-card__desc"><?php esc_html_e( 'Download your translations as XLIFF files, ready for any CAT tool.', 'idiomattic-wp' ); ?></p>

					<form method="post">
						<?php wp_nonce_field( 'idiomatticwp_ie_export' ); ?>
						<input type="hidden" name="iwp_ie_action" value="export">

						<select name="iwp_export_lang" class="iwp-select">
							<?php foreach ( $targetLangs as $lang ) :
								$ld = $allLangData[ $lang ] ?? [];
							?>
								<option value="<?php echo esc_attr( $lang ); ?>">
									<?php echo esc_html( $ld['native_name'] ?? $lang ); ?>
									<?php if ( ! empty( $ld['name'] ) && $ld['name'] !== ( $ld['native_name'] ?? '' ) ) : ?>
										(<?php echo esc_html( $ld['name'] ); ?>)
									<?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>

						<button type="submit" class="iwp-btn iwp-btn--primary iwp-ie-card__btn">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Download ZIP \u2192', 'idiomattic-wp' ); ?>
						</button>
					</form>
				</div>

				<?php /* ── Import card ─────────────────────────────────────── */ ?>
				<div class="iwp-ie-card">
					<div class="iwp-ie-card__icon">
						<span class="dashicons dashicons-upload"></span>
					</div>
					<h2 class="iwp-ie-card__title"><?php esc_html_e( 'Import Translations', 'idiomattic-wp' ); ?></h2>
					<p class="iwp-ie-card__desc"><?php esc_html_e( 'Upload an XLIFF file to apply translations directly to your database.', 'idiomattic-wp' ); ?></p>

					<div class="iwp-ie-card__warning">
						&#9888; <?php esc_html_e( 'Existing translations will be updated. This cannot be undone.', 'idiomattic-wp' ); ?>
					</div>

					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'idiomatticwp_ie_import' ); ?>
						<input type="hidden" name="iwp_ie_action" value="import">

						<input type="file" name="iwp_import_file" accept=".xliff,.xml">

						<button type="submit" class="iwp-btn iwp-btn--primary iwp-ie-card__btn">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Upload &amp; Apply \u2192', 'idiomattic-wp' ); ?>
						</button>
					</form>
				</div>

			</div>

		</div>

		<style>
		.iwp-ie-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:24px; }
		@media(max-width:700px) { .iwp-ie-grid { grid-template-columns:1fr; } }
		.iwp-ie-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:32px; }
		.iwp-ie-card__icon { font-size:32px; color:#2271b1; margin-bottom:16px; }
		.iwp-ie-card__icon .dashicons { font-size:32px; width:32px; height:32px; }
		.iwp-ie-card__title { font-size:18px; font-weight:700; margin:0 0 8px; }
		.iwp-ie-card__desc { color:#50575e; margin:0 0 24px; font-size:13px; line-height:1.6; }
		.iwp-ie-card__warning { font-size:12px; color:#8a0000; background:#fce8e8; padding:8px 12px; border-radius:4px; margin-bottom:16px; }
		.iwp-ie-card select,
		.iwp-ie-card input[type=file] { width:100%; margin-bottom:12px; }
		.iwp-ie-card__btn { width:100%; justify-content:center; text-align:center; display:flex; align-items:center; gap:6px; }
		.iwp-ie-card__btn .dashicons { font-size:16px; width:16px; height:16px; }
		</style>
		<?php
	}

	// ── Handlers ──────────────────────────────────────────────────────────

	private function handleExport(): void {
		if ( ! check_admin_referer( 'idiomatticwp_ie_export' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$langCode = sanitize_text_field( wp_unslash( $_POST['iwp_export_lang'] ?? '' ) );
		if ( $langCode === '' ) {
			return;
		}

		try {
			$lang = LanguageCode::from( $langCode );
		} catch ( \Throwable ) {
			return;
		}

		// downloadZip() sends headers and streams the file — execution ends there.
		$this->exporter->downloadZip( $lang );
		exit;
	}

	private function handleImport(): ?ImportResult {
		if ( ! check_admin_referer( 'idiomatticwp_ie_import' ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$file = $_FILES['iwp_import_file'] ?? null;
		if ( ! $file || empty( $file['tmp_name'] ) || $file['error'] !== UPLOAD_ERR_OK ) {
			return ImportResult::failure(
				__( 'No file uploaded or upload error occurred.', 'idiomattic-wp' )
			);
		}

		// Basic MIME / extension check.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'xliff', 'xml' ], true ) ) {
			return ImportResult::failure(
				__( 'Only .xliff or .xml files are accepted.', 'idiomattic-wp' )
			);
		}

		return $this->importer->importFromFile( $file['tmp_name'] );
	}
}
