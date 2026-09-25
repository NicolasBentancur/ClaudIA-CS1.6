<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

/**
 * "Los 3 Chanchitos del Banco": 5 rodillos x 3 filas, 25 líneas fijas.
 *
 *  - Chanchito comodín (rodillos 2-4): al caer se expande a 1, 2 o 3 filas y multiplica x1/x2/x3
 *    las líneas que pasan por él (varios comodines en una línea se multiplican entre sí).
 *  - Casita (rodillos 1, 3 y 5): 3 casitas pagan y dan giros gratis (se reactivan con 3 más).
 *    En los giros gratis los comodines salen más seguido y más grandes.
 *  - Monedas: la moneda del lobo trae un valor (x apuesta, o un jackpot Mini/Minor); el chanchito
 *    alcancía recolecta. Con N monedas o más y al menos un chanchito arranca "Candado y Carga":
 *    las monedas quedan trabadas, hay 3 re-giros que se reinician con cada moneda nueva, cada
 *    chanchito que cae junta el valor de todas las monedas del lobo y llenar la pantalla da el Grand.
 *
 * Las líneas pagan en múltiplos de la apuesta por línea (apuesta total / cantidad de líneas).
 * Grilla: $grid[rodillo][fila], fila 0 = arriba.
 */
final class ChanchitosEngine implements SlotEngine
{
    public const WILD = 'W';
    public const SCATTER = 'S';
    public const WOLF = 'L';
    public const PIGGY = 'K';

    public function __construct(private readonly array $m)
    {
    }

    public function layout(): array
    {
        return [
            'reels' => $this->m['reels'],
            'rows' => $this->m['rows'],
            'lines' => $this->m['lines'],
            'symbols' => array_keys($this->m['symbols']),
            'jackpots' => $this->m['hold']['jackpots'],
            'grand' => $this->m['hold']['grand'],
        ];
    }

    public function spin(Rng $rng, ?string $buy = null): array
    {
        $steps = [];
        $win = 0.0;
        $bonus = null;
        $summary = [];

        if ($buy === 'giros') {
            $bonus = 'giros';
            $win += $this->freeSpins($rng, $steps, $summary);
        } elseif ($buy === 'candado') {
            $bonus = 'candado';
            $grid = $this->boughtCoinGrid($rng);
            $win += $this->holdAndWin($rng, $grid, $steps, $summary);
        } else {
            $spin = $this->playSpin($rng, false);
            $steps[] = $spin['step'];
            $win += $spin['win'];
            if ($spin['scatters'] >= 3) {
                $bonus = 'giros';
                $win += $this->freeSpins($rng, $steps, $summary);
            }
            if ($spin['coins'] >= (int) $this->m['coins']['trigger'] && $spin['piggies'] >= 1) {
                $bonus = $bonus === null ? 'candado' : $bonus . '+candado';
                $win += $this->holdAndWin($rng, $spin['grid'], $steps, $summary);
            }
        }
        return [
            'win' => $win,
            'steps' => $steps,
            'bonus' => $bonus,
            'summary' => implode(' ', $summary),
        ];
    }

    /**
     * Un giro (normal o gratis).
     * @return array{win: float, step: array, scatters: int, coins: int, piggies: int, grid: array}
     */
    public function playSpin(Rng $rng, bool $free): array
    {
        $grid = $this->drawGrid($rng, $free);
        [$wilds, $mult] = $this->expandWilds($rng, $grid, $free);
        $lines = $this->evaluateLines($grid, $mult);
        $win = array_sum(array_column($lines, 'win'));

        $scatters = 0;
        $coins = 0;
        $piggies = 0;
        foreach ($grid as $reel) {
            foreach ($reel as $cell) {
                $c = $cell[0];
                if ($c === self::SCATTER) {
                    $scatters++;
                } elseif ($c === self::WOLF) {
                    $coins++;
                } elseif ($c === self::PIGGY) {
                    $coins++;
                    $piggies++;
                }
            }
        }
        $scatterWin = 0.0;
        if ($scatters >= 3) {
            $scatterWin = (float) $this->m['scatter']['pay'];
        }
        $win += $scatterWin;

        return [
            'win' => $win,
            'grid' => $grid,
            'scatters' => $scatters,
            'coins' => $coins,
            'piggies' => $piggies,
            'step' => [
                't' => 'spin',
                'free' => $free,
                'grid' => $grid,
                'wilds' => $wilds,
                'lines' => $lines,
                'scatter' => $scatters >= 3 ? ['count' => $scatters, 'win' => $scatterWin] : null,
                'win' => round($win, 4),
            ],
        ];
    }

