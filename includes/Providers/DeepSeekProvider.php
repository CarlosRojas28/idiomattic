<?php
/**
 * DeepSeekProvider — BYOK provider for DeepSeek AI.
 *
 * DeepSeek's API is fully compatible with the OpenAI chat-completions format,
 * so the implementation is structurally identical to OpenAIProvider.
 *
 * Pricing (as of 2025, cache-miss rates):
 *   deepseek-chat   : $0.14 / 1M input tokens · $0.28 / 1M output tokens
 *   deepseek-reasoner: $0.55 / 1M input tokens · $2.19 / 1M output tokens
 *
 * That makes deepseek-chat ~18× cheaper than gpt-4o for comparable quality.
 *
 * API docs: https://platform.deepseek.com/api-docs/
 *
 * @package IdiomatticWP\Providers
 */

declare( strict_types=1 );

namespace IdiomatticWP\Providers;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Support\EncryptionService;
use IdiomatticWP\Support\HttpClient;

class DeepSeekProvider implements ProviderInterface {

	private const ENDPOINT      = 'https://api.deepseek.com/chat/completions';
	private const DEFAULT_MODEL = 'deepseek-chat';
	private const BATCH_SIZE    = 50;

	/** Cost per 1M input tokens in USD (deepseek-chat cache-miss rate). */
	private const COST_PER_1M_INPUT = 0.14;

	private string $apiKey;
	private string $model;

	public function __construct(
		private HttpClient        $httpClient,
		private EncryptionService $encryption,
	) {
		$encryptedKey = get_option( 'idiomatticwp_deepseek_api_key', '' );
		$this->apiKey = $encryptedKey ? $this->encryption->decrypt( $encryptedKey ) : '';
		$this->model  = get_option( 'idiomatticwp_deepseek_model', self::DEFAULT_MODEL );
	}

	// ── ProviderInterface ─────────────────────────────────────────────────

	public function translate(
		array  $segments,
		string $sourceLang,
		string $targetLang,
		array  $glossaryTerms = []
	): array {
		if ( empty( $segments ) ) {
			return [];
		}

		if ( ! $this->isConfigured() ) {
			throw new \RuntimeException( 'DeepSeek API key not configured.' );
		}

		$translated = [];

		foreach ( array_chunk( $segments, self::BATCH_SIZE ) as $batch ) {
			$translated = array_merge(
				$translated,
				$this->translateBatch( $batch, $sourceLang, $targetLang, $glossaryTerms )
			);
		}

		return $translated;
	}

	public function getName(): string {
		return 'DeepSeek';
	}

	public function getId(): string {
		return 'deepseek';
	}

	public function isConfigured(): bool {
		return $this->apiKey !== '';
	}

	public function estimateCost( array $segments ): float {
		$chars = array_reduce( $segments, fn( $c, $s ) => $c + strlen( $s ), 0 );
		// 1 token ≈ 4 chars
		return ( $chars / 4 / 1_000_000 ) * self::COST_PER_1M_INPUT;
	}

	public function getConfigFields(): array {
		return [
			[
				'key'         => 'deepseek_api_key',
				'label'       => __( 'DeepSeek API Key', 'idiomattic-wp' ),
				'type'        => 'password',
				'placeholder' => 'sk-...',
				'description' => __( 'Get your key at platform.deepseek.com', 'idiomattic-wp' ),
			],
			[
				'key'     => 'deepseek_model',
				'label'   => __( 'Model', 'idiomattic-wp' ),
				'type'    => 'select',
				'options' => apply_filters( 'idiomatticwp_deepseek_models', [
					'deepseek-chat'     => 'DeepSeek-V3 (Recommended — fast & cheap)',
					'deepseek-reasoner' => 'DeepSeek-R1 (Higher quality, slower)',
				] ),
			],
		];
	}

	// ── Private ───────────────────────────────────────────────────────────

	private function translateBatch(
		array  $batch,
		string $sourceLang,
		string $targetLang,
		array  $glossaryTerms
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
				[ 'role' => 'user',   'content' => wp_json_encode( $batch ) ],
			],
			'response_format' => [ 'type' => 'json_object' ],
		];

		$headers = [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Content-Type'  => 'application/json',
		];

		$response = $this->httpClient->post( self::ENDPOINT, $headers, $body, $this->getId() );
		$data     = json_decode( $response['body'], true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			throw new \RuntimeException( 'Invalid response from DeepSeek API.' );
		}

		$content = json_decode( $data['choices'][0]['message']['content'], true );
		$result  = $this->extractTranslationsArray( $content, count( $batch ) );

		// Count mismatch — return sources unchanged to avoid data loss.
		if ( count( $result ) !== count( $batch ) ) {
			return $batch;
		}

		return $result;
	}

	private function extractTranslationsArray( mixed $content, int $expectedCount ): array {
		if ( ! is_array( $content ) ) {
			return [];
		}

		foreach ( [ 'translations', 'translated', 'results', 'output' ] as $key ) {
			if ( isset( $content[ $key ] ) && is_array( $content[ $key ] ) ) {
				return array_values( $content[ $key ] );
			}
		}

		$values = array_values( $content );
		if ( count( $values ) === $expectedCount ) {
			return $values;
		}

		if ( count( $values ) === 1 && is_array( $values[0] ) && count( $values[0] ) === $expectedCount ) {
			return array_values( $values[0] );
		}

		foreach ( $content as $val ) {
			if ( is_array( $val ) && count( $val ) === $expectedCount ) {
				return array_values( $val );
			}
		}

		return [];
	}
}
