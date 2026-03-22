<?php
/**
 * LanguageActivationHooks — triggers language pack import whenever the active
 * language list changes in Idiomattic settings.
 *
 * When a new language is saved, a WP-Cron event is scheduled for each newly
 * added language code. The cron handler calls LanguagePackImporter, which
 * downloads the .po/.mo files for all active plugins/themes and inserts the
 * translated strings into idiomatticwp_strings.
 *
 * An admin notice is shown after the save, and an AJAX action allows manually
 * re-triggering the import for a specific language.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Strings\LanguagePackImporter;

class LanguageActivationHooks implements HookRegistrarInterface {

	private const CRON_EVENT = 'idiomatticwp_import_language_pack';
	private const NOTICE_OPT = 'idiomatticwp_lang_pack_import_pending';

	public function __construct( private LanguagePackImporter $importer ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Detect newly activated languages after the option is saved.
		add_action( 'update_option_idiomatticwp_active_langs', [ $this, 'onLanguagesUpdated' ], 20, 2 );

		// Cron handler.
		add_action( self::CRON_EVENT, [ $this, 'handleCronImport' ] );

		// Admin notice (shown on any Idiomattic page until dismissed / completed).
		add_action( 'admin_notices', [ $this, 'renderPendingNotice' ] );

		// AJAX: manually re-import language pack for a specific language.
		add_action( 'wp_ajax_idiomatticwp_reimport_lang_pack', [ $this, 'handleReimportAjax' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Fired after idiomatticwp_active_langs is updated.
	 * Schedules a cron job for each newly added language.
	 *
	 * @param mixed $oldValue Previous option value.
	 * @param mixed $newValue New option value.
	 */
	public function onLanguagesUpdated( mixed $oldValue, mixed $newValue ): void {
		$old   = is_array( $oldValue ) ? $oldValue : [];
		$new   = is_array( $newValue ) ? $newValue : [];
		$added = array_values( array_diff( $new, $old ) );

		if ( empty( $added ) ) {
			return;
		}

		foreach ( $added as $langCode ) {
			// Avoid duplicate events.
			if ( ! wp_next_scheduled( self::CRON_EVENT, [ $langCode ] ) ) {
				wp_schedule_single_event( time(), self::CRON_EVENT, [ $langCode ] );
			}
		}

		// Store pending langs so the admin notice can reference them.
		$pending   = (array) get_option( self::NOTICE_OPT, [] );
		$pending   = array_unique( array_merge( $pending, $added ) );
		update_option( self::NOTICE_OPT, $pending, false );
	}

	/**
	 * WP-Cron handler: runs the importer for one language.
	 */
	public function handleCronImport( string $langCode ): void {
		$this->importer->importForLanguage( $langCode );

		// Remove from pending notice list.
		$pending = (array) get_option( self::NOTICE_OPT, [] );
		$pending = array_values( array_filter( $pending, fn( $l ) => $l !== $langCode ) );
		update_option( self::NOTICE_OPT, $pending, false );
	}

	/**
	 * Show an admin notice when language pack import jobs are queued.
	 */
	public function renderPendingNotice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( $screen->id, 'idiomatticwp' ) ) {
			return;
		}

		$pending = (array) get_option( self::NOTICE_OPT, [] );
		if ( empty( $pending ) ) {
			return;
		}

		$langs = implode( ', ', array_map( 'strtoupper', $pending ) );
		?>
		<div class="notice notice-info">
			<p>
				<?php printf(
					/* translators: %s = comma-separated list of language codes */
					esc_html__( 'Idiomattic is importing translation packages for: %s. Strings will appear in String Translation once the import completes (usually within a minute).', 'idiomattic-wp' ),
					'<strong>' . esc_html( $langs ) . '</strong>'
				); ?>
				&nbsp;
				<a href="#" class="idiomatticwp-reimport-link" style="text-decoration:underline;">
					<?php esc_html_e( 'Import now', 'idiomattic-wp' ); ?>
				</a>
			</p>
		</div>
		<script>
		(function(){
			var links = document.querySelectorAll('.idiomatticwp-reimport-link');
			links.forEach(function(link){
				link.addEventListener('click', function(e){
					e.preventDefault();
					link.textContent = <?php echo wp_json_encode( __( 'Importing…', 'idiomattic-wp' ) ); ?>;
					<?php foreach ( $pending as $langCode ) : ?>
					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'},
						body: new URLSearchParams({
							action: 'idiomatticwp_reimport_lang_pack',
							lang:   <?php echo wp_json_encode( $langCode ); ?>,
							nonce:  <?php echo wp_json_encode( wp_create_nonce( 'idiomatticwp_reimport_' . $langCode ) ); ?>,
						})
					}).then(function(r){ return r.json(); }).then(function(data){
						if (data.success) {
							link.closest('.notice').remove();
						}
					});
					<?php endforeach; ?>
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler: synchronously import language pack for one language.
	 */
	public function handleReimportAjax(): void {
		$langCode = sanitize_key( $_POST['lang'] ?? '' );
		check_ajax_referer( 'idiomatticwp_reimport_' . $langCode, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'idiomattic-wp' ) ], 403 );
		}

		$result = $this->importer->importForLanguage( $langCode );

		// Clear from pending list.
		$pending = (array) get_option( self::NOTICE_OPT, [] );
		$pending = array_values( array_filter( $pending, fn( $l ) => $l !== $langCode ) );
		update_option( self::NOTICE_OPT, $pending, false );

		wp_send_json_success( $result );
	}
}
