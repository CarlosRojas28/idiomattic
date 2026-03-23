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

	/**
	 * Each settings tab uses its own option group (idiomatticwp_settings_{tab}).
	 * This prevents WordPress from resetting options that belong to other tabs
	 * when saving a tab whose form does not include those fields.
	 */
	public function registerSettings(): void {

		// ── Languages tab ─────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings_languages', 'idiomatticwp_active_langs', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeActiveLanguages' ],
			'default'           => [],
		] );

		register_setting( 'idiomatticwp_settings_languages', 'idiomatticwp_default_lang', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitizeLangCode' ],
			'default'           => 'en',
		] );

		// ── URL tab ───────────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings_url', 'idiomatticwp_url_mode', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitizeUrlMode' ],
			'default'           => 'parameter',
		] );

		// ── Translation tab ───────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings_translation', 'idiomatticwp_wc_currencies', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeCurrencies' ],
			'default'           => [],
		] );

		register_setting( 'idiomatticwp_settings_translation', 'idiomatticwp_active_provider', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
			'default'           => 'openai',
		] );

		register_setting( 'idiomatticwp_settings_translation', 'idiomatticwp_tm_enabled', [
			'type'    => 'string',
			'default' => '',
		] );

		register_setting( 'idiomatticwp_settings_translation', 'idiomatticwp_auto_translate', [
			'type'    => 'string',
			'default' => '',
		] );

		register_setting( 'idiomatticwp_settings_translation', 'idiomatticwp_openai_model', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'gpt-4o-mini',
		] );

		// ── Content tab ───────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings_content', 'idiomatticwp_post_type_config', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeContentConfig' ],
			'default'           => [],
		] );

		register_setting( 'idiomatticwp_settings_content', 'idiomatticwp_taxonomy_config', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeContentConfig' ],
			'default'           => [],
		] );

		register_setting( 'idiomatticwp_settings_content', 'idiomatticwp_custom_field_config', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeFieldConfig' ],
			'default'           => [],
		] );

		register_setting( 'idiomatticwp_settings_content', 'idiomatticwp_translate_on_publish', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizePostTypeSlugs' ],
			'default'           => [],
		] );

		// ── URL tab (continued) — WooCommerce taxonomy base slugs ────────
		register_setting( 'idiomatticwp_settings_url', 'idiomatticwp_taxonomy_slugs', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitizeTaxonomySlugs' ],
			'default'           => [],
		] );

		// ── Menus tab ─────────────────────────────────────────────────────
		register_setting( 'idiomatticwp_settings_menus', 'idiomatticwp_nav_menus', [
			'type'              => 'array',
			'default'           => [],
			'sanitize_callback' => [ $this, 'sanitizeNavMenus' ],
		] );

		// ── Advanced tab ──────────────────────────────────────────────────
		// Includes general advanced settings + notifications + webhooks,
		// since all are rendered within the single Advanced tab form.
		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_uninstall_retention', [
			'type'    => 'string',
			'default' => '1',
		] );

		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_cache_lang_detect', [
			'type'    => 'string',
			'default' => '1',
		] );

		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_browser_lang_detect', [
			'type'    => 'string',
			'default' => '1',
		] );

		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_custom_languages', [
			'type'    => 'array',
			'default' => [],
		] );

		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_notify_outdated', [
			'type'    => 'string',
			'default' => '',
		] );
		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_notify_email', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		] );
		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_notify_mode', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitizeNotifyMode' ],
			'default'           => 'immediate',
		] );

		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_webhook_url', [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		] );
		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_webhook_secret', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		register_setting( 'idiomatticwp_settings_advanced', 'idiomatticwp_webhook_events', [
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
	 * Sanitize the nav menus option.
	 *
	 * Accepts the new 2-D shape: [ theme_location => [ lang_code => menu_id ] ]
	 *
	 * Legacy flat shape [ lang_code => menu_id ] is silently ignored so that
	 * existing installs do not break — admins simply re-save the Menus tab to
	 * migrate to the new format.
	 */
	public function sanitizeNavMenus( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$clean = [];
		foreach ( $input as $location => $langMap ) {
			$location = sanitize_key( (string) $location );
			if ( ! $location ) {
				continue;
			}
			if ( ! is_array( $langMap ) ) {
				// Skip legacy flat entries (scalar menu IDs keyed by lang code).
				continue;
			}
			foreach ( $langMap as $lang => $menuId ) {
				// Preserve BCP-47 case (e.g. en-GB) while stripping invalid chars.
				$lang   = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $lang );
				$menuId = absint( $menuId );
				if ( $lang && $menuId > 0 ) {
					$clean[ $location ][ $lang ] = $menuId;
				}
			}
		}

		return $clean;
	}

	public function sanitizePostTypeSlugs( mixed $input ): array {
		if ( ! is_array( $input ) ) return [];
		return array_values( array_map( 'sanitize_key', $input ) );
	}

	public function sanitizeActiveLanguages( mixed $input ): array {
		if ( ! is_array( $input ) ) return [];
		// sanitize_key lowercases everything, breaking BCP-47 codes like en-GB, zh-CN.
		// Strip any character that isn't alphanumeric or a hyphen while preserving case.
		$langs = array_values( array_filter(
			array_map(
				fn( $v ) => preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $v ),
				$input
			),
			fn( $v ) => $v !== ''
		) );

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
	 * Sanitize a BCP-47 language code, preserving case (e.g. en-GB, zh-CN).
	 */
	public function sanitizeLangCode( mixed $input ): string {
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $input );
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
	 * Sanitize the WooCommerce taxonomy base slug translations.
	 *
	 * Expected shape: [ lang => [ slug_key => 'translated-slug' ] ]
	 * Each slug is run through sanitize_title() to produce a URL-safe string.
	 */
	public function sanitizeTaxonomySlugs( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$allowed_keys = [ 'product_slug', 'product_category_slug', 'product_tag_slug' ];
		$clean        = [];

		foreach ( $input as $lang => $slugMap ) {
			$lang = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $lang );
			if ( $lang === '' || ! is_array( $slugMap ) ) {
				continue;
			}
			foreach ( $slugMap as $key => $slug ) {
				$key  = sanitize_key( (string) $key );
				$slug = sanitize_title( (string) $slug );
				if ( $key !== '' && $slug !== '' && in_array( $key, $allowed_keys, true ) ) {
					$clean[ $lang ][ $key ] = $slug;
				}
			}
		}

		return $clean;
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

	/**
	 * Sanitize the per-language WooCommerce currency config.
	 *
	 * Expected shape:
	 *   [ lang_code => [ 'code' => 'EUR', 'symbol' => '€', 'rate' => 1.08 ] ]
	 */
	public function sanitizeCurrencies( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$out = [];
		foreach ( $input as $lang => $config ) {
			$lang = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $lang );
			if ( ! $lang ) {
				continue;
			}

			if ( ! is_array( $config ) ) {
				continue;
			}

			$code   = strtoupper( sanitize_text_field( (string) ( $config['code']   ?? '' ) ) );
			$symbol = sanitize_text_field( (string) ( $config['symbol'] ?? '' ) );
			$rate   = (float) ( $config['rate'] ?? 1.0 );

			if ( $code ) {
				$out[ $lang ] = [
					'code'   => $code,
					'symbol' => $symbol,
					'rate'   => max( 0.0001, $rate ),
				];
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
	 * Set a transient flag so we flush rewrite rules on the next init,
	 * and clear all Idiomattic-related transients and object-cache entries.
	 */
	public function scheduleRewriteFlush(): void {
		global $wpdb;

		set_transient( 'idiomatticwp_flush_rewrite_rules', 1, MINUTE_IN_SECONDS * 5 );

		// Delete all transients whose keys start with 'idiomatticwp_'.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			  WHERE option_name LIKE '_transient_idiomatticwp_%'
			     OR option_name LIKE '_transient_timeout_idiomatticwp_%'"
		);

		// Re-set the flush flag (the query above may have deleted it).
		set_transient( 'idiomatticwp_flush_rewrite_rules', 1, MINUTE_IN_SECONDS * 5 );

		// Clear object-cache group if the backend supports it.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'idiomatticwp' );
		}
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
