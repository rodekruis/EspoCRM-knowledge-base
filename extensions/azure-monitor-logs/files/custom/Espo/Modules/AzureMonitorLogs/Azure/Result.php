<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Azure;

/**
 * Outcome of an ingestion attempt.
 */
final class Result
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $wasSkipped,
        public readonly int $status,
        public readonly ?string $message,
        public readonly ?int $retryAfter = null,
    ) {}

    public static function success(int $status, ?string $note = null): self
    {
        return new self(true, false, $status, $note);
    }

    public static function failure(int $status, string $message, ?int $retryAfter = null): self
    {
        return new self(false, false, $status, $message, $retryAfter);
    }

    public static function skipped(string $message): self
    {
        return new self(false, true, 0, $message);
    }

    public function describe(): string
    {
        if ($this->wasSkipped) {
            return 'skipped: ' . $this->message;
        }

        if ($this->ok) {
            return "ok (HTTP {$this->status})" . ($this->message === null ? '' : " - {$this->message}");
        }

        return "failed (HTTP {$this->status}): {$this->message}";
    }
}
