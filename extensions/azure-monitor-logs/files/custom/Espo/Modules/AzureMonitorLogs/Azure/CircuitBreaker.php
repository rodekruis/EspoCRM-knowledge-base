<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Azure;

use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Util;
use Throwable;

/**
 * Stops calling Azure after a failure so that an outage cannot add request latency
 * on every subsequent request. State is shared across PHP-FPM workers via a file.
 */
final class CircuitBreaker
{
    private const FILENAME = 'breaker.json';

    private ?int $openUntil = null;
    private bool $loaded = false;

    public function __construct(
        private readonly Settings $settings,
        // Applies defaultPermissions and chowns, so state stays usable whether cron
        // runs as root or as the web user.
        private readonly FileManager $fileManager,
    ) {}

    public function isOpen(): bool
    {
        $this->load();

        return $this->openUntil !== null && $this->openUntil > time();
    }

    public function secondsRemaining(): int
    {
        $this->load();

        return $this->openUntil === null ? 0 : max(0, $this->openUntil - time());
    }

    public function recordSuccess(): void
    {
        $this->openUntil = null;
        $this->loaded = true;

        $path = $this->getPath();

        if ($path === null || !$this->fileManager->isFile($path)) {
            return;
        }

        try {
            $this->fileManager->removeFile($path);
        } catch (Throwable) {
            // Best-effort.
        }
    }

    /**
     * Cooldown doubles per consecutive failure, capped by breakerMaxCooldown.
     */
    public function recordFailure(?int $retryAfter = null): void
    {
        $this->load();

        $streak = $this->readStreak() + 1;

        $cooldown = $retryAfter !== null && $retryAfter > 0
            ? $retryAfter
            : $this->settings->breakerCooldown * (2 ** min($streak - 1, 10));

        $cooldown = min($cooldown, $this->settings->breakerMaxCooldown);

        $this->openUntil = time() + $cooldown;

        $this->write(['openUntil' => $this->openUntil, 'streak' => $streak]);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $data = $this->read();
        $openUntil = $data['openUntil'] ?? null;

        $this->openUntil = is_int($openUntil) ? $openUntil : null;
    }

    private function readStreak(): int
    {
        $data = $this->read();
        $streak = $data['streak'] ?? 0;

        return is_int($streak) && $streak > 0 ? $streak : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->getPath();

        if ($path === null || !$this->fileManager->isFile($path) || is_link($path)) {
            return [];
        }

        try {
            $data = json_decode($this->fileManager->getContents($path), true);

            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $path = $this->getPath();

        if ($path === null) {
            return;
        }

        try {
            $payload = json_encode($data);

            if ($payload === false) {
                return;
            }

            $this->fileManager->putContents($path, $payload, LOCK_EX);
        } catch (Throwable) {
            // Best-effort: losing breaker state only costs one extra failed attempt.
        }
    }

    private function getPath(): ?string
    {
        $base = $this->settings->cachePath;

        return $base === '' ? null : Util::concatPath($base, self::FILENAME);
    }
}
