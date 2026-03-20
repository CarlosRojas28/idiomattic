<?php
/**
 * DeepLProvider — BYOK provider for DeepL API.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Providers;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Support\HttpClient;
use IdiomatticWP\Support\EncryptionService;

class DeepLProvider implements ProviderInterface
{

    private string $apiKey;

    public function __construct(private
        HttpClient $httpClient, private
        EncryptionService $encryption
        )
    {
        $encryptedKey = get_option('idiomatticwp_deepl_api_key', '');
        $this->apiKey = $encryptedKey ? $this->encryption->decrypt($encryptedKey) : '';
    }

    public function translate(array $segments, string $sourceLang, string $targetLang, array $glossaryTerms = []): array
    {
        if (empty($segments))
            return [];
        if (!$this->isConfigured())
            throw new \RuntimeException('DeepL API Key not configured.');

        $isFree = str_ends_with($this->apiKey, ':fx');
        $endpoint = $isFree ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';

        $body = [
            'text' => $segments,
            'source_lang' => strtoupper($sourceLang),
            'target_lang' => strtoupper($targetLang),
        ];

        $headers = [
            'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
            'Content-Type' => 'application/json'
        ];

        $response = $this->httpClient->post($endpoint, $headers, $body, $this->getId());
        $data = json_decode($response['body'], true);

        if (!isset($data['translations'])) {
            throw new \RuntimeException('Invalid response from DeepL API.');
        }

        return array_column($data['translations'], 'text');
    }

    public function getName(): string
    {
        return 'DeepL API';
    }

    public function getId(): string
    {
        return 'deepl';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function estimateCost(array $segments): float
    {
        $totalChars = array_reduce($segments, fn($carry, $item) => $carry + strlen($item), 0);
        return ($totalChars / 1000000) * 20.0; // DeepL is roughly $20 per million chars
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'deepl_api_key', 'label' => 'DeepL API Key', 'type' => 'password', 'placeholder' => '...:fx'],
        ];
    }
}
