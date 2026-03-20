<?php
/**
 * TbxFormat — TermBase eXchange (TBX-Basic) glossary import/export.
 *
 * TBX is the ISO 30042 standard for exchanging terminology data between
 * CAT tools, terminology management systems, and localization platforms
 * (SDL MultiTerm, memoQ, Phrase, Memsource…).
 *
 * This implementation uses TBX-Basic (the widely-supported subset).
 * Spec: https://www.tbxinfo.net/tbx-basic/
 *
 * @package IdiomatticWP\ImportExport
 */

declare( strict_types=1 );

namespace IdiomatticWP\ImportExport;

use IdiomatticWP\Glossary\GlossaryTerm;
use IdiomatticWP\Glossary\WpdbGlossaryRepository;
use IdiomatticWP\ValueObjects\LanguageCode;

class TbxFormat {

	public function __construct(
		private readonly WpdbGlossaryRepository $repository,
	) {}

	// ── Export ────────────────────────────────────────────────────────────

	/**
	 * Export all glossary terms for a language pair as a TBX-Basic XML string.
	 *
	 * @param LanguageCode $source Source language.
	 * @param LanguageCode $target Target language.
	 * @return string TBX XML document.
	 */
	public function export( LanguageCode $source, LanguageCode $target ): string {
		$terms = $this->repository->getTerms( $source, $target );

		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;

		// <TBX> root.
		$tbx = $doc->createElement( 'TBX' );
		$tbx->setAttribute( 'dialect', 'TBX-Basic' );
		$tbx->setAttribute( 'type', 'TBX-Basic' );
		$tbx->setAttribute( 'style', 'dca' );
		$tbx->setAttribute( 'xml:lang', (string) $source );
		$tbx->setAttribute( 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
		$tbx->setAttribute( 'xsi:noNamespaceSchemaLocation', 'https://raw.githubusercontent.com/LTAC-Global/TBX-Basic_dialect/master/DCA/TBXcoreStructV03_TBX-Basic_integrated.rng' );
		$doc->appendChild( $tbx );

		// <tbxHeader>.
		$tbxHeader = $doc->createElement( 'tbxHeader' );
		$tbx->appendChild( $tbxHeader );

		$fileDesc = $doc->createElement( 'fileDesc' );
		$tbxHeader->appendChild( $fileDesc );

		$sourceDesc = $doc->createElement( 'sourceDesc' );
		$sourceDesc->appendChild(
			$doc->createElement( 'p', 'Exported by Idiomattic WP — ' . gmdate( 'Y-m-d' ) )
		);
		$fileDesc->appendChild( $sourceDesc );

		// <text><body>.
		$text = $doc->createElement( 'text' );
		$body = $doc->createElement( 'body' );
		$text->appendChild( $body );
		$tbx->appendChild( $text );

		foreach ( $terms as $term ) {
			$body->appendChild( $this->buildConceptEntry( $doc, $term, $source, $target ) );
		}

		return $doc->saveXML();
	}

	/**
	 * Stream a TBX file download to the browser.
	 *
	 * @param LanguageCode $source Source language.
	 * @param LanguageCode $target Target language.
	 */
	public function download( LanguageCode $source, LanguageCode $target ): void {
		$xml      = $this->export( $source, $target );
		$filename = sprintf(
			'idiomatticwp-glossary-%s-%s-%s.tbx',
			(string) $source,
			(string) $target,
			gmdate( 'Y-m-d' )
		);

		header( 'Content-Type: application/x-tbx; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $xml ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ── Import ────────────────────────────────────────────────────────────

	/**
	 * Import glossary terms from a TBX XML string.
	 *
	 * Returns the number of terms successfully imported.
	 *
	 * @param string       $xml    Raw TBX XML content.
	 * @param LanguageCode $source Expected source language.
	 * @param LanguageCode $target Expected target language.
	 * @return int Number of concept entries imported.
	 *
	 * @throws \InvalidArgumentException When XML is malformed.
	 */
	public function import( string $xml, LanguageCode $source, LanguageCode $target ): int {
		$doc = new \DOMDocument();

		libxml_use_internal_errors( true );
		$parsed = $doc->loadXML( $xml );
		libxml_clear_errors();

		if ( ! $parsed ) {
			throw new \InvalidArgumentException( 'Invalid TBX XML provided.' );
		}

		$srcStr   = strtolower( (string) $source );
		$trgStr   = strtolower( (string) $target );
		$imported = 0;

		/** @var \DOMElement $entry */
		foreach ( $doc->getElementsByTagName( 'conceptEntry' ) as $entry ) {
			$srcTerm = null;
			$trgTerm = null;
			$notes   = null;
			$forbidden = false;

			/** @var \DOMElement $langSec */
			foreach ( $entry->getElementsByTagName( 'langSec' ) as $langSec ) {
				$lang = strtolower(
					str_replace( '_', '-', $langSec->getAttribute( 'xml:lang' ) ?: $langSec->getAttribute( 'lang' ) )
				);

				$termNode = $langSec->getElementsByTagName( 'term' )->item( 0 );
				if ( ! $termNode ) {
					continue;
				}

				$termValue = trim( $termNode->textContent );

				if ( $lang === $srcStr ) {
					$srcTerm = $termValue;
				} elseif ( $lang === $trgStr ) {
					$trgTerm = $termValue;

					// Check for forbidden/do-not-translate admin note.
					foreach ( $langSec->getElementsByTagName( 'termNote' ) as $note ) {
						/** @var \DOMElement $note */
						if ( 'administrativeStatus' === $note->getAttribute( 'type' )
							&& 'deprecatedTerm-admn-sts' === $note->textContent ) {
							$forbidden = true;
						}
					}
				}

				// Extract notes from source langSec.
				foreach ( $langSec->getElementsByTagName( 'note' ) as $noteEl ) {
					$notes = trim( $noteEl->textContent ) ?: null;
				}
			}

			if ( ! $srcTerm || ! $trgTerm ) {
				continue;
			}

			$this->repository->addTerm( $srcTerm, $trgTerm, $source, $target, $forbidden, $notes );
			++$imported;
		}

		return $imported;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Build a <conceptEntry> element for a single glossary term.
	 */
	private function buildConceptEntry(
		\DOMDocument $doc,
		GlossaryTerm $term,
		LanguageCode $source,
		LanguageCode $target
	): \DOMElement {
		$entry = $doc->createElement( 'conceptEntry' );
		$entry->setAttribute( 'id', 'term-' . $term->id );

		if ( $term->notes ) {
			$descrip = $doc->createElement( 'descrip', htmlspecialchars( $term->notes ) );
			$descrip->setAttribute( 'type', 'definition' );
			$entry->appendChild( $descrip );
		}

		// Source langSec.
		$entry->appendChild( $this->buildLangSec( $doc, (string) $source, $term->sourceTerm, false ) );

		// Target langSec.
		$entry->appendChild( $this->buildLangSec( $doc, (string) $target, $term->translatedTerm, $term->forbidden ) );

		return $entry;
	}

	/**
	 * Build a <langSec xml:lang="…"><termSec><term>…</term></termSec></langSec> structure.
	 */
	private function buildLangSec(
		\DOMDocument $doc,
		string $lang,
		string $termText,
		bool $forbidden
	): \DOMElement {
		$langSec = $doc->createElement( 'langSec' );
		$langSec->setAttribute( 'xml:lang', strtoupper( str_replace( '-', '_', $lang ) ) );

		$termSec = $doc->createElement( 'termSec' );
		$langSec->appendChild( $termSec );

		$term = $doc->createElement( 'term', htmlspecialchars( $termText ) );
		$termSec->appendChild( $term );

		if ( $forbidden ) {
			$termNote = $doc->createElement( 'termNote', 'deprecatedTerm-admn-sts' );
			$termNote->setAttribute( 'type', 'administrativeStatus' );
			$termSec->appendChild( $termNote );
		}

		return $langSec;
	}
}
