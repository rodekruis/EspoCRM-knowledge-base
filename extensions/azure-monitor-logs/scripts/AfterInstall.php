<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * Wires the Azure handler into `logger.handlerList`.
 *
 * Declaring handlerList replaces Espo's default file handler, so the file handler
 * has to be re-declared explicitly here. The database handler (App Log) is appended
 * by LogLoader independently and needs no entry.
 */
class AfterInstall
{
    private const AZURE_LOADER = 'Espo\\Modules\\AzureMonitorLogs\\Log\\AzureMonitorHandlerLoader';
    private const FILE_LOADER = 'Espo\\Modules\\AzureMonitorLogs\\Log\\RotatingFileHandlerLoader';

    /** Kept separate from user config so a pre-existing settings block cannot hide it. */
    private const BACKUP_KEY = 'azureMonitorLogsPreviousLogger';

    public function run(Container $container): void
    {
        $config = $container->getByClass(Config::class);
        $configWriter = $container->getByClass(InjectableFactory::class)->create(ConfigWriter::class);

        $logger = $config->get('logger');
        $logger = is_object($logger) ? (array) $logger : $logger;
        $logger = is_array($logger) ? $logger : [];

        $handlerList = $logger['handlerList'] ?? null;
        $handlerList = is_array($handlerList) ? $handlerList : null;

        if ($handlerList !== null && $this->hasAzureHandler($handlerList)) {
            return;
        }

        // Only the first install records the backup; a reinstall must not overwrite it.
        if (!$config->has(self::BACKUP_KEY)) {
            $configWriter->set(self::BACKUP_KEY, $handlerList);
        }

        if ($handlerList === null) {
            $handlerList = [[
                'loaderClassName' => self::FILE_LOADER,
                'params' => [
                    'filename' => $logger['path'] ?? 'data/logs/espo.log',
                    'rotation' => $logger['rotation'] ?? true,
                    'maxFileNumber' => $logger['maxFileNumber'] ?? 30,
                ],
            ]];
        }

        $handlerList[] = ['loaderClassName' => self::AZURE_LOADER];

        $logger['handlerList'] = $handlerList;

        $configWriter->set('logger', $logger);
        $configWriter->save();
    }

    /**
     * @param array<int, mixed> $handlerList
     */
    private function hasAzureHandler(array $handlerList): bool
    {
        foreach ($handlerList as $item) {
            $item = is_object($item) ? (array) $item : $item;

            if (!is_array($item)) {
                continue;
            }

            if (($item['loaderClassName'] ?? null) === self::AZURE_LOADER) {
                return true;
            }
        }

        return false;
    }
}
