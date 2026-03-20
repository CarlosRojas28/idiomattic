<?php
/**
 * LanguageCode — immutable BCP-47 language code value object.
 *
 * @package IdiomatticWP\ValueObjects
 */

declare( strict_types=1 );

namespace IdiomatticWP\ValueObjects;

use IdiomatticWP\Exceptions\InvalidLanguageCodeException;

final class LanguageCode {

	// Codes that retain their region variant
	private const KEEP_REGION = [ 'pt-BR', 'en-US', 'en-GB', 'zh-CN', 'zh-TW', 'fr-CA' ];

	// RTL base codes
	private const RTL_CODES = [ 'ar', 'he', 'fa', 'ur', 'yi', 'dv', 'ha' ];

	// BCP-47 → WordPress locale map
	private const LOCALE_MAP = [
		'ar'    => 'ar',
		'bg'    => 'bg_BG',
		'ca'    => 'ca',
		'zh-CN' => 'zh_CN',
		'zh-TW' => 'zh_TW',
		'hr'    => 'hr',
		'cs'    => 'cs_CZ',
		'da'    => 'da_DK',
		'nl'    => 'nl_NL',
		'en'    => 'en_US',
		'en-US' => 'en_US',
		'en-GB' => 'en_GB',
		'fi'    => 'fi',
		'fr'    => 'fr_FR',
		'fr-CA' => 'fr_CA',
		'de'    => 'de_DE',
		'el'    => 'el',
		'he'    => 'he_IL',
		'hi'    => 'hi_IN',
		'hu'    => 'hu_HU',
		'id'    => 'id_ID',
		'it'    => 'it_IT',
		'ja'    => 'ja',
		'ko'    => 'ko_KR',
		'lv'    => 'lv',
		'lt'    => 'lt_LT',
		'ms'    => 'ms_MY',
		'nb'    => 'nb_NO',
		'fa'    => 'fa_IR',
		'pl'    => 'pl_PL',
		'pt'    => 'pt_PT',
		'pt-BR' => 'pt_BR',
		'ro'    => 'ro_RO',
		'ru'    => 'ru_RU',
		'sr'    => 'sr_RS',
		'sk'    => 'sk_SK',
		'sl'    => 'sl_SI',
		'es'    => 'es_ES',
		'sv'    => 'sv_SE',
		'th'    => 'th',
		'tr'    => 'tr_TR',
		'uk'    => 'uk',
		'vi'    => 'vi',
	];

	private function __construct( private readonly string $code ) {}

	/**
	 * Create from a BCP-47 code ('en', 'pt-BR', 'zh-CN').
	 *
	 * @throws InvalidLanguageCodeException
	 */
	public static function from( string $code ): self {
		if ( ! preg_match( '/^[a-z]{2}(-[A-Z]{2})?$/', $code ) ) {
			throw new InvalidLanguageCodeException( "Invalid language code: {$code}" );
		}
		return new self( $code );
	}

	/**
	 * Create from a WordPress locale ('en_US', 'pt_BR').
	 *
	 * @throws InvalidLanguageCodeException
	 */
	public static function fromLocale( string $locale ): self {
		// Convert underscore to hyphen: 'pt_BR' → 'pt-BR'
		$bcp47 = str_replace( '_', '-', $locale );

		// Split into base + region
		$parts  = explode( '-', $bcp47 );
		$base   = strtolower( $parts[0] );
		$region = isset( $parts[1] ) ? strtoupper( $parts[1] ) : null;

		if ( $region ) {
			$full = "{$base}-{$region}";
			// Keep region only for meaningful variants
			$code = in_array( $full, self::KEEP_REGION, true ) ? $full : $base;
		} else {
			$code = $base;
		}

		return self::from( $code );
	}

	public function __toString(): string {
		return $this->code;
	}

	public function equals( self $other ): bool {
		return $this->code === $other->code;
	}

	/**
	 * Returns the two-letter base: 'pt-BR' → 'pt'.
	 */
	public function getBase(): string {
		return explode( '-', $this->code )[0];
	}

	/**
	 * Returns WordPress locale: 'es' → 'es_ES', 'pt-BR' → 'pt_BR'.
	 */
	public function toLocale(): string {
		if ( isset( self::LOCALE_MAP[ $this->code ] ) ) {
			return self::LOCALE_MAP[ $this->code ];
		}
		// Fallback: convert hyphen to underscore
		return str_replace( '-', '_', $this->code );
	}

	/**
	 * Returns true for right-to-left languages.
	 */
	public function isRtl(): bool {
		return in_array( $this->getBase(), self::RTL_CODES, true );
	}
}
