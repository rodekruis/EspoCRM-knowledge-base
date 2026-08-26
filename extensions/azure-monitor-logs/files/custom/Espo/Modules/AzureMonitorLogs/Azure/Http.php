<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Azure;

/**
 * Thin cURL wrapper.
 *
 * Not Espo\Core\HttpClient: that exists only since Espo 10.0, while this extension
 * supports 8.1+. It also throws on connect errors, which a log handler must not do.
 */
final class Http
{
    public const SCHEME_HTTPS = 'https';
    public const SCHEME_HTTP = 'http';

    /**
     * @param string[] $headers
     * @param self::SCHEME_* $scheme The only protocol cURL may speak for this call.
     * @return array{status: int, body: string, error: ?string, headers: array<string, string>}
     */
    public static function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $connectTimeout,
        float $timeout,
        string $scheme = self::SCHEME_HTTPS
    ): array {
        if (!str_starts_with($url, $scheme . '://')) {
            return ['status' => 0, 'body' => '', 'error' => "URL is not $scheme.", 'headers' => []];
        }

        $ch = curl_init();

        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed', 'headers' => []];
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        self::restrictProtocol($ch, $scheme);

        // Azure Monitor endpoints require TLS 1.2+ as of 2026-03-01.
        if ($scheme === self::SCHEME_HTTPS && defined('CURL_SSLVERSION_TLSv1_2')) {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;

        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
            'error' => $error,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Without this, a config-supplied URL could reach file://, gopher://, dict://, ...
     */
    private static function restrictProtocol(\CurlHandle $ch, string $scheme): void
    {
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, $scheme);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, $scheme);

            return;
        }

        $mask = $scheme === self::SCHEME_HTTPS ? CURLPROTO_HTTPS : CURLPROTO_HTTP;

        curl_setopt($ch, CURLOPT_PROTOCOLS, $mask);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $mask);
    }
}
