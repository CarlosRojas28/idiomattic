<?php
/**
 * TranslationMemoryInterface — contract for TM lookup and persistence.
 *
 * Implementations:
 *   - TranslationMemory      (Wpdb-backed, Standard/Pro)
 *   - NullTranslationMemory  (no-op, when TM is disabled)
 *
 * @package IdiomatticWP\Contracts
 */

declare( strict_types=1 );

namespace IdiomatticWP\Contracts;

use IdiomatticWP\Memory\MemoryMatch;
use IdiomatticWP\Memory\SavingsReport;
use IdiomatticWP\ValueObjects\LanguageCode;

interface TranslationMemoryInterface {

	/**
	 * Look up a text segment in the translation memory.
	 *
	 * Returns a MemoryMatch on hit, or null on miss.
	 *
	 * @param string       $text   Source text to look up.
	 * @param LanguageCode $source Source language.
	 * @param LanguageCode $target Target language.
	 * @return MemoryMatch|null
	 */
	public function lookup( string $text, LanguageCode $source, LanguageCode $target ): ?MemoryMatch;

	/**
	 * Persist a confirmed translation pair to the memory.
	 *
	 * @param string       $source     Original source text.
	 * @param string       $translated Translated text.
	 * @param LanguageCode $sourceLang Source language.
	 * @param LanguageCode $targetLang Target language.
	 * @param string       $provider   Provider slug that produced the translation.
	 */
	public function save(
		string $source,
		string $translated,
		LanguageCode $sourceLang,
		LanguageCode $targetLang,
		string $provider
	): void;
}
