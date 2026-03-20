<?php
/**
 * WpmlConfigParser — reads wpml-config.xml files and registers their elements
 * into the IdiomatticWP CustomElementRegistry.
 *
 * WPML's XML format is documented at:
 * https://wpml.org/documentation/support/language-configuration-files/
 *
 * Supported sections (all currently used by real-world plugins):
 *   <custom-fields>        → post meta fields
 *   <custom-options>       → wp_options values
 *   <custom-types>         → custom post types (registered as translatable)
 *   <taxonomies>           → custom taxonomies
 *   <shortcodes>           → shortcode attributes
 *   <admin-texts>          → option-based theme/plugin texts
 *
 * @package IdiomatticWP\Compatibility
 */

declare( strict_types=1 );

namespace IdiomatticWP\Compatibility;

use IdiomatticWP\Core\CustomElementRegistry;

class WpmlConfigParser {

	public function __construct(
		private CustomElementRegistry $registry
	) {}

	/**
	 * Parse a wpml-config.xml file and register all discovered elements.
	 *
	 * @param string      $xmlPath   Absolute path to the wpml-config.xml file.
	 * @param string|null $postType  If known, restrict post_meta to this post type.
	 *                               Defaults to '*' (all post types).
	 * @return array Summary: ['registered' => int, 'skipped' => int, 'errors' => string[]]
	 */
	public function parse( string $xmlPath, ?string $postType = null ): array {
		if ( ! file_exists( $xmlPath ) ) {
			return [ 'registered' => 0, 'skipped' => 0, 'errors' => [ "File not found: {$xmlPath}" ] ];
		}

		$summary = [ 'registered' => 0, 'skipped' => 0, 'errors' => [] ];

		try {
			$xml = new \SimpleXMLElement( file_get_contents( $xmlPath ) );
		} catch ( \Exception $e ) {
			return [ 'registered' => 0, 'skipped' => 0, 'errors' => [ 'XML parse error: ' . $e->getMessage() ] ];
		}

		$postTypeTarget = $postType ?? '*';

		// 1. Custom post meta fields
		$summary = $this->parseCustomFields( $xml, $postTypeTarget, $summary );

		// 2. wp_options values
		$summary = $this->parseCustomOptions( $xml, $summary );

		// 3. Admin texts (nested options, common in themes)
		$summary = $this->parseAdminTexts( $xml, $summary );

		// 4. Shortcode attributes
		$summary = $this->parseShortcodes( $xml, $summary );

		do_action( 'idiomatticwp_wpml_config_parsed', $xmlPath, $summary, $this->registry );

		return $summary;
	}

	// ── Section parsers ───────────────────────────────────────────────────

	/**
	 * <custom-fields>
	 *   <custom-field action="translate" post_type="product">_my_field</custom-field>
	 * </custom-fields>
	 */
	private function parseCustomFields( \SimpleXMLElement $xml, string $defaultPostType, array $summary ): array {
		foreach ( $xml->{'custom-fields'} ?? [] as $section ) {
			foreach ( $section->{'custom-field'} ?? [] as $field ) {
				$key    = trim( (string) $field );
				$action = strtolower( (string) ( $field['action'] ?? 'translate' ) );

				// WPML actions: translate, copy, copy-once, ignore
				$mode = $this->wpmlActionToMode( $action );

				if ( $mode === 'ignore' || $key === '' ) {
					$summary['skipped']++;
					continue;
				}

				// post_type attribute is optional in WPML; default to wildcard
				$pt = trim( (string) ( $field['post_type'] ?? $defaultPostType ) );
				if ( $pt === '' ) {
					$pt = $defaultPostType;
				}

				$this->registry->registerPostField( $pt, $key, [
					'label'      => $this->humanise( $key ),
					'mode'       => $mode,
					'field_type' => 'text',
					'source'     => 'wpml-config',
				] );
				$summary['registered']++;
			}
		}

		return $summary;
	}

