<?php

declare(strict_types=1);

namespace Claudia\Games\Roulette;

use Claudia\UserError;

/**
 * Validación y pago de las apuestas de la ruleta francesa.
 *
 * Apuestas simples (los números se mandan tal cual):
 *   pleno (1 número, 35:1)  caballo (2, 17:1)  transversal (3, 11:1)  cuadro (4, 8:1)
 *   seisena (6, 5:1)        docena (12, 2:1)   columna (12, 2:1)
 *   rojo / negro / par / impar / falta (1-18) / pasa (19-36)  (1:1, con La Partage en el 0)
 *
 * Apuestas anunciadas (se expanden en varias fichas de "unit" cada una):
 *   vecinos_cero (9 fichas)  tercio (6)  huerfanos (5)  juego_cero (4)  vecinos (5 plenos alrededor de "number")
 */
final class Bets
{
    public const EVEN_MONEY = ['rojo', 'negro', 'par', 'impar', 'falta', 'pasa'];

    private const SIZES = ['pleno' => 1, 'caballo' => 2, 'transversal' => 3, 'cuadro' => 4, 'seisena' => 6];

    /** @var array<string, array<string, true>>|null */
    private static ?array $valid = null;

    /**
     * Expande y valida lo que manda la página.
     * @param list<array{type:string, numbers?:list<int>, amount?:int, unit?:int, number?:int, which?:int}> $raw
     * @return list<array{type:string, numbers:list<int>, amount:int}>
     */
    public static function normalize(array $raw, int $maxBets = 200): array
    {
        if ($raw === []) {
            throw new UserError('No pusiste ninguna ficha.', 'no_bets');
        }
        $out = [];
        foreach ($raw as $bet) {
            if (!is_array($bet)) {
                throw new UserError('Apuesta inválida.', 'bad_bet');
            }
            $type = (string) ($bet['type'] ?? '');
            foreach (self::expand($type, $bet) as $b) {
                $out[] = $b;
            }
            if (count($out) > $maxBets) {
                throw new UserError('Demasiadas fichas en la mesa.', 'too_many_bets');
            }
        }
        return $out;
    }

    /** @param list<array{type:string, numbers:list<int>, amount:int}> $bets */
    public static function total(array $bets): int
    {
        return array_sum(array_map(fn ($b) => $b['amount'], $bets));
    }

    /**
     * Lo que devuelve la mesa (apuesta incluida) para un resultado.
     * @param list<array{type:string, numbers:list<int>, amount:int}> $bets
     */
    public static function payout(array $bets, int $result): int
    {
        $total = 0;
        foreach ($bets as $b) {
            $total += self::betReturn($b, $result);
        }
        return $total;
    }

    /** @param array{type:string, numbers:list<int>, amount:int} $b */
    public static function betReturn(array $b, int $result): int
    {
        if (in_array($b['type'], self::EVEN_MONEY, true) && $result === 0) {
            return intdiv($b['amount'], 2); // La Partage
        }
        if (!in_array($result, $b['numbers'], true)) {
            return 0;
        }
        $k = count($b['numbers']);
        $multiplier = intdiv(36, $k) - 1; // 35, 17, 11, 8, 5, 2, 1
        return $b['amount'] * ($multiplier + 1);
    }

    /** @return list<array{type:string, numbers:list<int>, amount:int}> */
    private static function expand(string $type, array $bet): array
    {
        switch ($type) {
            case 'pleno':
            case 'caballo':
            case 'transversal':
            case 'cuadro':
            case 'seisena':
                $numbers = self::numbers($bet['numbers'] ?? null);
                if (count($numbers) !== self::SIZES[$type] || !isset(self::valid()[$type][self::key($numbers)])) {
                    throw new UserError('Esa combinación no es válida para ' . $type . '.', 'bad_bet');
                }
                return [self::make($type, $numbers, $bet['amount'] ?? null)];
            case 'docena':
                $w = self::which($bet, 3);
                return [self::make($type, range(12 * ($w - 1) + 1, 12 * $w), $bet['amount'] ?? null)];
            case 'columna':
                $w = self::which($bet, 3);
                return [self::make($type, array_values(array_filter(range(1, 36), fn ($n) => ($n - $w) % 3 === 0)), $bet['amount'] ?? null)];
            case 'rojo':
                return [self::make($type, Wheel::RED, $bet['amount'] ?? null)];
            case 'negro':
                return [self::make($type, array_values(array_diff(range(1, 36), Wheel::RED)), $bet['amount'] ?? null)];
            case 'par':
                return [self::make($type, range(2, 36, 2), $bet['amount'] ?? null)];
            case 'impar':
                return [self::make($type, range(1, 35, 2), $bet['amount'] ?? null)];
            case 'falta':
                return [self::make($type, range(1, 18), $bet['amount'] ?? null)];
            case 'pasa':
                return [self::make($type, range(19, 36), $bet['amount'] ?? null)];
            case 'vecinos_cero':
                return self::announced([[0, 2, 3], [0, 2, 3], [4, 7], [12, 15], [18, 21], [19, 22], [25, 26, 28, 29], [25, 26, 28, 29], [32, 35]], $bet);
            case 'tercio':
                return self::announced([[5, 8], [10, 11], [13, 16], [23, 24], [27, 30], [33, 36]], $bet);
            case 'huerfanos':
                return self::announced([[1], [6, 9], [14, 17], [17, 20], [31, 34]], $bet);
            case 'juego_cero':
                return self::announced([[0, 3], [12, 15], [26], [32, 35]], $bet);
            case 'vecinos':
                $n = (int) ($bet['number'] ?? -1);
                if ($n < 0 || $n > 36) {
                    throw new UserError('Número inválido para vecinos.', 'bad_bet');
                }
                return self::announced(array_map(fn ($x) => [$x], Wheel::neighbours($n, 2)), $bet);
        }
        throw new UserError('Tipo de apuesta desconocido.', 'bad_bet');
    }

