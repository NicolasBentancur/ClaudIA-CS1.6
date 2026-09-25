<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

/**
 * Generador aleatorio de los slots. En producción usa random_int (CSPRNG); el simulador
 * y los tests usan una semilla (mt_rand) para ser reproducibles y rápidos.
 */
class Rng
{
    private const SCALE = 1 << 30;

    public function __construct(private readonly ?int $seed = null)
    {
        if ($seed !== null) {
            mt_srand($seed);
        }
    }

    /** Número en [0, 1). */
    public function float(): float
    {
        $n = $this->seed === null ? random_int(0, self::SCALE - 1) : mt_rand(0, self::SCALE - 1);
        return $n / self::SCALE;
    }

    public function int(int $min, int $max): int
    {
        return $this->seed === null ? random_int($min, $max) : mt_rand($min, $max);
    }

    public function chance(float $p): bool
    {
        return $p > 0 && $this->float() < $p;
    }

    /**
     * Elige una clave según pesos.
     * @param array<string|int, float|int> $weights
     */
    public function pick(array $weights): string|int
    {
        $total = array_sum($weights);
        $r = $this->float() * $total;
        foreach ($weights as $key => $w) {
            $r -= $w;
            if ($r < 0) {
                return $key;
            }
        }
        return array_key_last($weights);
    }
}
