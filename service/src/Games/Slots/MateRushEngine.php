<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

/**
 * "Mate Rush": 5x5, paga por clusters (4 o más iguales conectados en horizontal/vertical) con
 * cascada.
 *
 *  - Multiplicadores de casilla: la primera vez que una casilla es parte de un premio queda
 *    marcada; cada premio siguiente sobre ella duplica su multiplicador (x2, x4 ... hasta x256,
 *    o x1024 en los giros gratis). Un cluster paga x la suma de los multiplicadores de sus casillas.
 *    En el juego base se reinician en cada giro; en los giros gratis se conservan.
 *  - Rayo (Rush): trae un multiplicador; si el giro tuvo premio se multiplica por la suma de los
 *    rayos en pantalla al final de la cascada.
 *  - Chispazo: a veces, en un giro sin premio, se arma un cluster al azar.
 *  - Sincronización (giros gratis): a veces copia el multiplicador más alto a otras casillas marcadas.
 *  - Sol (bonus): 3 o más dan giros gratis; en los giros gratis 2 o más suman giros.
 */
final class MateRushEngine implements SlotEngine
{
    public const BONUS = 'S';
    public const RUSH = 'R';

    public function __construct(private readonly array $m)
    {
    }

    public function layout(): array
    {
        return [
            'cols' => $this->m['cols'],
            'rows' => $this->m['rows'],
            'symbols' => array_keys($this->m['symbols']),
            'min' => $this->m['min_cluster'],
        ];
    }

    public function spin(Rng $rng, ?string $buy = null): array
    {
        $steps = [];
        $summary = [];
        $win = 0.0;
        $bonus = null;
        $fs = $this->m['bonus'];
        if ($buy === 'giros' || $buy === 'super') {
            $bonus = $buy === 'super' ? 'super' : 'giros';
            $start = $buy === 'super' ? (int) $this->m['cells']['super_start'] : 0;
            $win += $this->freeSpins($rng, $steps, $summary, (int) $fs['spins'], $start);
        } else {
            $cells = $this->emptyCells();
            $res = $this->playSpin($rng, false, $steps, $cells);
            $win += $res['win'];
            if ($res['bonus'] >= (int) $fs['trigger']) {
                $bonus = 'giros';
                $win += $this->freeSpins($rng, $steps, $summary, (int) $fs['spins'], 0);
            } elseif ($win > 0) {
                $summary[] = 'Ganó ' . round($win, 1) . ' veces la apuesta.';
            }
        }
        return ['win' => $win, 'steps' => $steps, 'bonus' => $bonus, 'summary' => implode(' ', $summary)];
    }

    /** @return list<list<int>> estado de cada casilla: 0 sin marcar, 1 marcada, 2+ multiplicador */
    private function emptyCells(int $value = 0): array
    {
        return array_fill(0, $this->m['cols'], array_fill(0, $this->m['rows'], $value));
    }

