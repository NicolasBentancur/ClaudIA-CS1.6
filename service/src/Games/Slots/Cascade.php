<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

/**
 * Utilidades de grillas con cascada. Grilla: $grid[columna][fila], fila 0 = arriba.
 * Las celdas son strings: código de símbolo, o código + número para los especiales ("X10", "R25").
 */
final class Cascade
{
    /**
     * Saca las celdas indicadas, hace caer lo que queda y rellena arriba con $draw().
     * Las celdas en $fixed (ej. no se mueven) se tratan igual que las demás: todo cae.
     * @param list<list<string>> $grid
     * @param list<array{0:int,1:int}> $remove
     * @param callable(int $col):string $draw
     * @return list<list<string>>
     */
    public static function tumble(array $grid, array $remove, callable $draw): array
    {
        $gone = [];
        foreach ($remove as [$c, $r]) {
            $gone[$c][$r] = true;
        }
        foreach ($grid as $c => $col) {
            $rows = count($col);
            $kept = [];
            foreach ($col as $r => $cell) {
                if (!isset($gone[$c][$r])) {
                    $kept[] = $cell;
                }
            }
            $new = [];
            for ($i = 0; $i < $rows - count($kept); $i++) {
                $new[] = $draw($c);
            }
            $grid[$c] = array_merge($new, $kept);
        }
        return $grid;
    }

    /**
     * Símbolos que aparecen al menos $min veces en cualquier lugar de la grilla.
     * @param list<list<string>> $grid
     * @param callable(string):bool $pays si el código participa de premios
     * @return array<string, list<array{0:int,1:int}>>
     */
    public static function anywhere(array $grid, int $min, callable $pays): array
    {
        $found = [];
        foreach ($grid as $c => $col) {
            foreach ($col as $r => $cell) {
                if ($pays($cell)) {
                    $found[$cell][] = [$c, $r];
                }
            }
        }
        return array_filter($found, fn ($cells) => count($cells) >= $min);
    }

    /**
     * Clusters (conexión horizontal/vertical) de al menos $min celdas iguales.
     * @param list<list<string>> $grid
     * @param callable(string):bool $pays
     * @return list<array{sym:string, cells:list<array{0:int,1:int}>}>
     */
    public static function clusters(array $grid, int $min, callable $pays): array
    {
        $seen = [];
        $out = [];
        $cols = count($grid);
        foreach ($grid as $c => $col) {
            foreach ($col as $r => $cell) {
                if (isset($seen[$c][$r]) || !$pays($cell)) {
                    continue;
                }
                $stack = [[$c, $r]];
                $seen[$c][$r] = true;
                $cells = [];
                while ($stack !== []) {
                    [$x, $y] = array_pop($stack);
                    $cells[] = [$x, $y];
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        if ($nx < 0 || $nx >= $cols || $ny < 0 || $ny >= count($grid[$nx]) || isset($seen[$nx][$ny])) {
                            continue;
                        }
                        if ($grid[$nx][$ny] === $cell) {
                            $seen[$nx][$ny] = true;
                            $stack[] = [$nx, $ny];
                        }
                    }
                }
                if (count($cells) >= $min) {
                    usort($cells, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
                    $out[] = ['sym' => $cell, 'cells' => $cells];
                }
            }
        }
        return $out;
    }

    /**
     * Pago escalonado: $pays = {"8": 10, "10": 25, "12": 50} -> el mayor umbral <= $count.
     * @param array<string|int, float|int> $pays
     */
    public static function tieredPay(array $pays, int $count): float
    {
        $best = 0.0;
        $bestAt = -1;
        foreach ($pays as $at => $v) {
            if ((int) $at <= $count && (int) $at > $bestAt) {
                $best = (float) $v;
                $bestAt = (int) $at;
            }
        }
        return $best;
    }

    /** Número que acompaña a un código especial ("X10" -> 10). */
    public static function value(string $cell): float
    {
        return (float) substr($cell, 1);
    }
}
