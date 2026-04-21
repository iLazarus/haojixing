<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class ColorLineFormatter extends LineFormatter
{
    /** @var array<string, string> */
    private array $levelColorMap = [
        'DEBUG' => "\033[90m",
        'INFO' => "\033[32m",
        'NOTICE' => "\033[36m",
        'WARNING' => "\033[33m",
        'ERROR' => "\033[31m",
        'CRITICAL' => "\033[35m",
        'ALERT' => "\033[41;97m",
        'EMERGENCY' => "\033[41;97m",
    ];

    private string $reset = "\033[0m";

    public function __construct()
    {
        parent::__construct(
            "[%datetime%] %level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
    }

    public function format(LogRecord $record): string
    {
        $output = parent::format($record);
        $level = strtoupper($record->level->getName());
        $color = $this->levelColorMap[$level] ?? '';

        if ($color === '') {
            return $output;
        }

        return $color . $output . $this->reset;
    }
}
