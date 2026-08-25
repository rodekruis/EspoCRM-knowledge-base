<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Log;

use DateTimeInterface;
use Throwable;

/**
 * Flattens Monolog context into Application Insights customDimensions,
 * stripping credentials on the way out.
 */
final class ContextRedactor
{
    private const REDACTED = '***';

    private const SENSITIVE_KEY_PATTERN =
        '/(pass|secret|token|apikey|api_key|authorization|auth|credential|privatekey|hash|salt|cookie|session)/i';

    private const MAX_KEY_LENGTH = 150;

    public function __construct(
        private readonly int $maxValueLength = 8192,
        private readonly int $maxCount = 50,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public function toProperties(array $context): array
    {
        $properties = [];

        foreach ($context as $key => $value) {
            // Reserve the last slot for the truncation marker.
            if (count($properties) >= $this->maxCount - 1) {
                $properties['_truncated'] = 'true';

                break;
            }

            $name = substr(preg_replace('/[^A-Za-z0-9_.\-]/', '_', (string) $key) ?? '', 0, self::MAX_KEY_LENGTH);

            if ($name === '') {
                continue;
            }

            $properties[$name] = preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1
                ? self::REDACTED
                : $this->stringify($value);
        }

        return $properties;
    }

    private function stringify(mixed $value): string
    {
        try {
            $string = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof Throwable => get_class($value) . ': ' . $value->getMessage()
                    . ' @ ' . $value->getFile() . ':' . $value->getLine(),
                is_array($value) || $value instanceof \JsonSerializable || $value instanceof \stdClass =>
                    (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                is_object($value) => '[object ' . get_class($value) . ']',
                default => '[' . gettype($value) . ']',
            };
        } catch (Throwable) {
            return '[unserializable]';
        }

        return strlen($string) > $this->maxValueLength
            ? substr($string, 0, $this->maxValueLength) . '...'
            : $string;
    }
}
