<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Console;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\AzureMonitorLogs\Azure\CircuitBreaker;
use Espo\Modules\AzureMonitorLogs\Azure\IngestionClient;
use Espo\Modules\AzureMonitorLogs\Azure\Settings;
use Espo\Modules\AzureMonitorLogs\Log\AzureMonitorHandler;

/**
 * `php command.php azure-monitor-logs-test`
 *
 * Two stages: a direct POST that proves connectivity, then a real log record that
 * proves the handler is actually wired into `logger.handlerList`.
 */
final class TestCommand implements Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly IngestionClient $client,
        private readonly CircuitBreaker $breaker,
        private readonly Config $config,
        private readonly Log $log,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $io->writeLine('Configuration');
        $io->writeLine('  enabled:       ' . ($this->settings->enabled ? 'true' : 'false'));
        $io->writeLine('  endpoint:      ' . ($this->settings->ingestionEndpoint ?? '<not set or rejected>'));
        $io->writeLine('  iKey:          ' . self::maskKey($this->settings->instrumentationKey));
        $io->writeLine('  roleName:      ' . $this->settings->roleName);
        $io->writeLine('  roleInstance:  ' . $this->settings->roleInstance);
        $io->writeLine('  logger.level:  ' . ($this->config->get('logger.level') ?? '<default>'));
        $io->writeLine('  logger.sql:    ' . ($this->config->get('logger.sql')
            ? 'TRUE - do not use in production'
            : 'false'));
        $io->writeLine('  breaker:       ' . ($this->breaker->isOpen()
            ? 'OPEN for ' . $this->breaker->secondsRemaining() . 's'
            : 'closed'));
        $io->writeLine('');

        $reason = $this->settings->getUnusableReason();

        if ($reason !== null) {
            $io->writeLine('Not usable: ' . $reason);
            $io->setExitStatus(1);

            return;
        }

        $marker = 'AZMON-SMOKE-' . bin2hex(random_bytes(6));
        $level = $this->getEffectiveLevel();

        $io->writeLine('Stage 1: direct POST to ' . $this->settings->getTrackUrl());

        $result = $this->client->send([[
            'name' => $this->settings->getEnvelopeName('Message'),
            'time' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'iKey' => $this->settings->instrumentationKey,
            'tags' => [
                'ai.cloud.role' => $this->settings->roleName,
                'ai.cloud.roleInstance' => $this->settings->roleInstance,
                'ai.internal.sdkVersion' => AzureMonitorHandler::SDK_VERSION,
            ],
            'data' => [
                'baseType' => 'MessageData',
                'baseData' => [
                    'ver' => 2,
                    'message' => $marker . '-DIRECT',
                    'severityLevel' => 2,
                    'properties' => ['smokeTest' => 'true'],
                ],
            ],
        ]]);

        $io->writeLine('  ' . $result->describe());

        if (!$result->ok) {
            $io->setExitStatus(1);

            return;
        }

        $io->writeLine('');
        $io->writeLine("Stage 2: emitting a real $level record through the Espo logger");
        $this->log->log(strtolower($level), $marker . '-LOGGER', ['smokeTest' => 'true']);
        $io->writeLine('  queued; the buffer flushes when this process exits');

        $io->writeLine('');
        $io->writeLine('Verify in Application Insights (allow a few minutes):');
        $io->writeLine('  traces | where message startswith "' . $marker . '"');
        $io->writeLine('');
        $io->writeLine('Expect BOTH -DIRECT and -LOGGER. If only -DIRECT arrives, the handler is');
        $io->writeLine('not registered in logger.handlerList, or logger.level excludes ' . $level . '.');
    }

    /**
     * The level the Azure handler actually filters on.
     */
    private function getEffectiveLevel(): string
    {
        $level = $this->settings->level ?? $this->config->get('logger.level') ?? 'WARNING';

        return is_string($level) ? strtoupper($level) : 'WARNING';
    }

    private static function maskKey(?string $key): string
    {
        if ($key === null) {
            return '<not set>';
        }

        return strlen($key) <= 8 ? '***' : substr($key, 0, 8) . '...';
    }
}
