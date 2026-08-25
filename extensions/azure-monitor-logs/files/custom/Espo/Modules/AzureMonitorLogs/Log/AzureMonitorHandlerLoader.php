<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Log;

use Espo\Core\InjectableFactory;
use Espo\Core\Log\HandlerLoader;
use Espo\Modules\AzureMonitorLogs\Azure\IngestionClient;
use Espo\Modules\AzureMonitorLogs\Azure\Settings;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\Logger;
use Throwable;

/**
 * Registered from `logger.handlerList` in config.
 */
final class AzureMonitorHandlerLoader implements HandlerLoader
{
    public function __construct(
        private readonly Settings $settings,
        private readonly InjectableFactory $injectableFactory,
    ) {}

    public function load(array $params): HandlerInterface
    {
        try {
            return $this->createHandler($params);
        } catch (Throwable $e) {
            error_log('[azure-monitor-logs] Handler could not be created: ' . $e->getMessage());

            return new NullHandler();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function createHandler(array $params): HandlerInterface
    {
        if (!$this->settings->isUsable()) {
            return new NullHandler();
        }

        $level = Logger::toMonologLevel(
            $this->settings->level ?? $params['level'] ?? Level::Warning->value
        );

        $handler = new AzureMonitorHandler(
            $this->injectableFactory->create(IngestionClient::class),
            $this->settings,
            new ContextRedactor(
                $this->settings->maxPropertyLength,
                // Leave room for the properties the handler sets itself.
                max(1, $this->settings->maxPropertyCount - count(AzureMonitorHandler::RESERVED_PROPERTIES)),
            ),
            $level,
        );

        $buffer = new BufferHandler(
            handler: $handler,
            bufferLimit: $this->settings->bufferLimit,
            level: $level,
            bubble: true,
            flushOnOverflow: true,
        );

        // BufferHandler flushes in its destructor; this makes the flush order deterministic.
        register_shutdown_function(static function () use ($buffer): void {
            try {
                $buffer->close();
            } catch (Throwable $e) {
                error_log('[azure-monitor-logs] Shutdown flush failed: ' . $e->getMessage());
            }
        });

        return $buffer;
    }
}
