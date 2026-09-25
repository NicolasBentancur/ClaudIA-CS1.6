<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

/**
 * "Dulce de Leche Bonanza": 6 columnas x 5 filas, paga con 8 o más símbolos iguales en
 * cualquier lugar. Los ganadores explotan y caen nuevos (cascada) hasta que no haya premio.
 *
 *  - Chupetín (scatter): con 4 o más al final de la cascada paga y da giros gratis.
 *    En los giros gratis 3 o más chupetines suman giros.
 *  - Bombón multiplicador (solo en giros gratis): queda en pantalla y al terminar la cascada
 *    de ese giro, si hubo premio, se multiplica por la suma de todos los bombones.
 *
 * Los pagos están en múltiplos de la apuesta total.
 */
final class DulceEngine implements SlotEngine
{
    public const SCATTER = 'S';
    public const BOMB = 'X';

    public function __construct(private readonly array $m)
    {
    }

    public function layout(): array
    {
        return [
            'cols' => $this->m['cols'],
            'rows' => $this->m['rows'],
            'symbols' => array_keys($this->m['symbols']),
            'min' => $this->m['min_count'],
        ];
    }

    public function spin(Rng $rng, ?string $buy = null): array
    {
        $steps = [];
        $summary = [];
        $win = 0.0;
        $bonus = null;
        if ($buy === 'giros') {
            $bonus = 'giros';
            $win += $this->freeSpins($rng, $steps, $summary, (int) $this->m['scatter']['spins']);
        } else {
            $res = $this->playSpin($rng, false, $steps);
            $win += $res['win'];
            if ($res['scatters'] >= (int) $this->m['scatter']['trigger']) {
                $bonus = 'giros';
                $win += $this->freeSpins($rng, $steps, $summary, (int) $this->m['scatter']['spins']);
            }
        }
        if ($win > 0 && $bonus === null) {
            $summary[] = 'Ganó ' . round($win, 1) . ' veces la apuesta en las cascadas.';
        }
        return ['win' => $win, 'steps' => $steps, 'bonus' => $bonus, 'summary' => implode(' ', $summary)];
    }

    /**
     * Un giro con todas sus cascadas.
     * @return array{win: float, scatters: int}
     */
    public function playSpin(Rng $rng, bool $free, array &$steps, array $fsInfo = []): array
    {
        $draw = fn (int $col): string => $this->drawCell($rng, $free);
        $grid = [];
        for ($c = 0; $c < $this->m['cols']; $c++) {
            for ($r = 0; $r < $this->m['rows']; $r++) {
                $grid[$c][$r] = $draw($c);
            }
        }
        $steps[] = ['t' => 'drop', 'grid' => $grid, 'free' => $free] + ($fsInfo ? ['fs' => $fsInfo] : []);

        $isPay = fn (string $cell): bool => isset($this->m['symbols'][$cell]);
        $spinWin = 0.0;
        $guard = 100;
        while ($guard-- > 0) {
            $groups = Cascade::anywhere($grid, (int) $this->m['min_count'], $isPay);
            if ($groups === []) {
                break;
            }
            $wins = [];
            $remove = [];
            foreach ($groups as $sym => $cells) {
                $pay = Cascade::tieredPay($this->m['symbols'][$sym]['pays'], count($cells));
                $spinWin += $pay;
                $wins[] = ['sym' => $sym, 'count' => count($cells), 'cells' => $cells, 'win' => $pay];
                array_push($remove, ...$cells);
            }
            $steps[] = ['t' => 'pay', 'wins' => $wins, 'total' => round($spinWin, 4)];
            $grid = Cascade::tumble($grid, $remove, $draw);
            $steps[] = ['t' => 'tumble', 'removed' => $remove, 'grid' => $grid];
        }

        if ($free && $spinWin > 0) {
            $bombs = [];
            $sum = 0.0;
            foreach ($grid as $c => $col) {
                foreach ($col as $r => $cell) {
                    if ($cell[0] === self::BOMB) {
                        $bombs[] = ['col' => $c, 'row' => $r, 'v' => Cascade::value($cell)];
                        $sum += Cascade::value($cell);
                    }
                }
            }
            if ($sum > 0) {
                $before = $spinWin;
                $spinWin *= $sum;
                $steps[] = ['t' => 'bombs', 'bombs' => $bombs, 'sum' => $sum, 'before' => round($before, 4), 'after' => round($spinWin, 4)];
            }
        }

        $scatters = 0;
        foreach ($grid as $col) {
            foreach ($col as $cell) {
                if ($cell === self::SCATTER) {
                    $scatters++;
                }
            }
        }
        if (!$free) {
            $scatterPay = Cascade::tieredPay($this->m['scatter']['pays'], $scatters);
            if ($scatterPay > 0 && $scatters >= (int) $this->m['scatter']['trigger']) {
                $spinWin += $scatterPay;
                $steps[] = ['t' => 'scatter', 'count' => $scatters, 'win' => $scatterPay];
            }
        }
        $steps[] = ['t' => 'spin_end', 'win' => round($spinWin, 4)];
        return ['win' => $spinWin, 'scatters' => $scatters];
    }

    private function drawCell(Rng $rng, bool $free): string
    {
        $w = [];
        $fsWeights = $free ? (array) ($this->m['fs_weights'] ?? []) : [];
        foreach ($this->m['symbols'] as $sym => $def) {
            // En los giros gratis se pueden usar otros pesos (más símbolos baratos = menos giros en cero).
            $w[$sym] = (float) ($fsWeights[$sym] ?? $def['weight']);
        }
        $w[self::SCATTER] = (float) ($free ? $this->m['scatter']['fs_weight'] : $this->m['scatter']['weight']);
        if ($free) {
            $w[self::BOMB] = (float) $this->m['bomb']['fs_weight'];
        }
        $sym = (string) $rng->pick(array_filter($w, fn ($x) => $x > 0));
        if ($sym === self::BOMB) {
            return self::BOMB . $rng->pick($this->m['bomb']['values']);
        }
        return $sym;
    }

    private function freeSpins(Rng $rng, array &$steps, array &$summary, int $spins): float
    {
        $sc = $this->m['scatter'];
        $left = $spins;
        $total = 0.0;
        $played = 0;
        $steps[] = ['t' => 'fs_start', 'spins' => $left];
        $limit = (int) ($sc['max_spins'] ?? 100);
        while ($left > 0 && $played < $limit) {
            $left--;
            $played++;
            $res = $this->playSpin($rng, true, $steps, ['n' => $played, 'left' => $left]);
            $total += $res['win'];
            if ($res['scatters'] >= (int) $sc['retrigger_count']) {
                $left += (int) $sc['retrigger'];
                $steps[] = ['t' => 'fs_retrigger', 'add' => (int) $sc['retrigger'], 'left' => $left];
            }
        }
        $steps[] = ['t' => 'fs_end', 'win' => round($total, 4), 'played' => $played];
        $summary[] = "Entró a los giros gratis ({$played} giros) y sacó " . round($total, 1) . ' veces la apuesta.';
        return $total;
    }
}
