<?php
/**
 * FieldConfiguration — value object representing a single translatable field definition.
 *
 * Used by CustomElementRegistry, FieldClassifier, and the Translation Editor
 * to describe a field: what it is, how to handle it, and what label to show.
 *
 * Immutable by design — create via the static factory methods.
 *
 * @package IdiomatticWP\Fields
 */

declare( strict_types=1 );

namespace IdiomatticWP\Fields;

class FieldConfiguration {

	private function __construct(
		public readonly string    $id,
		public readonly string    $key,
		public readonly string    $type,        // 'post_meta'|'term_meta'|'option'|'block_attribute'|'custom'
		public readonly string    $label,
		public readonly string    $contentType, // 'text'|'html'|'textarea'
		public readonly FieldMode $mode,
		public readonly array     $postTypes,   // ['*'] means all post types
		public readonly array     $extra,       // arbitrary metadata for extensions
	) {}

	// ── Factories ─────────────────────────────────────────────────────────

	/**
	 * Create a post meta field configuration.
	 */
	public static function postMeta(
		string       $key,
		string|array $postTypes  = '*',
		string       $label      = '',
		string       $contentType = 'text',
		FieldMode    $mode       = FieldMode::Translate,
		array        $extra      = []
	): self {
		$types = is_string( $postTypes ) ? [ $postTypes ] : $postTypes;
		return new self(
			id:          'post_meta:' . implode( ',', $types ) . ':' . $key,
			key:         $key,
			type:        'post_meta',
			label:       $label ?: $key,
			contentType: $contentType,
			mode:        $mode,
			postTypes:   $types,
			extra:       $extra,
		);
	}

	/**
	 * Create a term meta field configuration.
	 */
	public static function termMeta(
		string       $key,
		string|array $taxonomies = '*',
		string       $label      = '',
		string       $contentType = 'text',
		FieldMode    $mode       = FieldMode::Translate,
		array        $extra      = []
	): self {
		$taxes = is_string( $taxonomies ) ? [ $taxonomies ] : $taxonomies;
		return new self(
			id:          'term_meta:' . implode( ',', $taxes ) . ':' . $key,
			key:         $key,
			type:        'term_meta',
			label:       $label ?: $key,
			contentType: $contentType,
			mode:        $mode,
			postTypes:   [ '*' ], // term meta doesn't have post types
			extra:       array_merge( $extra, [ 'taxonomies' => $taxes ] ),
		);
	}

	/**
	 * Create an option field configuration.
	 */
	public static function option(
		string    $key,
		string    $label       = '',
		string    $contentType = 'text',
		FieldMode $mode        = FieldMode::Translate,
		array     $extra       = []
	): self {
		return new self(
			id:          'option:' . $key,
			key:         $key,
			type:        'option',
			label:       $label ?: $key,
			contentType: $contentType,
			mode:        $mode,
			postTypes:   [ '*' ],
			extra:       $extra,
		);
	}

	// ── Interop with legacy array format ──────────────────────────────────

	/**
	 * Create a FieldConfiguration from the legacy array format used by CustomElementRegistry.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			id:          $data['id']         ?? ( $data['type'] . ':' . $data['key'] ),
			key:         $data['key']        ?? '',
			type:        $data['type']       ?? 'post_meta',
			label:       $data['label']      ?? ( $data['key'] ?? '' ),
			contentType: $data['field_type'] ?? 'text',
			mode:        FieldMode::fromString( $data['mode'] ?? 'translate' ),
			postTypes:   (array) ( $data['post_types'] ?? [ '*' ] ),
			extra:       array_diff_key( $data, array_flip( [ 'id', 'key', 'type', 'label', 'field_type', 'mode', 'post_types' ] ) ),
		);
	}

	/**
	 * Export back to the legacy array format for backwards compatibility.
	 */
	public function toArray(): array {
		return array_merge( [
			'id'         => $this->id,
			'key'        => $this->key,
			'type'       => $this->type,
			'label'      => $this->label,
			'field_type' => $this->contentType,
			'mode'       => $this->mode->value,
			'post_types' => $this->postTypes,
		], $this->extra );
	}

	// ── Convenience ───────────────────────────────────────────────────────

	public function appliesToPostType( string $postType ): bool {
		return in_array( '*', $this->postTypes, true )
			|| in_array( $postType, $this->postTypes, true );
	}

	public function isTranslatable(): bool {
		return $this->mode->isTranslatable();
	}
}
