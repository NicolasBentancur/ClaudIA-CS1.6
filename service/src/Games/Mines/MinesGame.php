<?php

declare(strict_types=1);

namespace Claudia\Games\Mines;

use Claudia\Config;
use Claudia\Economy\Wallet;
use Claudia\Games\Casino;
use Claudia\Games\GameHandler;
use Claudia\Games\GameManager;
use Claudia\Games\GameSession;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Minas por MOTD: tablero de 5x5 con N minas escondidas. Cada casilla segura sube el
 * multiplicador; el jugador cobra cuando quiere o pierde todo si pisa una mina.
 * Las minas se sortean en el servidor al apostar y la página no las ve hasta que termina.
 *
 * Acciones de la página:
 *   {"type":"bet","amount":N,"mines":M} | {"type":"reveal","cell":0..24} | {"type":"random"} | {"type":"cashout"}
 */
final class MinesGame implements GameHandler
{
    public const CELLS = 25;

    public function __construct(
        private readonly Config $config,
        private readonly GameManager $manager,
        private readonly Casino $casino,
        private readonly Wallet $wallet,
    ) {
    }

    /** Multiplicador tras $safe casillas seguras con $mines minas (con el RTP aplicado). */
    public static function multiplier(int $mines, int $safe, float $rtp): float
    {
        if ($safe <= 0) {
            return 1.0;
        }
        $m = 1.0;
        for ($i = 0; $i < $safe; $i++) {
            $m *= (self::CELLS - $i) / (self::CELLS - $mines - $i);
        }
        return floor(round($m * $rtp * 100, 6)) / 100;
    }

    public function onOpen(GameSession $s): void
    {
        if (!array_key_exists('round', $s->state)) {
            $s->state['round'] = null;
            $s->state['busy'] = false;
            $s->state['last'] = null;
        }
        $this->sendState($s, ['init' => true]);
    }

    public function onMessage(GameSession $s, array $msg): void
    {
        $type = (string) ($msg['type'] ?? '');
        switch ($type) {
            case 'bet':
                $this->start($s, (int) ($msg['amount'] ?? 0), (int) ($msg['mines'] ?? 0));
                return;
            case 'reveal':
                $this->reveal($s, (int) ($msg['cell'] ?? -1));
                return;
            case 'random':
                $round = $this->round($s);
                $free = array_values(array_diff(range(0, self::CELLS - 1), $round['revealed']));
                $this->reveal($s, $free[random_int(0, count($free) - 1)]);
                return;
            case 'cashout':
                $round = $this->round($s);
                if ($round['revealed'] === []) {
                    throw new UserError('Destapá al menos una casilla antes de cobrar.');
                }
                $this->finish($s, true, true);
                return;
            default:
                throw new UserError('Acción desconocida.');
        }
    }

    public function onClose(GameSession $s): void
    {
        if (($s->state['round'] ?? null) !== null) {
            // Cerró el MOTD con la ronda abierta: se cobra lo que llevaba (o se devuelve la apuesta).
            $this->finish($s, true, false);
        }
    }

    private function start(GameSession $s, int $amount, int $mines): void
    {
        if ($s->state['round'] !== null) {
            throw new UserError('Ya hay una ronda en juego.');
        }
        [$min, $max] = $this->mineLimits();
        if ($mines < $min || $mines > $max) {
            throw new UserError("Elegí entre {$min} y {$max} minas.");
        }
        $this->casino->validateTotal($amount);
        $cells = range(0, self::CELLS - 1);
        $positions = array_slice($this->secureShuffle($cells), 0, $mines);
        sort($positions);
        $id = $this->casino->openRound($s->userId, 'minas', $amount, ['mines' => $mines]);
        $s->state['round'] = [
            'id' => $id,
            'bet' => $amount,
            'mines' => $mines,
            'positions' => $positions,
            'revealed' => [],
        ];
        $s->state['busy'] = true;
        $s->state['last'] = null;
        $this->manager->sound($s, 'bet');
        $this->sendState($s);
    }

