<?php

declare(strict_types=1);

namespace Claudia\Ai;

/**
 * Último juego de cada usuario, para el criterio "comentario sobre un juego reciente"
 * y para pasarle a la IA qué pasó en ese juego.
 */
final class RecentGames
{
    /** @var array<int, array{game:string, summary:string, at:int}> */
    private array $last = [];

    public function __construct(private int $windowSeconds = 20)
    {
    }

    public function setWindow(int $seconds): void
    {
        $this->windowSeconds = $seconds;
    }

    public function record(int $userId, string $game, string $summary, int $at): void
    {
        $this->last[$userId] = ['game' => $game, 'summary' => $summary, 'at' => $at];
    }

    /** @return array{game:string, summary:string, at:int}|null */
    public function recent(int $userId, int $now): ?array
    {
        $g = $this->last[$userId] ?? null;
        if ($g === null || $now - $g['at'] > $this->windowSeconds) {
            return null;
        }
        return $g;
    }
}
