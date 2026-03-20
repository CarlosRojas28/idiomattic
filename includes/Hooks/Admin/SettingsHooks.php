<?php
/**
 * SettingsHooks — registers all plugin settings with the WordPress Settings API
 * and triggers rewrite-rule flushes when URL-related options change.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;

class SettingsHooks implements HookRegistrarInterface {

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		add_action( 'admin_init', [ $this, 'registerSettings' ] );

		// Flush rewrite rules when the URL mode or active languages change.
		add_action( 'update_option_idiomatticwp_url_mode',    [ $this, 'scheduleRewriteFlush' ], 10, 0 );
		add_action( 'update_option_idiomatticwp_active_langs', [ $this, 'scheduleRewriteFlush' ], 10, 0 );
		add_action( 'update_option_idiomatticwp_default_lang', [ $this, 'scheduleRewriteFlush' ], 10, 0 );

		add_action( 'init', [ $this, 'maybeFlushRewriteRules' ], 99 );

		// Custom language management
		add_action( 'admin_post_idiomatticwp_add_custom_lang',    [ $this, 'handleAddCustomLanguage'    ] );
		add_action( 'admin_post_idiomatticwp_delete_custom_lang', [ $this, 'handleDeleteCustomLanguage' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	public function registerSettings(): void {

		// ── Languages ─────────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_active_langs', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeActiveLanguages' ],
			'default'           => [],
		] );

		register_setting( 'idiomatticwp_settings', 'idiomatticwp_default_lang', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
			'default'           => 'en',
		] );

		// ── URL structure ─────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_url_mode', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitizeUrlMode' ],
			'default'           => 'parameter',
		] );

		// ── Translation / AI providers ────────────────────────────────────
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_active_provider', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
			'default'           => 'openai',
		] );

		register_setting( 'idiomatticwp_settings', 'idiomatticwp_tm_enabled', [
			'type'    => 'string',
			'default' => '',
		] );

		register_setting( 'idiomatticwp_settings', 'idiomatticwp_auto_translate', [
			'type'    => 'string',
			'default' => '',
		] );

		register_setting( 'idiomatticwp_settings', 'idiomatticwp_openai_model', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'gpt-4o-mini',
		] );

		// ── Content configuration ─────────────────────────────────────────
		// Post type translation modes: [ 'slug' => 'translate'|'show_as_translated'|'ignore' ]
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_post_type_config', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeContentConfig' ],
			'default'           => [],
		] );

		// Taxonomy translation modes
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_taxonomy_config', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeContentConfig' ],
			'default'           => [],
		] );

		// Custom field translation modes: [ 'meta_key' => 'translate'|'copy'|'ignore' ]
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_custom_field_config', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeFieldConfig' ],
			'default'           => [],
		] );

		// ── Advanced ──────────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_uninstall_retention', [
			'type'    => 'string',
			'default' => '1',
		] );

		register_setting( 'idiomatticwp_settings', 'idiomatticwp_cache_lang_detect', [
			'type'    => 'string',
			'default' => '1',
		] );

		register_setting( 'idiomatticwp_settings', 'idiomatticwp_nav_menus', [
			'type'              => 'array',
			'default'           => [],
			'sanitize_callback' => [ $this, 'sanitizeNavMenus' ],
		] );

		register_setting( 'idiomatticwp_settings', 'idiomatticwp_custom_languages', [
			'type'    => 'array',
			'default' => [],
		] );

		// ── Notifications ──────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_notify_outdated', [
			'type'    => 'string',
			'default' => '',
		] );
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_notify_email', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		] );
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_notify_mode', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitizeNotifyMode' ],
			'default'           => 'immediate',
		] );

		// ── Webhooks ───────────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_webhook_url', [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		] );
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_webhook_secret', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		register_setting( 'idiomatticwp_settings', 'idiomatticwp_webhook_events', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeWebhookEvents' ],
			'default'           => [ 'translation.completed', 'translation.outdated' ],
		] );
	}

	public function sanitizeNotifyMode( mixed $input ): string {
		return in_array( $input, [ 'immediate', 'digest' ], true ) ? (string) $input : 'immediate';
	}

	public function sanitizeWebhookEvents( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$allowed = [ 'translation.completed', 'translation.outdated', 'translation.queued' ];
		return array_values( array_filter( $input, fn( $e ) => in_array( $e, $allowed, true ) ) );
	}

	// ── Sanitize callbacks ────────────────────────────────────────────────

	/**
	 * Sanitize the nav menus option: array of [ lang_code => menu_id ].
	 */
	public function sanitizeNavMenus( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$clean = [];
		foreach ( $input as $code => $menuId ) {
			$code   = sanitize_key( $code );
			$menuId = absint( $menuId );
			if ( $code && $menuId > 0 ) {
				$clean[ $code ] = $menuId;
			}
		}

		return $clean;
	}

	public function sanitizeActiveLanguages( mixed $input ): array {
		if ( ! is_array( $input ) ) return [];
		$langs = array_values( array_map( 'sanitize_key', $input ) );

		// Clear the setup wizard flag as soon as the user saves at least one language
		if ( ! empty( $langs ) ) {
			delete_option( 'idiomatticwp_needs_setup' );
		}

		return $langs;
	}

	public function sanitizeUrlMode( mixed $input ): string {
		$allowed = [ 'parameter', 'directory', 'subdomain' ];
		$value   = sanitize_key( (string) $input );
		return in_array( $value, $allowed, true ) ? $value : 'parameter';
	}

	/**
	 * Sanitize post type / taxonomy config arrays.
	 * Values must be one of: translate | show_as_translated | ignore
	 */
	public function sanitizeContentConfig( mixed $input ): array {
		if ( ! is_array( $input ) ) return [];
		$allowed = [ 'translate', 'show_as_translated', 'ignore' ];
		$out     = [];
		foreach ( $input as $key => $value ) {
			$k = sanitize_key( (string) $key );
			$v = sanitize_key( (string) $value );
			if ( $k !== '' && in_array( $v, $allowed, true ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Sanitize custom field config arrays.
	 * Values must be one of: translate | copy | ignore
	 */
	public function sanitizeFieldConfig( mixed $input ): array {
		if ( ! is_array( $input ) ) return [];
		$allowed = [ 'translate', 'copy', 'ignore' ];
		$out     = [];
		foreach ( $input as $key => $value ) {
			$k = sanitize_text_field( (string) $key );
			$v = sanitize_key( (string) $value );
			if ( $k !== '' && in_array( $v, $allowed, true ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	// ── Custom language management ─────────────────────────────────────────

	/**
	 * Handle add-custom-language form submission.
	 */
	public function handleAddCustomLanguage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'idiomattic-wp' ), 403 );
		}

		check_admin_referer( 'idiomatticwp_add_custom_lang' );

		$code        = sanitize_key( (string) ( $_POST['custom_lang_code']        ?? '' ) );
		$nativeName  = sanitize_text_field( (string) ( $_POST['custom_lang_native'] ?? '' ) );
		$englishName = sanitize_text_field( (string) ( $_POST['custom_lang_name']   ?? '' ) );
		$flagCode    = sanitize_key( (string) ( $_POST['custom_lang_flag']          ?? '' ) );
		$rtl         = ! empty( $_POST['custom_lang_rtl'] );

		$settingsUrl = admin_url( 'admin.php?page=idiomatticwp-settings&tab=languages' );

		if ( $code === '' || $nativeName === '' || $englishName === '' ) {
			wp_safe_redirect( add_query_arg( 'idiomatticwp_error', 'missing_fields', $settingsUrl ) );
			exit;
		}

		if ( ! preg_match( '/^[a-z]{2}(-[A-Z]{2})?$/', $code ) ) {
			wp_safe_redirect( add_query_arg( 'idiomatticwp_error', 'invalid_code', $settingsUrl ) );
			exit;
		}

		$custom = get_option( 'idiomatticwp_custom_languages', [] );
		if ( ! is_array( $custom ) ) {
			$custom = [];
		}

		$custom[ $code ] = [
			'locale'      => str_replace( '-', '_', $code ),
			'name'        => $englishName,
			'native_name' => $nativeName,
			'rtl'         => $rtl,
			'flag'        => $flagCode,
		];

		update_option( 'idiomatticwp_custom_languages', $custom );

		wp_safe_redirect( add_query_arg( 'idiomatticwp_saved', '1', $settingsUrl ) );
		exit;
	}

	/**
	 * Handle delete-custom-language action.
	 */
	public function handleDeleteCustomLanguage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'idiomattic-wp' ), 403 );
		}

		$code = sanitize_key( (string) ( $_GET['code'] ?? '' ) );
		check_admin_referer( 'idiomatticwp_delete_custom_lang_' . $code );

		$custom = get_option( 'idiomatticwp_custom_languages', [] );
		if ( is_array( $custom ) && isset( $custom[ $code ] ) ) {
			unset( $custom[ $code ] );
			update_option( 'idiomatticwp_custom_languages', $custom );
		}

		$settingsUrl = admin_url( 'admin.php?page=idiomatticwp-settings&tab=languages' );
		wp_safe_redirect( add_query_arg( 'idiomatticwp_saved', '1', $settingsUrl ) );
		exit;
	}

	/**
	 * Set a transient flag so we flush rewrite rules on the next init.
	 */
	public function scheduleRewriteFlush(): void {
		set_transient( 'idiomatticwp_flush_rewrite_rules', 1, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Flush rewrite rules once if the transient flag is set.
	 */
	public function maybeFlushRewriteRules(): void {
		if ( get_transient( 'idiomatticwp_flush_rewrite_rules' ) ) {
			delete_transient( 'idiomatticwp_flush_rewrite_rules' );
			flush_rewrite_rules( false );
		}
	}
}
