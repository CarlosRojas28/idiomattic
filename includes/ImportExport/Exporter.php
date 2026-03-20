<?php
/**
 * Exporter — exports translation pairs to XLIFF 2.0 format.
 *
 * XLIFF (XML Localisation Interchange File Format) is the industry standard
 * for exchanging translation data with CAT tools (SDL Trados, memoQ, Phrase…).
 *
 * Exported file structure:
 *   xliff/
 *     source-lang/
 *       target-lang/
 *         post-{id}-{slug}.xliff
 *
 * Usage:
 *   $exporter->exportPost($postId, $targetLang);   → single post
 *   $exporter->exportAll($targetLang);              → all posts for a language
 *   $exporter->downloadZip($targetLang);            → streams a ZIP to browser
 *
 * @package IdiomatticWP\ImportExport
 */

declare( strict_types=1 );

namespace IdiomatticWP\ImportExport;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Infrastructure\WpdbTranslationRepository;
use IdiomatticWP\ValueObjects\LanguageCode;

class Exporter {

	public function __construct(
		private WpdbTranslationRepository $repository,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Export a single post's translatable fields as XLIFF 2.0 XML string.
	 *
	 * @param int          $sourcePostId  Source post ID.
	 * @param LanguageCode $targetLang    Target language.
	 * @return string|null XLIFF XML, or null if no translation record found.
	 */
	public function exportPost( int $sourcePostId, LanguageCode $targetLang ): ?string {
		$record = $this->repository->findBySourceAndLang( $sourcePostId, $targetLang );
		if ( ! $record ) {
			return null;
		}

		$sourcePost      = get_post( $sourcePostId );
		$translatedPost  = get_post( (int) $record['translated_post_id'] );

		if ( ! $sourcePost || ! $translatedPost ) {
			return null;
		}

		return $this->buildXliff(
			$sourcePost,
			$translatedPost,
			$record['source_lang'],
			(string) $targetLang
		);
	}

	/**
	 * Export all posts for a target language as an array of XLIFF strings.
	 * Keyed by source post ID.
	 *
	 * @param LanguageCode $targetLang
	 * @return array<int, string>
	 */
	public function exportAll( LanguageCode $targetLang ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'idiomatticwp_translations';
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE target_lang = %s ORDER BY source_post_id ASC",
				(string) $targetLang
			),
			ARRAY_A
		) ?: [];

		$results = [];

		foreach ( $records as $record ) {
			$xml = $this->exportPost( (int) $record['source_post_id'], $targetLang );
			if ( $xml ) {
				$results[ (int) $record['source_post_id'] ] = $xml;
			}
		}

		return $results;
	}

	/**
	 * Stream a ZIP archive of all XLIFF files for a language to the browser.
	 * Terminates execution after sending headers.
	 *
	 * @param LanguageCode $targetLang
	 */
	public function downloadZip( LanguageCode $targetLang ): void {
		$files = $this->exportAll( $targetLang );

		if ( empty( $files ) ) {
			wp_die( __( 'No translations found to export.', 'idiomattic-wp' ) );
		}

		$zipFile = tempnam( sys_get_temp_dir(), 'idiomatticwp_export_' );
		$zip     = new \ZipArchive();

		if ( $zip->open( $zipFile, \ZipArchive::OVERWRITE ) !== true ) {
			wp_die( __( 'Could not create export archive.', 'idiomattic-wp' ) );
		}

		foreach ( $files as $postId => $xml ) {
			$post = get_post( $postId );
			$slug = $post ? sanitize_file_name( $post->post_name ?: "post-{$postId}" ) : "post-{$postId}";
			$zip->addFromString( "post-{$postId}-{$slug}.xliff", $xml );
		}

		$zip->close();

		$filename = sprintf( 'idiomatticwp-export-%s-%s.zip', (string) $targetLang, date( 'Y-m-d' ) );

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $zipFile ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		readfile( $zipFile );
		unlink( $zipFile );
		exit;
	}

	// ── XLIFF builder ─────────────────────────────────────────────────────

	/**
	 * Build an XLIFF 2.0 document from a source/translated post pair.
	 */
	private function buildXliff(
		\WP_Post $source,
		\WP_Post $translated,
		string   $sourceLang,
		string   $targetLang
	): string {
		$doc  = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;

		// <xliff> root
		$xliff = $doc->createElement( 'xliff' );
		$xliff->setAttribute( 'version', '2.0' );
		$xliff->setAttribute( 'srcLang', $sourceLang );
		$xliff->setAttribute( 'trgLang', $targetLang );
		$xliff->setAttribute( 'xmlns', 'urn:oasis:names:tc:xliff:document:2.0' );
		$doc->appendChild( $xliff );

		// <file> element
		$file = $doc->createElement( 'file' );
		$file->setAttribute( 'id', 'post-' . $source->ID );
		$file->setAttribute( 'original', (string) get_permalink( $source->ID ) );
		$xliff->appendChild( $file );

		// <unit> for each field
		$fields = [
			'post_title'   => [ 'label' => 'Title',   'value_src' => $source->post_title,   'value_trg' => $translated->post_title   ],
			'post_content' => [ 'label' => 'Content', 'value_src' => $source->post_content, 'value_trg' => $translated->post_content ],
			'post_excerpt' => [ 'label' => 'Excerpt', 'value_src' => $source->post_excerpt, 'value_trg' => $translated->post_excerpt ],
		];

		foreach ( $fields as $key => $fieldData ) {
			if ( empty( $fieldData['value_src'] ) ) {
				continue;
			}

			$unit = $doc->createElement( 'unit' );
			$unit->setAttribute( 'id', $key );
			$unit->setAttribute( 'name', $fieldData['label'] );

			$segment = $doc->createElement( 'segment' );
			$unit->appendChild( $segment );

			$src = $doc->createElement( 'source' );
			$src->appendChild( $doc->createTextNode( $fieldData['value_src'] ) );
			$segment->appendChild( $src );

			$trg = $doc->createElement( 'target' );
			$trg->appendChild( $doc->createTextNode( $fieldData['value_trg'] ) );
			$segment->appendChild( $trg );

			$file->appendChild( $unit );
		}

		return $doc->saveXML();
	}
}
