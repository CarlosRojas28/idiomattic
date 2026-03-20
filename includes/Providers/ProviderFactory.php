<?php
/**
 * ProviderFactory — instantiates the active translation provider.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Providers;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Support\HttpClient;
use IdiomatticWP\Support\EncryptionService;

class ProviderFactory
{

    public function __construct(private
        ProviderRegistry $registry, private
        HttpClient $httpClient, private
        EncryptionService $encryption
        )
    {
    }

    /**
     * Create the active provider instance.
     */
    public function make(): ProviderInterface
    {
        $activeId = get_option( 'idiomatticwp_active_provider', '' );
        $class    = $activeId ? $this->registry->get( $activeId ) : null;

        if ( ! $class || ! class_exists( $class ) ) {
            // No provider configured or unknown ID — return safe no-op
            return new \IdiomatticWP\Providers\NullProvider();
        }

        return new $class( $this->httpClient, $this->encryption );
    }
}
