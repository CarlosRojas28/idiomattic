<?php
/**
 * StringRepositoryInterface — contract for theme/plugin string persistence.
 *
 * Implemented by WpdbStringRepository (future) and any test doubles.
 *
 * @package IdiomatticWP\Contracts
 */

declare( strict_types=1 );

namespace IdiomatticWP\Contracts;

use IdiomatticWP\Strings\TranslatableString;
use IdiomatticWP\ValueObjects\LanguageCode;

interface StringRepositoryInterface {

	/**
	 * Insert or update a translatable string record.
	 *
	 * @param TranslatableString $string The string to persist.
	 */
	public function save( TranslatableString $string ): void;

	/**
	 * Find a string record by its hash and target language.
	 *
	 * @param string       $hash MD5 of domain + original + context.
	 * @param LanguageCode $lang Target language.
	 * @return TranslatableString|null Null when not found.
	 */
	public function findByHash( string $hash, LanguageCode $lang ): ?TranslatableString;

	/**
	 * Return all strings pending translation for a domain + language.
	 *
	 * @param string       $domain Translation domain.
	 * @param LanguageCode $lang   Target language.
	 * @return TranslatableString[]
	 */
	public function findUntranslated( string $domain, LanguageCode $lang ): array;
}
