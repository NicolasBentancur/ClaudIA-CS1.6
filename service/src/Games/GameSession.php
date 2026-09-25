<?php

declare(strict_types=1);

namespace Claudia\Games;

use Claudia\Clock;
use Workerman\Connection\TcpConnection;

/**
 * Una ventana MOTD abierta por un jugador. Los mensajes hacia la página se numeran
 * (seq) y se guardan en un outbox para que el transporte por polling pueda pedirlos
 * desde el último que recibió; si hay WebSocket se empujan al instante.
 */
final class GameSession
{
    public ?TcpConnection $ws = null;
    public int $lastActive;
    public GameHandler $handler;

    /** Estado libre del juego (mesa, mazo, ronda...). */
    public array $state = [];

    /** @var list<int> timers activos, para cancelarlos al cerrar */
    public array $timers = [];

    private int $seq = 0;

    /** @var list<array{seq:int, msg:array}> */
    private array $outbox = [];

    public bool $closed = false;

    public function __construct(
        public readonly string $token,
        public readonly string $game,
        public readonly int $userId,
        public int $slot,
    ) {
        $this->lastActive = Clock::now();
    }

    public function send(array $msg): void
    {
        if ($this->closed) {
            return;
        }
        $this->seq++;
        $msg['seq'] = $this->seq;
        $this->outbox[] = ['seq' => $this->seq, 'msg' => $msg];
        if (count($this->outbox) > 400) {
            $this->outbox = array_slice($this->outbox, -300);
        }
        $this->ws?->send(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array> mensajes con seq > $since */
    public function since(int $since): array
    {
        $out = [];
        foreach ($this->outbox as $item) {
            if ($item['seq'] > $since) {
                $out[] = $item['msg'];
            }
        }
        return $out;
    }

    public function touch(): void
    {
        $this->lastActive = Clock::now();
    }
}
