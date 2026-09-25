<?php

declare(strict_types=1);

namespace Claudia\Ai;

/**
 * Últimos mensajes del chat global (incluye los de Claudia) para dar contexto a la IA.
 */
final class ChatBuffer
{
    /** @var list<array{at:int, nick:string, userId:?int, text:string, claudia:bool, dead:bool}> */
    private array $items = [];

    public function __construct(private int $capacity = 16)
    {
    }

    public function setCapacity(int $capacity): void
    {
        $this->capacity = max(1, $capacity);
        $this->trim();
    }

    public function add(int $at, string $nick, ?int $userId, string $text, bool $claudia = false, bool $dead = false): void
    {
        $this->items[] = ['at' => $at, 'nick' => $nick, 'userId' => $userId, 'text' => $text, 'claudia' => $claudia, 'dead' => $dead];
        $this->trim();
    }

    /**
     * Los últimos $n mensajes ANTERIORES al último (el último es el que dispara la interacción).
     * @return list<array{at:int, nick:string, userId:?int, text:string, claudia:bool, dead:bool}>
     */
    public function history(int $n): array
    {
        $prev = array_slice($this->items, 0, -1);
        return array_slice($prev, -$n);
    }

    /** @return list<int> ids de usuarios registrados que aparecen en los últimos $n mensajes */
    public function userIds(int $n): array
    {
        $ids = [];
        foreach (array_slice($this->items, -($n + 1)) as $it) {
            if ($it['userId'] !== null) {
                $ids[$it['userId']] = true;
            }
        }
        return array_keys($ids);
    }

    private function trim(): void
    {
        if (count($this->items) > $this->capacity) {
            $this->items = array_slice($this->items, -$this->capacity);
        }
    }
}
