<?php
/**
 * HttpClient — wrapper for WP HTTP API with error handling for providers.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Support;

use IdiomatticWP\Exceptions\InvalidApiKeyException;
use IdiomatticWP\Exceptions\RateLimitException;
use IdiomatticWP\Exceptions\ProviderUnavailableException;

class HttpClient
{

    public function post(string $url, array $headers, array $body, string $providerId, int $timeout = 30): array
    {
        $args = [
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => $timeout,
            'method' => 'POST',
            'data_format' => 'body'
        ];

        $response = wp_remote_post($url, $args);

        return $this->processResponse($response, $providerId);
    }

    public function get(string $url, array $headers, string $providerId, int $timeout = 15): array
    {
        $args = [
            'headers' => $headers,
            'timeout' => $timeout,
            'method' => 'GET'
        ];

        $response = wp_remote_get($url, $args);

        return $this->processResponse($response, $providerId);
    }

    private function processResponse($response, string $providerId): array
    {
        if (is_wp_error($response)) {
            throw new ProviderUnavailableException($providerId, $response);
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 401) {
            throw new InvalidApiKeyException($providerId);
        }

        if ($code === 429) {
            $retryAfter = (int)wp_remote_retrieve_header($response, 'retry-after');
            throw new RateLimitException($providerId, $retryAfter ?: 60);
        }

        if ($code >= 500) {
            throw new ProviderUnavailableException($providerId);
        }

        return [
            'status' => $code,
            'body' => $body,
            'headers' => wp_remote_retrieve_headers($response)
        ];
    }
}
