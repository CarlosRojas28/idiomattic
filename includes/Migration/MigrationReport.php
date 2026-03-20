<?php
/**
 * MigrationReport — stores migration results.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Migration;

class MigrationReport
{
    public int $translationsFound = 0;
    public int $translationsMigrated = 0;
    public int $errors = 0;
    public array $errorMessages = [];
    public string $startedAt = '';
    public string $completedAt = '';

    public function addError(string $message): void
    {
        $this->errors++;
        $this->errorMessages[] = $message;
    }
}
