<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Log;

use DateTimeZone;
use Espo\Modules\AzureMonitorLogs\Azure\IngestionClient;
use Espo\Modules\AzureMonitorLogs\Azure\Result;
use Espo\Modules\AzureMonitorLogs\Azure\Settings;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Throwable;

/**
 * Turns Monolog records into Application Insights MessageData envelopes.
 *
 * Normally wrapped in a BufferHandler, so handleBatch() is the hot path and
 * write() only fires for stray unbuffered records.
 */
final class AzureMonitorHandler extends AbstractProcessingHandler
{
    /** Guards against a failure inside the send path being logged back into us. */
    private static bool $isSending = false;

    private static int $reportedErrorCount = 0;

    private const MAX_REPORTED_ERRORS = 3;

    /** Keep in step with manifest.json on release. */
    public const SDK_VERSION = 'php:espocrm-azure-monitor-logs:0.4.1';

    /** Set by the handler itself; a context key of the same name is overwritten. */
    public const RESERVED_PROPERTIES = ['channel', 'level', 'source', 'processId'];

    /**
     * Espo interpolates `{placeholder}` only in its own file formatter and database
     * handler, so records arrive here still carrying the raw message template.
     */
    private readonly PsrLogMessageProcessor $interpolator;

    public function __construct(
        private readonly IngestionClient $client,
        private readonly Settings $settings,
        private readonly ContextRedactor $redactor,
        Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        // Context is left intact, so the values also stay in customDimensions.
        $this->interpolator = new PsrLogMessageProcessor();
    }

    protected function write(LogRecord $record): void
    {
        $this->handleBatch([$record]);
    }

    /**
     * @param array<int, LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        if (self::$isSending) {
            return;
        }

        self::$isSending = true;

        try {
            $envelopes = [];

            foreach ($records as $record) {
                if (!$this->isHandling($record)) {
                    continue;
                }

                if ($this->settings->excludeSql && ($record->context['isSql'] ?? false)) {
                    continue;
                }

                $envelopes[] = $this->toEnvelope($record);
            }

            foreach ($this->chunk($envelopes) as $batch) {
                $this->report($this->client->send($batch));
            }
        } catch (Throwable $e) {
            $this->reportError('Unexpected failure: ' . $e->getMessage());
        } finally {
            self::$isSending = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toEnvelope(LogRecord $record): array
    {
        $message = ($this->interpolator)($record)->message;

        if (strlen($message) > $this->settings->maxMessageLength) {
            $message = substr($message, 0, $this->settings->maxMessageLength) . '...';
        }

        $properties = $this->redactor->toProperties($record->context);

        foreach (self::RESERVED_PROPERTIES as $name) {
            unset($properties[$name]);
        }

        $properties['channel'] = $record->channel;
        $properties['level'] = $record->level->getName();
        $properties['source'] = PHP_SAPI === 'cli' ? 'cli' : 'web';
        $properties['processId'] = (string) (getmypid() ?: 0);

        return [
            'name' => $this->settings->getEnvelopeName('Message'),
            'time' => $record->datetime
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
            'iKey' => $this->settings->instrumentationKey,
            'tags' => [
                'ai.cloud.role' => $this->settings->roleName,
                'ai.cloud.roleInstance' => $this->settings->roleInstance,
                'ai.internal.sdkVersion' => self::SDK_VERSION,
            ],
            'data' => [
                'baseType' => 'MessageData',
                'baseData' => [
                    'ver' => 2,
                    'message' => $message,
                    'severityLevel' => self::toSeverityLevel($record->level),
                    'properties' => $properties,
                ],
            ],
        ];
    }

    /**
     * Monolog level to the Application Insights SeverityLevel enum.
     */
    private static function toSeverityLevel(Level $level): int
    {
        return match (true) {
            $level->value <= Level::Debug->value => 0,
            $level->value <= Level::Notice->value => 1,
            $level->value <= Level::Warning->value => 2,
            $level->value <= Level::Error->value => 3,
            default => 4,
        };
    }

    /**
     * Split into batches that stay under the byte ceiling. The record count is
     * already bounded by the BufferHandler's bufferLimit.
     *
     * @param array<int, array<string, mixed>> $envelopes
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function chunk(array $envelopes): array
    {
        $batches = [];
        $current = [];
        $currentBytes = 2;

        foreach ($envelopes as $envelope) {
            $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $size = ($encoded === false ? 0 : strlen($encoded)) + 1;

            if ($current !== [] && $currentBytes + $size > $this->settings->maxBatchBytes) {
                $batches[] = $current;
                $current = [];
                $currentBytes = 2;
            }

            $current[] = $envelope;
            $currentBytes += $size;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    private function report(Result $result): void
    {
        if ($result->wasSkipped) {
            return;
        }

        if (!$result->ok) {
            $this->reportError($result->describe());

            return;
        }

        // A 2xx can still carry per-item rejections; do not let those pass silently.
        if ($result->message !== null) {
            $this->reportError($result->describe());
        }
    }

    private function reportError(string $message): void
    {
        if (self::$reportedErrorCount >= self::MAX_REPORTED_ERRORS) {
            return;
        }

        self::$reportedErrorCount++;

        // Deliberately error_log, not the Espo logger, to avoid recursion.
        error_log('[azure-monitor-logs] ' . $message);
    }
}
