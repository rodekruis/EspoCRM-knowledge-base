<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * Restores the logger config that was in place before installation.
 *
 * The `azureMonitorLogs` block is left alone: it holds the operator's connection
 * string and is inert once the handler classes are gone.
 */
class AfterUninstall
{
    private const AZURE_LOADER = 'Espo\\Modules\\AzureMonitorLogs\\Log\\AzureMonitorHandlerLoader';
    private const FILE_LOADER = 'Espo\\Modules\\AzureMonitorLogs\\Log\\RotatingFileHandlerLoader';

    private const BACKUP_KEY = 'azureMonitorLogsPreviousLogger';

    public function run(Container $container): void
    {
        $config = $container->getByClass(Config::class);
        $configWriter = $container->getByClass(InjectableFactory::class)->create(ConfigWriter::class);

        $logger = $config->get('logger');
        $logger = is_object($logger) ? (array) $logger : $logger;
        $logger = is_array($logger) ? $logger : [];

        $previous = $config->get(self::BACKUP_KEY);

        if (is_array($previous) && $previous !== []) {
            $logger['handlerList'] = $previous;
        } else {
            // No handlerList before us: dropping the key restores Espo's default handler.
            unset($logger['handlerList']);
        }

        if (isset($logger['handlerList'])) {
            $logger['handlerList'] = array_values(array_filter(
                $logger['handlerList'],
                static function ($item): bool {
                    $item = is_object($item) ? (array) $item : $item;

                    if (!is_array($item)) {
                        return true;
                    }

                    $loader = $item['loaderClassName'] ?? null;

                    return $loader !== self::AZURE_LOADER && $loader !== self::FILE_LOADER;
                }
            ));

            if ($logger['handlerList'] === []) {
                unset($logger['handlerList']);
            }
        }

        $configWriter->set('logger', $logger);
        $configWriter->remove(self::BACKUP_KEY);
        $configWriter->save();
    }
}
