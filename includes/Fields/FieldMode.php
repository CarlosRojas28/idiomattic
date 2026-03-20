<?php
/**
 * FieldMode — enum representing how a field should be handled during translation.
 *
 * @package IdiomatticWP\Fields
 */

declare( strict_types=1 );

namespace IdiomatticWP\Fields;

enum FieldMode: string {

	case Translate = 'translate';
	case Copy      = 'copy';
	case Ignore    = 'ignore';

	/**
	 * Create a FieldMode from a string, defaulting to Translate for unknown values.
	 */
	public static function fromString( string $value ): self {
		return self::tryFrom( $value ) ?? self::Translate;
	}

	public function isTranslatable(): bool {
		return $this === self::Translate;
	}

	public function shouldCopy(): bool {
		return $this === self::Copy;
	}

	public function shouldIgnore(): bool {
		return $this === self::Ignore;
	}
}
