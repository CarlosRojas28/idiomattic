<?php
/**
 * StringTranslator — reads and writes translated strings in the database.
 *
 * Handles the runtime lookup path (gettext filters → DB) and the write path
 * (saving AI or manually entered translations back to the strings table).
 *
 * The strings table schema:
 *   id, source_hash (md5 of source_string), source_string, domain, lang,
 *   context, translated_string, status ('pending'|'translated'|'reviewed')
 *
 * @package IdiomatticWP\Strings
 */

declare( strict_types=1 );

namespace IdiomatticWP\Strings;

use IdiomatticWP\ValueObjects\LanguageCode;

class StringTranslator {

	private string $table;

	public function __construct( private \wpdb $wpdb ) {
		$this->table = $wpdb->prefix . 'idiomatticwp_strings';
	}

	/**
	 * Look up a translated string in the database.
	 *
	 * Returns the translated string when a row with status='translated' exists
	 * for the given source string + domain + language combination.
	 * Falls back to the original $string when no match is found.
	 *
	 * The $context parameter is accepted for API compatibility but is not
	 * currently used in the DB lookup — the hash is computed from the source
	 * string only, matching how StringRepository::register() stores it.
	 *
	 * @param string       $string  Original source string.
	 * @param string       $domain  Text domain (e.g. 'my-plugin').
	 * @param LanguageCode $lang    Target language.
	 * @param string       $context Optional gettext context (msgctxt).
	 * @return string Translated string, or $string if not found.
	 */
	public function translate( string $string, string $domain, LanguageCode $lang, string $context = '' ): string {
		$hash = md5( $string );

		$translated = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT translated_string FROM {$this->table}
				 WHERE source_hash = %s AND domain = %s AND lang = %s AND status = 'translated'",
				$hash,
				$domain,
				(string) $lang
			)
		);

		return $translated ?: $string;
	}

	/**
	 * Persist a translation for an existing string record.
	 *
	 * Attempts an UPDATE first. If no row matched (the record was deleted or
	 * never created), falls back to INSERT so the translation is never lost.
	 *
	 * Callers normally go through StringRepository::register() which creates
	 * the pending row before this method is invoked, but the INSERT fallback
	 * makes this method safe to call independently.
	 *
	 * @param string       $hash       md5 of the source string (from StringRepository).
	 * @param string       $domain     Text domain.
	 * @param LanguageCode $lang       Target language.
	 * @param string       $translated Translated value to store.
	 */
	public function saveTranslation( string $hash, string $domain, LanguageCode $lang, string $translated ): void {
		$updated = $this->wpdb->update(
			$this->table,
			[
				'translated_string' => $translated,
				'status'            => 'translated',
			],
			[
				'source_hash' => $hash,
				'domain'      => $domain,
				'lang'        => (string) $lang,
			],
			[ '%s', '%s' ],
			[ '%s', '%s', '%s' ]
		);

		// UPDATE returns 0 rows affected when the WHERE clause matches nothing.
		// Insert a minimal row so the translation is not silently discarded.
		if ( $updated === 0 ) {
			$this->wpdb->insert(
				$this->table,
				[
					'source_hash'       => $hash,
					'source_string'     => '',   // unknown at this call site
					'domain'            => $domain,
					'lang'              => (string) $lang,
					'translated_string' => $translated,
					'status'            => 'translated',
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}
	}

	/**
	 * Return all pending (untranslated) strings for a domain + language pair.
	 *
	 * Each row in the returned array contains:
	 *   - source_hash   (string) md5 of the source string
	 *   - source_string (string) original text
	 *   - context       (string) optional gettext context
	 *
	 * @param string       $domain Text domain.
	 * @param LanguageCode $lang   Target language.
	 * @return array<int, array{source_hash: string, source_string: string, context: string}>
	 */
	public function getPendingStrings( string $domain, LanguageCode $lang ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT source_hash, source_string, context FROM {$this->table}
				 WHERE domain = %s AND lang = %s AND status = 'pending'",
				$domain,
				(string) $lang
			),
			ARRAY_A
		) ?: [];
	}
}
