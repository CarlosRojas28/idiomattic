<?php
/**
 * Translation — immutable value object representing a post translation record.
 *
 * Wraps the `idiomatticwp_translations` table row into a typed object.
 * Use WpdbTranslationRepository to persist/retrieve instances.
 *
 * @package IdiomatticWP\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Translation;

use IdiomatticWP\ValueObjects\LanguageCode;

/**
 * Possible values for the `status` column.
 */
enum TranslationStatus: string {
	case Draft      = 'draft';
	case InProgress = 'in_progress';
	case Complete   = 'complete';
	case Outdated   = 'outdated';
	case Failed     = 'failed';
}

/**
 * Possible values for the `translation_mode` column.
 */
enum TranslationMode: string {
	case Duplicate  = 'duplicate';
	case Editor     = 'editor';
	case Automatic  = 'automatic';
}

final class Translation {

	public function __construct(
		public readonly int               $id,
		public readonly int               $sourcePostId,
		public readonly int               $translatedPostId,
		public readonly LanguageCode      $sourceLang,
		public readonly LanguageCode      $targetLang,
		public readonly TranslationStatus $status,
		public readonly TranslationMode   $mode,
		public readonly string            $providerUsed,
		public readonly bool              $needsUpdate,
		public readonly ?string           $translatedAt,
		public readonly string            $createdAt,
	) {}

	/**
	 * Hydrate from a raw DB row (associative array from wpdb).
	 *
	 * @param array $row
	 * @throws \InvalidArgumentException When required fields are missing.
	 */
	public static function fromRow( array $row ): self {
		return new self(
			id:               (int) $row['id'],
			sourcePostId:     (int) $row['source_post_id'],
			translatedPostId: (int) $row['translated_post_id'],
			sourceLang:       LanguageCode::from( $row['source_lang'] ),
			targetLang:       LanguageCode::from( $row['target_lang'] ),
			status:           TranslationStatus::from( $row['status'] ),
			mode:             TranslationMode::from( $row['translation_mode'] ),
			providerUsed:     (string) ( $row['provider_used'] ?? '' ),
			needsUpdate:      (bool) $row['needs_update'],
			translatedAt:     $row['translated_at'] ?? null,
			createdAt:        (string) $row['created_at'],
		);
	}

	/**
	 * Serialize to a DB-ready associative array (excludes `id` for inserts).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = [
			'source_post_id'     => $this->sourcePostId,
			'translated_post_id' => $this->translatedPostId,
			'source_lang'        => (string) $this->sourceLang,
			'target_lang'        => (string) $this->targetLang,
			'status'             => $this->status->value,
			'translation_mode'   => $this->mode->value,
			'provider_used'      => $this->providerUsed,
			'needs_update'       => $this->needsUpdate ? 1 : 0,
			'translated_at'      => $this->translatedAt,
			'created_at'         => $this->createdAt,
		];

		if ( $this->id > 0 ) {
			$data['id'] = $this->id;
		}

		return $data;
	}

	// ── Convenience predicates ────────────────────────────────────────────

	public function isComplete(): bool {
		return $this->status === TranslationStatus::Complete;
	}

	public function isOutdated(): bool {
		return $this->status === TranslationStatus::Outdated || $this->needsUpdate;
	}

	public function isInProgress(): bool {
		return $this->status === TranslationStatus::InProgress;
	}

	/**
	 * Return a copy with a new status (immutability preserved).
	 */
	public function withStatus( TranslationStatus $status ): self {
		return new self(
			id:               $this->id,
			sourcePostId:     $this->sourcePostId,
			translatedPostId: $this->translatedPostId,
			sourceLang:       $this->sourceLang,
			targetLang:       $this->targetLang,
			status:           $status,
			mode:             $this->mode,
			providerUsed:     $this->providerUsed,
			needsUpdate:      $this->needsUpdate,
			translatedAt:     $this->translatedAt,
			createdAt:        $this->createdAt,
		);
	}
}
