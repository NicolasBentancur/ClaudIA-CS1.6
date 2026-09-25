<?php

declare(strict_types=1);

namespace Claudia\Economy;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Log;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Bancos, préstamos, recargos por atraso y Clearing.
 *
 * Modelo de préstamo: se devuelve capital + interés (tasa del plazo elegido) antes del
 * vencimiento; se puede pagar en partes cuando quieras. Pasado el vencimiento, cada día de
 * atraso suma un recargo sobre lo adeudado (compuesto). Con N días de atraso (5 por
 * defecto) el usuario entra al Clearing: no puede pedir más préstamos y se le confisca
 * parte de las ganancias de los juegos. Sale del Clearing cuando no debe nada.
 * Todo corre en tiempo real, aunque el usuario esté desconectado.
 */
final class LoanService
{
    private const DAY = 86400;

    /** @var callable(int):?array{job:string, level:int, salary:int} */
    private $jobInfo;

    /** @var callable(int):int segundos jugados */
    private $playtime;

    /** @var callable(int $userId, string $message):void */
    private $notify;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Wallet $wallet,
        private readonly PromotionService $promos,
    ) {
        $this->jobInfo = static fn (int $u) => null;
        $this->playtime = static fn (int $u) => 0;
        $this->notify = static function (int $u, string $m): void {
        };
    }

    public function setJobInfo(callable $fn): void
    {
        $this->jobInfo = $fn;
    }

    public function setPlaytime(callable $fn): void
    {
        $this->playtime = $fn;
    }

    public function setNotifier(callable $fn): void
    {
        $this->notify = $fn;
    }

    /** @return array<string, array> */
    public function banks(): array
    {
        $out = [];
        foreach ($this->config->array('banks.banks') as $id => $bank) {
            if (is_array($bank) && ($bank['enabled'] ?? true)) {
                $out[(string) $id] = $bank;
            }
        }
        return $out;
    }

    public function bank(string $id): array
    {
        $id = mb_strtolower($id);
        $banks = $this->banks();
        if (!isset($banks[$id])) {
            throw new UserError('Ese banco no existe. Bancos: ' . implode(', ', array_keys($banks)) . '.');
        }
        return $banks[$id];
    }

    /** Crea las filas de liquidez que falten (al arrancar). */
    public function syncBanks(): void
    {
        foreach ($this->banks() as $id => $bank) {
            $this->db->exec(
                'INSERT OR IGNORE INTO banks(id, liquidity, updated_at) VALUES(?, ?, ?)',
                [$id, (int) ($bank['liquidity'] ?? 0), Clock::now()]
            );
        }
    }

    public function liquidity(string $bankId): int
    {
        return (int) $this->db->value('SELECT liquidity FROM banks WHERE id = ?', [$bankId]);
    }

    public function inClearing(int $userId): bool
    {
        return $this->db->value('SELECT 1 FROM clearing WHERE user_id = ?', [$userId]) !== null;
    }

    public function clearingSince(int $userId): ?int
    {
        $v = $this->db->value('SELECT since FROM clearing WHERE user_id = ?', [$userId]);
        return $v === null ? null : (int) $v;
    }

    public function totalDebt(int $userId): int
    {
        return (int) $this->db->value("SELECT COALESCE(SUM(outstanding), 0) FROM loans WHERE user_id = ? AND status = 'active'", [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public function activeLoans(int $userId): array
    {
        return $this->db->all("SELECT * FROM loans WHERE user_id = ? AND status = 'active' ORDER BY due_at, id", [$userId]);
    }

    /** Tasa efectiva de un plazo, con promociones. */
    public function effectiveRate(string $bankId, array $term): float
    {
        return (float) $term['rate'] * $this->promos->multiplier('bank_rate', $bankId);
    }

    public function effectiveLateRate(string $bankId, array $bank): float
    {
        return (float) ($bank['late_daily_rate'] ?? 0.02) * $this->promos->multiplier('bank_late_rate', $bankId);
    }

    /** Máximo que ese banco le presta a ese usuario ahora (0 si no le presta). */
    public function maxFor(int $userId, string $bankId): int
    {
        $bank = $this->bank($bankId);
        if ($this->inClearing($userId)) {
            return 0;
        }
        $max = (int) floor((int) ($bank['max_amount'] ?? 0) * $this->promos->multiplier('bank_max', $bankId));
        $job = ($this->jobInfo)($userId);
        if ($job === null) {
            if (($bank['unemployed_max'] ?? null) === null) {
                return 0;
            }
            $max = min($max, (int) $bank['unemployed_max']);
        } elseif (isset($bank['salary_multiplier'])) {
            $max = min($max, (int) floor($job['salary'] * (float) $bank['salary_multiplier']));
        }
        return max(0, min($max, $this->liquidity($bankId)));
    }

    /**
     * Pide un préstamo. Devuelve la fila creada.
     * @return array<string,mixed>
     */
    public function request(int $userId, string $bankId, int $amount, int $termDays): array
    {
        $bankId = mb_strtolower($bankId);
        $bank = $this->bank($bankId);
        if ($this->inClearing($userId)) {
            throw new UserError('Estás en el Clearing: ningún banco te presta hasta que pagues todo lo que debés.', 'clearing');
        }
        $term = null;
        foreach ((array) ($bank['terms'] ?? []) as $t) {
            if ((int) $t['days'] === $termDays) {
                $term = $t;
            }
        }
        if ($term === null) {
            $days = implode(', ', array_map(fn ($t) => $t['days'], (array) ($bank['terms'] ?? [])));
            throw new UserError("{$bank['name']} presta a estos plazos (días): {$days}.");
        }
        $req = (array) ($bank['requirements'] ?? []);
        $minHours = (float) ($req['min_playtime_hours'] ?? 0);
        if ($minHours > 0 && ($this->playtime)($userId) < $minHours * 3600) {
            throw new UserError("{$bank['name']} pide al menos {$minHours} horas jugadas en el servidor.");
        }
        $job = ($this->jobInfo)($userId);
        if (($req['requires_job'] ?? false) && $job === null) {
            throw new UserError("{$bank['name']} solo le presta a gente con trabajo. Buscate uno: /trabajos");
        }
        if (isset($req['min_job_level']) && ($job === null || $job['level'] + 1 < (int) $req['min_job_level'])) {
            throw new UserError("{$bank['name']} pide nivel {$req['min_job_level']} o más en tu trabajo.");
        }
        $active = (int) $this->db->value("SELECT COUNT(*) FROM loans WHERE user_id = ? AND bank = ? AND status = 'active'", [$userId, $bankId]);
        if ($active >= (int) ($bank['max_active_loans'] ?? 1)) {
            throw new UserError("Ya tenés un préstamo activo con {$bank['name']}. Pagalo primero.");
        }
        $min = (int) ($bank['min_amount'] ?? 1);
        $max = $this->maxFor($userId, $bankId);
        if ($max < $min) {
            throw new UserError("{$bank['name']} no te puede prestar ahora (disponible: " . Text::coins($max) . ').');
        }
        if ($amount < $min || $amount > $max) {
            throw new UserError("{$bank['name']} te presta entre " . Text::coins($min) . ' y ' . Text::coins($max) . ' URU Coins.');
        }
        $rate = $this->effectiveRate($bankId, $term);
        $interest = (int) ceil($amount * $rate);
        $now = Clock::now();
        return $this->db->transaction(function () use ($userId, $bankId, $bank, $amount, $interest, $rate, $termDays, $now): array {
            $id = $this->db->insert(
                'INSERT INTO loans(user_id, bank, principal, interest, outstanding, rate, late_daily_rate, term_days, created_at, due_at)
                 VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $bankId, $amount, $interest, $amount + $interest, $rate, $this->effectiveLateRate($bankId, $bank), $termDays, $now, $now + $termDays * self::DAY]
            );
            $this->db->exec('UPDATE banks SET liquidity = liquidity - ?, updated_at = ? WHERE id = ?', [$amount, $now, $bankId]);
            $this->wallet->credit($userId, $amount, 'loan', "{$bank['name']} #{$id}");
            Log::info('Préstamo otorgado', ['user' => $userId, 'bank' => $bankId, 'monto' => $amount, 'interes' => $interest]);
            return (array) $this->db->one('SELECT * FROM loans WHERE id = ?', [$id]);
        });
    }

    /**
     * Paga deuda. $bankId null = empieza por lo más vencido. $amount null = todo lo posible.
     * Si $fromWallet es false, el dinero no se descuenta del saldo (ya se descontó antes, ej. confiscación).
     * Devuelve lo efectivamente pagado.
     */
    public function pay(int $userId, ?string $bankId, ?int $amount, string $kind = 'loan_payment', bool $fromWallet = true): int
    {
        $loans = $this->activeLoans($userId);
        if ($bankId !== null) {
            $bankId = mb_strtolower($bankId);
            $this->bank($bankId);
            $loans = array_values(array_filter($loans, fn ($l) => $l['bank'] === $bankId));
        }
        if ($loans === []) {
            throw new UserError($bankId === null ? 'No debés nada.' : 'No tenés deuda con ese banco.', 'no_debt');
        }
        $owed = array_sum(array_map(fn ($l) => (int) $l['outstanding'], $loans));
        $budget = $amount ?? $owed;
        if ($fromWallet) {
            $budget = min($budget, $this->wallet->balance($userId));
        }
        $budget = min($budget, $owed);
        if ($budget <= 0) {
            throw new UserError('No tenés URU Coins para pagar.', 'insufficient_funds');
        }
        $now = Clock::now();
        $paid = $this->db->transaction(function () use ($userId, $loans, $budget, $kind, $fromWallet, $now): int {
            $left = $budget;
            foreach ($loans as $loan) {
                if ($left <= 0) {
                    break;
                }
                $part = min($left, (int) $loan['outstanding']);
                $remaining = (int) $loan['outstanding'] - $part;
                $this->db->exec(
                    'UPDATE loans SET outstanding = ?, status = ?, paid_at = ? WHERE id = ?',
                    [$remaining, $remaining === 0 ? 'paid' : 'active', $remaining === 0 ? $now : null, $loan['id']]
                );
                $this->db->exec('UPDATE banks SET liquidity = liquidity + ?, updated_at = ? WHERE id = ?', [$part, $now, $loan['bank']]);
                $left -= $part;
            }
            $paid = $budget - $left;
            if ($fromWallet) {
                $this->wallet->debit($userId, $paid, $kind, 'pago de deuda');
            }
            return $paid;
        });
        $this->checkClearingExit($userId);
        return $paid;
    }

    /**
     * Confiscación del Clearing sobre una ganancia de juego ya acreditada.
     * Devuelve lo confiscado (0 si no está en el Clearing).
     */
    public function confiscateProfit(int $userId, int $profit, string $source): int
    {
        if ($profit <= 0 || !$this->inClearing($userId)) {
            return 0;
        }
        $rate = $this->config->float('economy.clearing.confiscation_rate', 0.5);
        $amount = min((int) floor($profit * $rate), $this->totalDebt($userId));
        if ($amount <= 0) {
            return 0;
        }
        $this->db->transaction(function () use ($userId, $amount, $source): void {
            $this->wallet->debit($userId, $amount, 'confiscation', "Clearing ({$source})");
            $this->pay($userId, null, $amount, 'confiscation', false);
        });
        return $amount;
    }

    /** Scheduler: recargos diarios por atraso, entrada al Clearing y regeneración de liquidez. */
    public function tick(): void
    {
        $now = Clock::now();
        $threshold = $this->config->int('economy.clearing.days_overdue', 5);
        $rows = $this->db->all("SELECT * FROM loans WHERE status = 'active' AND due_at < ?", [$now]);
        foreach ($rows as $loan) {
            $daysLate = intdiv($now - (int) $loan['due_at'], self::DAY);
            $pending = $daysLate - (int) $loan['surcharge_days'];
            if ($pending > 0) {
                $outstanding = (int) $loan['outstanding'];
                $added = 0;
                for ($i = 0; $i < $pending; $i++) {
                    $s = (int) ceil($outstanding * (float) $loan['late_daily_rate']);
                    $outstanding += $s;
                    $added += $s;
                }
                $this->db->exec(
                    'UPDATE loans SET outstanding = ?, surcharges = surcharges + ?, surcharge_days = ? WHERE id = ?',
                    [$outstanding, $added, $daysLate, $loan['id']]
                );
            }
            $uid = (int) $loan['user_id'];
            if ($daysLate >= $threshold && !$this->inClearing($uid)) {
                $this->db->exec('INSERT OR IGNORE INTO clearing(user_id, since) VALUES(?, ?)', [$uid, $now]);
                Log::info('Usuario entra al Clearing', ['user' => $uid, 'loan' => $loan['id']]);
                ($this->notify)($uid, "Entraste al {green}Clearing{default} por {$daysLate} días de atraso con " . $this->bankName((string) $loan['bank'])
                    . '. Nadie te presta y te confiscamos parte de lo que ganes en los juegos hasta que pagues todo (/deuda).');
            }
        }
        $this->regenLiquidity($now);
    }

    public function bankName(string $id): string
    {
        return (string) ($this->banks()[$id]['name'] ?? strtoupper($id));
    }

    private function checkClearingExit(int $userId): void
    {
        if ($this->inClearing($userId) && $this->totalDebt($userId) === 0) {
            $this->db->exec('DELETE FROM clearing WHERE user_id = ?', [$userId]);
            Log::info('Usuario sale del Clearing', ['user' => $userId]);
            ($this->notify)($userId, 'Pagaste todo. {green}Saliste del Clearing{default}, ya podés volver a pedir préstamos.');
        }
    }

    private function regenLiquidity(int $now): void
    {
        $last = (int) ($this->db->kvGet('banks.last_regen', '0') ?? 0);
        if ($last === 0) {
            $this->db->kvSet('banks.last_regen', (string) $now);
            return;
        }
        $days = intdiv($now - $last, self::DAY);
        if ($days <= 0) {
            return;
        }
        foreach ($this->banks() as $id => $bank) {
            $cap = (int) ($bank['liquidity'] ?? 0);
            $regen = (int) floor($cap * (float) ($bank['liquidity_regen_per_day'] ?? 0.1) * $days);
            // Solo regenera hasta la liquidez inicial; si por intereses cobrados tiene más, no se toca.
            $this->db->exec(
                'UPDATE banks SET liquidity = CASE WHEN liquidity >= ? THEN liquidity ELSE MIN(?, liquidity + ?) END, updated_at = ? WHERE id = ?',
                [$cap, $cap, $regen, $now, $id]
            );
        }
        $this->db->kvSet('banks.last_regen', (string) ($last + $days * self::DAY));
    }
}
