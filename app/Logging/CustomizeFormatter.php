<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class CustomizeFormatter
{
    /**
     * Customize the given logger instance.
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof \Monolog\Handler\FormattableHandlerInterface) {
                $formatter = $handler->getFormatter();
                if ($formatter instanceof \Monolog\Formatter\NormalizerFormatter) {
                    $formatter->setMaxNormalizeDepth(50);
                }
            }
        }
    }
}
