<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Games\Roulette\BallPhysics;
use Claudia\Games\Roulette\Bets;
use Claudia\Games\Roulette\Wheel;
use Claudia\UserError;
use PHPUnit\Framework\TestCase;

final class RouletteTest extends TestCase
{
    public function testWheelHas37DistinctNumbers(): void
    {
        $this->assertCount(37, array_unique(Wheel::ORDER));
        $this->assertSame(range(0, 36), (function () {
            $o = Wheel::ORDER;
            sort($o);
            return $o;
        })());
        $this->assertCount(18, Wheel::RED);
    }

    /** @return iterable<string, array{array, int, int}> */
    public static function payoutCases(): iterable
    {
        yield 'pleno gana 35:1' => [['type' => 'pleno', 'numbers' => [17], 'amount' => 10], 17, 360];
        yield 'pleno pierde' => [['type' => 'pleno', 'numbers' => [17], 'amount' => 10], 18, 0];
        yield 'caballo 17:1' => [['type' => 'caballo', 'numbers' => [17, 20], 'amount' => 10], 20, 180];
        yield 'caballo con cero' => [['type' => 'caballo', 'numbers' => [0, 2], 'amount' => 10], 0, 180];
        yield 'transversal 11:1' => [['type' => 'transversal', 'numbers' => [13, 14, 15], 'amount' => 10], 14, 120];
        yield 'trio 0-1-2' => [['type' => 'transversal', 'numbers' => [0, 1, 2], 'amount' => 10], 0, 120];
        yield 'cuadro 8:1' => [['type' => 'cuadro', 'numbers' => [1, 2, 4, 5], 'amount' => 10], 5, 90];
        yield 'primeros cuatro' => [['type' => 'cuadro', 'numbers' => [0, 1, 2, 3], 'amount' => 10], 3, 90];
        yield 'seisena 5:1' => [['type' => 'seisena', 'numbers' => [31, 32, 33, 34, 35, 36], 'amount' => 10], 36, 60];
        yield 'docena 2:1' => [['type' => 'docena', 'which' => 2, 'amount' => 10], 24, 30];
        yield 'columna 2:1' => [['type' => 'columna', 'which' => 3, 'amount' => 10], 36, 30];
        yield 'columna pierde' => [['type' => 'columna', 'which' => 1, 'amount' => 10], 36, 0];
        yield 'rojo 1:1' => [['type' => 'rojo', 'amount' => 10], 32, 20];
        yield 'negro pierde con rojo' => [['type' => 'negro', 'amount' => 10], 32, 0];
        yield 'par' => [['type' => 'par', 'amount' => 10], 36, 20];
        yield 'impar' => [['type' => 'impar', 'amount' => 10], 35, 20];
        yield 'falta' => [['type' => 'falta', 'amount' => 10], 18, 20];
        yield 'pasa' => [['type' => 'pasa', 'amount' => 10], 19, 20];
        yield 'La Partage en el cero' => [['type' => 'rojo', 'amount' => 10], 0, 5];
        yield 'La Partage impar' => [['type' => 'pasa', 'amount' => 25], 0, 12];
        yield 'docena no gana en el cero' => [['type' => 'docena', 'which' => 1, 'amount' => 10], 0, 0];
    }

    /** @dataProvider payoutCases */
    #[\PHPUnit\Framework\Attributes\DataProvider('payoutCases')]
    public function testPayouts(array $bet, int $result, int $expected): void
    {
        $bets = Bets::normalize([$bet]);
        $this->assertSame($expected, Bets::payout($bets, $result));
    }

    public function testAnnouncedBetsExpandCorrectly(): void
    {
        $this->assertSame(90, Bets::total(Bets::normalize([['type' => 'vecinos_cero', 'unit' => 10]])));
        $this->assertSame(60, Bets::total(Bets::normalize([['type' => 'tercio', 'unit' => 10]])));
        $this->assertSame(50, Bets::total(Bets::normalize([['type' => 'huerfanos', 'unit' => 10]])));
        $this->assertSame(40, Bets::total(Bets::normalize([['type' => 'juego_cero', 'unit' => 10]])));
        $this->assertSame(50, Bets::total(Bets::normalize([['type' => 'vecinos', 'number' => 0, 'unit' => 10]])));

        // Vecinos del cero con resultado 0: el trío 0-2-3 va doble (2 x 12 fichas).
        $this->assertSame(240, Bets::payout(Bets::normalize([['type' => 'vecinos_cero', 'unit' => 10]]), 0));
        // Huérfanos con el 17: dos caballos lo cubren (14/17 y 17/20).
        $this->assertSame(360, Bets::payout(Bets::normalize([['type' => 'huerfanos', 'unit' => 10]]), 17));
        // Tercio no cubre el 0.
        $this->assertSame(0, Bets::payout(Bets::normalize([['type' => 'tercio', 'unit' => 10]]), 0));
        // Vecinos del 0: 3, 26, 0, 32, 15.
        $this->assertSame(360, Bets::payout(Bets::normalize([['type' => 'vecinos', 'number' => 0, 'unit' => 10]]), 15));
    }