    /**
     * Un giro con todas sus cascadas.
     * @param list<list<int>> $cells multiplicadores de casilla (se modifican)
     * @return array{win: float, bonus: int}
     */
    public function playSpin(Rng $rng, bool $free, array &$steps, array &$cells, array $fsInfo = []): array
    {
        $draw = fn (int $col): string => $this->drawCell($rng, $free);
        $grid = [];
        for ($c = 0; $c < $this->m['cols']; $c++) {
            for ($r = 0; $r < $this->m['rows']; $r++) {
                $grid[$c][$r] = $draw($c);
            }
        }
        $steps[] = ['t' => 'drop', 'grid' => $grid, 'free' => $free, 'cells' => $cells] + ($fsInfo ? ['fs' => $fsInfo] : []);

        $isPay = fn (string $cell): bool => isset($this->m['symbols'][$cell]);
        $min = (int) $this->m['min_cluster'];
        $max = (int) ($free ? $this->m['cells']['fs_max'] : $this->m['cells']['base_max']);

        // Chispazo: arma un cluster al azar si el giro arrancó sin premio.
        if (Cascade::clusters($grid, $min, $isPay) === [] && $rng->chance((float) $this->m['electric']['chance'])) {
            $made = $this->electric($rng, $grid);
            if ($made !== null) {
                $steps[] = ['t' => 'electric', 'sym' => $made['sym'], 'cells' => $made['cells'], 'grid' => $grid];
            }
        }

        $spinWin = 0.0;
        $guard = 100;
        while ($guard-- > 0) {
            $found = Cascade::clusters($grid, $min, $isPay);
            if ($found === []) {
                break;
            }
            $wins = [];
            $remove = [];
            foreach ($found as $cl) {
                $base = Cascade::tieredPay($this->m['symbols'][$cl['sym']]['pays'], count($cl['cells']));
                $mult = 0;
                foreach ($cl['cells'] as [$c, $r]) {
                    if ($cells[$c][$r] >= 2) {
                        $mult += $cells[$c][$r];
                    }
                }
                $mult = max(1, $mult);
                $spinWin += $base * $mult;
                $wins[] = ['sym' => $cl['sym'], 'count' => count($cl['cells']), 'cells' => $cl['cells'], 'base' => $base, 'mult' => $mult, 'win' => round($base * $mult, 6)];
                array_push($remove, ...$cl['cells']);
            }
            // Marcar / duplicar las casillas ganadoras (una vez por celda aunque esté en dos clusters).
            $done = [];
            foreach ($remove as [$c, $r]) {
                if (isset($done[$c][$r])) {
                    continue;
                }
                $done[$c][$r] = true;
                $cells[$c][$r] = $cells[$c][$r] === 0 ? 1 : min($max, max(2, $cells[$c][$r] * 2));
            }
            $steps[] = ['t' => 'pay', 'wins' => $wins, 'total' => round($spinWin, 4), 'cells' => $cells];
            $grid = Cascade::tumble($grid, $remove, $draw);
            $steps[] = ['t' => 'tumble', 'removed' => $remove, 'grid' => $grid];
        }

        if ($spinWin > 0) {
            $rush = [];
            $sum = 0.0;
            foreach ($grid as $c => $col) {
                foreach ($col as $r => $cell) {
                    if ($cell[0] === self::RUSH) {
                        $rush[] = ['col' => $c, 'row' => $r, 'v' => Cascade::value($cell)];
                        $sum += Cascade::value($cell);
                    }
                }
            }
            if ($sum > 0) {
                $before = $spinWin;
                $spinWin *= $sum;
                $steps[] = ['t' => 'rush', 'rush' => $rush, 'sum' => $sum, 'before' => round($before, 4), 'after' => round($spinWin, 4)];
            }
        }

        $bonus = 0;
        foreach ($grid as $col) {
            foreach ($col as $cell) {
                if ($cell === self::BONUS) {
                    $bonus++;
                }
            }
        }
        $steps[] = ['t' => 'spin_end', 'win' => round($spinWin, 4), 'bonus' => $bonus];
        return ['win' => $spinWin, 'bonus' => $bonus];
    }

    private function drawCell(Rng $rng, bool $free): string
    {
        $w = [];
        foreach ($this->m['symbols'] as $sym => $def) {
            $w[$sym] = (float) $def['weight'];
        }
        $w[self::BONUS] = (float) ($free ? $this->m['bonus']['fs_weight'] : $this->m['bonus']['weight']);
        $w[self::RUSH] = (float) ($free ? $this->m['rush']['fs_weight'] : $this->m['rush']['weight']);
        $sym = (string) $rng->pick(array_filter($w, fn ($x) => $x > 0));
        if ($sym === self::RUSH) {
            return self::RUSH . $rng->pick($this->m['rush']['values']);
        }
        return $sym;
    }

