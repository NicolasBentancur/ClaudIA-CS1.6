<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Config;
use Claudia\Games\Slots\Cascade;
use Claudia\Games\Slots\ChanchitosEngine;
use Claudia\Games\Slots\DulceEngine;
use Claudia\Games\Slots\MateRushEngine;
use Claudia\Games\Slots\Rng;
use Claudia\Games\Slots\SlotFactory;
use PHPUnit\Framework\TestCase;

final class SlotsTest extends TestCase
{
    private static Config $config;

    public static function setUpBeforeClass(): void
    {
        self::$config = new Config(dirname(__DIR__) . '/config');
    }

    private function math(string $game): array
    {
        return self::$config->array("games.{$game}.math");
    }

    public function testTumbleAppliesGravityAndRefills(): void
    {
        $grid = [['a', 'b', 'c'], ['d', 'e', 'f']];
        $out = Cascade::tumble($grid, [[0, 1], [1, 2]], fn (int $c) => 'n');
        $this->assertSame(['n', 'a', 'c'], $out[0]);
        $this->assertSame(['n', 'd', 'e'], $out[1]);
    }

    public function testClustersAreOrthogonal(): void
    {
        // a a . / a . . / a . a  -> un cluster de 4 "a" (la diagonal no cuenta)
        $grid = [['a', 'a', 'a'], ['a', 'x', 'y'], ['z', 'w', 'a']];
        $cl = Cascade::clusters($grid, 4, fn ($s) => $s !== 'x');
        $this->assertCount(1, $cl);
        $this->assertSame('a', $cl[0]['sym']);
        $this->assertCount(4, $cl[0]['cells']);
    }

    public function testTieredPay(): void
    {
        $pays = ['8' => 1, '10' => 2, '12' => 5];
        $this->assertSame(0.0, Cascade::tieredPay($pays, 7));
        $this->assertSame(1.0, Cascade::tieredPay($pays, 9));
        $this->assertSame(2.0, Cascade::tieredPay($pays, 11));
        $this->assertSame(5.0, Cascade::tieredPay($pays, 30));
    }

    public function testChanchitosLinesWithWildMultipliers(): void
    {
        $m = $this->math('chanchitos');
        $e = new ChanchitosEngine($m);
        // Línea del medio: caja W W caja pica -> 4 cajas; comodines x2 y x3 multiplican x6.
        $grid = [
            ['pica', 'caja', 'trebol'],
            ['pica', 'W', 'trebol'],
            ['trebol', 'W', 'pica'],
            ['diamante', 'caja', 'corazon'],
            ['corazon', 'pica', 'diamante'],
        ];
        $mult = [1 => [1 => 2], 2 => [1 => 3]];
        $lines = $e->evaluateLines($grid, $mult);
        $mid = array_values(array_filter($lines, fn ($l) => $l['line'] === 0));
        $this->assertCount(1, $mid);
        $this->assertSame('caja', $mid[0]['sym']);
        $this->assertSame(4, $mid[0]['count']);
        $this->assertSame(6, $mid[0]['mult']);
        $expected = $m['symbols']['caja']['pays']['4'] / count($m['lines']) * 6;
        $this->assertEqualsWithDelta($expected, $mid[0]['win'], 1e-6);
    }

    public function testChanchitosHoldAndWinGrandWhenFull(): void
    {
        $m = $this->math('chanchitos');
        $m['hold']['cell_chance'] = 1.0;   // todas las casillas se llenan en el primer re-giro
        $m['hold']['piggy_share'] = 0.0;
        $m['hold']['values'] = ['1' => 1];
        $e = new ChanchitosEngine($m);
        $start = array_fill(0, 5, array_fill(0, 3, 'pica'));
        $start[0][0] = 'K0';
        for ($i = 1; $i <= 5; $i++) {
            $start[$i % 5][intdiv($i, 5) + 1] = 'L2';
        }
        $steps = [];
        $summary = [];
        $win = $e->holdAndWin(new Rng(1), $start, $steps, $summary);
        $end = end($steps);
        $this->assertTrue($end['grand']);
        // chanchito junta 5x2=10; 5 monedas de 2 = 10; 9 monedas nuevas de 1 = 9; grand 1000.
        $this->assertEqualsWithDelta(10 + 10 + 9 + 1000, $win, 1e-9);
    }

    public function testEnginesAreDeterministicWithSeed(): void
    {
        foreach (array_keys(SlotFactory::GAMES) as $game) {
            $a = SlotFactory::engine($game, $this->math($game))->spin(new Rng(99));
            $b = SlotFactory::engine($game, $this->math($game))->spin(new Rng(99));
            $this->assertSame(json_encode($a), json_encode($b), $game);
        }
    }

    public function testDulceWinMatchesSteps(): void
    {
        $e = new DulceEngine($this->math('dulce'));
        $rng = new Rng(7);
        for ($i = 0; $i < 400; $i++) {
            $res = $e->spin($rng);
            $sum = 0.0;
            foreach ($res['steps'] as $s) {
                if ($s['t'] === 'spin_end') {
                    $sum += $s['win'];
                }
            }
            $this->assertEqualsWithDelta($res['win'], $sum, 0.01);
            if ($res['bonus'] === null) {
                foreach ($res['steps'] as $s) {
                    $this->assertNotSame('bombs', $s['t'], 'no hay bombones fuera de los giros gratis');
                }
            }
        }
    }

    public function testDulceBuyGoesStraightToFreeSpins(): void
    {
        $res = (new DulceEngine($this->math('dulce')))->spin(new Rng(3), 'giros');
        $this->assertSame('fs_start', $res['steps'][0]['t']);
        $this->assertSame('giros', $res['bonus']);
    }

    public function testBoughtFreeSpinsAlmostNeverPayZero(): void
    {
        foreach (['dulce', 'materush', 'chanchitos'] as $game) {
            $e = SlotFactory::engine($game, $this->math($game));
            $rng = new Rng(21);
            $zero = 0;
            for ($i = 0; $i < 300; $i++) {
                if ($e->spin($rng, 'giros')['win'] <= 0) {
                    $zero++;
                }
            }
            // La tasa real es < 1 %; se tolera hasta 3 % para no depender de rachas de la semilla.
            $this->assertLessThanOrEqual(9, $zero, "{$game}: demasiadas compras de giros que pagan 0");
        }
    }

    public function testMateRushCellMultipliersDouble(): void
    {
        $m = $this->math('materush');
        $e = new MateRushEngine($m);
        $rng = new Rng(11);
        $maxSeen = 0;
        for ($i = 0; $i < 300; $i++) {
            $res = $e->spin($rng);
            foreach ($res['steps'] as $s) {
                if ($s['t'] === 'pay') {
                    foreach ($s['cells'] as $col) {
                        foreach ($col as $v) {
                            $this->assertTrue(in_array($v, [0, 1, 2, 4, 8, 16, 32, 64, 128, 256], true), "valor de casilla inválido: {$v}");
                            $maxSeen = max($maxSeen, $v);
                        }
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(2, $maxSeen);
    }

    public function testMateRushSuperBuyStartsAtX4(): void
    {
        $res = (new MateRushEngine($this->math('materush')))->spin(new Rng(5), 'super');
        $this->assertSame('fs_start', $res['steps'][0]['t']);
        foreach ($res['steps'][0]['cells'] as $col) {
            foreach ($col as $v) {
                $this->assertSame(4, $v);
            }
        }
    }
}
