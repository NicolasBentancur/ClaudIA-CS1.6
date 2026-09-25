<?php

declare(strict_types=1);

namespace Claudia;

/**
 * Bus de eventos interno y síncrono (login, logout, fin de juego, etc.).
 */
final class Events
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, mixed ...$args): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener(...$args);
            } catch (\Throwable $e) {
                Log::error("Error en listener de '{$event}': " . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            }
        }
    }
}
