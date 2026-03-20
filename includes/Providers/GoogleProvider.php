<?php
/**
 * GoogleProvider — BYOK provider for Google Cloud Translation.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Providers;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Support\HttpClient;
use IdiomatticWP\Support\EncryptionService;

class GoogleProvider implements ProviderInterface
{

    private string $apiKey;

    public function __construct(private
        HttpClient $httpClient, private
        EncryptionService $encryption
        )
    {
        $encryptedKey = get_option('idiomatticwp_google_api_key', '');
        $this->apiKey = $encryptedKey ? $this->encryption->decrypt($encryptedKey) : '';
    }

    public function translate(array $segments, string $sourceLang, string $targetLang, array $glossaryTerms = []): array
    {
        if (empty($segments))
            return [];
        if (!$this->isConfigured())
            throw new \RuntimeException('Google API Key not configured.');

        $url = 'https://translation.googleapis.com/language/translate/v2?key=' . $this->apiKey;

        $body = [
            'q' => $segments,
            'source' => $sourceLang,
            'target' => $targetLang,
            'format' => 'text'
        ];

        $headers = ['Content-Type' => 'application/json'];

        $response = $this->httpClient->post($url, $headers, $body, $this->getId());
        $data = json_decode($response['body'], true);

        if (!isset($data['data']['translations'])) {
            throw new \RuntimeException('Invalid response from Google API.');
        }

        return array_column($data['data']['translations'], 'translatedText');
    }

    public function getName(): string
    {
        return 'Google Cloud Translation';
    }

    public function getId(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function estimateCost(array $segments): float
    {
        $totalChars = array_reduce($segments, fn($carry, $item) => $carry + strlen($item), 0);
        return ($totalChars / 1000000) * 20.0; // Google is roughly $20 per million chars
    }

    public function getConfigFields(): array
    {
        return [
            ['key' => 'google_api_key', 'label' => 'Google API Key', 'type' => 'password'],
        ];
    }
}
