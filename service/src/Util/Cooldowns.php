<?php

declare(strict_types=1);

namespace Claudia\Util;

use Claudia\Clock;

/**
 * Tiempos de espera en memoria para comandos (ej. "beso:12", "cupido").
 */
final class Cooldowns
{
    /** @var array<string, int> clave => momento en que se liberó por última vez */
    private array $until = [];

    /** Segundos que faltan para poder usar $key (0 = libre). */
    public function left(string $key): int
    {
        return max(0, ($this->until[$key] ?? 0) - Clock::now());
    }

    /** Si está libre lo toma por $seconds y devuelve 0; si no, devuelve los segundos que faltan. */
    public function take(string $key, int $seconds): int
    {
        $left = $this->left($key);
        if ($left === 0) {
            $this->until[$key] = Clock::now() + $seconds;
        }
        return $left;
    }
}
