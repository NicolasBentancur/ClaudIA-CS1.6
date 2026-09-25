<?php

declare(strict_types=1);

namespace Claudia\Economy;

use Claudia\Clock;
use Claudia\Db;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Saldo de URU Coins. Cada movimiento queda registrado en transactions.
 */
final class Wallet
{
    public function __construct(private readonly Db $db)
    {
    }

    public function balance(int $userId): int
    {
        return (int) $this->db->value('SELECT coins FROM users WHERE id = ?', [$userId]);
    }

    public function credit(int $userId, int $amount, string $kind, ?string $ref = null): int
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('credit con monto negativo');
        }
        return $this->apply($userId, $amount, $kind, $ref, false);
    }

    /** Descuenta; lanza UserError si no alcanza el saldo. */
    public function debit(int $userId, int $amount, string $kind, ?string $ref = null): int
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('debit con monto negativo');
        }
        return $this->apply($userId, -$amount, $kind, $ref, false);
    }

    /** Ajuste administrativo: puede dejar el saldo en 0 como mínimo (nunca negativo). Devuelve [nuevoSaldo, montoAplicado]. */
    public function adminAdjust(int $userId, int $delta, string $ref): array
    {
        return $this->db->transaction(function () use ($userId, $delta, $ref): array {
            $balance = $this->balance($userId);
            $applied = $delta < 0 ? -min($balance, -$delta) : $delta;
            $new = $this->apply($userId, $applied, 'admin', $ref, false);
            return [$new, $applied];
        });
    }

    public function transfer(int $from, int $to, int $amount, int $fee, string $fromNick, string $toNick): void
    {
        if ($from === $to) {
            throw new UserError('No te podés transferir a vos mismo.');
        }
        $this->db->transaction(function () use ($from, $to, $amount, $fee, $fromNick, $toNick): void {
            $this->apply($from, -($amount + $fee), 'transfer_out', "a {$toNick}" . ($fee > 0 ? " (comisión {$fee})" : ''), false);
            $this->apply($to, $amount, 'transfer_in', "de {$fromNick}", false);
        });
    }

    /** @return list<array{kind:string, amount:int, balance_after:int, ref:?string, created_at:int}> */
    public function history(int $userId, int $limit = 10): array
    {
        return $this->db->all('SELECT kind, amount, balance_after, ref, created_at FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT ?', [$userId, $limit]);
    }

    private function apply(int $userId, int $delta, string $kind, ?string $ref, bool $allowNegative): int
    {
        return $this->db->transaction(function () use ($userId, $delta, $kind, $ref, $allowNegative): int {
            $balance = $this->balance($userId);
            $new = $balance + $delta;
            if ($new < 0 && !$allowNegative) {
                throw new UserError('No te alcanza. Tenés ' . Text::coins($balance) . ' URU Coins.', 'insufficient_funds');
            }
            $this->db->exec('UPDATE users SET coins = ? WHERE id = ?', [$new, $userId]);
            $this->db->exec(
                'INSERT INTO transactions(user_id, kind, amount, balance_after, ref, created_at) VALUES(?, ?, ?, ?, ?, ?)',
                [$userId, $kind, $delta, $new, $ref, Clock::now()]
            );
            return $new;
        });
    }
}
