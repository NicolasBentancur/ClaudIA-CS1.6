<?php

declare(strict_types=1);

/**
 * Simulador de slots: mide el RTP y la frecuencia de premios y bonus.
 *
 *   php tools/slot_sim.php <chanchitos|dulce|materush> [giros] [--buy=<opción>] [--seed=N]
 *
 * Sin --buy mide el juego normal (incluye los bonus que se activan jugando).
 * Con --buy mide el valor esperado de la compra y sugiere el precio para un RTP del 96 %.
 * Los premios se topean en max_win como en el juego real.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Claudia\Config;
use Claudia\Games\Slots\Rng;
use Claudia\Games\Slots\SlotFactory;

$game = $argv[1] ?? '';
$spins = (int) ($argv[2] ?? 100000);
$buy = null;
$seed = 12345;
foreach (array_slice($argv, 3) as $a) {
    if (str_starts_with($a, '--buy=')) {
        $buy = substr($a, 6);
    } elseif (str_starts_with($a, '--seed=')) {
        $seed = (int) substr($a, 7);
    }
}
if (!isset(SlotFactory::GAMES[$game])) {
    fwrite(STDERR, "Uso: php tools/slot_sim.php <" . implode('|', array_keys(SlotFactory::GAMES)) . "> [giros] [--buy=opción] [--seed=N]\n");
    exit(1);
}

$config = new Config(dirname(__DIR__) . '/config');
$engine = SlotFactory::fromConfig($config, $game);
$maxWin = $config->float("games.{$game}.max_win", 5000);
$target = 0.96;
$rng = new Rng($seed);

$total = 0.0;
$sq = 0.0;
$hits = 0;
$max = 0.0;
$capped = 0;
$bonus = [];
$bonusWin = [];
$started = microtime(true);
for ($i = 1; $i <= $spins; $i++) {
    $res = $engine->spin($rng, $buy);
    $win = min($res['win'], $maxWin);
    if ($res['win'] > $maxWin) {
        $capped++;
    }
    $total += $win;
    $sq += $win * $win;
    if ($win > 0) {
        $hits++;
    }
    $max = max($max, $win);
    if ($res['bonus'] !== null) {
        $bonus[$res['bonus']] = ($bonus[$res['bonus']] ?? 0) + 1;
        $bonusWin[$res['bonus']] = ($bonusWin[$res['bonus']] ?? 0) + $win;
    }
    if ($i % 50000 === 0) {
        fwrite(STDERR, sprintf("  %d giros, RTP parcial %.2f%%\n", $i, $total / $i * 100));
    }
}
$mean = $total / $spins;
$sd = sqrt(max(0, $sq / $spins - $mean * $mean));

printf("Slot: %s | giros: %s | semilla: %d | %.1f s\n", $game, number_format($spins), $seed, microtime(true) - $started);
if ($buy === null) {
    printf("RTP:               %.2f %%  (±%.2f con 95%% de confianza)\n", $mean * 100, 1.96 * $sd / sqrt($spins) * 100);
    printf("Frecuencia premio: 1 cada %.2f giros (%.1f %%)\n", $spins / max(1, $hits), $hits / $spins * 100);
    foreach ($bonus as $k => $n) {
        printf("Bonus %-14s 1 cada %.0f giros, promedio %.1fx, aporta %.2f %% de RTP\n", $k . ':', $spins / $n, $bonusWin[$k] / $n, $bonusWin[$k] / $spins * 100);
    }
} else {
    printf("Compra '%s': valor esperado %.2fx (±%.2f) | precio sugerido para RTP %.0f%%: %.0fx\n", $buy, $mean, 1.96 * $sd / sqrt($spins), $target * 100, $mean / $target);
}
printf("Premio máximo: %.1fx | topeados en %sx: %d | desvío: %.2f\n", $max, $maxWin, $capped, $sd);
