<?php
/**
 * Uninstall handler — called by WordPress when the plugin is deleted.
 *
 * Only runs if the user explicitly deletes the plugin.
 * Translated posts are preserved — they are standard WP posts owned by the user.
 * Only our relationship tables and options are removed.
 *
 * @package IdiomatticWP
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Only delete data if the user opted out of retention (option '1' = keep data, default)
$retention = get_option( 'idiomatticwp_uninstall_retention', '1' );

if ( $retention !== '1' ) {
	$tables = [
		$wpdb->prefix . 'idiomatticwp_translations',
		$wpdb->prefix . 'idiomatticwp_field_translations',
		$wpdb->prefix . 'idiomatticwp_translation_memory',
		$wpdb->prefix . 'idiomatticwp_strings',
		$wpdb->prefix . 'idiomatticwp_glossary',
	];

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore
	}
}

// Always clean up options
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'idiomatticwp_%'"
);

// Clean up transients
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_idiomatticwp_%'
	    OR option_name LIKE '_transient_timeout_idiomatticwp_%'"
);
