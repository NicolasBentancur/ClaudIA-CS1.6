<?php

declare(strict_types=1);

namespace Claudia\Games\Roulette;

/**
 * Simula el giro: cilindro (antihorario) y bola (horaria) con desaceleración, caída desde
 * el borde, rebotes amortiguados entre los casilleros y asentamiento. Genera todos los
 * frames a la tasa pedida.
 *
 * El resultado se sortea ANTES de simular. La simulación es natural y al final se suma un
 * desplazamiento constante al ángulo de la bola (equivale a lanzarla desde otro punto del
 * borde) para que termine exactamente en el casillero sorteado.
 *
 * Convención: grados, sentido horario positivo (como CSS rotate). Con rotación del cilindro W,
 * el casillero i (orden de Wheel::ORDER) está en el ángulo W + i * 360/37.
 * r es el radio de la bola normalizado: 1.0 = pista exterior, ~0.64 = casilleros.
 */
final class BallPhysics
{
    public const POCKET_RADIUS = 0.64;
    private const TAIL_SECONDS = 2.5;

    /** @var callable(float,float):float */
    private $rand;

    public function __construct(?callable $rand = null)
    {
        $this->rand = $rand ?? static fn (float $min, float $max): float => $min + ($max - $min) * (random_int(0, 1_000_000) / 1_000_000);
    }

    /**
     * @return array{fps:int, duration:float, frames:list<array{0:int,1:float,2:float,3:float}>, bounces:list<float>, settle:float, result:int, wheelEnd:float}
     */
    public function simulate(int $result, float $wheelStart, int $fps = 30): array
    {
        $alpha = 360 / Wheel::POCKETS;
        $r = $this->rand;

        // Cilindro.
        $ww0 = $r(35, 55);
        $kw = 0.04;
        $wheel = fn (float $t): float => $wheelStart - $ww0 / $kw * (1 - exp(-$kw * $t));
        $wheelSpeed = fn (float $t): float => $ww0 * exp(-$kw * $t);

        // Fase 1: bola en la pista exterior.
        $b0 = $r(0, 360);
        $vb0 = $r(620, 760);
        $kb = $r(0.30, 0.36);
        $vDrop = $r(150, 190);
        $t1 = log($vb0 / $vDrop) / $kb;
        $ballRim = fn (float $t): float => $b0 + $vb0 / $kb * (1 - exp(-$kb * $t));

        // Fase 2: caída en espiral hacia los deflectores.
        $d2 = $r(0.7, 1.0);
        $decel = $vDrop * 0.45 / $d2;
        $b1 = $ballRim($t1);
        $t2 = $t1 + $d2;
        $ballDrop = fn (float $tau): float => $b1 + $vDrop * $tau - 0.5 * $decel * $tau * $tau;
        $rDropEnd = 0.80;

        // Fase 3: rebotes, en ángulo relativo al cilindro (phi = bola - cilindro).
        $phi = $ballDrop($d2) - $wheel($t2);
        $n = (int) floor($r(2, 5.999));
        $bounces = [];
        $t = $t2;
        $rStart = $rDropEnd;
        for ($i = 0; $i < $n; $i++) {
            $dur = 0.42 * (0.78 ** $i) * $r(0.85, 1.15);
            $pockets = $r(1.5, 4.0) * (0.7 ** $i);
            $dir = $r(0, 1) < 0.8 ? 1 : -1;
            $rEnd = $rDropEnd - (($rDropEnd - 0.66) * ($i + 1) / $n);
            $bounces[] = ['t' => $t, 'dur' => $dur, 'phi' => $phi, 'dphi' => $pockets * $alpha * $dir, 'r0' => $rStart, 'r1' => $rEnd, 'h' => 0.05 * (0.7 ** $i)];
            $phi += $pockets * $alpha * $dir;
            $t += $dur;
            $rStart = $rEnd;
        }
        $t3 = $t;

        // Fase 4: se acomoda en el casillero más cercano.
        $natural = (int) round($phi / $alpha);
        $phiPocket = $natural * $alpha;
        $d4 = 0.35;
        $settle = $t3 + $d4;
        $naturalIndex = (($natural % Wheel::POCKETS) + Wheel::POCKETS) % Wheel::POCKETS;

        // Desplazamiento para caer en el número sorteado.
        $shift = (Wheel::indexOf($result) - $naturalIndex) * $alpha;

        $duration = $settle + self::TAIL_SECONDS;
        $frames = [];
        $times = [];
        for ($f = 0; $f / $fps < $duration; $f++) {
            $times[] = $f / $fps;
        }
        if ((int) round($duration * 1000) > (int) round(end($times) * 1000)) {
            $times[] = $duration;
        }
        foreach ($times as $time) {
            $w = $wheel($time);
            if ($time < $t1) {
                $b = $ballRim($time);
                $rad = 1.0;
            } elseif ($time < $t2) {
                $tau = $time - $t1;
                $b = $ballDrop($tau);
                $rad = 1.0 - (1.0 - $rDropEnd) * (($tau / $d2) ** 2);
            } elseif ($time < $t3) {
                $bn = $bounces[0];
                foreach ($bounces as $candidate) {
                    if ($time >= $candidate['t']) {
                        $bn = $candidate;
                    }
                }
                $s = min(1.0, ($time - $bn['t']) / $bn['dur']);
                $p = $bn['phi'] + $bn['dphi'] * (1 - (1 - $s) ** 2);
                $b = $w + $p;
                $rad = $bn['r0'] + ($bn['r1'] - $bn['r0']) * $s + $bn['h'] * sin(M_PI * $s);
            } elseif ($time < $settle) {
                $s = ($time - $t3) / $d4;
                $e = 1 - (1 - $s) ** 3;
                $b = $w + $phi + ($phiPocket - $phi) * $e;
                $rad = 0.66 + (self::POCKET_RADIUS - 0.66) * $e;
            } else {
                $b = $w + $phiPocket;
                $rad = self::POCKET_RADIUS;
            }
            $frames[] = [(int) round($time * 1000), round($w, 2), round($b + $shift, 2), round($rad, 3)];
        }

        return [
            'fps' => $fps,
            'duration' => round($duration, 3),
            'frames' => $frames,
            'bounces' => array_map(fn ($b) => round($b['t'], 3), $bounces),
            'settle' => round($settle, 3),
            'result' => $result,
            'wheelEnd' => fmod($wheel($duration), 360.0),
        ];
    }

    /** Número bajo la bola en un frame (para verificar). */
    public static function pocketAt(float $wheelDeg, float $ballDeg): int
    {
        $alpha = 360 / Wheel::POCKETS;
        $rel = fmod($ballDeg - $wheelDeg, 360.0);
        if ($rel < 0) {
            $rel += 360.0;
        }
        return Wheel::numberAt((int) round($rel / $alpha));
    }
}
