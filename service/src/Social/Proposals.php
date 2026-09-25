<?php

declare(strict_types=1);

namespace Claudia\Social;

use Claudia\Clock;

/**
 * Propuestas pendientes (solo en memoria, vencen solas):
 *   pareja     from -> to        se responde con /aceptar o /rechazar
 *   casamiento from -> to        se responde con /si o /no
 *   adopcion   from -> to        se responde con /si o /no
 *   cupido     Claudia -> from y to: los DOS tienen que /aceptar
 */
final class Proposals
{
    public const PARTNER = 'pareja';
    public const MARRIAGE = 'casamiento';
    public const ADOPTION = 'adopcion';
    public const CUPID = 'cupido';

    /** @var array<int, array{id:int, kind:string, from:int, to:int, expires:int, accepted:list<int>}> */
    private array $items = [];
    private int $nextId = 1;

    /** Agrega una propuesta (reemplaza una igual entre los mismos). @return array{id:int, kind:string, from:int, to:int, expires:int, accepted:list<int>} */
    public function add(string $kind, int $from, int $to, int $ttl): array
    {
        foreach ($this->items as $id => $p) {
            if ($p['kind'] === $kind && $p['from'] === $from && $p['to'] === $to) {
                unset($this->items[$id]);
            }
        }
        $p = ['id' => $this->nextId++, 'kind' => $kind, 'from' => $from, 'to' => $to, 'expires' => Clock::now() + $ttl, 'accepted' => []];
        $this->items[$p['id']] = $p;
        return $p;
    }

    /**
     * La propuesta más nueva dirigida a $uid de alguno de esos tipos (opcionalmente de $from).
     * En "cupido" cuenta tanto si $uid es "from" como "to".
     * @param list<string> $kinds
     * @return array{id:int, kind:string, from:int, to:int, expires:int, accepted:list<int>}|null
     */
    public function pendingFor(int $uid, array $kinds, ?int $from = null): ?array
    {
        $now = Clock::now();
        $found = null;
        foreach ($this->items as $p) {
            if ($p['expires'] < $now || !in_array($p['kind'], $kinds, true)) {
                continue;
            }
            if ($p['kind'] === self::CUPID) {
                $involved = ($p['from'] === $uid || $p['to'] === $uid) && !in_array($uid, $p['accepted'], true);
                $other = $p['from'] === $uid ? $p['to'] : $p['from'];
                if (!$involved || ($from !== null && $other !== $from)) {
                    continue;
                }
            } elseif ($p['to'] !== $uid || ($from !== null && $p['from'] !== $from)) {
                continue;
            }
            if ($found === null || $p['id'] > $found['id']) {
                $found = $p;
            }
        }
        return $found;
    }

    /** ¿Ya hay una propuesta de ese tipo de $from a $to? */
    public function exists(string $kind, int $from, int $to): bool
    {
        $now = Clock::now();
        foreach ($this->items as $p) {
            if ($p['kind'] === $kind && $p['from'] === $from && $p['to'] === $to && $p['expires'] >= $now) {
                return true;
            }
        }
        return false;
    }

    /** Marca que $uid aceptó una propuesta de cupido. Devuelve true si ya aceptaron los dos. */
    public function acceptCupid(int $id, int $uid): bool
    {
        if (!isset($this->items[$id])) {
            return false;
        }
        if (!in_array($uid, $this->items[$id]['accepted'], true)) {
            $this->items[$id]['accepted'][] = $uid;
        }
        return count($this->items[$id]['accepted']) >= 2;
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }

    /** Borra todas las propuestas donde participa el usuario (ej. cuando consigue pareja). */
    public function removeInvolving(int $uid, ?string $kind = null): void
    {
        foreach ($this->items as $id => $p) {
            if (($p['from'] === $uid || $p['to'] === $uid) && ($kind === null || $p['kind'] === $kind)) {
                unset($this->items[$id]);
            }
        }
    }

    /** Saca las vencidas y las devuelve (para avisar). @return list<array{id:int, kind:string, from:int, to:int, expires:int, accepted:list<int>}> */
    public function purge(): array
    {
        $now = Clock::now();
        $out = [];
        foreach ($this->items as $id => $p) {
            if ($p['expires'] < $now) {
                $out[] = $p;
                unset($this->items[$id]);
            }
        }
        return $out;
    }
}
