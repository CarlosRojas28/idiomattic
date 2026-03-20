<?php
/**
 * TranslationEditorPage — placeholder submenu page (not used in current flow).
 *
 * The actual translation editing happens via TranslationEditor which intercepts
 * post.php?action=idiomatticwp_translate directly. This page class exists only
 * to satisfy the menu registration API if needed in the future.
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

class TranslationEditorPage {

	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Translation Editor', 'idiomattic-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Open any post or page and click "Translate" to use the translation editor.', 'idiomattic-wp' ) . '</p></div>';
	}
}
