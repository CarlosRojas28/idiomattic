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
	}

	// ── Sanitize callbacks ────────────────────────────────────────────────

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
