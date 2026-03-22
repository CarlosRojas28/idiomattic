<?php
/**
 * CompatibilityPage — admin page showing installed plugins and themes,
 * their compatibility status, and download buttons for compatibility files.
 *
 * URL: wp-admin/admin.php?page=idiomatticwp-compatibility
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Compatibility\CompatibilityScanner;
use IdiomatticWP\Compatibility\CompatibilityXmlGenerator;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Compatibility\WpmlConfigParser;

class CompatibilityPage {

	public function __construct(
		private CompatibilityScanner      $scanner,
		private CompatibilityXmlGenerator $generator,
		private CustomElementRegistry     $registry,
		private WpmlConfigParser          $wpmlParser,
	) {}

	// ─────────────────────────────────────────────────────────────────────────
	// Entry point
	// ─────────────────────────────────────────────────────────────────────────

	public function render(): void {
		// Handle redirecting actions (rescan, download, import) before output.
		$this->handleActions();

		// Fetch scan data (cached unless just rescanned).
		$scan    = $this->scanner->scan();
		$plugins = $scan['plugins'] ?? [];
		$themes  = $scan['theme']   ?? [];
		$all     = array_merge( $themes, $plugins );
		$stats   = $this->computeStats( $all );

		// Determine current filter tab.
		$filterTab = sanitize_key( $_GET['filter'] ?? 'all' );

		// Build notice from redirect messages.
		$notice = $this->buildNotice();
		?>
		<div class="wrap idiomatticwp-compat">

			<h1><?php esc_html_e( 'Plugin & Theme Compatibility', 'idiomattic-wp' ); ?></h1>

			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<p class="idiomatticwp-compat__intro">
				<?php esc_html_e( 'Idiomattic scans your active plugins and theme to determine which ones need a compatibility file so their custom fields, options, and shortcodes are translated correctly.', 'idiomattic-wp' ); ?>
			</p>

			<!-- Summary cards -->
			<div class="idiomatticwp-compat__summary">
				<?php
				foreach ( [
					'native' => __( 'Native support', 'idiomattic-wp' ),
					'wpml'   => __( 'WPML config', 'idiomattic-wp' ),
					'none'   => __( 'No config file', 'idiomattic-wp' ),
					'total'  => __( 'Total scanned', 'idiomattic-wp' ),
				] as $key => $label ) :
				?>
				<div class="idiomatticwp-compat__stat idiomatticwp-compat__stat--<?php echo esc_attr( $key ); ?>">
					<span class="idiomatticwp-compat__stat-number"><?php echo (int) $stats[ $key ]; ?></span>
					<span class="idiomatticwp-compat__stat-label"><?php echo esc_html( $label ); ?></span>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Toolbar: rescan + last-scanned + filter tabs -->
			<div class="idiomatticwp-compat__toolbar">

				<a href="<?php echo esc_url( wp_nonce_url(
					add_query_arg( [ 'page' => 'idiomatticwp-compatibility', 'action' => 'rescan' ], admin_url( 'admin.php' ) ),
					'idiomatticwp_compat_rescan'
				) ); ?>" class="button">
					<span class="dashicons dashicons-update" style="margin-top:3px;"></span>
					<?php esc_html_e( 'Re-scan', 'idiomattic-wp' ); ?>
				</a>

				<?php if ( ['wpml'] > 0 ) : ?>
				<a href="<?php echo esc_url( wp_nonce_url(
					add_query_arg( [ 'page' => 'idiomatticwp-compatibility', 'action' => 'autofix_wpml_all' ], admin_url( 'admin.php' ) ),
					'idiomatticwp_compat_autofix_all'
				) ); ?>" class="button button-primary"
				   onclick="return confirm('<?php echo esc_js( __( 'This will write idiomattic-elements.json into all plugin/theme directories that have a wpml-config.xml. Continue?', 'idiomattic-wp' ) ); ?>');"
				>
					&#10003; <?php esc_html_e( 'Bulk auto-fix WPML configs', 'idiomattic-wp' ); ?>
					<span class="count">(<?php echo (int) ['wpml']; ?>)</span>
				</a>
				<?php endif; ?>

				<?php if ( ! empty( $scan['scanned_at'] ) ) : ?>
				<span class="idiomatticwp-compat__last-scan">
					<?php printf(
						/* translators: %s = date/time string */
						esc_html__( 'Last scanned: %s', 'idiomattic-wp' ),
						esc_html( $scan['scanned_at'] )
					); ?>
				</span>
				<?php endif; ?>

				<div class="idiomatticwp-compat__filters">
					<?php
					foreach ( [
						'all'    => __( 'All', 'idiomattic-wp' ),
						'native' => __( 'Native', 'idiomattic-wp' ),
						'wpml'   => __( 'WPML config', 'idiomattic-wp' ),
						'none'   => __( 'Missing', 'idiomattic-wp' ),
					] as $tab => $label ) :
						$count    = $tab === 'all' ? $stats['total'] : ( $stats[ $tab ] ?? 0 );
						$tabUrl   = add_query_arg( [ 'page' => 'idiomatticwp-compatibility', 'filter' => $tab ], admin_url( 'admin.php' ) );
						$isActive = $filterTab === $tab;
					?>
					<a href="<?php echo esc_url( $tabUrl ); ?>"
					   class="idiomatticwp-compat__filter-tab <?php echo $isActive ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<span class="idiomatticwp-compat__filter-count"><?php echo (int) $count; ?></span>
					</a>
					<?php endforeach; ?>
				</div>

			</div><!-- .toolbar -->

			<!-- Main table -->
			<table class="wp-list-table widefat fixed striped idiomatticwp-compat__table">
				<thead>
					<tr>
						<th class="column-name"><?php esc_html_e( 'Plugin / Theme', 'idiomattic-wp' ); ?></th>
						<th class="column-version"><?php esc_html_e( 'Version', 'idiomattic-wp' ); ?></th>
						<th class="column-author"><?php esc_html_e( 'Author', 'idiomattic-wp' ); ?></th>
						<th class="column-type"><?php esc_html_e( 'Type', 'idiomattic-wp' ); ?></th>
						<th class="column-compat"><?php esc_html_e( 'Compatibility', 'idiomattic-wp' ); ?></th>
						<th class="column-elements"><?php esc_html_e( 'Elements', 'idiomattic-wp' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'idiomattic-wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$filtered = $this->filterEntries( $all, $filterTab );
				if ( empty( $filtered ) ) :
				?>
					<tr><td colspan="7"><em><?php esc_html_e( 'No items match the selected filter.', 'idiomattic-wp' ); ?></em></td></tr>
				<?php else : ?>
					<?php foreach ( $filtered as $entry ) : ?>
					<tr class="idiomatticwp-compat__row idiomatticwp-compat__row--<?php echo esc_attr( $entry['compatibility'] ); ?>">
						<td class="column-name">
							<strong><?php echo esc_html( $entry['name'] ); ?></strong>
							<div class="row-actions">
								<code style="font-size:10px;opacity:.7;"><?php echo esc_html( $entry['slug'] ); ?></code>
							</div>
						</td>
						<td class="column-version"><?php echo esc_html( $entry['version'] ); ?></td>
						<td class="column-author"><?php echo esc_html( $entry['author'] ); ?></td>
						<td class="column-type"><?php echo esc_html( $this->typeLabel( $entry['type'] ) ); ?></td>
						<td class="column-compat"><?php echo $this->renderCompatBadge( $entry ); // phpcs:ignore ?></td>
						<td class="column-elements"><?php echo $this->renderElementCounts( $entry['elements'] ?? [] ); // phpcs:ignore ?></td>
						<td class="column-actions"><?php echo $this->renderActions( $entry ); // phpcs:ignore ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<!-- Legend / help -->
			<div class="idiomatticwp-compat__help">
				<h3><?php esc_html_e( 'What do these statuses mean?', 'idiomattic-wp' ); ?></h3>
				<dl>
					<dt><?php $this->renderBadge( 'native' ); ?></dt>
					<dd><?php esc_html_e( 'This plugin/theme ships with a native idiomattic-elements.json file. All its elements are automatically registered. No action needed.', 'idiomattic-wp' ); ?></dd>

					<dt><?php $this->renderBadge( 'wpml' ); ?></dt>
					<dd><?php esc_html_e( 'This plugin/theme ships with a wpml-config.xml file. Idiomattic auto-imports its field definitions at runtime. Use "Import WPML config" to force-register them now, or "Download as JSON" to convert the file to a permanent idiomattic-elements.json.', 'idiomattic-wp' ); ?></dd>

					<dt><?php $this->renderBadge( 'none' ); ?></dt>
					<dd><?php esc_html_e( 'No compatibility file found. Custom fields from this plugin will not be automatically translated. Click "Download JSON template" to get a starter file you can fill in and place inside the plugin/theme directory, or ask the author to add support.', 'idiomattic-wp' ); ?></dd>
				</dl>
			</div>

		</div><!-- .wrap -->

		<?php $this->renderStyles(); ?>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Action handler  (runs before any HTML output)
	// ─────────────────────────────────────────────────────────────────────────

	private function handleActions(): void {
		$action = sanitize_key( $_GET['action'] ?? '' );
		if ( ! $action ) {
			return;
		}

		// ── Re-scan ───────────────────────────────────────────────────────
		if ( $action === 'rescan' ) {
			if ( ! check_admin_referer( 'idiomatticwp_compat_rescan' ) ) {
				return;
			}
			$this->scanner->clearCache();
			$this->scanner->scan( true );
			wp_safe_redirect( add_query_arg( [
				'page'    => 'idiomatticwp-compatibility',
				'message' => 'rescanned',
			], admin_url( 'admin.php' ) ) );
			exit;
		}

		// ── Download JSON ─────────────────────────────────────────────────
		if ( $action === 'download_json' ) {
			if ( ! check_admin_referer( 'idiomatticwp_compat_download' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'idiomattic-wp' ) );
			}
			$slug  = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
			$entry = $this->findEntry( $slug );
			if ( ! $entry ) {
				wp_die( esc_html__( 'Entry not found.', 'idiomattic-wp' ) );
			}
			$this->generator->download( $entry );
			// download() calls exit
		}

		// ── Auto-fix: write idiomattic-elements.json to plugin/theme dir ─────
		if ( $action === 'autofix_wpml' ) {
			if ( ! check_admin_referer( 'idiomatticwp_compat_autofix' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'idiomattic-wp' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'idiomattic-wp' ) );
			}
			$slug  = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
			$entry = $this->findEntry( $slug );
			if ( $entry && $entry['compatibility'] === 'wpml' ) {
				$result = $this->writeJsonToDirectory( $entry );
				wp_safe_redirect( add_query_arg( [
					'page'    => 'idiomatticwp-compatibility',
					'message' => $result ? 'autofix_ok' : 'autofix_fail',
					'slug'    => rawurlencode( $slug ),
				], admin_url( 'admin.php' ) ) );
				exit;
			}
			wp_safe_redirect( add_query_arg( [ 'page' => 'idiomatticwp-compatibility' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// ── Bulk auto-fix: all WPML entries ───────────────────────────────
		if ( $action === 'autofix_wpml_all' ) {
			if ( ! check_admin_referer( 'idiomatticwp_compat_autofix_all' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'idiomattic-wp' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'idiomattic-wp' ) );
			}

			$scan    = $this->scanner->scan();
			$all     = array_merge( $scan['theme'] ?? [], $scan['plugins'] ?? [] );
			$fixed   = 0;
			$failed  = 0;
			foreach ( $all as $entry ) {
				if ( ( $entry['compatibility'] ?? '' ) !== 'wpml' ) {
					continue;
				}
				$this->writeJsonToDirectory( $entry ) ? $fixed++ : $failed++;
			}

			// Refresh cache after writing new files.
			$this->scanner->clearCache();
			$this->scanner->scan( true );

			wp_safe_redirect( add_query_arg( [
				'page'    => 'idiomatticwp-compatibility',
				'message' => 'autofix_all',
				'fixed'   => $fixed,
				'failed'  => $failed,
			], admin_url( 'admin.php' ) ) );
			exit;
		}

		// ── Import WPML config into runtime registry ──────────────────────
		if ( $action === 'import_wpml' ) {
			if ( ! check_admin_referer( 'idiomatticwp_compat_import_wpml' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'idiomattic-wp' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'idiomattic-wp' ) );
			}
			$slug  = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
			$entry = $this->findEntry( $slug );
			if ( $entry && ! empty( $entry['wpml_path'] ) ) {
				$summary = $this->wpmlParser->parse( $entry['wpml_path'] );
				set_transient( 'idiomatticwp_wpml_import_' . md5( $slug ), $summary, 120 );
			}
			wp_safe_redirect( add_query_arg( [
				'page'    => 'idiomatticwp-compatibility',
				'message' => 'wpml_imported',
				'slug'    => rawurlencode( $slug ),
			], admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Notice builder
	// ─────────────────────────────────────────────────────────────────────────

	private function buildNotice(): string {
		$message = sanitize_key( $_GET['message'] ?? '' );

		if ( $message === 'rescanned' ) {
			return '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Compatibility scan completed successfully.', 'idiomattic-wp' )
				. '</p></div>';
		}

		if ( $message === 'autofix_ok' ) {
			$slug = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
			return '<div class="notice notice-success is-dismissible"><p>'
				. sprintf(
					/* translators: %s: plugin slug */
					esc_html__( 'Auto-fix applied: idiomattic-elements.json written to %s.', 'idiomattic-wp' ),
					'<code>' . esc_html( $slug ) . '</code>'
				)
				. '</p></div>';
		}

		if ( $message === 'autofix_fail' ) {
			$slug = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
			return '<div class="notice notice-error is-dismissible"><p>'
				. sprintf(
					/* translators: %s: plugin slug */
					esc_html__( 'Auto-fix failed for %s — the plugin directory may not be writable.', 'idiomattic-wp' ),
					'<code>' . esc_html( $slug ) . '</code>'
				)
				. '</p></div>';
		}

		if ( $message === 'autofix_all' ) {
			$fixed  = absint( $_GET['fixed']  ?? 0 );
			$failed = absint( $_GET['failed'] ?? 0 );
			$type   = $failed > 0 ? 'warning' : 'success';
			$text   = sprintf(
				/* translators: 1: number fixed, 2: number failed */
				esc_html__( 'Bulk auto-fix complete: %1$d written, %2$d failed (directory not writable).', 'idiomattic-wp' ),
				$fixed,
				$failed
			);
			return '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . $text . '</p></div>';
		}

		if ( $message === 'wpml_imported' ) {
			$slug    = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
			$summary = get_transient( 'idiomatticwp_wpml_import_' . md5( $slug ) );

			if ( is_array( $summary ) && empty( $summary['errors'] ) ) {
				$text = sprintf(
					/* translators: 1: number registered, 2: number skipped */
					esc_html__( 'WPML config imported: %1$d element(s) registered into the translation registry, %2$d skipped (action = ignore).', 'idiomattic-wp' ),
					(int) $summary['registered'],
					(int) $summary['skipped']
				);
				return '<div class="notice notice-success is-dismissible"><p>' . $text . '</p></div>';
			}

			if ( is_array( $summary ) && ! empty( $summary['errors'] ) ) {
				$errors = implode( ' ', array_map( 'esc_html', $summary['errors'] ) );
				return '<div class="notice notice-warning is-dismissible"><p>'
					. esc_html__( 'WPML config imported with warnings: ', 'idiomattic-wp' )
					. $errors . '</p></div>';
			}

			return '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'WPML config imported successfully.', 'idiomattic-wp' )
				. '</p></div>';
		}

		return '';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Find a scan entry by slug.
	 * Plugin slugs contain '/' (e.g. "woocommerce/woocommerce.php") but are
	 * passed through rawurlencode/rawurldecode in URLs, so we compare decoded values.
	 */
	private function findEntry( string $slug ): ?array {
		$slug = rawurldecode( $slug );
		$scan = $this->scanner->scan();
		$all  = array_merge( $scan['theme'] ?? [], $scan['plugins'] ?? [] );
		foreach ( $all as $entry ) {
			if ( $entry['slug'] === $slug ) {
				return $entry;
			}
		}
		return null;
	}

	private function computeStats( array $entries ): array {
		$stats = [ 'total' => 0, 'native' => 0, 'wpml' => 0, 'none' => 0 ];
		foreach ( $entries as $entry ) {
			$stats['total']++;
			$compat = $entry['compatibility'] ?? 'none';
			if ( isset( $stats[ $compat ] ) ) {
				$stats[ $compat ]++;
			}
		}
		return $stats;
	}

	private function filterEntries( array $entries, string $filter ): array {
		if ( $filter === 'all' ) {
			return $entries;
		}
		return array_values( array_filter( $entries, fn( $e ) => ( $e['compatibility'] ?? 'none' ) === $filter ) );
	}

	private function typeLabel( string $type ): string {
		return match ( $type ) {
			'plugin'      => __( 'Plugin', 'idiomattic-wp' ),
			'theme'       => __( 'Theme', 'idiomattic-wp' ),
			'theme-child' => __( 'Child theme', 'idiomattic-wp' ),
			default       => ucfirst( $type ),
		};
	}

	private function renderCompatBadge( array $entry ): string {
		ob_start();
		$this->renderBadge( $entry['compatibility'] ?? 'none' );
		return (string) ob_get_clean();
	}

	private function renderBadge( string $compat ): void {
		$labels = [
			'native' => __( '&#10003; Native support', 'idiomattic-wp' ),
			'wpml'   => __( '&#8593; WPML config', 'idiomattic-wp' ),
			'none'   => __( '&#10007; No config file', 'idiomattic-wp' ),
		];
		$label = $labels[ $compat ] ?? esc_html( $compat );
		printf(
			'<span class="idiomatticwp-compat__badge idiomatticwp-compat__badge--%s">%s</span>',
			esc_attr( $compat ),
			$label // pre-escaped above
		);
	}

	private function renderElementCounts( array $elements ): string {
		if ( empty( $elements ) || ( array_sum( $elements ) === 0 ) ) {
			return '<span style="color:#8c8f94;">' . esc_html__( 'None detected', 'idiomattic-wp' ) . '</span>';
		}
		$parts = [];
		if ( ! empty( $elements['post_fields'] ) ) {
			$parts[] = sprintf( _n( '%d field', '%d fields', $elements['post_fields'], 'idiomattic-wp' ), $elements['post_fields'] );
		}
		if ( ! empty( $elements['options'] ) ) {
			$parts[] = sprintf( _n( '%d option', '%d options', $elements['options'], 'idiomattic-wp' ), $elements['options'] );
		}
		if ( ! empty( $elements['shortcodes'] ) ) {
			$parts[] = sprintf( _n( '%d shortcode', '%d shortcodes', $elements['shortcodes'], 'idiomattic-wp' ), $elements['shortcodes'] );
		}
		if ( ! empty( $elements['blocks'] ) ) {
			$parts[] = sprintf( _n( '%d block', '%d blocks', $elements['blocks'], 'idiomattic-wp' ), $elements['blocks'] );
		}
		return esc_html( implode( ', ', $parts ) );
	}

	private function renderActions( array $entry ): string {
		$slug   = $entry['slug'];
		$type   = $entry['type'] ?? 'plugin';
		$compat = $entry['compatibility'] ?? 'none';
		$out    = '';

		$dlUrl = wp_nonce_url(
			add_query_arg( [
				'page'   => 'idiomatticwp-compatibility',
				'action' => 'download_json',
				'slug'   => rawurlencode( $slug ),
			], admin_url( 'admin.php' ) ),
			'idiomatticwp_compat_download'
		);

		if ( $compat === 'native' ) {
			$out .= '<a href="' . esc_url( $dlUrl ) . '" class="button button-small">'
				. esc_html__( 'Download JSON', 'idiomattic-wp' )
				. '</a>';
		} elseif ( $compat === 'wpml' ) {
			$importUrl = wp_nonce_url(
				add_query_arg( [
					'page'   => 'idiomatticwp-compatibility',
					'action' => 'import_wpml',
					'slug'   => rawurlencode( $slug ),
				], admin_url( 'admin.php' ) ),
				'idiomatticwp_compat_import_wpml'
			);
			$autofixUrl = wp_nonce_url(
				add_query_arg( [
					'page'   => 'idiomatticwp-compatibility',
					'action' => 'autofix_wpml',
					'slug'   => rawurlencode( $slug ),
				], admin_url( 'admin.php' ) ),
				'idiomatticwp_compat_autofix'
			);
			$out .= '<a href="' . esc_url( $autofixUrl ) . '" class="button button-primary button-small"'
				. ' title="' . esc_attr__( 'Convert wpml-config.xml and write idiomattic-elements.json to this plugin\'s directory', 'idiomattic-wp' ) . '">'
				. '&#10003; ' . esc_html__( 'Auto-fix', 'idiomattic-wp' )
				. '</a> ';
			$out .= '<a href="' . esc_url( $importUrl ) . '" class="button button-small">'
				. esc_html__( 'Import WPML config', 'idiomattic-wp' )
				. '</a> ';
			$out .= '<a href="' . esc_url( $dlUrl ) . '" class="button button-small">'
				. esc_html__( 'Download as JSON', 'idiomattic-wp' )
				. '</a>';
		} else {
			$out .= '<a href="' . esc_url( $dlUrl ) . '" class="button button-small">'
				. esc_html__( 'Download JSON template', 'idiomattic-wp' )
				. '</a>';
		}

		// Scan strings button — always available for all plugins/themes.
		$out .= ' <button'
			. ' type="button"'
			. ' class="button button-small idiomatticwp-scan-strings-btn"'
			. ' data-type="' . esc_attr( $type ) . '"'
			. ' data-slug="' . esc_attr( $slug ) . '"'
			. ' data-nonce="' . esc_attr( wp_create_nonce( 'idiomatticwp_nonce' ) ) . '"'
			. '>'
			. '<span class="iwp-scan-btn-icon dashicons dashicons-search" style="font-size:13px;width:13px;height:13px;vertical-align:middle;margin-right:3px;margin-top:-1px;"></span>'
			. esc_html__( 'Scan strings', 'idiomattic-wp' )
			. '</button>'
			. '<span class="iwp-scan-state" style="display:none;">'
			. '<span class="iwp-scan-bar"><span class="iwp-scan-bar__fill"></span></span>'
			. '<span class="iwp-scan-timer">0s</span>'
			. '</span>';

		return $out;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Styles (output once, after page content)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Write the generated idiomattic-elements.json into the plugin/theme directory.
	 *
	 * @param array $entry  A CompatibilityScanner entry with 'directory' and 'compatibility' keys.
	 * @return bool  True on success, false if the directory is not writable.
	 */
	private function writeJsonToDirectory( array $entry ): bool {
		$directory = (string) ( $entry['directory'] ?? '' );
		if ( ! $directory || ! is_dir( $directory ) ) {
			return false;
		}

		$targetPath = trailingslashit( $directory ) . 'idiomattic-elements.json';

		if ( ! wp_is_writable( $directory ) ) {
			return false;
		}

		$json = $this->generator->generate( $entry );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $targetPath, $json );

		return $written !== false;
	}

	// ── Styles (output once, after page content) ─────────────────────────────────────────

	private function renderStyles(): void {
		?>
		<style>
		.idiomatticwp-compat__intro {
			max-width: 680px;
			color: #50575e;
			margin-bottom: 20px;
		}

		/* ── Summary cards ── */
		.idiomatticwp-compat__summary {
			display: flex;
			gap: 16px;
			flex-wrap: wrap;
			margin: 20px 0;
		}
		.idiomatticwp-compat__stat {
			display: flex;
			flex-direction: column;
			align-items: center;
			padding: 14px 20px;
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 6px;
			min-width: 110px;
			border-top: 4px solid #dcdcde;
		}
		.idiomatticwp-compat__stat--native { border-top-color: #46b450; }
		.idiomatticwp-compat__stat--wpml   { border-top-color: #2271b1; }
		.idiomatticwp-compat__stat--none   { border-top-color: #d63638; }
		.idiomatticwp-compat__stat--total  { border-top-color: #8c8f94; }
		.idiomatticwp-compat__stat-number  { font-size: 28px; font-weight: 700; line-height: 1; }
		.idiomatticwp-compat__stat-label   { font-size: 11px; color: #50575e; margin-top: 4px; text-align: center; }

		/* ── Toolbar ── */
		.idiomatticwp-compat__toolbar {
			display: flex;
			align-items: center;
			gap: 16px;
			flex-wrap: wrap;
			margin: 16px 0 8px;
		}
		.idiomatticwp-compat__last-scan { font-size: 12px; color: #8c8f94; }
		.idiomatticwp-compat__filters   { display: flex; gap: 4px; margin-left: auto; }
		.idiomatticwp-compat__filter-tab {
			padding: 4px 12px;
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			font-size: 12px;
			text-decoration: none;
			color: #50575e;
		}
		.idiomatticwp-compat__filter-tab:hover    { border-color: #8c8f94; color: #1d2327; }
		.idiomatticwp-compat__filter-tab.is-active {
			background: #2271b1;
			color: #fff;
			border-color: #2271b1;
		}
		.idiomatticwp-compat__filter-count {
			display: inline-block;
			background: rgba(0,0,0,.12);
			border-radius: 10px;
			padding: 0 6px;
			font-size: 10px;
			margin-left: 4px;
		}

		/* ── Table ── */
		.idiomatticwp-compat__table .column-version  { width: 70px; }
		.idiomatticwp-compat__table .column-author   { width: 140px; }
		.idiomatticwp-compat__table .column-type     { width: 90px; }
		.idiomatticwp-compat__table .column-compat   { width: 150px; }
		.idiomatticwp-compat__table .column-elements { width: 170px; }
		.idiomatticwp-compat__table .column-actions  { width: 300px; }

		/* Row left-border accent */
		.idiomatticwp-compat__row--none   td:first-child { border-left: 3px solid #d63638; }
		.idiomatticwp-compat__row--wpml   td:first-child { border-left: 3px solid #2271b1; }
		.idiomatticwp-compat__row--native td:first-child { border-left: 3px solid #46b450; }

		/* ── Badges ── */
		.idiomatticwp-compat__badge {
			display: inline-block;
			padding: 3px 9px;
			border-radius: 12px;
			font-size: 11px;
			font-weight: 600;
			white-space: nowrap;
		}
		.idiomatticwp-compat__badge--native { background: #edfaef; color: #1a8a24; border: 1px solid #c3e6c6; }
		.idiomatticwp-compat__badge--wpml   { background: #deeeff; color: #135e96; border: 1px solid #b3d3f0; }
		.idiomatticwp-compat__badge--none   { background: #fce8e8; color: #8a0000; border: 1px solid #f0c3c3; }

		/* ── Scan progress indicator ── */
		.iwp-scan-state {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			margin-left: 6px;
			vertical-align: middle;
		}
		.iwp-scan-bar {
			width: 72px;
			height: 4px;
			background: #dcdcde;
			border-radius: 2px;
			overflow: hidden;
		}
		.iwp-scan-bar__fill {
			height: 100%;
			width: 0%;
			background: #2271b1;
			border-radius: 2px;
			transition: width 0.5s ease-out;
		}
		.iwp-scan-bar__fill--done { background: #46b450; }
		.iwp-scan-timer {
			font-size: 11px;
			color: #8c8f94;
			min-width: 22px;
			font-variant-numeric: tabular-nums;
		}

		/* ── Help box ── */
		.idiomatticwp-compat__help {
			margin-top: 32px;
			padding: 20px 24px;
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			max-width: 780px;
		}
		.idiomatticwp-compat__help h3 { margin-top: 0; }
		.idiomatticwp-compat__help dl {
			display: grid;
			grid-template-columns: auto 1fr;
			gap: 12px 20px;
			align-items: start;
		}
		.idiomatticwp-compat__help dt { padding-top: 2px; }
		.idiomatticwp-compat__help dd { margin: 0; color: #50575e; font-size: 13px; }
		</style>
		<?php
	}
}
