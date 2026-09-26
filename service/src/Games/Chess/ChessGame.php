<?php

declare(strict_types=1);

namespace Claudia\Games\Chess;

use Claudia\Games\GameHandler;
use Claudia\Games\GameSession;

/**
 * Ventana del tablero de un jugador: todo lo resuelve ChessService (la partida es de a dos).
 *
 * Acciones de la página:
 *   {"type":"move","from":"e2","to":"e4","promo":"q"} | {"type":"hint"} | {"type":"chat","text":"..."}
 *   {"type":"voice","on":true} | {"type":"resign"} | {"type":"draw"} | {"type":"draw_accept"} | {"type":"draw_decline"}
 */
final class ChessGame implements GameHandler
{
    public function __construct(private readonly ChessService $chess)
    {
    }

    public function onOpen(GameSession $session): void
    {
        $this->chess->attach($session);
    }

    public function onMessage(GameSession $session, array $msg): void
    {
        $this->chess->handle($session, $msg);
    }

    public function onClose(GameSession $session): void
    {
        $this->chess->detach($session);
    }
}
