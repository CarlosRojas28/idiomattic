<?php
/**
 * FieldTranslationHooks — wires field-level translation events.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Translation\FieldTranslator;
use IdiomatticWP\ValueObjects\LanguageCode;

class FieldTranslationHooks implements HookRegistrarInterface
{

    public function __construct(private FieldTranslator $translator)
    {
    }

    public function register(): void
    {
        // Trigger field copying after a translation is created
        add_action('idiomatticwp_after_create_translation', [$this, 'onAfterCreateTranslation'], 10, 4);
    }

    /**
     * Copy fields from source to target post after duplicate is created.
     */
    public function onAfterCreateTranslation(int $translationId, int $sourcePostId, int $targetPostId, LanguageCode $targetLang): void
    {
        $this->translator->copyFieldsFromSource($sourcePostId, $targetPostId, $targetLang);
    }
}
