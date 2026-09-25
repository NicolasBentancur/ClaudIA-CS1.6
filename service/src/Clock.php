<?php

declare(strict_types=1);

namespace Claudia;

/**
 * Reloj central del servicio. Todo el código usa Clock::now() en lugar de time()
 * para poder simular el paso del tiempo (tests, pruebas de préstamos o de cobro de sueldo).
 */
final class Clock
{
    private static int $offset = 0;
    private static ?int $frozen = null;

    public static function now(): int
    {
        return (self::$frozen ?? time()) + self::$offset;
    }

    public static function micro(): float
    {
        return (self::$frozen !== null ? (float) self::$frozen : microtime(true)) + self::$offset;
    }

    public static function setOffset(int $seconds): void
    {
        self::$offset = $seconds;
    }

    public static function offset(): int
    {
        return self::$offset;
    }

    public static function advance(int $seconds): void
    {
        self::$offset += $seconds;
    }

    /** Congela el reloj (solo tests). null lo libera. */
    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }
}
