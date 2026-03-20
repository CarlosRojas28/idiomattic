<?php
/**
 * ClaudeProvider — BYOK provider for Anthropic Claude.
 */

declare( strict_types=1 );

namespace IdiomatticWP\Providers;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Support\HttpClient;
use IdiomatticWP\Support\EncryptionService;
use IdiomatticWP\Exceptions\InvalidApiKeyException;
use IdiomatticWP\Exceptions\RateLimitException;
use IdiomatticWP\Exceptions\ProviderUnavailableException;

class ClaudeProvider implements ProviderInterface {

	private const ENDPOINT     = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION  = '2023-06-01';
	private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
	private const MAX_TOKENS   = 8192;

	private string $apiKey;
	private string $model;

	public function __construct(
		private HttpClient $httpClient,
		private EncryptionService $encryption
	) {
		$encryptedKey  = get_option( 'idiomatticwp_claude_api_key', '' );
		$this->apiKey  = $encryptedKey ? $this->encryption->decrypt( $encryptedKey ) : '';
		$this->model   = get_option( 'idiomatticwp_claude_model', self::DEFAULT_MODEL );
	}

	public function translate(
		array $segments,
		string $sourceLang,
		string $targetLang,
		array $glossaryTerms = []
	): array {
		if ( empty( $segments ) ) {
			return [];
		}
		if ( ! $this->isConfigured() ) {
			throw new \RuntimeException( 'Claude API Key not configured.' );
		}

		$batches    = array_chunk( $segments, 50 );
		$translated = [];

		foreach ( $batches as $batch ) {
			$translated = array_merge(
				$translated,
				$this->translateBatch( $batch, $sourceLang, $targetLang, $glossaryTerms )
			);
		}

		return $translated;
	}

	private function translateBatch(
		array $batch,
		string $sourceLang,
		string $targetLang,
		array $glossaryTerms
	): array {
		$glossaryStr = '';
		if ( ! empty( $glossaryTerms ) ) {
			$pairs       = array_map(
				fn( $k, $v ) => "{$k} → {$v}",
				array_keys( $glossaryTerms ),
				array_values( $glossaryTerms )
			);
			$glossaryStr = ' Terminology rules: ' . implode( ', ', $pairs ) . '.';
		}

		$systemPrompt = sprintf(
			'You are a professional translator. Translate the following JSON array of strings from %s to %s. '
			. 'Return ONLY a valid JSON array of translated strings in the same order. '
			. 'Do not add any explanation or markdown.%s',
			$sourceLang,
			$targetLang,
			$glossaryStr
		);

		/**
		 * Filter the translation system prompt for the Claude provider.
		 *
		 * @param string $systemPrompt  The default prompt.
		 * @param string $targetLang    Target language code.
		 * @param string $providerId    Provider identifier ('claude').
		 */
		$systemPrompt = (string) apply_filters(
			'idiomatticwp_translation_prompt',
			$systemPrompt,
			$targetLang,
			$this->getId()
		);

		$body = [
			'model'      => $this->model,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $systemPrompt,
			'messages'   => [
				[ 'role' => 'user', 'content' => wp_json_encode( $batch ) ],
			],
		];

		$headers = [
			'x-api-key'         => $this->apiKey,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		];

		// HttpClient throws InvalidApiKeyException (401), RateLimitException (429),
		// and ProviderUnavailableException (5xx) automatically — no manual checks needed.
		$response = $this->httpClient->post( self::ENDPOINT, $headers, $body, $this->getId() );
		$data     = json_decode( $response['body'], true );

		if ( ! isset( $data['content'][0]['text'] ) ) {
			throw new \RuntimeException( 'Invalid response from Claude API.' );
		}

		$translated = json_decode( $data['content'][0]['text'], true );

		// Ensure we return the same number of segments
		if ( is_array( $translated ) && count( $translated ) === count( $batch ) ) {
			return array_values( $translated );
		}

		// Fallback: return source values unchanged
		return $batch;
	}

	public function getName(): string {
		return 'Anthropic Claude';
	}

	public function getId(): string {
		return 'claude';
	}

	public function isConfigured(): bool {
		return ! empty( $this->apiKey );
	}

	public function estimateCost( array $segments ): float {
		$totalChars = array_reduce( $segments, fn( $carry, $item ) => $carry + strlen( $item ), 0 );
		// ~4 chars per token, $3 per 1M input tokens (claude-3-5-sonnet)
		return ( $totalChars / 4 / 1_000_000 ) * 3.0;
	}

	public function getConfigFields(): array {
		return [
			[
				'key'         => 'claude_api_key',
				'label'       => __( 'Claude API Key', 'idiomattic-wp' ),
				'type'        => 'password',
				'placeholder' => 'sk-ant-...',
			],
			[
				'key'     => 'claude_model',
				'label'   => __( 'Model', 'idiomattic-wp' ),
				'type'    => 'select',
				'options' => apply_filters( 'idiomatticwp_claude_models', [
					'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Recommended)',
					'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku (Fast)',
					'claude-3-opus-20240229'     => 'Claude 3 Opus (Powerful)',
				] ),
			],
		];
	}
}
