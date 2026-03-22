<?php
/**
 * TranslationEditorHooks — intercepts post.php to load the Translation Editor.
 *
 * Hooks into 'load-post.php' (which fires before any output) and checks
 * if action=idiomatticwp_translate. If so, delegates to TranslationEditor
 * which renders the full-page editor and exits.
 *
 * Also rewrites the "Edit" link for translated posts so it always points
 * to the Translation Editor instead of the standard post editor.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Admin\Pages\TranslationEditor;

class TranslationEditorHooks implements HookRegistrarInterface {

	public function __construct(
		private TranslationEditor $editor,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Intercept post.php before WordPress renders the standard editor
		add_action( 'load-post.php', [ $this, 'maybeInterceptEditor' ] );

		// Enqueue our Translation Editor CSS only on our page
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueEditorAssets' ] );

		// Redirect translated posts from the standard editor to our editor
		add_action( 'load-post.php', [ $this, 'redirectTranslatedPosts' ] );

		// Rewrite edit links for translated posts throughout the admin
		add_filter( 'get_edit_post_link', [ $this, 'rewriteEditLink' ], 10, 3 );

		// Show an admin notice when the translation being edited is outdated
		add_action( 'admin_notices', [ $this, 'maybeShowOutdatedNotice' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/**
	 * If action=idiomatticwp_translate, hand off to the Translation Editor.
	 */
	public function maybeInterceptEditor(): void {
		if ( ( $_GET['action'] ?? '' ) !== 'idiomatticwp_translate' ) {
			return;
		}
		$this->editor->intercept();
	}

	/**
	 * When a translated post is opened in the standard editor (action=edit),
	 * redirect to our Translation Editor automatically.
	 */
	public function redirectTranslatedPosts(): void {
		$action = $_GET['action'] ?? 'edit';
		if ( $action !== 'edit' ) {
			return;
		}

		// Skip when coming from the admin bar language switcher — the user
		// wants the standard Gutenberg editor, not the Translation Editor.
		// Also clean the bypass parameter from the URL immediately.
		if ( ! empty( $_GET['idiomatticwp_direct_edit'] ) ) {
			wp_safe_redirect( remove_query_arg( 'idiomatticwp_direct_edit' ) );
			exit;
		}

		$postId = absint( $_GET['post'] ?? 0 );
		if ( ! $postId ) {
			return;
		}

		// Only redirect if this post is a translated post (not a source)
		$record = $this->repository->findByTranslatedPost( $postId );
		if ( ! $record ) {
			return;
		}

		$editorUrl = add_query_arg( [
			'post'   => $postId,
			'action' => 'idiomatticwp_translate',
		], admin_url( 'post.php' ) );

		wp_safe_redirect( $editorUrl );
		exit;
	}

	/**
	 * Rewrite get_edit_post_link() for translated posts to point to our editor.
	 * This affects metaboxes, post list "Edit" links, admin bar, etc.
	 */
	public function rewriteEditLink( string $link, int $postId, string $context ): string {
		// Avoid infinite loop — if we're already building the translate URL, skip
		if ( str_contains( $link, 'idiomatticwp_translate' ) ) {
			return $link;
		}

		// Static cache avoids one DB query per post row in the admin list table
		static $cache = [];
		if ( ! array_key_exists( $postId, $cache ) ) {
			$cache[ $postId ] = $this->repository->findByTranslatedPost( $postId );
		}

		if ( ! $cache[ $postId ] ) {
			return $link; // Not a translated post, leave untouched
		}

		$url = add_query_arg( [
			'post'   => $postId,
			'action' => 'idiomatticwp_translate',
		], admin_url( 'post.php' ) );

		return $context === 'display' ? esc_url( $url ) : $url;
	}

	/**
	 * Show an admin notice when the open translation is marked outdated.
	 * Fires on `admin_notices` — only on the Translation Editor screen.
	 */
	public function maybeShowOutdatedNotice(): void {
		if ( ( $_GET['action'] ?? '' ) !== 'idiomatticwp_translate' ) {
			return;
		}

		$postId = absint( $_GET['post'] ?? 0 );
		if ( ! $postId ) {
			return;
		}

		$record = $this->repository->findByTranslatedPost( $postId );
		if ( ! $record || ( $record['status'] ?? '' ) !== 'outdated' ) {
			return;
		}

		$sourceUrl = get_edit_post_link( (int) ( $record['source_post_id'] ?? 0 ) );
		printf(
			'<div class="notice notice-warning"><p>%s%s</p></div>',
			esc_html__( 'This translation is outdated — the source post has been updated since it was last translated. Please review and re-translate.', 'idiomattic-wp' ),
			$sourceUrl
				? ' <a href="' . esc_url( $sourceUrl ) . '">' . esc_html__( 'View source post →', 'idiomattic-wp' ) . '</a>'
				: ''
		);
	}

	/**
	 * Enqueue CSS for the Translation Editor page only.
	 */
	public function enqueueEditorAssets( string $hook ): void {
		if ( $hook !== 'post.php' ) {
			return;
		}
		if ( ( $_GET['action'] ?? '' ) !== 'idiomatticwp_translate' ) {
			return;
		}

		wp_enqueue_style(
			'idiomatticwp-translation-editor',
			IDIOMATTICWP_ASSETS_URL . 'css/translation-editor.css',
			[ 'wp-admin' ],
			IDIOMATTICWP_VERSION
		);
	}
}
