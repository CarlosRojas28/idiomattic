<?php
/**
 * TmxFormat — Translation Memory eXchange (TMX 1.4b) import/export.
 *
 * TMX is the industry-standard open format for exchanging translation memory
 * data between CAT tools (SDL Trados, memoQ, Phrase, OmegaT…).
 *
 * Spec: https://www.gala-global.org/tmx-14b
 *
 * @package IdiomatticWP\ImportExport
 */

declare( strict_types=1 );

namespace IdiomatticWP\ImportExport;

use IdiomatticWP\Memory\WpdbTranslationMemoryRepository;
use IdiomatticWP\ValueObjects\LanguageCode;

class TmxFormat {

	public function __construct(
		private readonly WpdbTranslationMemoryRepository $repository,
	) {}

	// ── Export ────────────────────────────────────────────────────────────

	/**
	 * Export all TM segments for a language pair as a TMX 1.4b XML string.
	 *
	 * @param LanguageCode $source Source language.
	 * @param LanguageCode $target Target language.
	 * @return string TMX XML document.
	 */
	public function export( LanguageCode $source, LanguageCode $target ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'idiomatticwp_translation_memory';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_text, translated_text, provider_used, created_at
				 FROM {$table}
				 WHERE source_lang = %s AND target_lang = %s
				 ORDER BY id ASC",
				(string) $source,
				(string) $target
			),
			ARRAY_A
		) ?: [];

		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;

		// <?xml ...?> is added automatically — add standalone PI.
		$doc->xmlStandalone = false;

		// <tmx> root.
		$tmx = $doc->createElement( 'tmx' );
		$tmx->setAttribute( 'version', '1.4' );
		$doc->appendChild( $tmx );

		// <header>.
		$header = $doc->createElement( 'header' );
		$header->setAttribute( 'creationtool', 'Idiomattic WP' );
		$header->setAttribute( 'creationtoolversion', defined( 'IDIOMATTICWP_VERSION' ) ? IDIOMATTICWP_VERSION : '1.0.0' );
		$header->setAttribute( 'datatype', 'plaintext' );
		$header->setAttribute( 'segtype', 'sentence' );
		$header->setAttribute( 'adminlang', 'en' );
		$header->setAttribute( 'srclang', (string) $source );
		$header->setAttribute( 'o-tmf', 'IdiomatticWP' );
		$header->setAttribute( 'creationdate', gmdate( 'Ymd\THis\Z' ) );
		$tmx->appendChild( $header );

		// <body>.
		$body = $doc->createElement( 'body' );
		$tmx->appendChild( $body );

		foreach ( $rows as $row ) {
			$tu = $doc->createElement( 'tu' );
			$tu->setAttribute( 'creationdate', $this->toTmxDate( $row['created_at'] ?? '' ) );

			if ( $row['provider_used'] ) {
				$prop = $doc->createElement( 'prop', htmlspecialchars( $row['provider_used'] ) );
				$prop->setAttribute( 'type', 'x-provider' );
				$tu->appendChild( $prop );
			}

			// Source <tuv>.
			$tu->appendChild( $this->buildTuv( $doc, (string) $source, (string) $row['source_text'] ) );

			// Target <tuv>.
			$tu->appendChild( $this->buildTuv( $doc, (string) $target, (string) $row['translated_text'] ) );

			$body->appendChild( $tu );
		}

		return $doc->saveXML();
	}

	/**
	 * Stream a TMX file download to the browser.
	 *
	 * @param LanguageCode $source Source language.
	 * @param LanguageCode $target Target language.
	 */
	public function download( LanguageCode $source, LanguageCode $target ): void {
		$xml      = $this->export( $source, $target );
		$filename = sprintf(
			'idiomatticwp-tm-%s-%s-%s.tmx',
			(string) $source,
			(string) $target,
			gmdate( 'Y-m-d' )
		);

		header( 'Content-Type: application/x-tmx+xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $xml ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ── Import ────────────────────────────────────────────────────────────

	/**
	 * Import TM segments from a TMX XML string.
	 *
	 * Returns the number of segments successfully imported.
	 *
	 * @param string $xml    Raw TMX XML content.
	 * @param string $provider Provider slug to tag imported entries with.
	 * @return int Number of translation units imported.
	 *
	 * @throws \InvalidArgumentException When XML is invalid or missing srclang.
	 */
	public function import( string $xml, string $provider = 'import' ): int {
		$doc = new \DOMDocument();

		libxml_use_internal_errors( true );
		$parsed = $doc->loadXML( $xml );
		libxml_clear_errors();

		if ( ! $parsed ) {
			throw new \InvalidArgumentException( 'Invalid TMX XML provided.' );
		}

		$header  = $doc->getElementsByTagName( 'header' )->item( 0 );
		$srcLang = $header ? $header->getAttribute( 'srclang' ) : '';

		if ( ! $srcLang ) {
			throw new \InvalidArgumentException( 'TMX header missing srclang attribute.' );
		}

		$sourceLang = LanguageCode::from( strtolower( str_replace( '_', '-', $srcLang ) ) );
		$imported   = 0;

		/** @var \DOMElement $tu */
		foreach ( $doc->getElementsByTagName( 'tu' ) as $tu ) {
			$tuvs = $tu->getElementsByTagName( 'tuv' );

			$segments = [];
			foreach ( $tuvs as $tuv ) {
				/** @var \DOMElement $tuv */
				$lang = strtolower( str_replace( '_', '-', $tuv->getAttribute( 'xml:lang' ) ?: $tuv->getAttribute( 'lang' ) ) );
				$seg  = $tuv->getElementsByTagName( 'seg' )->item( 0 );

				if ( $lang && $seg ) {
					$segments[ $lang ] = trim( $seg->textContent );
				}
			}

			$sourceLangStr = (string) $sourceLang;

			if ( ! isset( $segments[ $sourceLangStr ] ) || empty( $segments[ $sourceLangStr ] ) ) {
				continue;
			}

			foreach ( $segments as $lang => $text ) {
				if ( $lang === $sourceLangStr || empty( $text ) ) {
					continue;
				}

				try {
					$targetLang = LanguageCode::from( $lang );
				} catch ( \Throwable $e ) {
					continue; // Skip unknown language codes gracefully.
				}

				$this->repository->save(
					$segments[ $sourceLangStr ],
					$text,
					$sourceLang,
					$targetLang,
					$provider
				);

				++$imported;
			}
		}

		return $imported;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Build a <tuv xml:lang="…"><seg>…</seg></tuv> element.
	 */
	private function buildTuv( \DOMDocument $doc, string $lang, string $text ): \DOMElement {
		$tuv = $doc->createElement( 'tuv' );
		$tuv->setAttribute( 'xml:lang', strtoupper( str_replace( '-', '_', $lang ) ) );

		$seg = $doc->createElement( 'seg' );
		$seg->appendChild( $doc->createTextNode( $text ) );
		$tuv->appendChild( $seg );

		return $tuv;
	}

	/**
	 * Convert a MySQL datetime to TMX date format (20231005T120000Z).
	 */
	private function toTmxDate( string $mysqlDate ): string {
		$ts = $mysqlDate ? strtotime( $mysqlDate ) : time();

		return gmdate( 'Ymd\THis\Z', $ts ?: time() );
	}
}
