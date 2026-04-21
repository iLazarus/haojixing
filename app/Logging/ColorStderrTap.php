<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;

class ColorStderrTap
{
    public function __invoke(Logger $logger): void
    {
        $formatter = new ColorLineFormatter();

        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter($formatter);
            }
        }
    }
}
