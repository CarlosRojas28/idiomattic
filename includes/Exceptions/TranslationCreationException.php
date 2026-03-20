<?php
declare( strict_types=1 );
namespace IdiomatticWP\Exceptions;
class TranslationCreationException extends \RuntimeException {
	public function __construct( int $postId, string $targetLang, string $reason, ?\Throwable $previous = null ) {
		parent::__construct( "Failed to create translation for post {$postId} in {$targetLang}: {$reason}", 0, $previous );
	}
}