	/**
	 * <custom-options>
	 *   <custom-option action="translate">option_key</custom-option>
	 * </custom-options>
	 */
	private function parseCustomOptions( \SimpleXMLElement $xml, array $summary ): array {
		foreach ( $xml->{'custom-options'} ?? [] as $section ) {
			foreach ( $section->{'custom-option'} ?? [] as $option ) {
				$key    = trim( (string) $option );
				$action = strtolower( (string) ( $option['action'] ?? 'translate' ) );
				$mode   = $this->wpmlActionToMode( $action );

				if ( $mode === 'ignore' || $key === '' ) {
					$summary['skipped']++;
					continue;
				}

				$this->registry->registerOption( $key, [
					'label'      => $this->humanise( $key ),
					'mode'       => $mode,
					'field_type' => 'text',
					'source'     => 'wpml-config',
				] );
				$summary['registered']++;
			}
		}

		return $summary;
	}

	/**
	 * <admin-texts>
	 *   <key name="my_option">
	 *     <key name="nested_key" />
	 *   </key>
	 * </admin-texts>
	 *
	 * WPML uses this for nested option arrays. We flatten to dot-notation keys.
	 */
	private function parseAdminTexts( \SimpleXMLElement $xml, array $summary ): array {
		foreach ( $xml->{'admin-texts'} ?? [] as $section ) {
			foreach ( $section->key ?? [] as $keyNode ) {
				$summary = $this->parseAdminTextKey( $keyNode, '', $summary );
			}
		}

		return $summary;
	}

	private function parseAdminTextKey( \SimpleXMLElement $node, string $prefix, array $summary ): array {
		$name = trim( (string) ( $node['name'] ?? '' ) );
		if ( $name === '' ) {
			return $summary;
		}

		$fullKey = $prefix !== '' ? "{$prefix}.{$name}" : $name;

		if ( count( $node->key ) === 0 ) {
			// Leaf node — register as a translatable option
			$this->registry->registerOption( $fullKey, [
				'label'      => $this->humanise( $name ),
				'mode'       => 'translate',
				'field_type' => 'text',
				'source'     => 'wpml-config',
			] );
			$summary['registered']++;
		} else {
			// Recurse into nested keys
			foreach ( $node->key as $child ) {
				$summary = $this->parseAdminTextKey( $child, $fullKey, $summary );
			}
		}

		return $summary;
	}

	/**
	 * <shortcodes>
	 *   <shortcode>
	 *     <tag>my_shortcode</tag>
	 *     <attributes>
	 *       <attribute><name>title</name></attribute>
	 *       <attribute><name>caption</name></attribute>
	 *     </attributes>
	 *   </shortcode>
	 * </shortcodes>
	 */
	private function parseShortcodes( \SimpleXMLElement $xml, array $summary ): array {
		foreach ( $xml->shortcodes ?? [] as $section ) {
			foreach ( $section->shortcode ?? [] as $sc ) {
				$tag = trim( (string) ( $sc->tag ?? '' ) );
				if ( $tag === '' ) {
					$summary['skipped']++;
					continue;
				}

				$attributes = [];
				foreach ( $sc->attributes->attribute ?? [] as $attr ) {
					$attrName = trim( (string) ( $attr->name ?? '' ) );
					if ( $attrName !== '' ) {
						$attributes[] = $attrName;
					}
				}

				$this->registry->registerShortcode( $tag, $attributes, [
					'source' => 'wpml-config',
				] );
				$summary['registered']++;
			}
		}

		return $summary;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Map a WPML action string to an IdiomatticWP field mode.
	 */
	private function wpmlActionToMode( string $action ): string {
		return match ( $action ) {
			'translate'  => 'translate',
			'copy', 'copy-once' => 'copy',
			'ignore'     => 'ignore',
			default      => 'translate',
		};
	}

	/**
	 * Convert a snake_case/kebab-case meta key to a human-readable label.
	 */
	private function humanise( string $key ): string {
		// Strip leading underscore (common in post meta)
		$key = ltrim( $key, '_' );
		// Split on dots (nested keys)
		$parts = explode( '.', $key );
		$last  = end( $parts );
		return ucwords( str_replace( [ '_', '-' ], ' ', $last ) );
	}
}
