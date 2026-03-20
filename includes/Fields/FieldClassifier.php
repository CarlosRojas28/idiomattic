<?php
/**
 * FieldClassifier — determines how a field should be handled during translation.
 *
 * Classifies any field (post meta, term meta, option, block attribute) into
 * one of three translation modes:
 *
 *   translate — the value should be sent to the AI provider for translation
 *   copy      — the value should be copied verbatim (e.g. images, IDs)
 *   ignore    — the field should not be touched at all
 *
 * Classification is done in order of priority:
 *   1. Explicit registration in CustomElementRegistry (highest priority)
 *   2. Known meta key patterns (prefix-based rules)
 *   3. Content type heuristics (serialized data, JSON, numeric)
 *   4. Default: translate
 *
 * This is intentionally a stateless, pure-function service — it never
 * writes to the database or modifies WordPress state.
 *
 * Filters:
 *   idiomatticwp_field_mode           — override mode for any field
 *   idiomatticwp_field_content_type   — override content type ('text'|'html'|'json'|...)
 *
 * @package IdiomatticWP\Fields
 */

declare( strict_types=1 );

namespace IdiomatticWP\Fields;

use IdiomatticWP\Core\CustomElementRegistry;

class FieldClassifier {

	/**
	 * Meta key prefixes that should always be copied verbatim.
	 * These are structural fields managed by their own plugins.
	 */
	private const COPY_PREFIXES = [
		'_thumbnail_id',
		'_product_image_gallery',
		'_wp_attachment',
		'rank_math_',
		'_yoast_wpseo_canonical',
		'_yoast_wpseo_redirect',
	];

	/**
	 * Meta key prefixes / exact keys that should always be ignored.
	 * These are internal WordPress or plugin housekeeping keys.
	 */
	private const IGNORE_PREFIXES = [
		'_edit_',
		'_wp_old_slug',
		'_wp_page_template',
		'_menu_item_',
		'_elementor_css',
		'_elementor_page_settings',
		'_elementor_template_type',
		'_et_pb_',    // Divi builder internal
		'_wpb_',      // WPBakery internal
	];

	/**
	 * Exact meta keys that are always ignored.
	 */
	private const IGNORE_KEYS = [
		'_edit_lock',
		'_edit_last',
		'post_views_count',
		'_pingme',
		'_encloseme',
	];

	public function __construct(
		private CustomElementRegistry $registry,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Classify a single field.
	 *
	 * @param string $key       Meta key or field identifier.
	 * @param mixed  $value     Current field value (used for heuristics).
	 * @param string $postType  The post type context (used for registry lookup).
	 *
	 * @return FieldMode One of: translate, copy, ignore.
	 */
	public function classify( string $key, mixed $value, string $postType = '*' ): FieldMode {
		// 1. Check the registry first — explicit registrations always win
		$registered = $this->registry->getByKey( $key );
		if ( $registered ) {
			$mode = FieldMode::fromString( $registered['mode'] ?? 'translate' );
		} else {
			// 2. Apply prefix-based rules
			$mode = $this->classifyByKeyPatterns( $key );
		}

		if ( $mode === FieldMode::Translate ) {
			// 3. Heuristics — skip non-translatable values
			$mode = $this->classifyByValueHeuristics( $value );
		}

		/**
		 * Allow third-party code to override the classification.
		 *
		 * @param string    $modeString  'translate'|'copy'|'ignore'
		 * @param string    $key         Meta key.
		 * @param mixed     $value       Field value.
		 * @param string    $postType    Post type.
		 */
		$modeString = (string) apply_filters(
			'idiomatticwp_field_mode',
			$mode->value,
			$key,
			$value,
			$postType
		);

		return FieldMode::fromString( $modeString );
	}

	/**
	 * Determine the content type of a field value.
	 * Used by the Segmenter and AI providers to pick the right strategy.
	 *
	 * @return string 'html'|'text'|'json'|'serialized'|'numeric'|'empty'
	 */
	public function contentType( string $key, mixed $value ): string {
		// Check registry first
		$registered = $this->registry->getByKey( $key );
		if ( $registered && isset( $registered['field_type'] ) ) {
			return $this->normalizeContentType( $registered['field_type'] );
		}

		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			return 'empty';
		}

		if ( is_serialized( $value ) ) {
			return 'serialized';
		}

		$decoded = json_decode( $value, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return 'json';
		}

		if ( is_numeric( trim( $value ) ) ) {
			return 'numeric';
		}

		// Detect HTML by looking for tags
		if ( preg_match( '/<[a-z][^>]*>/i', $value ) ) {
			return 'html';
		}

		/**
		 * Allow third-party code to override the content type.
		 *
		 * @param string $type  Detected content type.
		 * @param string $key   Meta key.
		 * @param mixed  $value Field value.
		 */
		return (string) apply_filters( 'idiomatticwp_field_content_type', 'text', $key, $value );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	private function classifyByKeyPatterns( string $key ): FieldMode {
		// Exact key matches
		if ( in_array( $key, self::IGNORE_KEYS, true ) ) {
			return FieldMode::Ignore;
		}

		// Prefix matches
		foreach ( self::IGNORE_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return FieldMode::Ignore;
			}
		}

		foreach ( self::COPY_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) || $key === $prefix ) {
				return FieldMode::Copy;
			}
		}

		return FieldMode::Translate;
	}

	private function classifyByValueHeuristics( mixed $value ): FieldMode {
		if ( ! is_string( $value ) ) {
			return FieldMode::Ignore;
		}

		$trimmed = trim( $value );

		if ( $trimmed === '' ) {
			return FieldMode::Ignore;
		}

		// Serialized data — do not attempt to translate, could be any structure
		if ( is_serialized( $value ) ) {
			return FieldMode::Ignore;
		}

		// Pure numeric or ID-like values — copy or ignore
		if ( is_numeric( $trimmed ) ) {
			return FieldMode::Copy;
		}

		// Single word without spaces — likely a slug, code, or identifier
		if ( ! str_contains( $trimmed, ' ' ) && strlen( $trimmed ) < 40 ) {
			return FieldMode::Copy;
		}

		return FieldMode::Translate;
	}

	private function normalizeContentType( string $fieldType ): string {
		return match ( $fieldType ) {
			'wysiwyg', 'rich_text', 'html' => 'html',
			'text', 'string', 'textarea'   => 'text',
			default                         => 'text',
		};
	}
}
