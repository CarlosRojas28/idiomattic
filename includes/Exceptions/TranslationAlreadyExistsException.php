<?php
declare( strict_types=1 );
namespace IdiomatticWP\Exceptions;
class TranslationAlreadyExistsException extends \RuntimeException {
	public function __construct( int $postId, string $targetLang ) {
		parent::__construct( "Translation already exists for post {$postId} in language {$targetLang}" );
	}
}
