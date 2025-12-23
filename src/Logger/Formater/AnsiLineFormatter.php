<?php

namespace On1kel\HyperfLighty\Logger\Formater;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

final class AnsiLineFormatter extends LineFormatter
{
    private const RESET = "\033[0m";

    private const COLORS = [
        'DEBUG'     => "\033[37m", // gray
        'INFO'      => "\033[32m", // green
        'NOTICE'    => "\033[36m", // cyan
        'WARNING'   => "\033[33m", // yellow
        'ERROR'     => "\033[31m", // red
        'CRITICAL'  => "\033[31m",
        'ALERT'     => "\033[31m",
        'EMERGENCY' => "\033[31m",
    ];

    public function format(LogRecord $record): string
    {
        $level = $record->level->getName();
        $color = self::COLORS[$level] ?? '';

        // Формируем строку с цветом вокруг %level_name%
        $output = parent::format($record);

        if ($color !== '') {
            $output = str_replace(
                $level,
                $color . $level . self::RESET,
                $output
            );
        }

        return $output;
    }
}