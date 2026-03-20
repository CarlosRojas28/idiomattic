<?php
/**
 * Importer — imports translations from XLIFF 2.0 files.
 *
 * Reads XLIFF files exported by the Exporter (or produced by a CAT tool)
 * and writes the translated values back into the WordPress database.
 *
 * Supported formats:
 *   - XLIFF 2.0 (urn:oasis:names:tc:xliff:document:2.0)
 *   - XLIFF 1.2 (urn:oasis:names:tc:xliff:document:1.2)  — legacy
 *
 * Usage:
 *   $result = $importer->importFromFile('/path/to/file.xliff');
 *   $result = $importer->importFromString($xliffXml, $sourcePostId);
 *
 * @package IdiomatticWP\ImportExport
 */

declare( strict_types=1 );

namespace IdiomatticWP\ImportExport;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\ValueObjects\LanguageCode;

class Importer {

	public function __construct(
		private TranslationRepositoryInterface $repository,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Import from an uploaded XLIFF file path.
	 *
	 * @param string $filePath Absolute path to the .xliff file.
	 * @return ImportResult
	 */
	public function importFromFile( string $filePath ): ImportResult {
		if ( ! file_exists( $filePath ) || ! is_readable( $filePath ) ) {
			return ImportResult::failure( "File not found or not readable: {$filePath}" );
		}

		$xml = file_get_contents( $filePath );
		if ( $xml === false ) {
			return ImportResult::failure( "Could not read file: {$filePath}" );
		}

		return $this->importFromString( $xml );
	}

	/**
	 * Import from XLIFF XML string.
	 *
	 * @param string $xml Raw XLIFF XML content.
	 * @return ImportResult
	 */
	public function importFromString( string $xml ): ImportResult {
		$doc = new \DOMDocument();
		$doc->preserveWhiteSpace = false;

		libxml_use_internal_errors( true );
		$loaded = $doc->loadXML( $xml );
		$errors = libxml_get_errors();
		libxml_clear_errors();

		if ( ! $loaded || ! empty( $errors ) ) {
			return ImportResult::failure( 'Invalid XML: ' . ( $errors[0]->message ?? 'parse error' ) );
		}

		$root = $doc->documentElement;
		if ( ! $root || $root->localName !== 'xliff' ) {
			return ImportResult::failure( 'Not a valid XLIFF document.' );
		}

		$version = $root->getAttribute( 'version' );

		return match ( true ) {
			str_starts_with( $version, '2' ) => $this->importXliff2( $root ),
			str_starts_with( $version, '1' ) => $this->importXliff1( $root ),
			default                           => ImportResult::failure( "Unsupported XLIFF version: {$version}" ),
		};
	}

	// ── XLIFF 2.0 parser ─────────────────────────────────────────────────

	private function importXliff2( \DOMElement $root ): ImportResult {
		$targetLangStr = $root->getAttribute( 'trgLang' );
		if ( ! $targetLangStr ) {
			return ImportResult::failure( 'Missing trgLang attribute on <xliff>.' );
		}

		try {
			$targetLang = LanguageCode::from( $targetLangStr );
		} catch ( \Throwable $e ) {
			return ImportResult::failure( "Invalid target language code: {$targetLangStr}" );
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];

		$files = $root->getElementsByTagName( 'file' );

		foreach ( $files as $file ) {
			/** @var \DOMElement $file */
			$fileId = $file->getAttribute( 'id' ); // e.g. "post-42"
			$postId = (int) preg_replace( '/\D/', '', $fileId );

			if ( ! $postId ) {
				$skipped++;
				continue;
			}

			$record = $this->repository->findBySourceAndLang( $postId, $targetLang );
			if ( ! $record ) {
				$errors[] = "No translation record for post {$postId} → {$targetLangStr}";
				$skipped++;
				continue;
			}

			$translatedPostId = (int) $record['translated_post_id'];
			$updateData       = [ 'ID' => $translatedPostId ];

			$units = $file->getElementsByTagName( 'unit' );

			foreach ( $units as $unit ) {
				/** @var \DOMElement $unit */
				$fieldKey = $unit->getAttribute( 'id' ); // e.g. "post_title"
				$targets  = $unit->getElementsByTagName( 'target' );

				if ( $targets->length === 0 ) {
					continue;
				}

				$value = $targets->item( 0 )->textContent;

				if ( in_array( $fieldKey, [ 'post_title', 'post_content', 'post_excerpt' ], true ) ) {
					$updateData[ $fieldKey ] = $value;
				} else {
					// Custom meta field
					update_post_meta( $translatedPostId, $fieldKey, $value );
				}
			}

			if ( count( $updateData ) > 1 ) {
				wp_update_post( $updateData );
				$imported++;
			}
		}

		return new ImportResult( $imported, $skipped, $errors );
	}

	// ── XLIFF 1.2 parser ─────────────────────────────────────────────────

	private function importXliff1( \DOMElement $root ): ImportResult {
		$errors   = [];
		$imported = 0;
		$skipped  = 0;

		$files = $root->getElementsByTagName( 'file' );

		foreach ( $files as $file ) {
			/** @var \DOMElement $file */
			$targetLangStr = $file->getAttribute( 'target-language' );
			if ( ! $targetLangStr ) {
				$skipped++;
				continue;
			}

			try {
				$targetLang = LanguageCode::from( $targetLangStr );
			} catch ( \Throwable $e ) {
				$skipped++;
				continue;
			}

			$original = $file->getAttribute( 'original' ); // may be a URL or post ID
			$postId   = $this->resolvePostIdFromOriginal( $original );

			if ( ! $postId ) {
				$skipped++;
				continue;
			}

			$record = $this->repository->findBySourceAndLang( $postId, $targetLang );
			if ( ! $record ) {
				$skipped++;
				continue;
			}

			$translatedPostId = (int) $record['translated_post_id'];
			$updateData       = [ 'ID' => $translatedPostId ];

			$transUnits = $file->getElementsByTagName( 'trans-unit' );

			foreach ( $transUnits as $unit ) {
				/** @var \DOMElement $unit */
				$fieldKey = $unit->getAttribute( 'id' );
				$targets  = $unit->getElementsByTagName( 'target' );

				if ( $targets->length === 0 ) continue;

				$value = $targets->item( 0 )->textContent;

				if ( in_array( $fieldKey, [ 'post_title', 'post_content', 'post_excerpt' ], true ) ) {
					$updateData[ $fieldKey ] = $value;
				} else {
					update_post_meta( $translatedPostId, $fieldKey, $value );
				}
			}

			if ( count( $updateData ) > 1 ) {
				wp_update_post( $updateData );
				$imported++;
			}
		}

		return new ImportResult( $imported, $skipped, $errors );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Try to resolve a post ID from a URL or "post-{id}" string.
	 */
	private function resolvePostIdFromOriginal( string $original ): int {
		// Direct numeric ID
		if ( is_numeric( $original ) ) {
			return (int) $original;
		}

		// "post-{id}" pattern
		if ( preg_match( '/^post-(\d+)$/', $original, $m ) ) {
			return (int) $m[1];
		}

		// URL — try url_to_postid()
		if ( filter_var( $original, FILTER_VALIDATE_URL ) ) {
			return (int) url_to_postid( $original );
		}

		return 0;
	}
}

// ── Value object ──────────────────────────────────────────────────────────────

/**
 * Result of an import operation.
 */
final class ImportResult {

	public function __construct(
		public readonly int   $imported,
		public readonly int   $skipped,
		public readonly array $errors,
	) {}

	public static function failure( string $message ): self {
		return new self( 0, 0, [ $message ] );
	}

	public function isSuccess(): bool {
		return $this->imported > 0 && empty( $this->errors );
	}
}
