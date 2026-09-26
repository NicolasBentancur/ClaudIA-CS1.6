<?php

declare(strict_types=1);

namespace Claudia\Games\Chess;

use Claudia\Games\GameSession;
use Claudia\UserError;

/**
 * Una partida de ajedrez en curso entre dos usuarios: posición, reloj, pistas, tablas y chat.
 * No toca plata ni red: ChessService la usa y se encarga de pagar y avisar.
 */
final class ChessMatch
{
    public Board $board;

    /** @var list<string> jugadas en notación (Cf3, exd5...) */
    public array $san = [];
    /** @var array{from:int, to:int}|null */
    public ?array $last = null;
    /** @var array<string, int> veces que apareció cada posición */
    public array $seen = [];

    /** @var array{w:float, b:float} segundos que le quedan a cada uno */
    public array $clock;
    /** Momento en que empezó a correr el reloj del que mueve. */
    public float $turnStarted;

    /** @var array<int, bool> pistas compradas (por usuario) */
    public array $hints = [];
    public ?int $drawOffer = null;
    /** @var array<int, int> ofertas de tablas hechas */
    public array $drawOffers = [];

    /** @var list<array{from:string, text:string, uid:int, system:bool}> */
    public array $chat = [];
    /** @var array<int, float> último mensaje de chat (antiflood) */
    public array $lastChat = [];
    /** @var array<int, float> micrófono prendido hasta (0 = apagado) */
    public array $voice = [];

    /** @var array<int, GameSession|null> ventana de cada jugador */
    public array $sessions = [];
    /** @var array<int, float> desde cuándo está sin la ventana abierta */
    public array $away = [];

    public bool $over = false;
    /** @var array{winner:?int, reason:string, text:string}|null */
    public ?array $result = null;

    /**
     * @param array<int, int> $rounds ronda del casino de cada usuario (apuesta retenida)
     * @param array<int, string> $names
     */
    public function __construct(
        public readonly int $id,
        public readonly int $white,
        public readonly int $black,
        public readonly int $bet,
        public readonly array $rounds,
        public readonly array $names,
        int $seconds,
        float $now,
    ) {
        $this->board = Board::start();
        $this->clock = ['w' => (float) $seconds, 'b' => (float) $seconds];
        $this->turnStarted = $now;
        $this->seen[$this->board->positionKey()] = 1;
        foreach ([$white, $black] as $uid) {
            $this->hints[$uid] = false;
            $this->drawOffers[$uid] = 0;
            $this->voice[$uid] = 0.0;
            $this->sessions[$uid] = null;
            $this->away[$uid] = $now;
        }
    }

    public function has(int $uid): bool
    {
        return $uid === $this->white || $uid === $this->black;
    }

    public function colorOf(int $uid): string
    {
        return $uid === $this->white ? 'w' : 'b';
    }

    public function userOf(string $color): int
    {
        return $color === 'w' ? $this->white : $this->black;
    }

    public function opponent(int $uid): int
    {
        return $uid === $this->white ? $this->black : $this->white;
    }

    /** Segundos que le quedan a $color en este momento. */
    public function remaining(string $color, float $now): float
    {
        $left = $this->clock[$color];
        if (!$this->over && $this->board->turn() === $color) {
            $left -= $now - $this->turnStarted;
        }
        return max(0.0, $left);
    }

    /** Bando al que se le terminó el tiempo, o null. */
    public function flagged(float $now): ?string
    {
        $turn = $this->board->turn();
        return !$this->over && $this->remaining($turn, $now) <= 0.0 ? $turn : null;
    }

    /**
     * Juega from->to para $uid. Devuelve la jugada aplicada y cómo terminó la partida (si terminó).
     * @return array{move:array, san:string, end:?array{winner:?string, reason:string}}
     */
    public function move(int $uid, int $from, int $to, string $promo, float $now, int $increment = 0): array
    {
        if ($this->over) {
            throw new UserError('La partida ya terminó.');
        }
        $color = $this->colorOf($uid);
        if ($this->board->turn() !== $color) {
            throw new UserError('No es tu turno.');
        }
        $m = $this->board->findMove($from, $to, $promo);
        if ($m === null) {
            throw new UserError('Movimiento ilegal.');
        }
        $this->clock[$color] = $this->remaining($color, $now) + $increment;
        $this->turnStarted = $now;
        $san = $this->board->san($m);
        $this->board = $this->board->play($m);
        $this->san[] = $san;
        $this->last = ['from' => $from, 'to' => $to];
        $this->drawOffer = null; // jugar rechaza la oferta de tablas pendiente
        $key = $this->board->positionKey();
        $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;
        return ['move' => $m, 'san' => $san, 'end' => $this->endAfterMove($color, $key)];
    }

    /** @return array{winner:?string, reason:string}|null */
    private function endAfterMove(string $mover, string $key): ?array
    {
        $b = $this->board;
        if ($b->isCheckmate()) {
            return ['winner' => $mover, 'reason' => 'mate'];
        }
        if ($b->isStalemate()) {
            return ['winner' => null, 'reason' => 'stalemate'];
        }
        if ($b->insufficientMaterial()) {
            return ['winner' => null, 'reason' => 'material'];
        }
        if (($this->seen[$key] ?? 0) >= 3) {
            return ['winner' => null, 'reason' => 'repetition'];
        }
        if ($b->halfmove() >= 100) {
            return ['winner' => null, 'reason' => 'fifty'];
        }
        return null;
    }

    /**
     * Jugadas legales agrupadas por casilla de origen (lo que muestra la pista).
     * @return array<string, list<string>>
     */
    public function legalMap(): array
    {
        $map = [];
        foreach ($this->board->legalMoves() as $m) {
            $to = Board::name($m['to']);
            $from = Board::name($m['from']);
            if (!in_array($to, $map[$from] ?? [], true)) {
                $map[$from][] = $to;
            }
        }
        return $map;
    }

    public function addChat(int $uid, string $from, string $text, bool $system = false): array
    {
        $line = ['from' => $from, 'text' => $text, 'uid' => $uid, 'system' => $system];
        $this->chat[] = $line;
        if (count($this->chat) > 60) {
            $this->chat = array_slice($this->chat, -50);
        }
        return $line;
    }
}