    /** @return list<list<string>> */
    public function drawGrid(Rng $rng, bool $free): array
    {
        $grid = [];
        for ($r = 0; $r < $this->m['reels']; $r++) {
            $weights = $this->weightsForReel($r, $free);
            $reel = [];
            $hasWild = false;
            $hasScatter = false;
            for ($row = 0; $row < $this->m['rows']; $row++) {
                $w = $weights;
                if ($hasWild) {
                    unset($w[self::WILD]);
                }
                if ($hasScatter) {
                    unset($w[self::SCATTER]);
                }
                $sym = (string) $rng->pick($w);
                if ($sym === self::WILD) {
                    $hasWild = true;
                } elseif ($sym === self::SCATTER) {
                    $hasScatter = true;
                } elseif ($sym === self::WOLF) {
                    $sym = self::WOLF . $rng->pick($this->m['coins']['values']);
                } elseif ($sym === self::PIGGY) {
                    $sym = self::PIGGY . '0';
                }
                $reel[] = $sym;
            }
            $grid[] = $reel;
        }
        return $grid;
    }

    /** @return array<string, float> */
    private function weightsForReel(int $reel, bool $free): array
    {
        $w = [];
        foreach ($this->m['symbols'] as $sym => $def) {
            $w[$sym] = (float) $def['weight'];
        }
        $wild = $this->m['wild'];
        if (in_array($reel, $wild['reels'], true)) {
            $w[self::WILD] = (float) ($free ? $wild['fs_weight'] : $wild['weight']);
        }
        $sc = $this->m['scatter'];
        if (in_array($reel, $sc['reels'], true)) {
            $w[self::SCATTER] = (float) ($free ? $sc['fs_weight'] : $sc['weight']);
        }
        $coins = $this->m['coins'];
        if (!$free || ($coins['in_free_spins'] ?? false)) {
            $w[self::WOLF] = (float) $coins['wolf_weight'];
            if (in_array($reel, $coins['piggy_reels'], true)) {
                $w[self::PIGGY] = (float) $coins['piggy_weight'];
            }
        }
        return array_filter($w, fn ($x) => $x > 0);
    }

    /**
     * Expande cada comodín: tamaño 1-3 filas, multiplicador = tamaño.
     * @param list<list<string>> $grid (se modifica)
     * @return array{0: list<array{reel:int, from:int, size:int}>, 1: array<int, array<int, int>>}
     */
    private function expandWilds(Rng $rng, array &$grid, bool $free): array
    {
        $rows = $this->m['rows'];
        $sizes = $free ? $this->m['wild']['fs_sizes'] : $this->m['wild']['sizes'];
        $wilds = [];
        $mult = [];
        foreach ($grid as $r => $reel) {
            $at = array_search(self::WILD, $reel, true);
            if ($at === false) {
                continue;
            }
            $size = (int) $rng->pick($sizes);
            $size = max(1, min($rows, $size));
            $minFrom = max(0, $at - $size + 1);
            $maxFrom = min($at, $rows - $size);
            $from = $rng->int($minFrom, $maxFrom);
            for ($row = $from; $row < $from + $size; $row++) {
                $grid[$r][$row] = self::WILD;
                $mult[$r][$row] = $size;
            }
            $wilds[] = ['reel' => $r, 'from' => $from, 'size' => $size, 'landed' => $at];
        }
        return [$wilds, $mult];
    }

