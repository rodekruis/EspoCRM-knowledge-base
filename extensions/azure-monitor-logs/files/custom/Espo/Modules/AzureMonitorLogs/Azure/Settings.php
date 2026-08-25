<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Azure;

use Espo\Core\Utils\Config;

/**
 * Resolved `azureMonitorLogs` config block, including the parsed connection string.
 */
final class Settings
{
    public const CONFIG_KEY = 'azureMonitorLogs';

    private const TRACK_PATH = '/v2.1/track';

    /** Ingestion is anonymous, so still constrain where telemetry can be sent. */
    private const HOST_SUFFIXES = [
        '.applicationinsights.azure.com',
        '.applicationinsights.azure.cn',
        '.applicationinsights.us',
        '.services.visualstudio.com',
        '.monitor.azure.com',
    ];

    private const MIN_TIMEOUT = 0.5;
    private const MAX_TIMEOUT = 30.0;

    public readonly bool $enabled;
    public readonly ?string $instrumentationKey;
    public readonly ?string $ingestionEndpoint;
    public readonly ?string $configError;
    public readonly string $roleName;
    public readonly string $roleInstance;
    public readonly float $connectTimeout;
    public readonly float $timeout;
    public readonly int $bufferLimit;
    public readonly int $maxBatchBytes;
    public readonly int $maxMessageLength;
    public readonly int $maxPropertyLength;
    public readonly int $maxPropertyCount;
    public readonly bool $excludeSql;
    public readonly ?string $level;
    public readonly string $cachePath;
    public readonly int $breakerCooldown;
    public readonly int $breakerMaxCooldown;

    public function __construct(Config $config)
    {
        $raw = $config->get(self::CONFIG_KEY);
        $raw = is_object($raw) ? (array) $raw : $raw;
        $raw = is_array($raw) ? $raw : [];

        $this->enabled = (bool) ($raw['enabled'] ?? false);

        $parsed = self::parseConnectionString(self::trimOrNull($raw['connectionString'] ?? null));

        $this->instrumentationKey = $parsed['instrumentationKey'];
        $this->ingestionEndpoint = $parsed['ingestionEndpoint'];
        $this->configError = $parsed['error'];

        $this->roleName = self::trimOrNull($raw['roleName'] ?? null) ?? 'espocrm';
        $this->roleInstance = self::trimOrNull($raw['roleInstance'] ?? null)
            ?? (gethostname() ?: 'unknown');

        // A zero or negative cURL timeout means "wait forever"; never allow that.
        $this->connectTimeout = self::clampFloat($raw['connectTimeout'] ?? null, 2.0);
        $this->timeout = self::clampFloat($raw['timeout'] ?? null, 5.0);

        $this->bufferLimit = self::clampInt($raw['bufferLimit'] ?? null, 200, 1, 5000);
        $this->maxBatchBytes = self::clampInt($raw['maxBatchBytes'] ?? null, 262144, 1024, 1048576);
        $this->maxMessageLength = self::clampInt($raw['maxMessageLength'] ?? null, 10000, 256, 32768);
        $this->maxPropertyLength = self::clampInt($raw['maxPropertyLength'] ?? null, 8192, 64, 8192);
        $this->maxPropertyCount = self::clampInt($raw['maxPropertyCount'] ?? null, 50, 1, 200);
        $this->breakerCooldown = self::clampInt($raw['breakerCooldown'] ?? null, 60, 5, 3600);
        $this->breakerMaxCooldown = self::clampInt($raw['breakerMaxCooldown'] ?? null, 900, 5, 86400);

        $this->excludeSql = (bool) ($raw['excludeSql'] ?? true);
        $this->level = self::trimOrNull($raw['level'] ?? null);
        $this->cachePath = self::sanitiseCachePath($raw['cachePath'] ?? null);
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->instrumentationKey !== null && $this->ingestionEndpoint !== null;
    }

    public function getTrackUrl(): string
    {
        return rtrim((string) $this->ingestionEndpoint, '/') . self::TRACK_PATH;
    }

    /**
     * Envelope type name, e.g. Microsoft.ApplicationInsights.<ikey>.Message.
     */
    public function getEnvelopeName(string $type): string
    {
        $key = str_replace('-', '', (string) $this->instrumentationKey);

        return "Microsoft.ApplicationInsights.$key.$type";
    }

    /**
     * Reason the extension is inactive, for diagnostics. Null when usable.
     */
    public function getUnusableReason(): ?string
    {
        if (!$this->enabled) {
            return 'Disabled (' . self::CONFIG_KEY . '.enabled is false).';
        }

        return $this->configError;
    }

    /**
     * @return array{instrumentationKey: ?string, ingestionEndpoint: ?string, error: ?string}
     */
    private static function parseConnectionString(?string $connectionString): array
    {
        $none = ['instrumentationKey' => null, 'ingestionEndpoint' => null];

        if ($connectionString === null) {
            return $none + ['error' => 'Missing ' . self::CONFIG_KEY . '.connectionString.'];
        }

        if (strlen($connectionString) > 4096) {
            return $none + ['error' => 'connectionString exceeds the 4096 character limit.'];
        }

        $parts = [];

        foreach (explode(';', $connectionString) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $parts[strtolower(trim($key))] = trim($value);
        }

        $key = $parts['instrumentationkey'] ?? '';

        if ($key === '') {
            return $none + ['error' => 'connectionString has no InstrumentationKey.'];
        }

        if (preg_match('/^[A-Za-z0-9-]{1,100}$/', $key) !== 1) {
            return $none + ['error' => 'InstrumentationKey contains unexpected characters.'];
        }

        $endpoint = $parts['ingestionendpoint'] ?? null;

        if ($endpoint === null || $endpoint === '') {
            $suffix = $parts['endpointsuffix'] ?? null;
            $endpoint = $suffix ? 'https://dc.' . ltrim($suffix, '.') : 'https://dc.services.visualstudio.com';
        }

        $error = self::validateEndpoint($endpoint);

        if ($error !== null) {
            return $none + ['error' => $error];
        }

        return [
            'instrumentationKey' => $key,
            'ingestionEndpoint' => $endpoint,
            'error' => null,
        ];
    }

    private static function validateEndpoint(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'IngestionEndpoint is not a valid URL.';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'IngestionEndpoint must use https.';
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'IngestionEndpoint must not contain credentials, a query string or a fragment.';
        }

        $host = strtolower($parts['host']);

        foreach (self::HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return null;
            }
        }

        return "IngestionEndpoint host '$host' is not a known Application Insights host.";
    }

    /**
     * Keeps breaker state inside the app directory.
     */
    private static function sanitiseCachePath(mixed $value): string
    {
        $default = 'data/cache/azureMonitorLogs';

        if (is_string($value) && trim($value) === '') {
            return '';
        }

        $path = self::trimOrNull($value);

        if ($path === null) {
            return $default;
        }

        $normalised = str_replace('\\', '/', $path);

        $isAbsolute = str_starts_with($normalised, '/')
            || preg_match('~^[A-Za-z]:/~', $normalised) === 1;

        if ($isAbsolute || in_array('..', explode('/', $normalised), true)) {
            return $default;
        }

        return rtrim($normalised, '/');
    }

    private static function clampFloat(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return min(max((float) $value, self::MIN_TIMEOUT), self::MAX_TIMEOUT);
    }

    private static function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return min(max((int) $value, $min), $max);
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