    private function reveal(GameSession $s, int $cell): void
    {
        $round = $this->round($s);
        if ($cell < 0 || $cell >= self::CELLS) {
            throw new UserError('Casilla inválida.');
        }
        if (in_array($cell, $round['revealed'], true)) {
            return;
        }
        $s->state['round']['revealed'][] = $cell;
        if (in_array($cell, $round['positions'], true)) {
            $s->state['round']['hit'] = $cell;
            $this->finish($s, false, true);
            return;
        }
        $safeTotal = self::CELLS - $round['mines'];
        if (count($s->state['round']['revealed']) >= $safeTotal) {
            // Destapó todas las seguras: cobra solo.
            $this->finish($s, true, true);
            return;
        }
        $this->manager->sound($s, 'gem');
        $this->sendState($s);
    }

    private function finish(GameSession $s, bool $cashout, bool $notify): void
    {
        $round = $s->state['round'];
        if ($round === null) {
            return;
        }
        $s->state['round'] = null;
        $s->state['busy'] = false;
        $safe = count($round['revealed']);
        $mult = $cashout ? self::multiplier($round['mines'], $safe, $this->rtp()) : 0.0;
        $payout = $cashout ? (int) floor($round['bet'] * $mult) : 0;
        $summary = $cashout
            ? "Jugó a las minas con {$round['mines']} minas: destapó {$safe} casillas y cobró x" . number_format($mult, 2, ',', '') . '.'
            : "Jugó a las minas con {$round['mines']} minas y pisó una mina después de destapar " . ($safe - 1) . ' casillas.';
        $settled = $this->casino->settle((int) $round['id'], $payout, $summary, [
            'mines' => $round['positions'],
            'revealed' => $round['revealed'],
            'multiplier' => $mult,
        ]);
        $s->state['last'] = [
            'mines' => $round['positions'],
            'revealed' => $round['revealed'],
            'hit' => $round['hit'] ?? null,
            'count' => $round['mines'],
            'bet' => $round['bet'],
            'multiplier' => $mult,
            'payout' => $payout,
            'net' => $settled['net'],
        ];
        if (!$notify) {
            return;
        }
        $this->manager->sound($s, $cashout ? 'win' : 'boom');
        $this->sendState($s);
        if ($settled['confiscated'] > 0) {
            $s->send(['type' => 'notice', 'message' => 'Clearing: te confiscaron ' . Text::coins($settled['confiscated']) . ' URU Coins para pagar tu deuda.']);
        }
    }

    private function sendState(GameSession $s, array $extra = []): void
    {
        $round = $s->state['round'];
        $balance = $this->wallet->balance($s->userId);
        [$min, $max] = $this->mineLimits();
        $rtp = $this->rtp();
        $state = [
            'type' => 'state',
            'phase' => $round !== null ? 'playing' : ($s->state['last'] !== null ? 'done' : 'betting'),
            'balance' => $balance,
            'chips' => $this->casino->chipsFor($balance),
            'min' => $this->casino->minBet(),
            'max' => $this->casino->maxBet(),
            'minMines' => $min,
            'maxMines' => $max,
            'defaultMines' => $this->config->int('games.minas.default_mines', 3),
            'rtp' => $rtp,
        ];
        if ($round !== null) {
            $safe = count($round['revealed']);
            $mult = self::multiplier($round['mines'], $safe, $rtp);
            $state['round'] = [
                'bet' => $round['bet'],
                'mines' => $round['mines'],
                'revealed' => $round['revealed'],
                'multiplier' => $mult,
                'next' => self::multiplier($round['mines'], $safe + 1, $rtp),
                'win' => $safe > 0 ? (int) floor($round['bet'] * $mult) : 0,
            ];
        }
        if ($s->state['last'] !== null) {
            $state['last'] = $s->state['last'];
        }
        $s->send($state + $extra);
    }

    /** @return array{id:int, bet:int, mines:int, positions:list<int>, revealed:list<int>} */
    private function round(GameSession $s): array
    {
        $round = $s->state['round'] ?? null;
        if ($round === null) {
            throw new UserError('Primero apostá.');
        }
        return $round;
    }

    /** @return array{int, int} */
    private function mineLimits(): array
    {
        $min = max(1, $this->config->int('games.minas.min_mines', 1));
        $max = min(self::CELLS - 1, $this->config->int('games.minas.max_mines', 24));
        return [$min, $max];
    }

    private function rtp(): float
    {
        return $this->config->float('games.minas.rtp', 0.97);
    }

    /**
     * @param list<int> $cells
     * @return list<int>
     */
    private function secureShuffle(array $cells): array
    {
        for ($i = count($cells) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$cells[$i], $cells[$j]] = [$cells[$j], $cells[$i]];
        }
        return $cells;
    }
}
