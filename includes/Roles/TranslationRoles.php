<?php
/**
 * TranslationRoles — registers and removes translator user roles.
 *
 * @package IdiomatticWP\Roles
 */

declare( strict_types=1 );

namespace IdiomatticWP\Roles;

class TranslationRoles {

	/**
	 * Register the two translator roles.
	 * Safe to call on every activation — add_role() is a no-op if the role already exists.
	 */
	public static function register(): void {
		// Translator: can read and edit (but not publish) posts assigned to them.
		add_role(
			'idiomatticwp_translator',
			__( 'Translator', 'idiomattic-wp' ),
			[
				'read'                   => true,
				'edit_posts'             => true,
				'edit_others_posts'      => false, // only posts assigned via meta
				'publish_posts'          => false,
				'delete_posts'           => false,
				'upload_files'           => true,
				'idiomatticwp_translate' => true,  // custom cap for translation UI
			]
		);

		// Translation Manager: can see all translations, assign, and manage workflow.
		add_role(
			'idiomatticwp_translation_manager',
			__( 'Translation Manager', 'idiomattic-wp' ),
			[
				'read'                             => true,
				'edit_posts'                       => true,
				'edit_others_posts'                => true,
				'publish_posts'                    => true,
				'delete_posts'                     => false,
				'upload_files'                     => true,
				'manage_options'                   => false,
				'idiomatticwp_translate'           => true,
				'idiomatticwp_manage_translations' => true, // access to full dashboard
			]
		);
	}

	/**
	 * Remove both translator roles.
	 * Only called on uninstall — not on deactivation — to preserve user assignments.
	 */
	public static function remove(): void {
		remove_role( 'idiomatticwp_translator' );
		remove_role( 'idiomatticwp_translation_manager' );
	}
}
