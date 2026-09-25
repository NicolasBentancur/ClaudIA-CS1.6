<?php

declare(strict_types=1);

namespace Claudia\Games;

/**
 * Temporizadores de una sola ejecución. En producción usa Workerman\Timer;
 * en tests se reemplaza por uno manual.
 */
class Timers
{
    /** @var callable(float, callable):int */
    private $add;

    /** @var callable(int):void */
    private $del;

    public function __construct(?callable $add = null, ?callable $del = null)
    {
        $this->add = $add ?? static fn (float $s, callable $cb): int => \Workerman\Timer::add(max(0.001, $s), $cb, [], false);
        $this->del = $del ?? static function (int $id): void {
            \Workerman\Timer::del($id);
        };
    }

    public function later(float $seconds, callable $cb): int
    {
        return ($this->add)($seconds, $cb);
    }

    public function cancel(int $id): void
    {
        ($this->del)($id);
    }
}
