<?php
/**
 * TranslateSingleStringAjax — translate a single UI string on demand.
 *
 * Accepts: nonce, row_id (int), lang (string).
 * Returns JSON with the translated string.
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Repositories\StringRepository;
use IdiomatticWP\Strings\MoCompiler;
use IdiomatticWP\ValueObjects\LanguageCode;

class TranslateSingleStringAjax {

	public function __construct(
		private StringRepository $stringRepo,
		private ProviderInterface $provider,
		private LanguageManager $languageManager,
		private LicenseChecker $licenseChecker,
		private MoCompiler $moCompiler,
	) {}

	public function handle(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ] );
		}

		if ( ! $this->licenseChecker->isPro() ) {
			wp_send_json_error( [ 'message' => __( 'AI string translation requires a Pro license.', 'idiomattic-wp' ) ] );
		}

		if ( ! $this->provider->isConfigured() ) {
			wp_send_json_error( [ 'message' => __( 'No AI provider configured. Please add an API key in Settings → AI Provider.', 'idiomattic-wp' ) ] );
		}

		$rowId      = (int) ( $_POST['row_id'] ?? 0 );
		$targetLang = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );

		if ( ! $rowId || $targetLang === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing required parameters.', 'idiomattic-wp' ) ] );
		}

		// Fetch the row to get source_string and domain.
		$rows = $this->stringRepo->getStrings( $targetLang, '', '', 1, 0 );
		// We need the specific row — use getRowId in reverse. Fetch via direct query using the repo.
		// StringRepository doesn't expose a getById(), so we fetch by lang and filter. Instead,
		// call the raw lookup: get source text via source_hash on the row we already have.
		// Simpler: use getStrings() filtered to a small set and find our row.
		// Best: add a getById() helper call — but to keep it simple, pass domain+hash from the client.

		$sourceHash = sanitize_text_field( wp_unslash( $_POST['source_hash'] ?? '' ) );
		$domain     = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );

		if ( $sourceHash === '' || $domain === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing source hash or domain.', 'idiomattic-wp' ) ] );
		}

		$sourceData = $this->stringRepo->getSourceByHash( $sourceHash, $domain );
		if ( $sourceData === null ) {
			wp_send_json_error( [ 'message' => __( 'String not found.', 'idiomattic-wp' ) ] );
		}

		$sourceLang = (string) $this->languageManager->getDefaultLanguage();

		try {
			$results = $this->provider->translate( [ $sourceData['source_string'] ], $sourceLang, $targetLang );
		} catch ( \Throwable $e ) {
			error_log( 'IdiomatticWP TranslateSingleString error: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => __( 'Translation failed. Please check your API key and try again.', 'idiomattic-wp' ) ] );
		}

		$translated = $results[0] ?? '';
		if ( $translated === '' ) {
			wp_send_json_error( [ 'message' => __( 'AI returned an empty translation.', 'idiomattic-wp' ) ] );
		}

		// Save to the database.
		$this->stringRepo->saveTranslation( $rowId, $translated, 'translated' );

		// Recompile the .mo file for this domain + lang pair.
		try {
			$this->moCompiler->compile( $domain, LanguageCode::from( $targetLang ) );
		} catch ( \Throwable ) {
			// Non-fatal.
		}

		wp_send_json_success( [
			'translated' => $translated,
			'row_id'     => $rowId,
		] );
	}
}
