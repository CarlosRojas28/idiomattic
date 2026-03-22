<?php
/**
 * LanguagePackImporter — downloads WordPress.org language packs for active
 * plugins and themes and imports the translations into idiomatticwp_strings.
 *
 * Flow for a given language code (e.g. 'es'):
 *  1. Convert BCP-47 code → WP locale (es → es_ES).
 *  2. For each active plugin/theme, check for an existing .po file in
 *     WP_LANG_DIR/plugins/ or WP_LANG_DIR/themes/.
 *  3. If missing, query the WordPress.org translations API and download + extract
 *     the language pack zip.
 *  4. Parse the .po file (or .mo if .po is absent) and upsert every entry into
 *     the strings table with status='translated'.
 *
 * @package IdiomatticWP\Strings
 */

declare( strict_types=1 );

namespace IdiomatticWP\Strings;

use IdiomatticWP\Repositories\StringRepository;
use IdiomatticWP\ValueObjects\LanguageCode;
use IdiomatticWP\Exceptions\InvalidLanguageCodeException;

class LanguagePackImporter {

	public function __construct(
		private StringRepository $stringRepo,
		private PoParser         $poParser,
	) {}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Import all available translations for the given BCP-47 language code.
	 *
	 * @return array{downloaded: int, strings: int, errors: string[]}
	 */
	public function importForLanguage( string $langCode ): array {
		try {
			$locale = LanguageCode::from( $langCode )->toLocale();
		} catch ( InvalidLanguageCodeException $e ) {
			return [ 'downloaded' => 0, 'strings' => 0, 'errors' => [ $e->getMessage() ] ];
		}

		$downloaded = 0;
		$strings    = 0;
		$errors     = [];

		foreach ( $this->collectSources() as $source ) {
			[ $dl, $imp, $errs ] = $this->importSource( $source, $locale, $langCode );
			$downloaded += $dl;
			$strings    += $imp;
			$errors      = array_merge( $errors, $errs );
		}

		return [ 'downloaded' => $downloaded, 'strings' => $strings, 'errors' => $errors ];
	}

