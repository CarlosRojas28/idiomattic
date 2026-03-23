<?php
/**
 * TranslatorAccessHooks — capability grants and post-list filtering for translator roles.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class TranslatorAccessHooks implements HookRegistrarInterface {

	public function __construct(
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Grant edit_post for posts assigned to the current translator.
		add_filter( 'user_has_cap', [ $this, 'grantTranslatorCaps' ], 10, 4 );
		// Filter post lists to only show assigned translations for plain translators.
		add_action( 'pre_get_posts', [ $this, 'restrictTranslatorPostList' ] );
		// "Assigned Translator" column for translation managers.
		add_filter( 'manage_posts_columns', [ $this, 'addAssigneeColumn' ] );
		add_action( 'manage_posts_custom_column', [ $this, 'renderAssigneeColumn' ], 10, 2 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * Dynamically grant edit_post for translation posts assigned to this user.
	 *
	 * @param array<string,bool> $allCaps   All capabilities the user has.
	 * @param string[]           $cap       Required primitive caps being checked.
	 * @param array<int,mixed>   $args      [0] requested cap, [1] user ID, [2] object ID.
	 * @param \WP_User           $user      User object.
	 * @return array<string,bool>
	 */
	public function grantTranslatorCaps( array $allCaps, array $cap, array $args, \WP_User $user ): array {
		if ( ! $user->has_cap( 'idiomatticwp_translate' ) ) {
			return $allCaps;
		}

		// Grant edit_post for translation posts assigned to this translator.
		if ( in_array( 'edit_post', $cap, true ) && ! empty( $args[2] ) ) {
			$postId     = (int) $args[2];
			$assignedTo = get_post_meta( $postId, '_idiomatticwp_assigned_translator', true );
			if ( (int) $assignedTo === $user->ID ) {
				$allCaps['edit_post']  = true;
				$allCaps['edit_posts'] = true;
			}
		}

		return $allCaps;
	}

	/**
	 * Restrict the post list to only show posts assigned to the current translator.
	 * Translation managers (with idiomatticwp_manage_translations) see everything.
	 */
	public function restrictTranslatorPostList( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$user = wp_get_current_user();
		if (
			! $user->has_cap( 'idiomatticwp_translate' ) ||
			$user->has_cap( 'idiomatticwp_manage_translations' )
		) {
			return;
		}

		$query->set( 'meta_key', '_idiomatticwp_assigned_translator' );
		$query->set( 'meta_value', $user->ID );
	}

	/**
	 * Add an "Assigned Translator" column to post list tables for managers.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function addAssigneeColumn( array $columns ): array {
		$user = wp_get_current_user();
		if ( ! $user->has_cap( 'idiomatticwp_manage_translations' ) ) {
			return $columns;
		}
		$columns['iwp_assignee'] = __( 'Assigned Translator', 'idiomattic-wp' );
		return $columns;
	}

	/**
	 * Render the assignee column cell.
	 */
	public function renderAssigneeColumn( string $column, int $postId ): void {
		if ( $column !== 'iwp_assignee' ) {
			return;
		}
		$userId = (int) get_post_meta( $postId, '_idiomatticwp_assigned_translator', true );
		if ( $userId ) {
			$user = get_user_by( 'id', $userId );
			echo $user ? esc_html( $user->display_name ) : '&mdash;';
		} else {
			echo '&mdash;';
		}
	}
}
