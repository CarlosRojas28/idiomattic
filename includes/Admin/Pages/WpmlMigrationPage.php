<?php
/**
 * WpmlMigrationPage — admin UI for migrating from WPML or Polylang.
 *
 * Provides a tabbed interface:
 *  - "From WPML"     reads icl_translations and imports translation pairs.
 *  - "From Polylang" reads post_translations taxonomy terms and imports pairs.
 *
 * Both wizards run the import in AJAX batches to avoid PHP timeouts.
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Migration\WpmlDetector;
use IdiomatticWP\Migration\WpmlMigrator;
use IdiomatticWP\Migration\PolylangDetector;
use IdiomatticWP\Migration\PolylangMigrator;

class WpmlMigrationPage implements HookRegistrarInterface {

	public function __construct(
		private WpmlDetector     $wpmlDetector,
		private WpmlMigrator     $wpmlMigrator,
		private PolylangDetector $polylangDetector,
		private PolylangMigrator $polylangMigrator,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_action( 'admin_menu', function () {
			add_submenu_page(
				'idiomatticwp',
				__( 'Migration Wizard', 'idiomattic-wp' ),
				__( 'Migration', 'idiomattic-wp' ),
				'manage_options',
				'idiomatticwp-migration',
				[ $this, 'render' ]
			);
		} );

		add_action( 'wp_ajax_idiomatticwp_run_migration',          [ $this, 'handleAjaxWpmlMigration' ] );
		add_action( 'wp_ajax_idiomatticwp_run_polylang_migration',  [ $this, 'handleAjaxPolylangMigration' ] );
	}

	// ── Render ────────────────────────────────────────────────────────────

	public function render(): void {
		$activeTab = sanitize_key( $_GET['tab'] ?? 'wpml' );

		$wpmlCount      = $this->wpmlDetector->getTranslationCount();
		$wpmlActive     = $this->wpmlDetector->isWpmlActive();
		$polylangCount  = $this->polylangDetector->getGroupCount();
		$polylangActive = $this->polylangDetector->isPolylangActive();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migration Wizard', 'idiomattic-wp' ); ?></h1>

			<!-- ── Tabs ──────────────────────────────────────── -->
			<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'wpml' ) ); ?>"
				   class="nav-tab <?php echo $activeTab === 'wpml' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'From WPML', 'idiomattic-wp' ); ?>
					<?php if ( $wpmlCount > 0 ) : ?><span class="count">&nbsp;(<?php echo esc_html( number_format_i18n( $wpmlCount ) ); ?>)</span><?php endif; ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'polylang' ) ); ?>"
				   class="nav-tab <?php echo $activeTab === 'polylang' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'From Polylang', 'idiomattic-wp' ); ?>
					<?php if ( $polylangCount > 0 ) : ?><span class="count">&nbsp;(<?php echo esc_html( number_format_i18n( $polylangCount ) ); ?>)</span><?php endif; ?>
				</a>
			</nav>

			<?php if ( $activeTab === 'wpml' ) : ?>

			<!-- ── WPML tab ──────────────────────────────────── -->
			<?php if ( ! $wpmlActive ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'WPML is not currently active. Migration can still run if the WPML database tables exist.', 'idiomattic-wp' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="card" style="max-width:680px;">
				<h2><?php esc_html_e( 'Migrate from WPML', 'idiomattic-wp' ); ?></h2>
				<?php if ( $wpmlCount <= 0 ) : ?>
					<p><?php esc_html_e( 'No WPML translation records found in the database. Nothing to migrate.', 'idiomattic-wp' ); ?></p>
				<?php else : ?>
					<p><?php printf(
						/* translators: %s: formatted number */
						esc_html__( 'Found %s WPML translation record(s). Click the button below to import them into Idiomattic WP.', 'idiomattic-wp' ),
						'<strong>' . esc_html( number_format_i18n( $wpmlCount ) ) . '</strong>'
					); ?></p>
					<p class="description"><?php esc_html_e( 'The import runs in background batches. You can leave this page while it runs.', 'idiomattic-wp' ); ?></p>

					<button id="iwp-start-wpml" class="button button-primary" style="margin-top:8px;">
						<?php esc_html_e( 'Start WPML Import', 'idiomattic-wp' ); ?>
					</button>

					<?php $this->renderProgressArea( 'wpml', $wpmlCount ); ?>
				<?php endif; ?>
			</div>

			<?php else : // polylang tab ?>

			<!-- ── Polylang tab ──────────────────────────────── -->
			<?php if ( ! $polylangActive ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'Polylang is not currently active. Migration can still run if the Polylang taxonomy data exists.', 'idiomattic-wp' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="card" style="max-width:680px;">
				<h2><?php esc_html_e( 'Migrate from Polylang', 'idiomattic-wp' ); ?></h2>
				<?php if ( $polylangCount <= 0 ) : ?>
					<p><?php esc_html_e( 'No Polylang translation groups found in the database. Nothing to migrate.', 'idiomattic-wp' ); ?></p>
				<?php else : ?>
					<p><?php printf(
						/* translators: %s: formatted number */
						esc_html__( 'Found %s Polylang translation group(s). Click the button below to import them into Idiomattic WP.', 'idiomattic-wp' ),
						'<strong>' . esc_html( number_format_i18n( $polylangCount ) ) . '</strong>'
					); ?></p>
					<p class="description"><?php esc_html_e( 'The import runs in background batches. You can leave this page while it runs.', 'idiomattic-wp' ); ?></p>

					<button id="iwp-start-polylang" class="button button-primary" style="margin-top:8px;">
						<?php esc_html_e( 'Start Polylang Import', 'idiomattic-wp' ); ?>
					</button>

					<?php $this->renderProgressArea( 'polylang', $polylangCount ); ?>
				<?php endif; ?>
			</div>

			<?php endif; ?>

		</div><!-- .wrap -->

		<script>
		(function($) {
			'use strict';
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'idiomatticwp_migration' ) ); ?>;

			function runBatch(type, offset, total, $bar, $text, $errors) {
				var action = type === 'wpml'
					? 'idiomatticwp_run_migration'
					: 'idiomatticwp_run_polylang_migration';

				$.post(ajaxurl, {
					action: action,
					offset: offset,
					_ajax_nonce: nonce
				}, function(response) {
					if (!response.success) {
						$text.text('<?php echo esc_js( __( 'Error during import.', 'idiomattic-wp' ) ); ?>');
						return;
					}
					var nextOffset = offset + 100;
					var progress   = Math.min(100, (nextOffset / total) * 100);
					$bar.css('width', progress.toFixed(1) + '%');
					$text.text(
						'<?php echo esc_js( __( 'Imported', 'idiomattic-wp' ) ); ?> ' +
						Math.min(total, nextOffset) + ' / ' + total
					);
					// Show any per-batch errors
					if (response.data && response.data.errors && response.data.errors.length) {
						$.each(response.data.errors, function(i, msg) {
							$errors.append('<li>' + $('<span>').text(msg).html() + '</li>');
						});
						$errors.closest('.iwp-error-box').show();
					}
					if (nextOffset < total) {
						runBatch(type, nextOffset, total, $bar, $text, $errors);
					} else {
						$text.text('<?php echo esc_js( __( 'Import complete!', 'idiomattic-wp' ) ); ?> ✓');
						$bar.css('background', '#46b450');
					}
				}).fail(function() {
					$text.text('<?php echo esc_js( __( 'Network error — please retry.', 'idiomattic-wp' ) ); ?>');
				});
			}

			$('#iwp-start-wpml').on('click', function() {
				$(this).prop('disabled', true);
				$('#iwp-progress-wpml').show();
				runBatch(
					'wpml',
					0,
					<?php echo (int) $wpmlCount; ?>,
					$('#iwp-bar-wpml'),
					$('#iwp-text-wpml'),
					$('#iwp-errors-wpml ul')
				);
			});

			$('#iwp-start-polylang').on('click', function() {
				$(this).prop('disabled', true);
				$('#iwp-progress-polylang').show();
				runBatch(
					'polylang',
					0,
					<?php echo (int) $polylangCount; ?>,
					$('#iwp-bar-polylang'),
					$('#iwp-text-polylang'),
					$('#iwp-errors-polylang ul')
				);
			});
		}(jQuery));
		</script>
		<?php
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────

	public function handleAjaxWpmlMigration(): void {
		check_ajax_referer( 'idiomatticwp_migration' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		$offset = absint( $_POST['offset'] ?? 0 );
		$report = $this->wpmlMigrator->migrateBatch( $offset );

		wp_send_json_success( [
			'migrated' => $report->translationsMigrated,
			'errors'   => $report->errorMessages,
		] );
	}

	public function handleAjaxPolylangMigration(): void {
		check_ajax_referer( 'idiomatticwp_migration' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		$offset = absint( $_POST['offset'] ?? 0 );
		$report = $this->polylangMigrator->migrateBatch( $offset );

		wp_send_json_success( [
			'migrated' => $report->translationsMigrated,
			'errors'   => $report->errorMessages,
		] );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	private function renderProgressArea( string $id, int $total ): void {
		?>
		<div id="iwp-progress-<?php echo esc_attr( $id ); ?>" style="margin-top:20px; display:none;">
			<div style="background:#eee; height:22px; border-radius:11px; overflow:hidden;">
				<div id="iwp-bar-<?php echo esc_attr( $id ); ?>"
				     style="background:#2271b1; height:100%; width:0%; border-radius:11px; transition:width .3s;">
				</div>
			</div>
			<p id="iwp-text-<?php echo esc_attr( $id ); ?>" style="margin-top:6px; color:#3c434a;"></p>
			<div id="iwp-errors-<?php echo esc_attr( $id ); ?>" class="iwp-error-box notice notice-error" style="display:none; margin-top:10px;">
				<p><strong><?php esc_html_e( 'Errors during import:', 'idiomattic-wp' ); ?></strong></p>
				<ul style="margin-left:1.5em; list-style:disc;"></ul>
			</div>
		</div>
		<?php
	}
}
