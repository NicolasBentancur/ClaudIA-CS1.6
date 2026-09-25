<?php

declare(strict_types=1);

namespace Claudia\Combat;

use Claudia\App;
use Claudia\Clock;
use Claudia\Log;
use Claudia\Net\Out;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Modo de juego (config/combat.json): duelos por coins, rachas con pozo y robo a cuchillo,
 * MVP de cada ronda, arma bonus y recompensas por cabeza.
 *
 * Las muertes llegan del plugin en el momento (game.kill); las rondas con round.start / round.end.
 * Las coins en juego de los duelos y las recompensas se descuentan al apostar/poner y se guardan
 * en la base (kv), así un reinicio del servicio no las pierde: los duelos se devuelven al arrancar.
 */
final class CombatService
{
    /** @var array<int, array{kills:int, pot:int}> racha por usuario */
    private array $streaks = [];
    /** @var array<int, int> kills de la ronda actual por usuario (orden = quién llegó primero) */
    private array $roundKills = [];
    private ?int $mvp = null;
    private int $mvpStreak = 0;
    /** @var array<int, int> mejor racha de MVP por usuario (en memoria) */
    private array $mvpBest = [];
    private ?string $weapon = null;
    /** @var array<int, array{id:int, a:int, b:int, amount:int, active:bool, expires:int}> */
    private array $duels = [];
    private int $nextDuel = 1;
    /** @var array<string, array{count:int, last:int}> duelos seguidos por par */
    private array $pairs = [];
    /** @var callable(int, int):int */
    private $rand;

    public function __construct(private readonly App $app)
    {
        $this->rand = fn (int $min, int $max): int => random_int($min, $max);
    }

    /** @param callable(int, int):int $rand para tests */
    public function setRandom(callable $rand): void
    {
        $this->rand = $rand;
    }

    private function cfg(string $path, mixed $default): mixed
    {
        return $this->app->config->get("combat.{$path}", $default);
    }

    /* ------------------------------------------------------------------
     * Duelos
     * ---------------------------------------------------------------- */

    public function challenge(int $from, int $to, int $amount): void
    {
        $min = (int) $this->cfg('duel.min', 1);
        $max = (int) $this->cfg('duel.max', 30000);
        if ($from === $to) {
            throw new UserError('No te podés retar a vos mismo.');
        }
        if ($amount < $min || $amount > $max) {
            throw new UserError('La apuesta va de ' . Text::coins($min) . ' a ' . Text::coins($max) . ' URU Coins.');
        }
        foreach ([$from, $to] as $uid) {
            if ($this->activeDuelOf($uid) !== null) {
                throw new UserError($uid === $from ? 'Ya estás en un duelo.' : "{$this->app->nick($uid)} ya está en un duelo.");
            }
        }
        $this->checkPairCap($from, $to);
        if ($this->app->wallet->balance($from) < $amount) {
            throw new UserError('No te alcanza para esa apuesta.');
        }
        if ($this->app->wallet->balance($to) < $amount) {
            throw new UserError("{$this->app->nick($to)} no tiene " . Text::coins($amount) . ' URU Coins.');
        }
        foreach ($this->duels as $id => $d) {
            if (!$d['active'] && $d['a'] === $from && $d['b'] === $to) {
                unset($this->duels[$id]);
            }
        }
        $id = $this->nextDuel++;
        $this->duels[$id] = ['id' => $id, 'a' => $from, 'b' => $to, 'amount' => $amount, 'active' => false,
            'expires' => Clock::now() + (int) $this->cfg('duel.accept_seconds', 60)];
    }

    /** @return array{id:int, a:int, b:int, amount:int, active:bool, expires:int}|null duelo pendiente dirigido a $uid */
    public function pendingFor(int $uid): ?array
    {
        $found = null;
        foreach ($this->duels as $d) {
            if (!$d['active'] && $d['b'] === $uid && $d['expires'] >= Clock::now() && ($found === null || $d['id'] > $found['id'])) {
                $found = $d;
            }
        }
        return $found;
    }

