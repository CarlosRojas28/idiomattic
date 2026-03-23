<?php
/**
 * EmailLocaleHooks — switches the WordPress locale to match the recipient
 * user's preferred language before wp_mail() generates email content.
 *
 * When WordPress sends system emails (password reset, new-user welcome, etc.)
 * it calls wp_mail() with the recipient address already resolved. This hook
 * looks up the recipient user, reads their stored language preference (or falls
 * back to their WordPress locale setting), and injects a short-lived locale
 * override so that translated strings, date formats, and MO files all resolve
 * to the recipient's language rather than the site default.
 *
 * The override is intentionally narrow: it applies only for the duration of
 * the wp_mail filter chain and is removed immediately after to avoid polluting
 * subsequent code.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;

class EmailLocaleHooks implements HookRegistrarInterface {

	public function __construct( private LanguageManager $lm ) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Priority 1 — fires before other wp_mail filters that build content.
		add_filter( 'wp_mail', [ $this, 'switchEmailLocale' ], 1 );
	}

	// ── Filter callback ───────────────────────────────────────────────────

	/**
	 * Inject a locale override onto the `locale` filter when a known user is
	 * the email recipient and they have a language preference configured.
	 *
	 * The override is removed by a one-shot action on `wp_mail` priority 999
	 * so it does not outlive the current mail-sending call.
	 *
	 * @param array $atts wp_mail() argument array (to, subject, message, …).
	 * @return array Unmodified $atts — only the locale filter is changed.
	 */
	public function switchEmailLocale( array $atts ): array {
		$to = $atts['to'] ?? '';
		if ( $to === '' || $to === [] ) {
			return $atts;
		}

		// Handle both string and array recipients — inspect only the first one.
		$email = is_array( $to ) ? $to[0] : $to;

		// Strip display-name format: "John Doe <john@example.com>" → "john@example.com".
		if ( preg_match( '/<([^>]+)>/', $email, $m ) ) {
			$email = $m[1];
		}
		$email = sanitize_email( $email );
		if ( $email === '' ) {
			return $atts;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return $atts;
		}

		// 1. Explicit Idiomattic language preference stored by the user profile.
		$userLang = (string) get_user_meta( $user->ID, 'idiomatticwp_preferred_lang', true );

		// 2. Fallback: WordPress core locale setting for the user.
		if ( $userLang === '' ) {
			$userLocale = (string) get_user_meta( $user->ID, 'locale', true );
			if ( $userLocale !== '' ) {
				$userLang = $this->localeToLangCode( $userLocale );
			}
		}

		if ( $userLang === '' ) {
			return $atts;
		}

		// Verify the resolved language is currently active.
		$active = array_map( 'strval', $this->lm->getActiveLanguages() );
		if ( ! in_array( $userLang, $active, true ) ) {
			return $atts;
		}

		// Resolve to a WP locale string (e.g. "fr" → "fr_FR").
		$allLangs = $this->lm->getAllSupportedLanguages();
		$locale   = $allLangs[ $userLang ]['locale'] ?? '';
		if ( $locale === '' ) {
			return $atts;
		}

		// Apply the locale override for this mail-send only.
		$localeOverride = static fn( string $l ) => $locale;
		add_filter( 'locale', $localeOverride, 999 );

		// Remove the override after wp_mail() completes (priority 999 = after content).
		add_filter(
			'wp_mail',
			function ( array $a ) use ( $localeOverride ): array {
				remove_filter( 'locale', $localeOverride, 999 );
				return $a;
			},
			999
		);

		return $atts;
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Map a WP locale string back to a BCP-47 language code used internally.
	 *
	 * Falls back to the primary-language subtag match when an exact locale
	 * entry is not found in the language data (e.g. "fr_CA" → "fr").
	 *
	 * @param string $locale WP locale (e.g. "fr_FR", "pt_BR").
	 * @return string BCP-47 code or empty string when no match.
	 */
	private function localeToLangCode( string $locale ): string {
		$all = $this->lm->getAllSupportedLanguages();

		// Exact locale match.
		foreach ( $all as $code => $data ) {
			if ( ( $data['locale'] ?? '' ) === $locale ) {
				return $code;
			}
		}

		// Primary-subtag fallback: "fr_FR" → "fr".
		$primary = strtolower( strstr( $locale, '_', true ) ?: $locale );
		return isset( $all[ $primary ] ) ? $primary : '';
	}
}