    /** @return iterable<string, array{array}> */
    public static function invalidBets(): iterable
    {
        yield 'caballo no adyacente' => [['type' => 'caballo', 'numbers' => [1, 5], 'amount' => 10]];
        yield 'caballo entre columnas 3-4' => [['type' => 'caballo', 'numbers' => [3, 4], 'amount' => 10]];
        yield 'cuadro inválido' => [['type' => 'cuadro', 'numbers' => [3, 4, 6, 7], 'amount' => 10]];
        yield 'número fuera de rango' => [['type' => 'pleno', 'numbers' => [37], 'amount' => 10]];
        yield 'monto negativo' => [['type' => 'pleno', 'numbers' => [1], 'amount' => -10]];
        yield 'monto decimal' => [['type' => 'pleno', 'numbers' => [1], 'amount' => 10.5]];
        yield 'tipo desconocido' => [['type' => 'trampa', 'amount' => 10]];
        yield 'docena 4' => [['type' => 'docena', 'which' => 4, 'amount' => 10]];
    }

    /** @dataProvider invalidBets */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBets')]
    public function testInvalidBetsAreRejected(array $bet): void
    {
        $this->expectException(UserError::class);
        Bets::normalize([$bet]);
    }

    public function testExpectedReturnHasHouseEdge(): void
    {
        // Ventaja de la casa francesa: 1/37 en plenos, 1/74 en simples (por La Partage).
        $straight = Bets::normalize([['type' => 'pleno', 'numbers' => [7], 'amount' => 37]]);
        $even = Bets::normalize([['type' => 'rojo', 'amount' => 74]]);
        $sumS = $sumE = 0;
        for ($n = 0; $n <= 36; $n++) {
            $sumS += Bets::payout($straight, $n);
            $sumE += Bets::payout($even, $n);
        }
        $this->assertSame(36 * 37, $sumS);          // devuelve 36/37 de lo apostado
        $this->assertSame(74 * 37 - 37, $sumE);     // pierde medio 1/37
    }

    public function testPhysicsLandsOnDrawnNumberAtBothFrameRates(): void
    {
        $physics = new BallPhysics();
        foreach ([30, 15] as $fps) {
            for ($i = 0; $i < 60; $i++) {
                $result = Wheel::draw();
                $sim = $physics->simulate($result, (float) random_int(0, 359), $fps);
                $last = end($sim['frames']);
                $this->assertSame($result, BallPhysics::pocketAt($last[1], $last[2]), "fps {$fps}, resultado {$result}");
                $this->assertEqualsWithDelta(BallPhysics::POCKET_RADIUS, $last[3], 0.001);
                $this->assertGreaterThanOrEqual(2, count($sim['bounces']));
                $this->assertEqualsWithDelta($sim['duration'] * $fps, count($sim['frames']) - 1, 1.0);
                // Tiempos crecientes y radio dentro de la rueda.
                $prev = -1;
                foreach ($sim['frames'] as $f) {
                    $this->assertGreaterThan($prev, $f[0]);
                    $prev = $f[0];
                    $this->assertGreaterThan(0.5, $f[3]);
                    $this->assertLessThanOrEqual(1.0, $f[3]);
                }
            }
        }
    }

    public function testBallRestsAfterSettling(): void
    {
        $sim = (new BallPhysics())->simulate(26, 0.0, 30);
        foreach ($sim['frames'] as $f) {
            if ($f[0] / 1000 >= $sim['settle']) {
                $this->assertSame(26, BallPhysics::pocketAt($f[1], $f[2]));
            }
        }
    }
}
