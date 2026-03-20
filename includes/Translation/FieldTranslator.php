<?php
/**
 * FieldTranslator — reads and writes field-level translations.
 *
 * @package IdiomatticWP\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Translation;

use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\ValueObjects\LanguageCode;

class FieldTranslator {

	private string $fieldTable;

	public function __construct(
		private CustomElementRegistry $registry,
		private \wpdb $wpdb
	) {
		$this->fieldTable = $wpdb->prefix . 'idiomatticwp_field_translations';
	}

	/**
	 * Copy fields from source post to target post based on registry rules.
	 */
	public function copyFieldsFromSource( int $sourceId, int $targetId, LanguageCode $targetLang ): void {
		$postType = get_post_type( $sourceId );
		$fields   = $this->registry->getFieldsForPostType( $postType );

		foreach ( $fields as $field ) {
			$key  = $field['key'];
			$mode = $field['mode'] ?? 'translate';

			if ( $mode === 'copy' ) {
				$value = get_post_meta( $sourceId, $key, true );
				update_post_meta( $targetId, $key, $value );
			}
			// 'translate' mode: leave blank on the duplicate, filled later by AIOrchestrator
		}

		do_action( 'idiomatticwp_fields_copied', $sourceId, $targetId, $targetLang );
	}

	/**
	 * Save a specific field translation to the database and apply it to the post.
	 *
	 * Note: the idiomatticwp_field_translations table has columns:
	 * id, translation_id, field_key, source_value, translated_value, status
	 * — no created_at / updated_at columns.
	 */
	public function saveFieldTranslation( int $translationId, string $fieldKey, string $translatedValue ): void {
		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->fieldTable} WHERE translation_id = %d AND field_key = %s",
				$translationId,
				$fieldKey
			)
		);

		if ( $existing ) {
			$this->wpdb->update(
				$this->fieldTable,
				[
					'translated_value' => $translatedValue,
					'status'           => 'translated',
				],
				[ 'id' => (int) $existing ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$this->wpdb->insert(
				$this->fieldTable,
				[
					'translation_id'   => $translationId,
					'field_key'        => $fieldKey,
					'translated_value' => $translatedValue,
					'status'           => 'translated',
				],
				[ '%d', '%s', '%s', '%s' ]
			);
		}

		// Apply the translation to the actual WordPress post meta
		$translatedPostId = $this->getTranslatedPostId( $translationId );
		if ( $translatedPostId ) {
			update_post_meta( $translatedPostId, $fieldKey, $translatedValue );
		}
	}

	/**
	 * Get all field translations for a translation relationship.
	 */
	public function getFieldTranslations( int $translationId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT field_key, source_value, translated_value, status FROM {$this->fieldTable} WHERE translation_id = %d",
				$translationId
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Get the translatable fields for a post (core + custom from registry).
	 */
	public function getTranslatableFields( int $postId ): array {
		return [
			'core'   => [
				[ 'key' => 'post_title',   'label' => 'Title',   'field_type' => 'text'     ],
				[ 'key' => 'post_content', 'label' => 'Content', 'field_type' => 'html'     ],
				[ 'key' => 'post_excerpt', 'label' => 'Excerpt', 'field_type' => 'textarea' ],
			],
			'custom' => array_values( $this->registry->getFieldsForPostType( get_post_type( $postId ) ) ),
		];
	}

	private function getTranslatedPostId( int $translationId ): ?int {
		$id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT translated_post_id FROM {$this->wpdb->prefix}idiomatticwp_translations WHERE id = %d",
				$translationId
			)
		);

		return $id > 0 ? $id : null;
	}
}
