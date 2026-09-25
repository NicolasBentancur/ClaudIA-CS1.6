<?php

declare(strict_types=1);

namespace Claudia;

/**
 * Logger mínimo a archivo + stdout. Nunca registra contraseñas ni API keys:
 * quien llama es responsable de no pasarlas en el mensaje.
 */
final class Log
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static ?string $file = null;
    private static int $minLevel = 1;
    private static bool $echo = true;

    public static function configure(?string $file, string $level = 'info', bool $echo = true): void
    {
        self::$file = $file;
        self::$minLevel = self::LEVELS[$level] ?? 1;
        self::$echo = $echo;
        if ($file !== null && !is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::write('debug', $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('info', $msg, $ctx);
    }

    public static function warning(string $msg, array $ctx = []): void
    {
        self::write('warning', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('error', $msg, $ctx);
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        if (self::LEVELS[$level] < self::$minLevel) {
            return;
        }
        $line = sprintf(
            "[%s] %-7s %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        if (self::$file !== null) {
            @file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
        }
        if (self::$echo) {
            echo $line;
        }
    }
}
