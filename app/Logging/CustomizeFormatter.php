<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\FormattableHandlerInterface;

class CustomizeFormatter
{
    /**
     * Customize the given logger instance.
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $formatter = $handler->getFormatter();
                if ($formatter instanceof NormalizerFormatter) {
                    $formatter->setMaxNormalizeDepth(50);
                }
            }
        }
    }
}
