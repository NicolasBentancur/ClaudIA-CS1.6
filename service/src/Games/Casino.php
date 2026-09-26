<?php

declare(strict_types=1);

namespace Claudia\Games;

use Claudia\Ai\RecentGames;
use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Economy\LoanService;
use Claudia\Economy\Wallet;
use Claudia\Events;
use Claudia\Log;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Dinero de los juegos: abre rondas (descuenta la apuesta), las liquida (paga, aplica la
 * confiscación del Clearing, actualiza ganancias/pérdidas y el "juego reciente" para la IA)
 * y reembolsa las rondas que quedaron abiertas si el servicio se reinició.
 */
final class Casino
{
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Wallet $wallet,
        private readonly LoanService $loans,
        private readonly RecentGames $recent,
        private readonly Events $events,
    ) {
    }

    public function minBet(): int
    {
        return $this->config->int('economy.bet.min', 10);
    }

    public function maxBet(): int
    {
        return $this->config->int('economy.bet.max', 10000);
    }

    public function validateTotal(int $total): void
    {
        if ($total < $this->minBet() || $total > $this->maxBet()) {
            throw new UserError('La apuesta tiene que estar entre ' . Text::coins($this->minBet()) . ' y ' . Text::coins($this->maxBet()) . ' URU Coins.', 'bet_limits');
        }
    }

    /**
     * Denominaciones de fichas escaladas al saldo del jugador.
     * @return list<int>
     */
    public function chipsFor(int $balance): array
    {
        $cfg = $this->config->array('economy.chips');
        $denoms = array_map('intval', (array) ($cfg['denominations'] ?? [10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000]));
        sort($denoms);
        $count = (int) ($cfg['count'] ?? 6);
        $fraction = (float) ($cfg['balance_fraction'] ?? 0.25);
        $top = min($this->maxBet(), max($this->minBet(), (int) floor($balance * $fraction)));
        $eligible = array_values(array_filter($denoms, fn (int $d) => $d >= $this->minBet() && $d <= $top));
        if ($eligible === []) {
            $eligible = [$this->minBet()];
        }
        return array_slice($eligible, -$count);
    }

    /** Descuenta la apuesta y registra la ronda abierta. Devuelve el id de ronda. */
    public function openRound(int $userId, string $game, int $bet, array $detail = []): int
    {
        return $this->db->transaction(function () use ($userId, $game, $bet, $detail): int {
            $this->wallet->debit($userId, $bet, 'game_bet', $game);
            return $this->db->insert(
                'INSERT INTO game_rounds(user_id, game, bet, detail, created_at) VALUES(?, ?, ?, ?, ?)',
                [$userId, $game, $bet, json_encode($detail, JSON_UNESCAPED_UNICODE), Clock::now()]
            );
        });
    }

    /** Suma apuesta a una ronda abierta (doblar / dividir en blackjack). */
    public function raiseRound(int $roundId, int $userId, int $extra): void
    {
        $this->db->transaction(function () use ($roundId, $userId, $extra): void {
            $round = $this->round($roundId);
            if ($round['status'] !== 'open') {
                throw new \LogicException('La ronda no está abierta');
            }
            $this->wallet->debit($userId, $extra, 'game_bet', (string) $round['game']);
            $this->db->exec('UPDATE game_rounds SET bet = bet + ? WHERE id = ?', [$extra, $roundId]);
        });
    }

    /**
     * Liquida la ronda. Con $publish en false todavía no la anuncia (chat, contexto de la IA): el juego
     * liquida apenas se decide el resultado, llama a publish() cuando la página termina de mostrarlo, y
     * así un reinicio en medio de la animación no reembolsa una ronda que el jugador ya vio.
     * @return array{balance:int, net:int, confiscated:int, bet:int, payout:int, user:int, game:string, text:string}
     */
    public function settle(int $roundId, int $payout, string $summary, array $detail = [], bool $publish = true): array
    {
        $round = $this->round($roundId);
        if ($round['status'] !== 'open') {
            throw new \LogicException("La ronda {$roundId} ya fue liquidada");
        }
        $userId = (int) $round['user_id'];
        $bet = (int) $round['bet'];
        $game = (string) $round['game'];
        $net = $payout - $bet;
        $confiscated = $this->db->transaction(function () use ($roundId, $userId, $payout, $net, $game, $detail): int {
            if ($payout > 0) {
                $this->wallet->credit($userId, $payout, 'game_payout', $game);
            }
            $this->db->exec(
                "UPDATE game_rounds SET payout = ?, status = 'settled', settled_at = ?, detail = ? WHERE id = ?",
                [$payout, Clock::now(), json_encode($detail, JSON_UNESCAPED_UNICODE), $roundId]
            );
            if ($net > 0) {
                $this->db->exec('UPDATE users SET total_won = total_won + ? WHERE id = ?', [$net, $userId]);
            } elseif ($net < 0) {
                $this->db->exec('UPDATE users SET total_lost = total_lost + ? WHERE id = ?', [-$net, $userId]);
            }
            return $this->loans->confiscateProfit($userId, $net, $game);
        });

        $text = $summary . ' Apostó ' . Text::coins($bet) . ', ' . ($net > 0 ? 'ganó ' . Text::coins($net) : ($net < 0 ? 'perdió ' . Text::coins(-$net) : 'recuperó lo apostado')) . ' URU Coins.';
        if ($confiscated > 0) {
            $text .= ' Por estar en el Clearing le confiscaron ' . Text::coins($confiscated) . '.';
        }
        $settled = [
            'balance' => $this->wallet->balance($userId),
            'net' => $net,
            'confiscated' => $confiscated,
            'bet' => $bet,
            'payout' => $payout,
            'user' => $userId,
            'game' => $game,
            'text' => $text,
        ];
        if ($publish) {
            $this->publish($settled);
        }
        return $settled;
    }

    /**
     * Anuncia una ronda ya liquidada: queda como "juego reciente" para la IA y dispara game.finished
     * (los avisos de ganancias y pérdidas grandes en el chat).
     * @param array{net:int, bet:int, payout:int, user:int, game:string, text:string} $settled lo que devolvió settle()
     */
    public function publish(array $settled): void
    {
        $this->recent->record($settled['user'], $settled['game'], $settled['text'], Clock::now());
        $this->events->emit('game.finished', $settled['user'], $settled['game'], $settled['bet'], $settled['payout'], $settled['net']);
    }

    /** Al arrancar: devuelve la apuesta de las rondas que quedaron abiertas. */
    public function refundOpenRounds(): int
    {
        $rows = $this->db->all("SELECT * FROM game_rounds WHERE status = 'open'");
        foreach ($rows as $r) {
            $this->db->transaction(function () use ($r): void {
                $this->wallet->credit((int) $r['user_id'], (int) $r['bet'], 'game_payout', 'reembolso ' . $r['game']);
                $this->db->exec("UPDATE game_rounds SET status = 'refunded', settled_at = ? WHERE id = ?", [Clock::now(), $r['id']]);
            });
        }
        if ($rows !== []) {
            Log::warning('Rondas abiertas reembolsadas al arrancar', ['cantidad' => count($rows)]);
        }
        return count($rows);
    }

    /** @return array<string,mixed> */
    private function round(int $id): array
    {
        $r = $this->db->one('SELECT * FROM game_rounds WHERE id = ?', [$id]);
        if ($r === null) {
            throw new \LogicException("No existe la ronda {$id}");
        }
        return $r;
    }
}