    /**
     * @param list<list<string>> $grid
     * @param array<int, array<int, int>> $mult
     * @return list<array{line:int, sym:string, count:int, mult:int, win:float}>
     */
    public function evaluateLines(array $grid, array $mult): array
    {
        $out = [];
        $lineBet = 1 / count($this->m['lines']);
        foreach ($this->m['lines'] as $i => $line) {
            $cells = [];
            foreach ($line as $reel => $row) {
                $cells[] = $grid[$reel][$row];
            }
            // Comodines al principio.
            $leadWild = 0;
            while ($leadWild < count($cells) && $cells[$leadWild] === self::WILD) {
                $leadWild++;
            }
            $target = null;
            if ($leadWild < count($cells) && isset($this->m['symbols'][$cells[$leadWild]])) {
                $target = $cells[$leadWild];
            }
            $best = null;
            if ($target !== null) {
                $n = 0;
                while ($n < count($cells) && ($cells[$n] === $target || $cells[$n] === self::WILD)) {
                    $n++;
                }
                $pay = (float) ($this->m['symbols'][$target]['pays'][(string) $n] ?? 0);
                if ($pay > 0) {
                    $best = ['sym' => $target, 'count' => $n, 'pay' => $pay];
                }
            }
            if ($leadWild >= 3) {
                $pay = (float) ($this->m['wild']['pays'][(string) $leadWild] ?? 0);
                if ($best === null || $pay > $best['pay']) {
                    $best = ['sym' => self::WILD, 'count' => $leadWild, 'pay' => $pay];
                }
            }
            if ($best === null || $best['pay'] <= 0) {
                continue;
            }
            $m = 1;
            for ($reel = 0; $reel < $best['count']; $reel++) {
                $m *= $mult[$reel][$line[$reel]] ?? 1;
            }
            $out[] = [
                'line' => $i,
                'sym' => $best['sym'],
                'count' => $best['count'],
                'mult' => $m,
                'win' => round($best['pay'] * $lineBet * $m, 6),
            ];
        }
        return $out;
    }

    private function freeSpins(Rng $rng, array &$steps, array &$summary): float
    {
        $sc = $this->m['scatter'];
        $left = (int) $sc['spins'];
        $total = 0.0;
        $played = 0;
        $steps[] = ['t' => 'fs_start', 'spins' => $left];
        $limit = (int) ($sc['max_spins'] ?? 100);
        while ($left > 0 && $played < $limit) {
            $left--;
            $played++;
            $spin = $this->playSpin($rng, true);
            $spin['step']['fs'] = ['n' => $played, 'left' => $left];
            $steps[] = $spin['step'];
            $total += $spin['win'];
            if ($spin['scatters'] >= 3) {
                $left += (int) $sc['retrigger'];
                $steps[] = ['t' => 'fs_retrigger', 'add' => (int) $sc['retrigger'], 'left' => $left];
            }
        }
        $steps[] = ['t' => 'fs_end', 'win' => round($total, 4), 'played' => $played];
        $summary[] = "Entró a los giros gratis ({$played} giros) y sacó " . round($total, 1) . ' veces la apuesta.';
        return $total;
    }

    /** Grilla inicial al comprar "Candado y Carga": N monedas del lobo + 1 chanchito. */
    private function boughtCoinGrid(Rng $rng): array
    {
        $reels = $this->m['reels'];
        $rows = $this->m['rows'];
        $grid = array_fill(0, $reels, array_fill(0, $rows, ''));
        $cells = [];
        for ($r = 0; $r < $reels; $r++) {
            for ($row = 0; $row < $rows; $row++) {
                $cells[] = [$r, $row];
            }
        }
        // Barajar posiciones.
        for ($i = count($cells) - 1; $i > 0; $i--) {
            $j = $rng->int(0, $i);
            [$cells[$i], $cells[$j]] = [$cells[$j], $cells[$i]];
        }
        $n = (int) $this->m['coins']['trigger'];
        foreach (array_slice($cells, 0, $n) as $k => [$r, $row]) {
            $grid[$r][$row] = $k === 0 ? self::PIGGY . '0' : self::WOLF . $rng->pick($this->m['coins']['values']);
        }
        return $grid;
    }

