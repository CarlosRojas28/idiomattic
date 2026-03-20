<?php
/**
 * TranslatableString — immutable value object for a theme/plugin string entry.
 *
 * @package IdiomatticWP\Strings
 */

declare( strict_types=1 );

namespace IdiomatticWP\Strings;

use IdiomatticWP\ValueObjects\LanguageCode;

/**
 * Represents a single localisation string with its source, context, and (optional) translation.
 *
 * Usage:
 *   $s = TranslatableString::fromSource( 'Read more', 'my-domain' );
 *   $translated = $s->withTranslation( 'Leer más', LanguageCode::from('es') );
 */
final class TranslatableString {

	/** @var string Possible status values. */
	public const STATUS_PENDING    = 'pending';
	public const STATUS_TRANSLATED = 'translated';
	public const STATUS_REVIEWED   = 'reviewed';

	public function __construct(
		public readonly string      $hash,
		public readonly string      $domain,
		public readonly string      $originalString,
		public readonly string      $context,
		public readonly string      $status,
		public readonly ?string     $translatedString,
		public readonly ?LanguageCode $lang,
	) {}

	/**
	 * Alias of fromSource() — used by StringScanner.
	 *
	 * @param string $domain  Translation domain.
	 * @param string $string  Original string.
	 * @param string $context Optional gettext context.
	 */
	public static function create( string $domain, string $string, string $context = '' ): self {
		return self::fromSource( $string, $domain, $context );
	}

	/**
	 * Create a new pending entry for a source string.
	 */
	public static function fromSource( string $string, string $domain, string $context = '' ): self {
		return new self(
			hash:             md5( $domain . $string . $context ),
			domain:           $domain,
			originalString:   $string,
			context:          $context,
			status:           self::STATUS_PENDING,
			translatedString: null,
			lang:             null,
		);
	}

	/**
	 * Create from a raw database row.
	 *
	 * @param array $row Associative array from wpdb.
	 */
	public static function fromRow( array $row ): self {
		return new self(
			hash:             (string) $row['source_hash'],
			domain:           (string) $row['domain'],
			originalString:   (string) $row['original_string'],
			context:          (string) ( $row['context'] ?? '' ),
			status:           (string) $row['status'],
			translatedString: isset( $row['translated_string'] ) ? (string) $row['translated_string'] : null,
			lang:             isset( $row['lang'] ) ? LanguageCode::from( $row['lang'] ) : null,
		);
	}

	/**
	 * Returns a copy with the translation applied.
	 */
	public function withTranslation( string $translation, LanguageCode $lang ): self {
		return new self(
			hash:             $this->hash,
			domain:           $this->domain,
			originalString:   $this->originalString,
			context:          $this->context,
			status:           self::STATUS_TRANSLATED,
			translatedString: $translation,
			lang:             $lang,
		);
	}

	/**
	 * Returns the best available string for display — translation if available, original otherwise.
	 */
	public function resolve(): string {
		return $this->translatedString ?? $this->originalString;
	}

	/**
	 * Returns true when the string has a translation saved.
	 */
	public function isTranslated(): bool {
		return $this->status === self::STATUS_TRANSLATED
			|| $this->status === self::STATUS_REVIEWED;
	}

	/**
	 * Serialize to array for DB persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'source_hash'       => $this->hash,
			'domain'            => $this->domain,
			'original_string'   => $this->originalString,
			'context'           => $this->context,
			'status'            => $this->status,
			'translated_string' => $this->translatedString,
			'lang'              => $this->lang ? (string) $this->lang : null,
		];
	}
}
