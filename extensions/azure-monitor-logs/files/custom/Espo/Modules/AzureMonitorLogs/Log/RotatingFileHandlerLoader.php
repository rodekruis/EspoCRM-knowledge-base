<?php

declare(strict_types=1);

namespace Espo\Modules\AzureMonitorLogs\Log;

use Espo\Core\Log\DefaultFormatter;
use Espo\Core\Log\Handler\EspoFileHandler;
use Espo\Core\Log\Handler\EspoRotatingFileHandler;
use Espo\Core\Log\HandlerLoader;
use Espo\Core\Utils\Config;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\Logger;

/**
 * Drop-in replacement for Espo\Core\Log\EspoRotatingFileHandlerLoader.
 *
 * Espo's own loader hardcodes maxFiles = 0 (keep every rotated file), so declaring
 * `logger.handlerList` with it would silently disable log pruning. This one honours
 * `logger.rotation`, `logger.maxFileNumber` and `logger.printTrace`, reproducing the
 * default handler that `handlerList` otherwise replaces.
 */
final class RotatingFileHandlerLoader implements HandlerLoader
{
    private const DEFAULT_PATH = 'data/logs/espo.log';
    private const DEFAULT_MAX_FILE_NUMBER = 30;

    public function __construct(
        private readonly Config $config,
    ) {}

    public function load(array $params): HandlerInterface
    {
        $filename = $params['filename']
            ?? $this->config->get('logger.path')
            ?? self::DEFAULT_PATH;

        $level = Logger::toMonologLevel(
            $params['level'] ?? $this->config->get('logger.level') ?? Level::Warning->value
        );

        $rotation = $params['rotation'] ?? $this->config->get('logger.rotation') ?? true;

        if ($rotation) {
            $maxFileNumber = (int) ($params['maxFileNumber']
                ?? $this->config->get('logger.maxFileNumber')
                ?? self::DEFAULT_MAX_FILE_NUMBER);

            $handler = new EspoRotatingFileHandler($this->config, $filename, $maxFileNumber, $level, true);
        } else {
            $handler = new EspoFileHandler($this->config, $filename, $level, true);
        }

        $handler->setFormatter(new DefaultFormatter((bool) $this->config->get('logger.printTrace')));

        return $handler;
    }
}
