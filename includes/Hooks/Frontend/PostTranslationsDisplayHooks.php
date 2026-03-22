<?php
/**
 * PostTranslationsDisplayHooks — registers the content filter for the post translations notice.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Frontend\PostTranslationsDisplay;

class PostTranslationsDisplayHooks implements HookRegistrarInterface {

	public function __construct( private PostTranslationsDisplay $display ) {}

	public function register(): void {
		// Priority 20 — runs after standard content filters (e.g. wpautop at 10).
		add_filter( 'the_content', [ $this->display, 'injectIntoContent' ], 20 );
	}
}
