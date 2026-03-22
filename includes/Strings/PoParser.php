<?php
/**
 * PoParser — parses GNU gettext .po file content into structured entries.
 *
 * Supports:
 *  - msgid / msgstr pairs
 *  - msgctxt (gettext context)
 *  - Multi-line strings (continuation lines starting with ")
 *  - Plural forms (msgid_plural / msgstr[N]) — imports msgstr[0] as the singular translation
 *
 * @package IdiomatticWP\Strings
 */

declare( strict_types=1 );

namespace IdiomatticWP\Strings;

class PoParser {

	/**
	 * Parse a .po file from disk.
	 *
	 * @return array<array{msgid: string, msgstr: string, msgctxt: string}>
	 */
	public function parseFile( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return [];
		}

		$content = file_get_contents( $path );
		if ( $content === false ) {
			return [];
		}

		return $this->parse( $content );
	}

	/**
	 * Parse raw .po file content.
	 *
	 * @return array<array{msgid: string, msgstr: string, msgctxt: string}>
	 */
	public function parse( string $content ): array {
		$entries = [];
		$lines   = explode( "\n", str_replace( "\r\n", "\n", $content ) );

		$current = [ 'msgid' => '', 'msgstr' => '', 'msgctxt' => '' ];
		$field   = null; // currently active field (msgid|msgstr|msgctxt)

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			// Blank line → flush current entry.
			if ( $line === '' ) {
				if ( $current['msgid'] !== '' && $current['msgstr'] !== '' ) {
					$entries[] = $current;
				}
				$current = [ 'msgid' => '', 'msgstr' => '', 'msgctxt' => '' ];
				$field   = null;
				continue;
			}

			// Comment lines.
			if ( $line[0] === '#' ) {
				continue;
			}

			// msgctxt
			if ( preg_match( '/^msgctxt\s+"(.*)"$/', $line, $m ) ) {
				$current['msgctxt'] = $this->unescape( $m[1] );
				$field = 'msgctxt';
				continue;
			}

			// msgid
			if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
				$current['msgid'] = $this->unescape( $m[1] );
				$field = 'msgid';
				continue;
			}

			// msgid_plural — remember the field but don't overwrite msgid.
			if ( preg_match( '/^msgid_plural\s+"(.*)"$/', $line, $m ) ) {
				$field = 'msgid_plural'; // skip continuation lines
				continue;
			}

			// msgstr (singular)
			if ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
				$current['msgstr'] = $this->unescape( $m[1] );
				$field = 'msgstr';
				continue;
			}

			// msgstr[0] — first plural form, use as the primary translation.
			if ( preg_match( '/^msgstr\[0\]\s+"(.*)"$/', $line, $m ) ) {
				$current['msgstr'] = $this->unescape( $m[1] );
				$field = 'msgstr';
				continue;
			}

			// Other plural forms — ignore.
			if ( preg_match( '/^msgstr\[\d+\]\s+"(.*)"$/', $line, $m ) ) {
				$field = null;
				continue;
			}

			// Continuation line.
			if ( $line[0] === '"' && $field !== null && $field !== 'msgid_plural' ) {
				if ( preg_match( '/^"(.*)"$/', $line, $m ) ) {
					$current[ $field ] .= $this->unescape( $m[1] );
				}
			}
		}

		// Flush last entry.
		if ( $current['msgid'] !== '' && $current['msgstr'] !== '' ) {
			$entries[] = $current;
		}

		return $entries;
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function unescape( string $s ): string {
		return str_replace(
			[ '\\n', '\\t', '\\"', '\\\\' ],
			[ "\n",  "\t",  '"',   '\\' ],
			$s
		);
	}
}