    /**
     * "Candado y Carga". Valores en x apuesta.
     * @param list<list<string>> $start grilla del giro que lo activó (solo cuentan las monedas)
     */
    public function holdAndWin(Rng $rng, array $start, array &$steps, array &$summary): float
    {
        $h = $this->m['hold'];
        $reels = $this->m['reels'];
        $rows = $this->m['rows'];
        /** @var array<int, array<int, array{k:string, v:float}>> $coins */
        $coins = [];
        $newPiggies = [];
        foreach ($start as $r => $reel) {
            foreach ($reel as $row => $cell) {
                if ($cell === '' || ($cell[0] !== self::WOLF && $cell[0] !== self::PIGGY)) {
                    continue;
                }
                $coins[$r][$row] = ['k' => $cell[0], 'v' => (float) substr($cell, 1)];
                if ($cell[0] === self::PIGGY) {
                    $newPiggies[] = [$r, $row];
                }
            }
        }
        $steps[] = ['t' => 'hw_start', 'coins' => $this->coinList($coins), 'respins' => (int) $h['respins']];
        $collects = $this->collect($coins, $newPiggies);
        if ($collects !== []) {
            $steps[] = ['t' => 'hw_collect', 'collects' => $collects, 'coins' => $this->coinList($coins)];
        }

        $respins = (int) $h['respins'];
        $capacity = $reels * $rows;
        $guard = 200;
        while ($respins > 0 && $this->count($coins) < $capacity && $guard-- > 0) {
            $respins--;
            $new = [];
            $newPiggies = [];
            for ($r = 0; $r < $reels; $r++) {
                for ($row = 0; $row < $rows; $row++) {
                    if (isset($coins[$r][$row]) || !$rng->chance((float) $h['cell_chance'])) {
                        continue;
                    }
                    if ($rng->chance((float) $h['piggy_share'])) {
                        $coins[$r][$row] = ['k' => self::PIGGY, 'v' => 0.0];
                        $newPiggies[] = [$r, $row];
                    } else {
                        $coins[$r][$row] = ['k' => self::WOLF, 'v' => (float) $rng->pick($h['values'])];
                    }
                    $new[] = ['reel' => $r, 'row' => $row] + $coins[$r][$row];
                }
            }
            if ($new !== []) {
                $respins = (int) $h['respins'];
            }
            $collects = $this->collect($coins, $newPiggies);
            $steps[] = ['t' => 'hw_respin', 'new' => $new, 'collects' => $collects, 'respins' => $respins, 'coins' => $this->coinList($coins)];
        }

        $total = 0.0;
        foreach ($coins as $reel) {
            foreach ($reel as $c) {
                $total += $c['v'];
            }
        }
        $grand = $this->count($coins) >= $capacity;
        if ($grand) {
            $total += (float) $h['grand'];
        }
        $steps[] = ['t' => 'hw_end', 'win' => round($total, 4), 'grand' => $grand];
        $summary[] = 'Entró al bonus Candado y Carga' . ($grand ? ' y llenó la pantalla (GRAND)' : '') . ': ' . round($total, 1) . ' veces la apuesta.';
        return $total;
    }

    /**
     * Cada chanchito nuevo junta el valor de todas las monedas del lobo en pantalla.
     * @return list<array{reel:int, row:int, v:float}>
     */
    private function collect(array &$coins, array $piggies): array
    {
        $out = [];
        foreach ($piggies as [$r, $row]) {
            $sum = 0.0;
            foreach ($coins as $reel) {
                foreach ($reel as $c) {
                    if ($c['k'] === self::WOLF) {
                        $sum += $c['v'];
                    }
                }
            }
            $coins[$r][$row]['v'] += $sum;
            $out[] = ['reel' => $r, 'row' => $row, 'v' => $coins[$r][$row]['v']];
        }
        return $out;
    }

    private function count(array $coins): int
    {
        return array_sum(array_map('count', $coins));
    }

    private function coinList(array $coins): array
    {
        $out = [];
        foreach ($coins as $r => $reel) {
            foreach ($reel as $row => $c) {
                $out[] = ['reel' => $r, 'row' => $row, 'k' => $c['k'], 'v' => $c['v']];
            }
        }
        return $out;
    }
}