    /** @return array{id:int, a:int, b:int, amount:int, active:bool, expires:int} el duelo que empieza */
    public function accept(int $uid): array
    {
        $d = $this->pendingFor($uid);
        if ($d === null) {
            throw new UserError('No tenés ningún duelo pendiente.');
        }
        if ($this->activeDuelOf($d['a']) !== null || $this->activeDuelOf($uid) !== null) {
            unset($this->duels[$d['id']]);
            throw new UserError('Alguno de los dos ya está en otro duelo.');
        }
        $this->checkPairCap($d['a'], $d['b']);
        $this->app->db->transaction(function () use ($d): void {
            $this->app->wallet->debit($d['a'], $d['amount'], 'duel_stake', 'duelo contra ' . $this->app->nick($d['b']));
            $this->app->wallet->debit($d['b'], $d['amount'], 'duel_stake', 'duelo contra ' . $this->app->nick($d['a']));
        });
        $this->duels[$d['id']]['active'] = true;
        $key = $this->pairKey($d['a'], $d['b']);
        $pair = $this->pairs[$key] ?? ['count' => 0, 'last' => 0];
        if (Clock::now() - $pair['last'] >= (int) $this->cfg('duel.pair_reset_seconds', 3600)) {
            $pair['count'] = 0;
        }
        $this->pairs[$key] = ['count' => $pair['count'] + 1, 'last' => Clock::now()];
        $this->persistDuels();
        return $this->duels[$d['id']];
    }

    public function decline(int $uid): array
    {
        $d = $this->pendingFor($uid);
        if ($d === null) {
            throw new UserError('No tenés ningún duelo pendiente.');
        }
        unset($this->duels[$d['id']]);
        return $d;
    }

    /** @return array{id:int, a:int, b:int, amount:int, active:bool, expires:int}|null */
    public function activeDuelOf(int $uid): ?array
    {
        foreach ($this->duels as $d) {
            if ($d['active'] && ($d['a'] === $uid || $d['b'] === $uid)) {
                return $d;
            }
        }
        return null;
    }

    private function checkPairCap(int $a, int $b): void
    {
        $pair = $this->pairs[$this->pairKey($a, $b)] ?? null;
        $cap = (int) $this->cfg('duel.pair_cap', 5);
        $reset = (int) $this->cfg('duel.pair_reset_seconds', 3600);
        if ($pair !== null && $pair['count'] >= $cap && Clock::now() - $pair['last'] < $reset) {
            throw new UserError("Ya jugaron {$cap} duelos seguidos entre ustedes. Esperen " . Text::duration($pair['last'] + $reset - Clock::now()) . ' sin duelos.');
        }
    }

    private function pairKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    /** @param array{id:int, a:int, b:int, amount:int} $d */
    private function payDuel(array $d, int $winner, string $why): void
    {
        unset($this->duels[$d['id']]);
        $loser = $winner === $d['a'] ? $d['b'] : $d['a'];
        $this->app->wallet->credit($winner, $d['amount'] * 2, 'duel_win', "duelo contra {$this->app->nick($loser)}");
        $this->persistDuels();
        $this->app->out->chat(Out::ALL, "{green}{$this->app->nick($winner)}{default} le ganó el duelo a {$this->app->nick($loser)} {$why} y se llevó {green}" . Text::coins($d['amount'] * 2) . '{default} URU Coins.');
    }

    /** @param array{id:int, a:int, b:int, amount:int} $d */
    private function cancelDuel(array $d, string $why): void
    {
        unset($this->duels[$d['id']]);
        foreach ([$d['a'], $d['b']] as $uid) {
            $this->app->wallet->credit($uid, $d['amount'], 'duel_refund', 'duelo cancelado');
            $this->app->notify($uid, "Duelo cancelado ({$why}): te devolví " . Text::coins($d['amount']) . '.');
        }
        $this->persistDuels();
    }

    private function persistDuels(): void
    {
        $active = array_values(array_filter($this->duels, fn ($d) => $d['active']));
        $this->app->db->kvSet('combat.duels', (string) json_encode($active));
    }

    /** Al arrancar: devuelve las apuestas de duelos que quedaron abiertos (se reinició el servicio). */
    public function start(): void
    {
        $open = json_decode((string) $this->app->db->kvGet('combat.duels', '[]'), true) ?: [];
        foreach ($open as $d) {
            foreach ([(int) $d['a'], (int) $d['b']] as $uid) {
                $this->app->wallet->credit($uid, (int) $d['amount'], 'duel_refund', 'duelo cancelado (reinicio)');
            }
        }
        if ($open !== []) {
            Log::info('Duelos devueltos al arrancar', ['cantidad' => count($open)]);
        }
        $this->app->db->kvSet('combat.duels', '[]');
    }

    /** Vencen los duelos no aceptados. */
    public function tick(): void
    {
        foreach ($this->duels as $id => $d) {
            if (!$d['active'] && $d['expires'] < Clock::now()) {
                unset($this->duels[$id]);
                $this->app->notify($d['a'], "{$this->app->nick($d['b'])} no aceptó el duelo.");
            }
        }
    }

    /* ------------------------------------------------------------------
     * Muertes y rondas
     * ---------------------------------------------------------------- */