	/**
	 * Import translations for a single plugin or theme across all given target languages.
	 *
	 * Called from ScanStringsAjax so that clicking "Scan strings" on a plugin/theme
	 * also imports any existing .po/.mo translations for it.
	 *
	 * @param string   $type      'plugin' | 'theme'
	 * @param string   $slug      Plugin directory slug or theme stylesheet slug.
	 * @param string   $domain    Text domain.
	 * @param string   $version   Version string (used for WP.org API download).
	 * @param string[] $langCodes BCP-47 target language codes.
	 * @return array{strings: int, errors: string[]}
	 */
	public function importSourceForLangs( string $type, string $slug, string $domain, string $version, array $langCodes ): array {
		$strings = 0;
		$errors  = [];

		foreach ( $langCodes as $langCode ) {
			try {
				$locale = LanguageCode::from( $langCode )->toLocale();
			} catch ( InvalidLanguageCodeException $e ) {
				$errors[] = $e->getMessage();
				continue;
			}

			$source = [ 'type' => $type, 'slug' => $slug, 'domain' => $domain, 'version' => $version ];
			[ , $imp, $errs ] = $this->importSource( $source, $locale, $langCode );
			$strings += $imp;
			$errors   = array_merge( $errors, $errs );
		}

		return [ 'strings' => $strings, 'errors' => $errors ];
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * @return array<array{type: string, slug: string, domain: string, version: string}>
	 */
	private function collectSources(): array {
		$sources = [];

		// Active plugins.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$allPlugins    = get_plugins();
		$activePlugins = (array) get_option( 'active_plugins', [] );

		foreach ( $activePlugins as $pluginFile ) {
			if ( ! isset( $allPlugins[ $pluginFile ] ) ) {
				continue;
			}
			$data   = $allPlugins[ $pluginFile ];
			$domain = (string) ( $data['TextDomain'] ?? '' );
			if ( $domain === '' ) {
				continue;
			}
			$slug = dirname( $pluginFile );
			if ( $slug === '.' ) {
				$slug = basename( $pluginFile, '.php' );
			}
			$sources[] = [
				'type'    => 'plugin',
				'slug'    => $slug,
				'domain'  => $domain,
				'version' => (string) ( $data['Version'] ?? '' ),
			];
		}

		// Active theme + parent.
		$child = wp_get_theme();
		if ( $child->exists() ) {
			$childDomain = (string) ( $child->get( 'TextDomain' ) ?: '' );
			if ( $childDomain !== '' ) {
				$sources[] = [
					'type'    => 'theme',
					'slug'    => $child->get_stylesheet(),
					'domain'  => $childDomain,
					'version' => (string) $child->get( 'Version' ),
				];
			}
			$parentSlug = $child->get( 'Template' );
			if ( $parentSlug ) {
				$parent       = wp_get_theme( $parentSlug );
				$parentDomain = (string) ( $parent->get( 'TextDomain' ) ?: '' );
				if ( $parentDomain !== '' ) {
					$sources[] = [
						'type'    => 'theme',
						'slug'    => $parent->get_stylesheet(),
						'domain'  => $parentDomain,
						'version' => (string) $parent->get( 'Version' ),
					];
				}
			}
		}

		return $sources;
	}

	/**
	 * @return array{0: int, 1: int, 2: string[]}  [downloaded, strings_imported, errors]
	 */
	private function importSource( array $source, string $locale, string $langCode ): array {
		$type    = $source['type'];
		$slug    = $source['slug'];
		$domain  = $source['domain'];
		$version = $source['version'];

		// Default download/install directory (WordPress convention).
		$defaultLangDir = WP_LANG_DIR . '/' . $type . 's/';

		// Resolve the first existing .po or .mo across all candidate paths.
		[ $foundPo, $foundMo ] = $this->findExistingFiles( $type, $slug, $domain, $locale );

		$downloaded = 0;
		$errors     = [];

		// If nothing found locally, attempt a download to the standard location.
		if ( $foundPo === null && $foundMo === null ) {
			$ok = $this->downloadPack( $type, $slug, $version, $locale, $defaultLangDir );
			if ( $ok ) {
				$downloaded = 1;
				$poCandidate = $defaultLangDir . $domain . '-' . $locale . '.po';
				$moCandidate = $defaultLangDir . $domain . '-' . $locale . '.mo';
				$foundPo     = file_exists( $poCandidate ) ? $poCandidate : null;
				$foundMo     = file_exists( $moCandidate ) ? $moCandidate : null;
			}
		}

		$imported = 0;
		if ( $foundPo !== null ) {
			$imported = $this->importFromPo( $foundPo, $domain, $langCode );
		} elseif ( $foundMo !== null ) {
			$imported = $this->importFromMo( $foundMo, $domain, $langCode );
		}

		return [ $downloaded, $imported, $errors ];
	}

	/**
	 * Search for .po and .mo files across all standard locations for a given source.
	 *
	 * WordPress loads translations from several paths. In order of preference:
	 *  1. WP_LANG_DIR/{type}s/{domain}-{locale}.{ext}  — auto-downloaded by WordPress
	 *  2. WP_PLUGIN_DIR/{slug}/languages/{domain}-{locale}.{ext}  — bundled in plugin
	 *  3. WP_PLUGIN_DIR/{slug}/i18n/languages/{domain}-{locale}.{ext}  — WooCommerce convention
	 *  4. WP_PLUGIN_DIR/{slug}/lang/{domain}-{locale}.{ext}  — some older plugins
	 *
	 * @return array{0: string|null, 1: string|null}  [po_path|null, mo_path|null]
	 */
	private function findExistingFiles( string $type, string $slug, string $domain, string $locale ): array {
		$basename = $domain . '-' . $locale;

		$dirs = [ WP_LANG_DIR . '/' . $type . 's/' ];

		if ( $type === 'plugin' ) {
			$pluginBase = WP_PLUGIN_DIR . '/' . $slug . '/';
			$dirs[]     = $pluginBase . 'languages/';
			$dirs[]     = $pluginBase . 'i18n/languages/';
			$dirs[]     = $pluginBase . 'lang/';
			$dirs[]     = $pluginBase;
		} elseif ( $type === 'theme' ) {
			$themeBase = get_theme_root() . '/' . $slug . '/';
			$dirs[]    = $themeBase . 'languages/';
			$dirs[]    = $themeBase . 'lang/';
			$dirs[]    = $themeBase;
		}

		$foundPo = null;
		$foundMo = null;

		foreach ( $dirs as $dir ) {
			if ( $foundPo === null ) {
				$candidate = $dir . $basename . '.po';
				if ( file_exists( $candidate ) ) {
					$foundPo = $candidate;
				}
			}
			if ( $foundMo === null ) {
				$candidate = $dir . $basename . '.mo';
				if ( file_exists( $candidate ) ) {
					$foundMo = $candidate;
				}
			}
			if ( $foundPo !== null && $foundMo !== null ) {
				break;
			}
		}

		return [ $foundPo, $foundMo ];
	}

	/**
	 * Download and extract a language pack zip from WordPress.org.
	 */
	private function downloadPack( string $type, string $slug, string $version, string $locale, string $langDir ): bool {
		if ( ! function_exists( 'translations_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$api = translations_api( $type, [ 'slug' => $slug, 'version' => $version ] );
		if ( is_wp_error( $api ) || empty( $api->translations ) ) {
			return false;
		}

		$packageUrl = null;
		foreach ( $api->translations as $t ) {
			if ( $t['language'] === $locale ) {
				$packageUrl = $t['package'] ?? null;
				break;
			}
		}

		if ( ! $packageUrl ) {
			return false;
		}

		// Download zip to a temp file.
		$tmpFile = download_url( $packageUrl );
		if ( is_wp_error( $tmpFile ) ) {
			return false;
		}

		wp_mkdir_p( $langDir );

		// Extract using ZipArchive (no WP Filesystem needed for temp extraction).
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( $zip->open( $tmpFile ) === true ) {
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$filename = $zip->getNameIndex( $i );
					// Extract only .po and .mo files.
					if ( ! preg_match( '/\.(po|mo)$/', $filename ) ) {
						continue;
					}
					$content = $zip->getFromIndex( $i );
					if ( $content !== false ) {
						file_put_contents( $langDir . basename( $filename ), $content );
					}
				}
				$zip->close();
				@unlink( $tmpFile );
				return true;
			}
			@unlink( $tmpFile );
			return false;
		}

		// Fallback: unzip_file (requires WP Filesystem).
		$result = unzip_file( $tmpFile, $langDir );
		@unlink( $tmpFile );

		return ! is_wp_error( $result );
	}

	/**
	 * Parse a .po file and upsert strings with translations.
	 */
	private function importFromPo( string $poPath, string $domain, string $langCode ): int {
		$entries = $this->poParser->parseFile( $poPath );
		$count   = 0;

		foreach ( $entries as $entry ) {
			if ( $entry['msgid'] === '' || $entry['msgstr'] === '' ) {
				continue;
			}
			$this->stringRepo->upsertWithTranslation(
				$domain,
				$entry['msgid'],
				$entry['msgctxt'],
				$langCode,
				$entry['msgstr']
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Parse a .mo file (binary) using WordPress's MO class and upsert strings.
	 */
	private function importFromMo( string $moPath, string $domain, string $langCode ): int {
		if ( ! class_exists( 'MO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
		}

		$mo = new \MO();
		if ( ! $mo->import_from_file( $moPath ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $mo->entries as $entry ) {
			/** @var \Translation_Entry $entry */
			$source      = $entry->singular ?? '';
			$translation = $entry->translations[0] ?? '';
			$context     = $entry->context ?? '';

			if ( $source === '' || $translation === '' ) {
				continue;
			}

			$this->stringRepo->upsertWithTranslation( $domain, $source, $context, $langCode, $translation );
			$count++;
		}

		return $count;
	}
}