    /** @param list<list<int>> $groups */
    private static function announced(array $groups, array $bet): array
    {
        $unit = self::amount($bet['unit'] ?? null);
        $out = [];
        foreach ($groups as $g) {
            sort($g);
            $type = array_search(count($g), self::SIZES, true);
            $out[] = ['type' => (string) $type, 'numbers' => $g, 'amount' => $unit];
        }
        return $out;
    }

    private static function make(string $type, array $numbers, mixed $amount): array
    {
        sort($numbers);
        return ['type' => $type, 'numbers' => array_values($numbers), 'amount' => self::amount($amount)];
    }

    private static function amount(mixed $amount): int
    {
        if (!is_int($amount) && !(is_string($amount) && ctype_digit($amount)) && !(is_float($amount) && floor($amount) === $amount)) {
            throw new UserError('Monto inválido.', 'bad_bet');
        }
        $a = (int) $amount;
        if ($a <= 0) {
            throw new UserError('Monto inválido.', 'bad_bet');
        }
        return $a;
    }

    private static function which(array $bet, int $max): int
    {
        $w = (int) ($bet['which'] ?? 0);
        if ($w < 1 || $w > $max) {
            throw new UserError('Apuesta inválida.', 'bad_bet');
        }
        return $w;
    }

    /** @return list<int> */
    private static function numbers(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new UserError('Apuesta inválida.', 'bad_bet');
        }
        $nums = [];
        foreach ($raw as $n) {
            if (!is_int($n) || $n < 0 || $n > 36) {
                throw new UserError('Número inválido.', 'bad_bet');
            }
            $nums[] = $n;
        }
        sort($nums);
        return array_values(array_unique($nums));
    }

    private static function key(array $numbers): string
    {
        return implode('-', $numbers);
    }

    /** Combinaciones válidas de la mesa (3 filas x 12 columnas + el cero). */
    private static function valid(): array
    {
        if (self::$valid !== null) {
            return self::$valid;
        }
        $v = ['pleno' => [], 'caballo' => [], 'transversal' => [], 'cuadro' => [], 'seisena' => []];
        for ($n = 0; $n <= 36; $n++) {
            $v['pleno'][(string) $n] = true;
        }
        for ($n = 1; $n <= 36; $n++) {
            if ($n % 3 !== 0) {
                $v['caballo'][self::key([$n, $n + 1])] = true;
            }
            if ($n <= 33) {
                $v['caballo'][self::key([$n, $n + 3])] = true;
            }
            if ($n % 3 !== 0 && $n <= 32) {
                $v['cuadro'][self::key([$n, $n + 1, $n + 3, $n + 4])] = true;
            }
        }
        foreach ([[0, 1], [0, 2], [0, 3]] as $s) {
            $v['caballo'][self::key($s)] = true;
        }
        for ($c = 0; $c < 12; $c++) {
            $v['transversal'][self::key([3 * $c + 1, 3 * $c + 2, 3 * $c + 3])] = true;
            if ($c < 11) {
                $v['seisena'][self::key(range(3 * $c + 1, 3 * $c + 6))] = true;
            }
        }
        $v['transversal'][self::key([0, 1, 2])] = true;
        $v['transversal'][self::key([0, 2, 3])] = true;
        $v['cuadro'][self::key([0, 1, 2, 3])] = true;
        return self::$valid = $v;
    }
}
