<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class DeepNormalizerTap
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->getFormatter()->setMaxNormalizeDepth(20);
        }
    }
}