    /**
     * Una muerte. $killer / $victim son usuarios (null = bot o sin identificarse);
     * $selfOrWorld = suicidio o muerte por el mundo; $teamKill = mató a un compañero.
     */
    public function onKill(?int $killer, ?int $victim, string $weapon, bool $selfOrWorld, bool $teamKill): void
    {
        $weapon = strtolower($weapon);
        $knife = $weapon === 'knife';
        $validKill = !$selfOrWorld && !$teamKill && $killer !== null;

        // 1. Duelos: gana solo si lo mató el rival; cualquier otra muerte lo cancela.
        if ($victim !== null && ($d = $this->activeDuelOf($victim)) !== null) {
            $rival = $d['a'] === $victim ? $d['b'] : $d['a'];
            if (!$selfOrWorld && $killer === $rival) {
                $this->payDuel($d, $rival, $knife ? 'a cuchillo' : "con {$weapon}");
            } else {
                $this->cancelDuel($d, $selfOrWorld ? 'se murió solo' : 'lo mató otro');
            }
        }

        if ($validKill && $victim !== null && $killer !== $victim && $knife) {
            $this->knifeSteal($killer, $victim);
            $this->collectBounty($killer, $victim);
        }

        // 2. La víctima pierde la racha y el pozo.
        if ($victim !== null) {
            unset($this->streaks[$victim]);
        }

        if (!$validKill || $killer === $victim) {
            return;
        }
        // 3. Racha, kills de la ronda, MVP killer y arma bonus del asesino.
        $this->roundKills[$killer] = ($this->roundKills[$killer] ?? 0) + 1;
        $s = $this->streaks[$killer] ?? ['kills' => 0, 'pot' => 0];
        $s['kills']++;
        $reward = (int) ($this->cfg('streak.rewards', [])[(string) $s['kills']] ?? 0);
        if ($reward > 0) {
            $s['pot'] += $reward;
            $this->app->wallet->credit($killer, $reward, 'streak', "racha de {$s['kills']}");
            $this->app->out->chat(Out::ALL, "{green}{$this->app->nick($killer)}{default} lleva {$s['kills']} kills seguidas: +" . Text::coins($reward) . ' URU Coins (pozo: ' . Text::coins($s['pot']) . ').');
        }
        $this->streaks[$killer] = $s;

        if ($victim !== null && $victim === $this->mvp) {
            $bonus = (int) $this->cfg('mvp.killer_bonus', 2);
            $this->app->wallet->credit($killer, $bonus, 'mvp_killer', 'mató al MVP');
            $this->app->notify($killer, "Mataste al MVP de la ronda pasada: +{$bonus} URU Coins.");
        }
        if ($this->weapon !== null && $weapon === $this->weapon) {
            $amount = (int) $this->cfg('weapon_bonus.amount', 5);
            $this->app->wallet->credit($killer, $amount, 'weapon_bonus', "kill con {$weapon}");
            $this->app->notify($killer, "Kill con el arma bonus ({$weapon}): +{$amount} URU Coins.");
        }
    }

    private function knifeSteal(int $killer, int $victim): void
    {
        $pot = $this->streaks[$victim]['pot'] ?? 0;
        if ($pot <= 0) {
            return;
        }
        $roll = ($this->rand)(1, 100);
        $fraction = 0.0;
        $acc = 0;
        foreach ((array) $this->cfg('streak.knife_steal', []) as [$chance, $frac]) {
            $acc += (int) $chance;
            if ($roll <= $acc) {
                $fraction = (float) $frac;
                break;
            }
        }
        if ($fraction <= 0) {
            return;
        }
        $stolen = max(1, (int) floor($pot * $fraction));
        $stolen = min($stolen, $this->app->wallet->balance($victim));
        if ($stolen <= 0) {
            return;
        }
        $this->app->db->transaction(function () use ($killer, $victim, $stolen): void {
            $this->app->wallet->debit($victim, $stolen, 'streak_stolen', 'te robaron a cuchillo');
            $this->app->wallet->credit($killer, $stolen, 'streak_steal', 'robo a cuchillo');
        });
        $this->app->out->chat(Out::ALL, "{green}{$this->app->nick($killer)}{default} acuchilló a {$this->app->nick($victim)} y le robó {green}" . Text::coins($stolen) . '{default} del pozo de su racha (' . (int) round($fraction * 100) . '%).');
    }

    public function roundStart(): void
    {
        $this->roundKills = [];
        $weapons = (array) $this->cfg('weapon_bonus.weapons', []);
        $this->weapon = $weapons === [] ? null : (string) $weapons[($this->rand)(0, count($weapons) - 1)];
        if ($this->weapon !== null) {
            $this->app->out->chat(Out::ALL, "Arma bonus de la ronda: {green}{$this->weapon}{default} (+" . (int) $this->cfg('weapon_bonus.amount', 5) . ' URU Coins por kill).');
        }
    }

