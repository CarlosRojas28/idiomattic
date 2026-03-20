<?php
/**
 * OpenAIProvider — BYOK provider for OpenAI.
 */

declare( strict_types=1 );

namespace IdiomatticWP\Providers;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Support\HttpClient;
use IdiomatticWP\Support\EncryptionService;
use IdiomatticWP\Exceptions\InvalidApiKeyException;
use IdiomatticWP\Exceptions\RateLimitException;
use IdiomatticWP\Exceptions\ProviderUnavailableException;

class OpenAIProvider implements ProviderInterface {

	private const ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
	private const DEFAULT_MODEL = 'gpt-4o-mini';
	private const BATCH_SIZE    = 50;

	private string $apiKey;
	private string $model;

	public function __construct(
		private HttpClient $httpClient,
		private EncryptionService $encryption
	) {
		$encryptedKey = get_option( 'idiomatticwp_openai_api_key', '' );
		$this->apiKey = $encryptedKey ? $this->encryption->decrypt( $encryptedKey ) : '';
		$this->model  = get_option( 'idiomatticwp_openai_model', self::DEFAULT_MODEL );
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
			throw new \RuntimeException( 'OpenAI API Key not configured.' );
		}

		$batches    = array_chunk( $segments, self::BATCH_SIZE );
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
			. 'Return ONLY a JSON object with a "translations" key containing an array of translated strings in the same order.%s',
			$sourceLang,
			$targetLang,
			$glossaryStr
		);

		/**
		 * Filter the translation system prompt for the OpenAI provider.
		 *
		 * @param string $systemPrompt  The default prompt.
		 * @param string $targetLang    Target language code.
		 * @param string $providerId    Provider identifier ('openai').
		 */
		$systemPrompt = (string) apply_filters(
			'idiomatticwp_translation_prompt',
			$systemPrompt,
			$targetLang,
			$this->getId()
		);

		$body = [
			'model'           => $this->model,
			'messages'        => [
				[ 'role' => 'system', 'content' => $systemPrompt ],
				[ 'role' => 'user', 'content' => wp_json_encode( $batch ) ],
			],
			'response_format' => [ 'type' => 'json_object' ],
		];

		$headers = [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Content-Type'  => 'application/json',
		];

		// HttpClient throws InvalidApiKeyException (401), RateLimitException (429),
		// and ProviderUnavailableException (5xx) automatically — no manual checks needed.
		$response = $this->httpClient->post( self::ENDPOINT, $headers, $body, $this->getId() );
		$data     = json_decode( $response['body'], true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			throw new \RuntimeException( 'Invalid response from OpenAI API.' );
		}

		$content = json_decode( $data['choices'][0]['message']['content'], true );

		// Extract the translations array from the response
		$result = $this->extractTranslationsArray( $content, count( $batch ) );

		if ( count( $result ) !== count( $batch ) ) {
			// Count mismatch — return sources unchanged to avoid data loss
			return $batch;
		}

		return $result;
	}

	/**
	 * Extract a translations array of the expected length from an AI response.
	 */
	private function extractTranslationsArray( mixed $content, int $expectedCount ): array {
		if ( ! is_array( $content ) ) {
			return [];
		}

		// Common key names the model might use
		foreach ( [ 'translations', 'translated', 'results', 'output' ] as $key ) {
			if ( isset( $content[ $key ] ) && is_array( $content[ $key ] ) ) {
				return array_values( $content[ $key ] );
			}
		}

		// Top-level indexed array
		$values = array_values( $content );
		if ( count( $values ) === $expectedCount ) {
			return $values;
		}

		// Single nested array
		if ( count( $values ) === 1 && is_array( $values[0] ) && count( $values[0] ) === $expectedCount ) {
			return array_values( $values[0] );
		}

		// Last resort: any nested array of the right size
		foreach ( $content as $val ) {
			if ( is_array( $val ) && count( $val ) === $expectedCount ) {
				return array_values( $val );
			}
		}

		return [];
	}

	public function getName(): string {
		return 'OpenAI';
	}

	public function getId(): string {
		return 'openai';
	}

	public function isConfigured(): bool {
		return ! empty( $this->apiKey );
	}

	public function estimateCost( array $segments ): float {
		$totalChars = array_reduce( $segments, fn( $carry, $item ) => $carry + strlen( $item ), 0 );
		// gpt-4o-mini: ~$0.15 per 1M input tokens; 1 token ≈ 4 chars
		return ( $totalChars / 4 / 1_000_000 ) * 0.15;
	}

	public function getConfigFields(): array {
		return [
			[
				'key'         => 'openai_api_key',
				'label'       => __( 'OpenAI API Key', 'idiomattic-wp' ),
				'type'        => 'password',
				'placeholder' => 'sk-...',
			],
			[
				'key'     => 'openai_model',
				'label'   => __( 'Model', 'idiomattic-wp' ),
				'type'    => 'select',
				'options' => apply_filters( 'idiomatticwp_openai_models', [
					'gpt-4o-mini' => 'GPT-4o Mini (Recommended)',
					'gpt-4o'      => 'GPT-4o',
					'gpt-4-turbo' => 'GPT-4 Turbo',
					'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Cheap)',
				] ),
			],
		];
	}
}
