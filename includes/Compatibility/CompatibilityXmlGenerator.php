<?php
/**
 * CompatibilityXmlGenerator — generates idiomattic-elements.json for a plugin/theme
 * that has neither a native file nor a wpml-config.xml.
 *
 * The generated JSON is either downloaded by the user or saved to a transient
 * so a developer can copy it into their plugin/theme.
 *
 * @package IdiomatticWP\Compatibility
 */

declare( strict_types=1 );

namespace IdiomatticWP\Compatibility;

class CompatibilityXmlGenerator {

	/**
	 * Build the idiomattic-elements.json content for a given plugin/theme directory.
	 *
	 * Auto-detects translatable elements by:
	 *   1. Parsing wpml-config.xml if present
	 *   2. Parsing any existing idiomattic-elements.json (pass-through)
	 *   3. Scanning registered post meta (ACF / CMB2 if active)
	 *
	 * @param array $entry  A CompatibilityScanner entry array.
	 * @return string  JSON content ready for download.
	 */
	public function generate( array $entry ): string {
		$slug      = $entry['slug'];
		$name      = $entry['name'];
		$directory = $entry['directory'];
		$type      = $entry['type'];

		$data = [
			'$schema'     => 'https://idiomattic.app/schemas/elements.json',
			'plugin'      => $name,
			'version'     => $entry['version'] ?? '1.0',
			'generated'   => current_time( 'Y-m-d' ),
			'post_fields' => [],
			'options'     => [],
			'shortcodes'  => [],
			'blocks'      => [],
		];

		// ── Source 1: wpml-config.xml ─────────────────────────────────────
		$wpmlPath = $directory . '/wpml-config.xml';
		if ( file_exists( $wpmlPath ) ) {
			$data = $this->mergeFromWpml( $wpmlPath, $data );
		}

		// ── Source 2: idiomattic-elements.json (pass-through) ─────────────
		$nativePath = $directory . '/idiomattic-elements.json';
		if ( file_exists( $nativePath ) ) {
			$existing = json_decode( file_get_contents( $nativePath ), true );
			if ( is_array( $existing ) ) {
				return json_encode( $existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			}
		}

		/**
		 * Filter the generated elements data before JSON encoding.
		 *
		 * Third-party integrations can append their own elements.
		 *
		 * @param array  $data   The elements array.
		 * @param array  $entry  The CompatibilityScanner entry.
		 */
		$data = (array) apply_filters( 'idiomatticwp_generate_compat_data', $data, $entry );

		// Remove empty sections
		foreach ( [ 'post_fields', 'options', 'shortcodes', 'blocks' ] as $key ) {
			if ( empty( $data[ $key ] ) ) {
				unset( $data[ $key ] );
			}
		}

		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Trigger a browser download of the generated JSON for a given entry.
	 *
	 * Sends appropriate headers and exits. Must be called before any output.
	 */
	public function download( array $entry ): void {
		$json     = $this->generate( $entry );
		$filename = sanitize_file_name( $entry['slug'] . '-idiomattic-elements.json' );

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $json ) );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
		}

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	// ── Private helpers ───────────────────────────────────────────────────

	private function mergeFromWpml( string $xmlPath, array $data ): array {
		try {
			$xml = new \SimpleXMLElement( file_get_contents( $xmlPath ) );
		} catch ( \Exception $e ) {
			return $data;
		}

		// Custom fields → post_fields
		foreach ( $xml->{'custom-fields'} ?? [] as $section ) {
			foreach ( $section->{'custom-field'} ?? [] as $field ) {
				$key    = trim( (string) $field );
				$action = strtolower( (string) ( $field['action'] ?? 'translate' ) );
				$mode   = $this->wpmlActionToMode( $action );
				$pt     = trim( (string) ( $field['post_type'] ?? '*' ) );

				if ( $key === '' || $mode === 'ignore' ) continue;

				$data['post_fields'][] = [
					'key'        => $key,
					'post_types' => $pt === '' ? [ '*' ] : [ $pt ],
					'label'      => $this->humanise( $key ),
					'field_type' => 'text',
					'mode'       => $mode,
				];
			}
		}

		// Custom options → options
		foreach ( $xml->{'custom-options'} ?? [] as $section ) {
			foreach ( $section->{'custom-option'} ?? [] as $option ) {
				$key    = trim( (string) $option );
				$action = strtolower( (string) ( $option['action'] ?? 'translate' ) );
				$mode   = $this->wpmlActionToMode( $action );

				if ( $key === '' || $mode === 'ignore' ) continue;

				$data['options'][] = [
					'key'        => $key,
					'label'      => $this->humanise( $key ),
					'field_type' => 'text',
					'mode'       => $mode,
				];
			}
		}

		// Admin texts → options (flattened)
		foreach ( $xml->{'admin-texts'} ?? [] as $section ) {
			foreach ( $section->key ?? [] as $keyNode ) {
				$data = $this->flattenAdminTextKey( $keyNode, '', $data );
			}
		}

		// Shortcodes
		foreach ( $xml->shortcodes ?? [] as $section ) {
			foreach ( $section->shortcode ?? [] as $sc ) {
				$tag = trim( (string) ( $sc->tag ?? '' ) );
				if ( $tag === '' ) continue;

				$attributes = [];
				foreach ( $sc->attributes->attribute ?? [] as $attr ) {
					$n = trim( (string) ( $attr->name ?? '' ) );
					if ( $n !== '' ) $attributes[] = $n;
				}

				$data['shortcodes'][] = [
					'key'        => $tag,
					'attributes' => $attributes,
				];
			}
		}

		return $data;
	}

	private function flattenAdminTextKey( \SimpleXMLElement $node, string $prefix, array $data ): array {
		$name = trim( (string) ( $node['name'] ?? '' ) );
		if ( $name === '' ) return $data;

		$fullKey = $prefix !== '' ? "{$prefix}.{$name}" : $name;

		if ( count( $node->key ) === 0 ) {
			$data['options'][] = [
				'key'        => $fullKey,
				'label'      => $this->humanise( $name ),
				'field_type' => 'text',
				'mode'       => 'translate',
			];
		} else {
			foreach ( $node->key as $child ) {
				$data = $this->flattenAdminTextKey( $child, $fullKey, $data );
			}
		}

		return $data;
	}

	private function wpmlActionToMode( string $action ): string {
		return match ( $action ) {
			'translate'        => 'translate',
			'copy', 'copy-once' => 'copy',
			default            => 'ignore',
		};
	}

	private function humanise( string $key ): string {
		$key   = ltrim( $key, '_' );
		$parts = explode( '.', $key );
		$last  = end( $parts );
		return ucwords( str_replace( [ '_', '-' ], ' ', $last ) );
	}
}