    public function roundEnd(): void
    {
        $best = null;
        $max = 0;
        foreach ($this->roundKills as $uid => $kills) {
            if ($kills > $max) {
                $best = $uid;
                $max = $kills;
            }
        }
        $this->roundKills = [];
        if ($best === null) {
            $this->mvp = null;
            $this->mvpStreak = 0;
            return;
        }
        $this->mvpStreak = $best === $this->mvp ? $this->mvpStreak + 1 : 1;
        $this->mvp = $best;
        $this->mvpBest[$best] = max($this->mvpBest[$best] ?? 0, $this->mvpStreak);
        $reward = (int) round((float) $this->cfg('mvp.base', 20) * ((float) $this->cfg('mvp.multiplier', 1.025)) ** ($this->mvpStreak - 1));
        $this->app->wallet->credit($best, $reward, 'mvp', "MVP ({$max} kills)");
        $this->app->out->chat(Out::ALL, "MVP de la ronda: {green}{$this->app->nick($best)}{default} con {$max} kills (+" . Text::coins($reward) . ')'
            . ($this->mvpStreak > 1 ? " - {$this->mvpStreak} rondas seguidas" : '') . '.');
    }

    /** El jugador se fue: pierde la racha y, si estaba en duelo, el pozo es del rival. */
    public function onLeave(int $uid): void
    {
        unset($this->streaks[$uid]);
        foreach ($this->duels as $id => $d) {
            if (!$d['active'] && ($d['a'] === $uid || $d['b'] === $uid)) {
                unset($this->duels[$id]);
            }
        }
        if (($d = $this->activeDuelOf($uid)) !== null) {
            $this->payDuel($d, $d['a'] === $uid ? $d['b'] : $d['a'], 'porque el otro se fue');
        }
    }

    /* ------------------------------------------------------------------
     * Consultas
     * ---------------------------------------------------------------- */

    /** @return array{kills:int, pot:int} */
    public function streak(int $uid): array
    {
        return $this->streaks[$uid] ?? ['kills' => 0, 'pot' => 0];
    }

    /** @return array{current:int, best:int, isMvp:bool} */
    public function mvpInfo(int $uid): array
    {
        return ['current' => $this->mvp === $uid ? $this->mvpStreak : 0, 'best' => $this->mvpBest[$uid] ?? 0, 'isMvp' => $this->mvp === $uid];
    }

    public function weapon(): ?string
    {
        return $this->weapon;
    }

    /* ------------------------------------------------------------------
     * Recompensas
     * ---------------------------------------------------------------- */

    /** @return array{target:int, total:int}|null */
    public function bounty(): ?array
    {
        $b = json_decode((string) $this->app->db->kvGet('combat.bounty', 'null'), true);
        return is_array($b) && isset($b['target']) ? ['target' => (int) $b['target'], 'total' => (int) $b['total']] : null;
    }

    /** @return int total de la recompensa después de sumar */
    public function placeBounty(int $from, int $target, int $amount): int
    {
        $min = (int) $this->cfg('bounty.min', 100);
        if ($from === $target) {
            throw new UserError('No te podés poner precio a vos mismo.');
        }
        if ($amount < $min) {
            throw new UserError('La recompensa mínima es de ' . Text::coins($min) . ' URU Coins.');
        }
        $current = $this->bounty();
        if ($current !== null && $current['target'] !== $target) {
            throw new UserError('Ya hay una recompensa activa por ' . $this->app->nick($current['target']) . ' (' . Text::coins($current['total']) . '). Hasta que alguien la cobre no se puede poner otra.');
        }
        return $this->app->db->transaction(function () use ($from, $target, $amount, $current): int {
            $this->app->wallet->debit($from, $amount, 'bounty_place', 'recompensa por ' . $this->app->nick($target));
            $total = ($current['total'] ?? 0) + $amount;
            $this->app->db->kvSet('combat.bounty', (string) json_encode(['target' => $target, 'total' => $total]));
            return $total;
        });
    }

    private function collectBounty(int $killer, int $victim): void
    {
        $b = $this->bounty();
        if ($b === null || $b['target'] !== $victim) {
            return;
        }
        $this->app->db->transaction(function () use ($killer, $b): void {
            $this->app->wallet->credit($killer, $b['total'], 'bounty', 'recompensa por ' . $this->app->nick($b['target']));
            $this->app->db->kvSet('combat.bounty', 'null');
        });
        $this->app->out->chat(Out::ALL, "{green}{$this->app->nick($killer)}{default} cobró la recompensa por {$this->app->nick($victim)}: {green}" . Text::coins($b['total']) . '{default} URU Coins.');
    }
}
