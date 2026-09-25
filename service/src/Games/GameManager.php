<?php

declare(strict_types=1);

namespace Claudia\Games;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Log;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\Players\SessionManager;
use Claudia\UserError;
use Workerman\Connection\TcpConnection;

/**
 * Sesiones de juego por MOTD: tokens de un solo uso, transporte (WebSocket o polling HTTP)
 * y ruteo de mensajes a la lógica de cada juego.
 */
final class GameManager
{
    /** @var array<string, GameSession> */
    private array $sessions = [];

    /** @var array<string, array{title:string, factory:callable(GameSession):GameHandler}> */
    private array $games = [];

    private string $fallbackHost = '127.0.0.1';

    public function __construct(
        private readonly Config $config,
        private readonly Out $out,
        private readonly SessionManager $players,
        private readonly Timers $timers,
    ) {
    }

    /** @param callable(GameSession):GameHandler $factory */
    public function register(string $game, string $title, callable $factory): void
    {
        $this->games[$game] = ['title' => $title, 'factory' => $factory];
    }

    public function setFallbackHost(string $host): void
    {
        if ($host !== '' && $host !== '0.0.0.0') {
            $this->fallbackHost = $host;
        }
    }

    public function timers(): Timers
    {
        return $this->timers;
    }

    /** Abre el juego en el MOTD del jugador. */
    public function open(Session $player, string $game): GameSession
    {
        if ($player->userId === null) {
            throw new UserError('Tenés que loguearte para jugar.');
        }
        if (!isset($this->games[$game])) {
            throw new UserError('Ese juego no existe.');
        }
        if (!$this->config->bool("games.{$game}.enabled", true)) {
            throw new UserError('Ese juego está deshabilitado por ahora.');
        }
        foreach ($this->sessions as $s) {
            if ($s->userId === $player->userId) {
                $this->close($s);
            }
        }
        $token = bin2hex(random_bytes(16));
        $session = new GameSession($token, $game, $player->userId, $player->slot);
        $session->handler = ($this->games[$game]['factory'])($session);
        $this->sessions[$token] = $session;
        $this->out->motd($player->slot, $this->games[$game]['title'], $this->url($game, $token));
        Log::info('Juego abierto', ['game' => $game, 'user' => $player->userId]);
        return $session;
    }

    public function url(string $game, string $token): string
    {
        $host = $this->config->string('service.http.public_host', '');
        if ($host === '') {
            $host = $this->fallbackHost;
        }
        $http = $this->config->int('service.http.port', 27101);
        $ws = $this->config->int('service.ws.port', 27102);
        return "http://{$host}:{$http}/juegos/{$game}/?t={$token}&ws=" . rawurlencode("ws://{$host}:{$ws}/");
    }

    public function get(string $token): ?GameSession
    {
        $s = $this->sessions[$token] ?? null;
        return ($s === null || $s->closed) ? null : $s;
    }

    public function attachWs(string $token, TcpConnection $conn): ?GameSession
    {
        $s = $this->get($token);
        if ($s === null) {
            return null;
        }
        if ($s->ws !== null && $s->ws !== $conn) {
            $s->ws->close();
        }
        $s->ws = $conn;
        return $s;
    }

    public function detachWs(TcpConnection $conn): void
    {
        foreach ($this->sessions as $s) {
            if ($s->ws === $conn) {
                $s->ws = null;
                $s->touch();
            }
        }
    }

    public function receive(GameSession $s, array $msg): void
    {
        $s->touch();
        $type = (string) ($msg['type'] ?? '');
        try {
            if ($type === 'hello') {
                $s->handler->onOpen($s);
                return;
            }
            if ($type === 'ping') {
                return;
            }
            if ($type === 'jserror') {
                // Errores de JavaScript de la página (la propia página limita cuántos manda).
                Log::warning('Error JS en MOTD', [
                    'game' => $s->game,
                    'message' => mb_substr((string) ($msg['message'] ?? ''), 0, 300),
                    'at' => mb_substr((string) ($msg['source'] ?? ''), 0, 120) . ':' . (int) ($msg['line'] ?? 0),
                    'ua' => mb_substr((string) ($msg['ua'] ?? ''), 0, 200),
                ]);
                return;
            }
            $s->handler->onMessage($s, $msg);
        } catch (UserError $e) {
            $s->send(['type' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error("Error en juego {$s->game}: " . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            $s->send(['type' => 'error', 'message' => 'Se trancó algo. Probá de nuevo.']);
        }
    }

    /** Sonido del juego reproducido solo para ese jugador (si sigue en el mismo slot). */
    public function sound(GameSession $s, string $key): void
    {
        $sound = $this->config->string("games.{$s->game}.sounds.{$key}", '');
        if ($sound === '') {
            return;
        }
        $p = $this->players->get($s->slot);
        if ($p !== null && $p->userId === $s->userId) {
            $this->out->sound($s->slot, $sound);
        }
    }

    public function later(GameSession $s, float $seconds, callable $cb): void
    {
        $id = 0;
        $id = $this->timers->later($seconds, function () use ($s, $cb, &$id): void {
            $s->timers = array_values(array_diff($s->timers, [$id]));
            try {
                $cb();
            } catch (\Throwable $e) {
                Log::error("Error en timer de {$s->game}: " . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            }
        });
        $s->timers[] = $id;
    }

    public function close(GameSession $s): void
    {
        if ($s->closed) {
            return;
        }
        try {
            $s->handler->onClose($s);
        } catch (\Throwable $e) {
            Log::error("Error cerrando juego {$s->game}: " . $e->getMessage());
        }
        foreach ($s->timers as $id) {
            $this->timers->cancel($id);
        }
        $s->timers = [];
        $s->send(['type' => 'closed']);
        $s->closed = true;
        $s->ws?->close();
        $s->ws = null;
        unset($this->sessions[$s->token]);
    }

    /** Cantidad de juegos abiertos (MOTD). */
    public function openCount(): int
    {
        return count($this->sessions);
    }

    public function closeForUser(int $userId): void
    {
        foreach ($this->sessions as $s) {
            if ($s->userId === $userId) {
                $this->close($s);
            }
        }
    }

    /** Scheduler: cierra sesiones inactivas. */
    public function tick(): void
    {
        $idle = $this->config->int('service.games.idle_seconds', 600);
        $now = Clock::now();
        foreach ($this->sessions as $s) {
            if ($s->ws === null && $now - $s->lastActive > $idle && !($s->state['busy'] ?? false)) {
                $this->close($s);
            }
        }
    }
}
