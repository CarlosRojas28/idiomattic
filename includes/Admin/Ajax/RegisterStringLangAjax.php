<?php
/**
 * RegisterStringLangAjax — registers an existing source string for an
 * additional target language on demand.
 *
 * Accepts: source_hash, domain, lang (nonce: idiomatticwp_nonce).
 * Looks up the source_string + context from an existing DB row, then
 * calls StringRepository::register() to insert a new pending row.
 * Returns the new row's id so the UI can enable the textarea immediately.
 *
 * @package IdiomatticWP\Admin\Ajax
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Ajax;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Repositories\StringRepository;

class RegisterStringLangAjax {

	public function __construct(
		private StringRepository $stringRepo,
		private LanguageManager  $languageManager,
	) {}

	public function handle(): void {
		check_ajax_referer( 'idiomatticwp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'idiomattic-wp' ) ], 403 );
		}

		$hash   = sanitize_text_field( wp_unslash( $_POST['source_hash'] ?? '' ) );
		$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
		$lang   = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );

		if ( $hash === '' || $domain === '' || $lang === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'idiomattic-wp' ) ] );
		}

		// Validate that $lang is an active non-default language.
		$activeLangs = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		if ( ! in_array( $lang, $activeLangs, true ) || $lang === $defaultLang ) {
			wp_send_json_error( [ 'message' => __( 'Invalid target language.', 'idiomattic-wp' ) ] );
		}

		// Resolve source_string and context from an existing row.
		$source = $this->stringRepo->getSourceByHash( $hash, $domain );
		if ( $source === null ) {
			wp_send_json_error( [ 'message' => __( 'Source string not found.', 'idiomattic-wp' ) ] );
		}

		$this->stringRepo->register(
			$domain,
			$source['source_string'],
			$source['context'],
			$lang
		);

		// Fetch the newly created (or already existing) row id.
		$newId = $this->stringRepo->getRowId( $hash, $domain, $lang );

		wp_send_json_success( [ 'id' => $newId ] );
	}
}
