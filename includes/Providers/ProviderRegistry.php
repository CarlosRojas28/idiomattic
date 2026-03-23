<?php
/**
 * ProviderRegistry — manages available translation providers.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Providers;

class ProviderRegistry
{

    /**
     * Get list of registered provider classes.
     */
    public function getProviders(): array
    {
        return apply_filters('idiomatticwp_registered_providers', [
            'openai'   => \IdiomatticWP\Providers\OpenAIProvider::class,
            'claude'   => \IdiomatticWP\Providers\ClaudeProvider::class,
            'deepseek' => \IdiomatticWP\Providers\DeepSeekProvider::class,
            'deepl'    => \IdiomatticWP\Providers\DeepLProvider::class,
            'google'   => \IdiomatticWP\Providers\GoogleProvider::class,
        ]);
    }

    /**
     * Get provider class by ID.
     */
    public function get(string $id): ?string
    {
        $providers = $this->getProviders();
        return $providers[$id] ?? null;
    }
}
