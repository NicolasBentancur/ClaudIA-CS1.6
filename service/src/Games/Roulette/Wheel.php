<?php

declare(strict_types=1);

namespace Claudia\Games\Roulette;

/**
 * Ruleta francesa (un solo cero).
 */
final class Wheel
{
    /** Orden de los casilleros en el cilindro, en sentido horario empezando por el 0. */
    public const ORDER = [0, 32, 15, 19, 4, 21, 2, 25, 17, 34, 6, 27, 13, 36, 11, 30, 8, 23, 10, 5, 24, 16, 33, 1, 20, 14, 31, 9, 22, 18, 29, 7, 28, 12, 35, 3, 26];

    public const RED = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

    public const POCKETS = 37;

    public static function color(int $n): string
    {
        if ($n === 0) {
            return 'verde';
        }
        return in_array($n, self::RED, true) ? 'rojo' : 'negro';
    }

    /** Índice del casillero (posición en el cilindro) de un número. */
    public static function indexOf(int $n): int
    {
        $i = array_search($n, self::ORDER, true);
        if ($i === false) {
            throw new \InvalidArgumentException("Número inválido: {$n}");
        }
        return (int) $i;
    }

    public static function numberAt(int $index): int
    {
        return self::ORDER[(($index % self::POCKETS) + self::POCKETS) % self::POCKETS];
    }

    /**
     * Vecinos en el cilindro: el número y $k a cada lado.
     * @return list<int>
     */
    public static function neighbours(int $n, int $k = 2): array
    {
        $i = self::indexOf($n);
        $out = [];
        for ($d = -$k; $d <= $k; $d++) {
            $out[] = self::numberAt($i + $d);
        }
        return $out;
    }

    /** Número ganador sorteado con CSPRNG. */
    public static function draw(): int
    {
        return random_int(0, 36);
    }
}
