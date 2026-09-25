<?php

declare(strict_types=1);

namespace Claudia\Tests\Support;

use Claudia\Games\Timers;

/**
 * Temporizadores manuales: se ejecutan en orden de tiempo con run().
 */
final class FakeTimers extends Timers
{
    private float $now = 0.0;
    private int $next = 1;

    /** @var array<int, array{at:float, cb:callable}> */
    private array $pending = [];

    public function __construct()
    {
        parent::__construct(
            fn (float $s, callable $cb): int => $this->schedule($s, $cb),
            function (int $id): void {
                unset($this->pending[$id]);
            }
        );
    }

    private function schedule(float $seconds, callable $cb): int
    {
        $id = $this->next++;
        $this->pending[$id] = ['at' => $this->now + $seconds, 'cb' => $cb];
        return $id;
    }

    /** Ejecuta todo lo pendiente (incluye lo que se programe mientras tanto). */
    public function run(int $max = 10000): void
    {
        while ($this->pending !== [] && $max-- > 0) {
            uasort($this->pending, fn ($a, $b) => $a['at'] <=> $b['at']);
            $id = array_key_first($this->pending);
            $item = $this->pending[$id];
            unset($this->pending[$id]);
            $this->now = max($this->now, $item['at']);
            ($item['cb'])();
        }
    }

    public function count(): int
    {
        return count($this->pending);
    }
}
