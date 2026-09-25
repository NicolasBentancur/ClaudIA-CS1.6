<?php

declare(strict_types=1);

namespace Claudia\Players;

use Claudia\Clock;
use Claudia\Util\Text;

/**
 * Sesiones de los jugadores conectados, indexadas por slot.
 *
 * Reanudación: cuando un jugador logueado se va (o el plugin se reconecta en un cambio
 * de mapa) se recuerda nick+IP durante unos minutos, así no tiene que loguearse de nuevo.
 */
final class SessionManager
{
    /** @var array<int, Session> */
    private array $sessions = [];

    /** @var array<string, array{ip:string, userId:int, until:int}> */
    private array $resume = [];

    public function __construct(private readonly int $resumeSeconds = 180)
    {
    }

    public function join(int $slot, string $nick, string $ip, string $authid): Session
    {
        if (isset($this->sessions[$slot])) {
            $this->leave($slot);
        }
        $session = new Session($slot, $nick, $ip, $authid, Clock::now());
        $this->sessions[$slot] = $session;
        return $session;
    }

    /** Devuelve el userId a reanudar para ese nick/IP, si corresponde, y consume la entrada. */
    public function takeResume(string $nick, string $ip): ?int
    {
        $key = mb_strtolower($nick);
        $entry = $this->resume[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        unset($this->resume[$key]);
        if ($entry['until'] < Clock::now() || $entry['ip'] !== $ip) {
            return null;
        }
        return $entry['userId'];
    }

    public function leave(int $slot): ?Session
    {
        $session = $this->sessions[$slot] ?? null;
        if ($session === null) {
            return null;
        }
        unset($this->sessions[$slot]);
        if ($session->userId !== null) {
            $this->resume[mb_strtolower($session->nick)] = [
                'ip' => $session->ip,
                'userId' => $session->userId,
                'until' => Clock::now() + $this->resumeSeconds,
            ];
        }
        return $session;
    }

    /** El plugin se desconectó: todas las sesiones pasan a "reanudables". */
    public function leaveAll(): void
    {
        foreach (array_keys($this->sessions) as $slot) {
            $this->leave($slot);
        }
    }

    public function get(int $slot): ?Session
    {
        return $this->sessions[$slot] ?? null;
    }

    public function byUser(int $userId): ?Session
    {
        foreach ($this->sessions as $s) {
            if ($s->userId === $userId) {
                return $s;
            }
        }
        return null;
    }

    /** @return array<int, Session> */
    public function all(): array
    {
        return $this->sessions;
    }

    /** @return list<Session> */
    public function logged(): array
    {
        return array_values(array_filter($this->sessions, fn (Session $s) => $s->userId !== null));
    }

    /**
     * Busca un jugador conectado por nick (exacto o parcial, sin distinguir mayúsculas).
     * Devuelve null si no hay coincidencia o si es ambigua.
     */
    public function findByName(string $query): ?Session
    {
        $q = Text::fold($query);
        if ($q === '') {
            return null;
        }
        $partial = [];
        foreach ($this->sessions as $s) {
            $n = Text::fold($s->nick);
            if ($n === $q) {
                return $s;
            }
            if (str_contains($n, $q)) {
                $partial[] = $s;
            }
        }
        return count($partial) === 1 ? $partial[0] : null;
    }

    public function purgeResume(): void
    {
        $now = Clock::now();
        foreach ($this->resume as $k => $e) {
            if ($e['until'] < $now) {
                unset($this->resume[$k]);
            }
        }
    }
}