    /**
     * Chispazo: elige un símbolo y hace crecer una zona conectada de 4-7 casillas.
     * @param list<list<string>> $grid (se modifica)
     * @return array{sym:string, cells:list<array{0:int,1:int}>}|null
     */
    private function electric(Rng $rng, array &$grid): ?array
    {
        $e = $this->m['electric'];
        $syms = array_keys($this->m['symbols']);
        $sym = $syms[$rng->int(0, count($syms) - 1)];
        $size = $rng->int((int) $e['min'], (int) $e['max']);
        $cols = $this->m['cols'];
        $rows = $this->m['rows'];
        $start = [$rng->int(0, $cols - 1), $rng->int(0, $rows - 1)];
        $region = [$start];
        $in = [$start[0] => [$start[1] => true]];
        $guard = 200;
        while (count($region) < $size && $guard-- > 0) {
            [$x, $y] = $region[$rng->int(0, count($region) - 1)];
            [$dx, $dy] = [[1, 0], [-1, 0], [0, 1], [0, -1]][$rng->int(0, 3)];
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($nx < 0 || $nx >= $cols || $ny < 0 || $ny >= $rows || isset($in[$nx][$ny])) {
                continue;
            }
            $in[$nx][$ny] = true;
            $region[] = [$nx, $ny];
        }
        if (count($region) < (int) $this->m['min_cluster']) {
            return null;
        }
        foreach ($region as [$x, $y]) {
            $grid[$x][$y] = $sym;
        }
        return ['sym' => $sym, 'cells' => $region];
    }

    private function freeSpins(Rng $rng, array &$steps, array &$summary, int $spins, int $startMult): float
    {
        $b = $this->m['bonus'];
        $left = $spins;
        $total = 0.0;
        $played = 0;
        $cells = $this->emptyCells($startMult);
        $steps[] = ['t' => 'fs_start', 'spins' => $left, 'cells' => $cells];
        $limit = (int) ($b['max_spins'] ?? 100);
        while ($left > 0 && $played < $limit) {
            $left--;
            $played++;
            if ($played > 1) {
                $this->sync($rng, $cells, $steps);
            }
            $res = $this->playSpin($rng, true, $steps, $cells, ['n' => $played, 'left' => $left]);
            $total += $res['win'];
            if ($res['bonus'] >= (int) $b['retrigger_count']) {
                $left += (int) $b['retrigger'];
                $steps[] = ['t' => 'fs_retrigger', 'add' => (int) $b['retrigger'], 'left' => $left];
            }
        }
        $steps[] = ['t' => 'fs_end', 'win' => round($total, 4), 'played' => $played];
        $summary[] = "Entró a los giros gratis ({$played} giros) y sacó " . round($total, 1) . ' veces la apuesta.';
        return $total;
    }

    /** Sincronización: copia el multiplicador más alto a otras casillas marcadas. */
    private function sync(Rng $rng, array &$cells, array &$steps): void
    {
        $s = $this->m['sync'];
        if (!$rng->chance((float) $s['chance'])) {
            return;
        }
        $best = 0;
        $from = null;
        $targets = [];
        foreach ($cells as $c => $col) {
            foreach ($col as $r => $v) {
                if ($v > $best) {
                    $best = $v;
                    $from = [$c, $r];
                }
            }
        }
        if ($best < 2) {
            return;
        }
        foreach ($cells as $c => $col) {
            foreach ($col as $r => $v) {
                if ($v >= 1 && $v < $best) {
                    $targets[] = [$c, $r];
                }
            }
        }
        if ($targets === []) {
            return;
        }
        $chosen = [];
        $n = min((int) $s['cells'], count($targets));
        for ($i = 0; $i < $n; $i++) {
            $k = $rng->int(0, count($targets) - 1);
            $chosen[] = $targets[$k];
            array_splice($targets, $k, 1);
        }
        foreach ($chosen as [$c, $r]) {
            $cells[$c][$r] = $best;
        }
        $steps[] = ['t' => 'sync', 'from' => $from, 'cells' => $chosen, 'value' => $best, 'state' => $cells];
    }
}
