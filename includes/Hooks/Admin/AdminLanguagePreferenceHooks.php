<?php
/**
 * AdminLanguagePreferenceHooks — per-user admin interface language selection.
 *
 * Adds a "Backend Language" field to every user profile page so that each
 * administrator or editor can choose which of the site's active Idiomattic
 * languages they want to use for the WordPress admin interface.
 *
 * Implementation uses WordPress's native `locale` user-meta key so that the
 * language switch is handled entirely by WordPress core — no custom locale
 * filter needed. load_plugin_textdomain() then picks up the matching
 * idiomattic-wp-{locale}.mo file automatically.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\ValueObjects\LanguageCode;

class AdminLanguagePreferenceHooks implements HookRegistrarInterface {

	private const META_KEY = 'idiomatticwp_admin_lang';

	public function __construct( private LanguageManager $languageManager ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Render field on "Your Profile" and "Edit User" screens.
		add_action( 'show_user_profile', [ $this, 'renderField' ] );
		add_action( 'edit_user_profile', [ $this, 'renderField' ] );

		// Save field on profile update.
		add_action( 'personal_options_update',  [ $this, 'saveField' ] );
		add_action( 'edit_user_profile_update', [ $this, 'saveField' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	public function renderField( \WP_User $user ): void {
		$activeLanguages = $this->languageManager->getActiveLanguages();

		// Nothing useful to show if the site has fewer than 2 languages.
		if ( count( $activeLanguages ) < 2 ) {
			return;
		}

		$saved = get_user_meta( $user->ID, self::META_KEY, true );
		$langData = $this->languageManager->getAllSupportedLanguages();
		?>
		<h2><?php esc_html_e( 'Idiomattic — Admin Interface Language', 'idiomattic-wp' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="iwp_admin_lang">
						<?php esc_html_e( 'Admin Language', 'idiomattic-wp' ); ?>
					</label>
				</th>
				<td>
					<?php wp_nonce_field( 'iwp_admin_lang_' . $user->ID, 'iwp_admin_lang_nonce' ); ?>
					<select name="iwp_admin_lang" id="iwp_admin_lang">
						<option value=""><?php esc_html_e( '— Use site default —', 'idiomattic-wp' ); ?></option>
						<?php foreach ( $activeLanguages as $lang ) : ?>
							<?php
							$code   = (string) $lang;
							$ld     = $langData[ $code ] ?? [];
							$name   = $ld['name'] ?? strtoupper( $code );
							$native = $ld['native_name'] ?? $name;
							$label  = $native !== $name ? "{$native} ({$name})" : $name;
							?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $saved, $code ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select the language you prefer for the WordPress admin interface. Only languages active on this site are available.', 'idiomattic-wp' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function saveField( int $userId ): void {
		if ( ! isset( $_POST['iwp_admin_lang_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['iwp_admin_lang_nonce'] ), 'iwp_admin_lang_' . $userId )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $userId ) ) {
			return;
		}

		$code = sanitize_key( wp_unslash( $_POST['iwp_admin_lang'] ?? '' ) );

		if ( $code === '' ) {
			// Restore site default: remove both our meta and WP's native locale meta.
			delete_user_meta( $userId, self::META_KEY );
			delete_user_meta( $userId, 'locale' );
			return;
		}

		// Validate: must be one of the active Idiomattic languages.
		$activeCodes = array_map( 'strval', $this->languageManager->getActiveLanguages() );
		if ( ! in_array( $code, $activeCodes, true ) ) {
			return;
		}

		// Persist our own key (so we can pre-select the dropdown on next load).
		update_user_meta( $userId, self::META_KEY, $code );

		// Write WP's native `locale` meta — this is what WordPress reads to
		// switch the admin interface language for the user.
		try {
			$locale = LanguageCode::from( $code )->toLocale();
			update_user_meta( $userId, 'locale', $locale );
		} catch ( \Throwable ) {
			// Unrecognised code — leave WP locale unchanged.
		}
	}
}
