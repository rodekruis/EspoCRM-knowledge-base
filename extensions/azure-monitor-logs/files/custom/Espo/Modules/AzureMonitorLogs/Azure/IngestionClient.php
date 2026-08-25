<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Azure;

use Throwable;

/**
 * Posts telemetry envelopes to the Application Insights track endpoint.
 *
 * Never throws: log shipping must not be able to break a request.
 */
final class IngestionClient
{
    /** Compress payloads above this size. */
    private const GZIP_THRESHOLD = 8192;

    public function __construct(
        private readonly Settings $settings,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $envelopes
     */
    public function send(array $envelopes): Result
    {
        if ($envelopes === []) {
            return Result::skipped('No envelopes to send.');
        }

        $reason = $this->settings->getUnusableReason();

        if ($reason !== null) {
            return Result::skipped($reason);
        }

        if ($this->breaker->isOpen()) {
            return Result::skipped(
                'Circuit breaker open for another ' . $this->breaker->secondsRemaining() . 's.'
            );
        }

        $body = json_encode($envelopes, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            return Result::failure(0, 'Payload could not be JSON-encoded.');
        }

        try {
            $result = $this->post($body);
        } catch (Throwable $e) {
            $result = Result::failure(0, 'Send failed: ' . $e->getMessage());
        }

        if ($result->ok) {
            $this->breaker->recordSuccess();
        } else {
            $this->breaker->recordFailure($result->retryAfter);
        }

        return $result;
    }

    private function post(string $body): Result
    {
        $headers = ['Content-Type: application/json'];
        $payload = $body;

        if (strlen($body) > self::GZIP_THRESHOLD && function_exists('gzencode')) {
            $compressed = @gzencode($body, 6);

            if (is_string($compressed)) {
                $payload = $compressed;
                $headers[] = 'Content-Encoding: gzip';
            }
        }

        $response = Http::request(
            'POST',
            $this->settings->getTrackUrl(),
            $headers,
            $payload,
            $this->settings->connectTimeout,
            $this->settings->timeout,
            Http::SCHEME_HTTPS
        );

        if ($response['error'] !== null) {
            return Result::failure(0, $response['error']);
        }

        $status = $response['status'];

        // 206 means some items were rejected; the transport itself is healthy.
        if ($status === 200 || $status === 206) {
            $rejected = self::countRejected($response['body']);

            return Result::success($status, $rejected > 0 ? "$rejected item(s) rejected" : null);
        }

        return Result::failure(
            $status,
            self::explain($status, $response['body']),
            self::parseRetryAfter($response['headers'])
        );
    }

    private static function countRejected(string $body): int
    {
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return 0;
        }

        return max(0, (int) ($data['itemsReceived'] ?? 0) - (int) ($data['itemsAccepted'] ?? 0));
    }

    private static function explain(int $status, string $body): string
    {
        $hint = match ($status) {
            400 => 'Malformed envelope.',
            403 => 'Forbidden - local authentication may be disabled on the resource.',
            402, 439 => 'Daily quota exceeded for the Application Insights resource.',
            429 => 'Throttled.',
            default => '',
        };

        $body = trim($body);
        $body = $body === '' ? 'No response body.' : substr($body, 0, 500);

        return trim("$hint $body");
    }

    /**
     * @param array<string, string> $headers
     */
    private static function parseRetryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'] ?? null;

        return is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }
}
