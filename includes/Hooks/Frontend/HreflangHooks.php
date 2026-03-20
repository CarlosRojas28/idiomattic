<?php
/**
 * HreflangHooks — connects SEO tags to wp_head.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Frontend\HreflangOutput;

class HreflangHooks implements HookRegistrarInterface
{
    public function __construct(private HreflangOutput $hreflangOutput)
    {
    }

    public function register(): void
    {
        add_action('wp_head', [$this->hreflangOutput, 'output'], 1);
    }
}
