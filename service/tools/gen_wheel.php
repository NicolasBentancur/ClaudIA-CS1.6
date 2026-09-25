<?php

declare(strict_types=1);

/**
 * Genera la imagen de la ruleta francesa (SVG) que gira en el MOTD.
 * Geometría (unidades del viewBox, radio 100):
 *   100-88 aro de madera con la pista de la bola (r normalizado 1.0 = 93)
 *    88-72 anillo de números
 *    72-50 casilleros (la bola descansa en r = 0.64 * 93 ~ 59.5)
 *    50-0  cono y torreta
 * El casillero i (orden de Wheel::ORDER) está centrado en i * 360/37 grados, en sentido horario desde arriba.
 *
 *   php tools/gen_wheel.php [archivo]   (por defecto public/juegos/ruleta/img/rueda.svg)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Claudia\Games\Roulette\Wheel;

$file = $argv[1] ?? dirname(__DIR__) . '/public/juegos/ruleta/img/rueda.svg';
if (!is_dir(dirname($file))) {
    mkdir(dirname($file), 0775, true);
}

$alpha = 360 / 37;
$pt = function (float $deg, float $r): string {
    $rad = deg2rad($deg);
    return sprintf('%.3f,%.3f', $r * sin($rad), -$r * cos($rad));
};
$wedge = function (float $a0, float $a1, float $r0, float $r1) use ($pt): string {
    return sprintf(
        'M%s L%s A%.1f,%.1f 0 0,1 %s L%s A%.1f,%.1f 0 0,0 %s Z',
        $pt($a0, $r0),
        $pt($a0, $r1),
        $r1,
        $r1,
        $pt($a1, $r1),
        $pt($a1, $r0),
        $r0,
        $r0,
        $pt($a0, $r0)
    );
};

$svg = [];
$svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-100 -100 200 200" width="400" height="400">';
$svg[] = '<defs>';
$svg[] = '<radialGradient id="wood" cx="0" cy="0" r="100" gradientUnits="userSpaceOnUse"><stop offset="0.86" stop-color="#6b3a1a"/><stop offset="0.93" stop-color="#8a4f25"/><stop offset="1" stop-color="#3d1f0c"/></radialGradient>';
$svg[] = '<radialGradient id="cone" cx="0" cy="0" r="50" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#c9a24a"/><stop offset="0.25" stop-color="#8a5a2b"/><stop offset="1" stop-color="#4a2710"/></radialGradient>';
$svg[] = '<linearGradient id="gold" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff2b0"/><stop offset="0.5" stop-color="#d4a437"/><stop offset="1" stop-color="#8a6414"/></linearGradient>';
$svg[] = '</defs>';
$svg[] = '<circle r="100" fill="url(#wood)"/>';
$svg[] = '<circle r="93" fill="none" stroke="#2a1405" stroke-width="1.2" opacity="0.6"/>';
$svg[] = '<circle r="88" fill="#1a1a1a"/>';

foreach (Wheel::ORDER as $i => $n) {
    $color = $n === 0 ? '#0e7a3b' : (in_array($n, Wheel::RED, true) ? '#b3202a' : '#161616');
    $dark = $n === 0 ? '#0a5a2b' : (in_array($n, Wheel::RED, true) ? '#8c1820' : '#0c0c0c');
    $a0 = ($i - 0.5) * $alpha;
    $a1 = ($i + 0.5) * $alpha;
    $svg[] = sprintf('<path d="%s" fill="%s"/>', $wedge($a0, $a1, 72, 88), $color);
    $svg[] = sprintf('<path d="%s" fill="%s"/>', $wedge($a0, $a1, 50, 72), $dark);
    $center = $i * $alpha;
    [$x, $y] = explode(',', $pt($center, 80));
    $svg[] = sprintf(
        '<text x="%s" y="%s" transform="rotate(%.3f %s %s)" fill="#fff" font-family="Georgia, serif" font-size="8" font-weight="bold" text-anchor="middle" dominant-baseline="central">%d</text>',
        $x,
        $y,
        $center,
        $x,
        $y,
        $n
    );
    // Separadores (trastes) de los casilleros.
    $svg[] = sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="url(#gold)" stroke-width="0.9"/>',
        ...array_merge(explode(',', $pt($a0, 50)), explode(',', $pt($a0, 88))));
}

$svg[] = '<circle r="88" fill="none" stroke="url(#gold)" stroke-width="1.2"/>';
$svg[] = '<circle r="72" fill="none" stroke="url(#gold)" stroke-width="0.8"/>';
$svg[] = '<circle r="50" fill="url(#cone)" stroke="url(#gold)" stroke-width="1.2"/>';
// Torreta central.
$svg[] = '<g fill="url(#gold)" stroke="#6b4a0e" stroke-width="0.4">';
$svg[] = '<rect x="-2" y="-30" width="4" height="60" rx="2"/><rect x="-30" y="-2" width="60" height="4" rx="2"/>';
foreach ([[0, -30], [0, 30], [-30, 0], [30, 0]] as [$x, $y]) {
    $svg[] = sprintf('<circle cx="%d" cy="%d" r="4"/>', $x, $y);
}
$svg[] = '<circle r="9"/><circle r="4" fill="#fff2b0"/>';
$svg[] = '</g>';
$svg[] = '</svg>';

file_put_contents($file, implode("\n", $svg) . "\n");
echo "Ruleta generada en {$file}\n";
