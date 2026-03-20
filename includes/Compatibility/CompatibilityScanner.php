<?php
/**
 * CompatibilityScanner — discovers active plugins and the active theme,
 * detects existing compatibility files (wpml-config.xml, idiomattic-elements.json),
 * and produces a structured compatibility report.
 *
 * Runs automatically on plugin activation and can be re-triggered from the
 * Compatibility admin page. Results are cached in a transient.
 *
 * @package IdiomatticWP\Compatibility
 */

declare( strict_types=1 );

namespace IdiomatticWP\Compatibility;

class CompatibilityScanner {

	private const TRANSIENT_KEY     = 'idiomatticwp_compat_scan';
	private const TRANSIENT_EXPIRY  = DAY_IN_SECONDS;

	/** @var string[] Known plugins/themes with built-in idiomattic-elements.json support */
	private array $nativeSupport = [];

	/**
	 * Run the full compatibility scan and cache the result.
	 * Passing $force = true bypasses the transient cache.
	 */
	public function scan( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$results = [
			'scanned_at' => current_time( 'mysql' ),
			'plugins'    => $this->scanPlugins(),
			'theme'      => $this->scanTheme(),
		];

		set_transient( self::TRANSIENT_KEY, $results, self::TRANSIENT_EXPIRY );

		return $results;
	}

	/**
	 * Clear the cached scan result so the next scan() call runs fresh.
	 */
	public function clearCache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	// ── Plugins ───────────────────────────────────────────────────────────

	private function scanPlugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$allPlugins    = get_plugins();
		$activePlugins = (array) get_option( 'active_plugins', [] );
		$results       = [];

		foreach ( $activePlugins as $pluginFile ) {
			if ( ! isset( $allPlugins[ $pluginFile ] ) ) {
				continue;
			}

			$pluginData = $allPlugins[ $pluginFile ];
			$pluginDir  = WP_PLUGIN_DIR . '/' . dirname( $pluginFile );

			$results[] = $this->buildEntry(
				$pluginFile,
				$pluginData['Name'],
				$pluginData['Version'],
				$pluginData['Author'],
				$pluginDir,
				'plugin'
			);
		}

		return $results;
	}

	// ── Theme ─────────────────────────────────────────────────────────────

	private function scanTheme(): array {
		$results = [];

		// Child theme
		$child = wp_get_theme();
		if ( $child->get( 'Template' ) ) {
			// Child theme exists — scan it first
			$results[] = $this->buildEntry(
				$child->get_stylesheet(),
				$child->get( 'Name' ),
				$child->get( 'Version' ),
				$child->get( 'Author' ),
				$child->get_stylesheet_directory(),
				'theme-child'
			);
		}

		// Parent (or only) theme
		$parent = wp_get_theme( $child->get( 'Template' ) ?: $child->get_stylesheet() );
		$results[] = $this->buildEntry(
			$parent->get_stylesheet(),
			$parent->get( 'Name' ),
			$parent->get( 'Version' ),
			$parent->get( 'Author' ),
			$parent->get_template_directory(),
			'theme'
		);

		return $results;
	}

	// ── Entry builder ─────────────────────────────────────────────────────

	/**
	 * Build a single compatibility entry for a plugin or theme.
	 *
	 * Compatibility levels:
	 *   'native'   — has idiomattic-elements.json (ideal)
	 *   'wpml'     — has wpml-config.xml (auto-importable)
	 *   'none'     — no known compatibility file
	 */
	private function buildEntry(
		string $slug,
		string $name,
		string $version,
		string $author,
		string $directory,
		string $type
	): array {
		$idiomatticFile = $directory . '/idiomattic-elements.json';
		$wpmlFile       = $directory . '/wpml-config.xml';

		$hasIdiomattic = file_exists( $idiomatticFile );
		$hasWpml       = file_exists( $wpmlFile );

		$compatibility = 'none';
		if ( $hasIdiomattic ) {
			$compatibility = 'native';
		} elseif ( $hasWpml ) {
			$compatibility = 'wpml';
		}

		$entry = [
			'slug'          => $slug,
			'name'          => $name,
			'version'       => $version,
			'author'        => $this->stripHtmlTags( $author ),
			'type'          => $type,
			'directory'     => $directory,
			'compatibility' => $compatibility,
			'has_idiomattic'=> $hasIdiomattic,
			'has_wpml'      => $hasWpml,
			'idiomattic_path' => $hasIdiomattic ? $idiomatticFile : null,
			'wpml_path'     => $hasWpml ? $wpmlFile : null,
			'elements'      => [],
		];

		// Parse elements from whichever file is available
		if ( $hasIdiomattic ) {
			$entry['elements'] = $this->countIdiomatticElements( $idiomatticFile );
		} elseif ( $hasWpml ) {
			$entry['elements'] = $this->countWpmlElements( $wpmlFile );
		}

		/**
		 * Filter a single compatibility entry before it is stored.
		 *
		 * @param array  $entry  The entry data.
		 * @param string $slug   Plugin file or theme slug.
		 * @param string $type   'plugin' | 'theme' | 'theme-child'
		 */
		return (array) apply_filters( 'idiomatticwp_compat_entry', $entry, $slug, $type );
	}

	// ── Element counters ──────────────────────────────────────────────────

	private function countIdiomatticElements( string $path ): array {
		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return [];
		}
		return [
			'post_fields' => count( $data['post_fields'] ?? [] ),
			'options'     => count( $data['options'] ?? [] ),
			'shortcodes'  => count( $data['shortcodes'] ?? [] ),
			'blocks'      => count( $data['blocks'] ?? [] ),
		];
	}

	private function countWpmlElements( string $path ): array {
		$counts = [
			'post_fields' => 0,
			'options'     => 0,
			'shortcodes'  => 0,
			'blocks'      => 0,
		];

		try {
			$xml = new \SimpleXMLElement( file_get_contents( $path ) );

			foreach ( $xml->{'custom-fields'} ?? [] as $section ) {
				$counts['post_fields'] += count( $section->{'custom-field'} ?? [] );
			}
			foreach ( $xml->{'custom-options'} ?? [] as $section ) {
				$counts['options'] += count( $section->{'custom-option'} ?? [] );
			}
			foreach ( $xml->shortcodes ?? [] as $section ) {
				$counts['shortcodes'] += count( $section->shortcode ?? [] );
			}
		} catch ( \Exception $e ) {
			// Invalid XML — not a blocking error
		}

		return $counts;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function stripHtmlTags( string $text ): string {
		return wp_strip_all_tags( $text );
	}
}
