<?php
/**
 * AIOrchestrator — coordinates the full translation pipeline.
 *
 * Segment → TM lookup → AI Provider → TM save → write fields.
 *
 * @package IdiomatticWP\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Translation;

use IdiomatticWP\Contracts\ProviderInterface;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Exceptions\InvalidApiKeyException;
use IdiomatticWP\Glossary\GlossaryManager;
use IdiomatticWP\Memory\TranslationMemory;
use IdiomatticWP\ValueObjects\LanguageCode;

class AIOrchestrator {

	public function __construct(
		private Segmenter $segmenter,
		private TranslationMemory $memory,
		private ProviderInterface $provider,
		private FieldTranslator $fieldTranslator,
		private GlossaryManager $glossary,
		private TranslationRepositoryInterface $repository,
	) {}

	/**
	 * Perform full automatic translation for a post.
	 *
	 * @return array{segments: int, tm_hits: int, cost: float}
	 *
	 * @throws InvalidApiKeyException       Sets status to 'failed', no retry.
	 * @throws \IdiomatticWP\Exceptions\RateLimitException          Re-thrown — caller retries.
	 * @throws \IdiomatticWP\Exceptions\ProviderUnavailableException Re-thrown — caller retries.
	 */
	public function translate(
		int $postId,
		int $translationId,
		LanguageCode $sourceLang,
		LanguageCode $targetLang
	): array {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( "Post not found: {$postId}" );
		}

		do_action( 'idiomatticwp_before_auto_translate', $postId, $targetLang, $this->provider->getId() );

		// 1. Segment post fields
		$fieldSegments    = $this->segmenter->segmentPostFields( $post );
		$translatedFields = [];
		$stats            = [ 'segments' => 0, 'tm_hits' => 0 ];

		// 2. Process each field — TM lookup then batch-translate misses
		foreach ( $fieldSegments as $fieldKey => $segments ) {
			$translatedSegments = [];
			$toTranslateBatch   = [];
			$batchMap           = [];

			foreach ( $segments as $index => $text ) {
				$stats['segments']++;

				$match = $this->memory->lookup( $text, $sourceLang, $targetLang );

				if ( $match ) {
					$translatedSegments[ $index ] = $match->translatedText;
					$stats['tm_hits']++;
				} else {
					$toTranslateBatch[] = $text;
					$batchMap[]         = $index;
				}
			}

			// 3. Call AI provider for cache misses
			if ( ! empty( $toTranslateBatch ) ) {
				$glossaryTerms = $this->glossary->getTerms( $sourceLang, $targetLang );

				try {
					$aiTranslations = $this->provider->translate(
						$toTranslateBatch,
						(string) $sourceLang,
						(string) $targetLang,
						$glossaryTerms
					);
				} catch ( InvalidApiKeyException $e ) {
					$this->repository->updateStatus( $translationId, 'failed' );
					throw $e; // Do NOT retry — bad key needs user action
				}
				// RateLimitException / ProviderUnavailableException bubble up for caller retry

				foreach ( $aiTranslations as $i => $translatedText ) {
					$originalIndex                        = $batchMap[ $i ] ?? $i;
					$translatedSegments[ $originalIndex ] = $translatedText;

					$this->memory->save(
						$toTranslateBatch[ $i ],
						$translatedText,
						$sourceLang,
						$targetLang,
						$this->provider->getId()
					);
				}
			}

			// 4. Reconstruct the field from translated segments
			$originalValue = match ( $fieldKey ) {
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				default        => (string) get_post_meta( $postId, $fieldKey, true ),
			};

			$contentType                    = ( $fieldKey === 'post_content' ) ? 'html' : 'text';
			$translatedFields[ $fieldKey ] = $this->segmenter->reconstruct(
				$originalValue,
				$translatedSegments,
				$contentType
			);
		}

		// 5. Write translated fields to the translated post
		// Use findBySourceAndLang — available on the interface and returns the row array
		$translationRow = $this->repository->findBySourceAndLang( $postId, $targetLang );

		if ( $translationRow && ! empty( $translationRow['translated_post_id'] ) ) {
			$translatedPostId = (int) $translationRow['translated_post_id'];

			wp_update_post( [
				'ID'           => $translatedPostId,
				'post_title'   => $translatedFields['post_title']   ?? $post->post_title,
				'post_content' => $translatedFields['post_content'] ?? $post->post_content,
				'post_excerpt' => $translatedFields['post_excerpt'] ?? $post->post_excerpt,
				'post_status'  => 'publish',
			] );

			foreach ( $translatedFields as $key => $value ) {
				if ( in_array( $key, [ 'post_title', 'post_content', 'post_excerpt' ], true ) ) {
					continue;
				}
				$this->fieldTranslator->saveFieldTranslation( $translationId, $key, (string) $value );
			}

			// updateStatus is declared in the interface
			$this->repository->updateStatus( $translationId, 'complete' );
		}

		$result = array_merge(
			$stats,
			[ 'cost' => $this->provider->estimateCost( array_values( $translatedFields ) ) ]
		);

		do_action( 'idiomatticwp_after_auto_translate', $postId, $targetLang, $result );

		return $result;
	}

	/**
	 * Translate a single field value (title, content, excerpt, or any meta value).
	 *
	 * Used by AutoTranslateAjax for per-field translation in the Translation Editor.
	 * Respects Translation Memory: saves hits and stores new translations.
	 *
	 * @param string       $value       Raw source text/HTML.
	 * @param string       $contentType 'html' | 'text'
	 * @param LanguageCode $sourceLang
	 * @param LanguageCode $targetLang
	 * @return string Translated value.
	 */
	public function translateField(
		string $value,
		string $contentType,
		LanguageCode $sourceLang,
		LanguageCode $targetLang
	): string {
		if ( trim( $value ) === '' ) {
			return '';
		}

		// Segment the value
		$segments = $this->segmenter->segment( $value, $contentType );
		if ( empty( $segments ) ) {
			return $value;
		}

		$translatedSegments = [];
		$toTranslateBatch   = [];
		$batchMap           = [];

		// TM lookup per segment
		foreach ( $segments as $index => $text ) {
			$match = $this->memory->lookup( $text, $sourceLang, $targetLang );
			if ( $match ) {
				$translatedSegments[ $index ] = $match->translatedText;
			} else {
				$toTranslateBatch[] = $text;
				$batchMap[]         = $index;
			}
		}

		// Translate cache-misses via provider
		if ( ! empty( $toTranslateBatch ) ) {
			$glossaryTerms  = $this->glossary->getTerms( $sourceLang, $targetLang );
			$aiTranslations = $this->provider->translate(
				$toTranslateBatch,
				(string) $sourceLang,
				(string) $targetLang,
				$glossaryTerms
			);

			foreach ( $aiTranslations as $i => $translatedText ) {
				$originalIndex                        = $batchMap[ $i ] ?? $i;
				$translatedSegments[ $originalIndex ] = $translatedText;

				// Persist to TM for future reuse
				$this->memory->save(
					$toTranslateBatch[ $i ],
					$translatedText,
					$sourceLang,
					$targetLang,
					$this->provider->getId()
				);
			}
		}

		return $this->segmenter->reconstruct( $value, $translatedSegments, $contentType );
	}
}
