<?php
/**
 * AutoTranslateStringsAjax — bulk-translate pending UI strings via the configured AI provider.
 *
 * Accepts: nonce, lang (required), domain (optional).
 * Returns JSON with the number of strings translated.
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

class AutoTranslateStringsAjax {

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

		$targetLang = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );
		$domain     = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );

		if ( $targetLang === '' ) {
			wp_send_json_error( [ 'message' => __( 'Target language is required.', 'idiomattic-wp' ) ] );
		}

		$sourceLang = (string) $this->languageManager->getDefaultLanguage();

		// Load pending strings — cap at 200 to avoid PHP memory exhaustion.
		$rows    = $this->stringRepo->getStrings( $targetLang, $domain, '', 200, 0 );
		$pending = array_values( array_filter( $rows, fn( $r ) => ( $r['status'] ?? '' ) === 'pending' ) );

		if ( empty( $pending ) ) {
			wp_send_json_success( [
				'translated' => 0,
				'message'    => __( 'No pending strings found for this language.', 'idiomattic-wp' ),
			] );
		}

		$sources = array_column( $pending, 'source_string' );

		try {
			$translations = $this->provider->translate( $sources, $sourceLang, $targetLang );
		} catch ( \Throwable $e ) {
			error_log( 'IdiomatticWP AutoTranslateStrings error: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => __( 'Translation failed. Please check your API key and try again.', 'idiomattic-wp' ) ] );
		}

		$savedIds = [];
		foreach ( $pending as $idx => $row ) {
			$translated = $translations[ $idx ] ?? '';
			if ( $translated !== '' ) {
				$this->stringRepo->saveTranslation( (int) $row['id'], $translated, 'translated' );
				$savedIds[] = (int) $row['id'];
			}
		}

		// Recompile .mo files for every affected domain + language pair.
		foreach ( $this->stringRepo->getDomainsAndLangsByIds( $savedIds ) as $pair ) {
			try {
				$this->moCompiler->compile( $pair['domain'], LanguageCode::from( $pair['lang'] ) );
			} catch ( \Throwable ) {
				// Non-fatal — continue.
			}
		}

		wp_send_json_success( [
			'translated' => count( $savedIds ),
			/* translators: %d: number of strings translated */
			'message'    => sprintf( __( 'Translated %d strings successfully.', 'idiomattic-wp' ), count( $savedIds ) ),
		] );
	}
}
