<?php

declare(strict_types=1);

/**
 * Genera los sonidos de Claudia (WAV PCM 16 bit mono 22050 Hz, formato que acepta GoldSrc)
 * sintetizándolos, así no dependemos de audio de terceros.
 *
 *   php tools/gen_sounds.php [directorio_destino]   (por defecto ../sound/claudia)
 */

const RATE = 22050;

$out = $argv[1] ?? dirname(__DIR__, 2) . '/sound/claudia';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

mt_srand(1234);

function noise(): float
{
    return mt_rand() / mt_getrandmax() * 2 - 1;
}

/** @param list<float> $samples */
function wav(string $file, array $samples): void
{
    $data = '';
    foreach ($samples as $s) {
        $data .= pack('v', (int) round(max(-1.0, min(1.0, $s)) * 32767) & 0xFFFF);
    }
    $header = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, RATE, RATE * 2, 2, 16)
        . 'data' . pack('V', strlen($data));
    file_put_contents($file, $header . $data);
}

/** Tono con envolvente exponencial. */
function tone(float $freq, float $dur, float $decay = 6.0, float $vol = 0.5, float $harm = 0.3): array
{
    $n = (int) (RATE * $dur);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $i / RATE;
        $env = exp(-$decay * $t) * min(1.0, $t * 200);
        $out[] = $vol * $env * (sin(2 * M_PI * $freq * $t) + $harm * sin(4 * M_PI * $freq * $t));
    }
    return $out;
}

function mix(array ...$tracks): array
{
    $len = max(array_map('count', $tracks));
    $out = array_fill(0, $len, 0.0);
    foreach ($tracks as $tr) {
        foreach ($tr as $i => $v) {
            $out[$i] += $v;
        }
    }
    return $out;
}

function offset(array $samples, float $seconds): array
{
    return array_merge(array_fill(0, (int) (RATE * $seconds), 0.0), $samples);
}

/** Filtro pasa-bajos de un polo. */
function lowpass(array $samples, float $alpha): array
{
    $y = 0.0;
    foreach ($samples as $i => $x) {
        $y += $alpha * ($x - $y);
        $samples[$i] = $y;
    }
    return $samples;
}

// Ruleta girando: ruido rodante con "tic-tic" que se va frenando.
$n = (int) (RATE * 2.2);
$spin = [];
$phase = 0.0;
for ($i = 0; $i < $n; $i++) {
    $t = $i / RATE;
    $rate = 28 - 18 * ($t / 2.2);           // clics por segundo que bajan
    $phase += $rate / RATE;
    $click = exp(-60 * fmod($phase, 1.0) / max(1, $rate) * 10);
    $env = min(1.0, $t * 8) * (1 - $t / 2.2);
    $spin[] = $env * (0.25 * noise() * (0.4 + 0.6 * $click) + 0.08 * sin(2 * M_PI * 90 * $t));
}
wav("{$out}/ruleta_giro.wav", lowpass($spin, 0.35));

// Rebote de la bola: clic corto y seco.
$bounce = [];
$n = (int) (RATE * 0.07);
for ($i = 0; $i < $n; $i++) {
    $t = $i / RATE;
    $bounce[] = exp(-90 * $t) * (0.55 * noise() + 0.45 * sin(2 * M_PI * 2600 * $t));
}
wav("{$out}/ruleta_rebote.wav", lowpass($bounce, 0.6));

// Ganar: arpegio ascendente.
wav("{$out}/ganar.wav", mix(
    tone(523.25, 0.5, 5),
    offset(tone(659.25, 0.5, 5), 0.10),
    offset(tone(783.99, 0.5, 5), 0.20),
    offset(tone(1046.5, 0.7, 4, 0.55), 0.30),
));

// Perder: tres notas que bajan.
wav("{$out}/perder.wav", mix(
    tone(392.0, 0.35, 5, 0.45, 0.5),
    offset(tone(329.63, 0.35, 5, 0.45, 0.5), 0.2),
    offset(tone(261.63, 0.7, 3, 0.45, 0.5), 0.4),
));

// Carta: "fsss" corto.
$card = [];
$n = (int) (RATE * 0.14);
for ($i = 0; $i < $n; $i++) {
    $t = $i / RATE;
    $card[] = 0.6 * noise() * min(1.0, $t * 300) * exp(-30 * $t);
}
wav("{$out}/bj_carta.wav", lowpass($card, 0.5));

// Ficha: dos clics cerámicos.
wav("{$out}/bj_ficha.wav", mix(tone(3200, 0.08, 60, 0.4, 0.1), offset(tone(2600, 0.08, 60, 0.35, 0.1), 0.045)));

// Empate: dos pitidos neutros.
wav("{$out}/bj_empate.wav", mix(tone(587.33, 0.18, 8, 0.4), offset(tone(587.33, 0.25, 8, 0.4), 0.2)));

// Recordatorio: campanita de dos notas.
wav("{$out}/recordatorio.wav", mix(tone(1318.5, 0.6, 5, 0.4, 0.2), offset(tone(987.77, 0.7, 4, 0.4, 0.2), 0.18)));

// Slots: rodillos girando (tic-tic rápido y parejo).
$n = (int) (RATE * 1.2);
$reels = [];
for ($i = 0; $i < $n; $i++) {
    $t = $i / RATE;
    $click = exp(-80 * fmod($t * 22, 1.0) / 22 * 10);
    $env = min(1.0, $t * 10) * min(1.0, (1.2 - $t) * 6);
    $reels[] = $env * (0.18 * noise() * $click + 0.12 * sin(2 * M_PI * 180 * $t) * $click);
}
wav("{$out}/slot_giro.wav", lowpass($reels, 0.4));

// Slots: parada de rodillo (golpe seco grave).
wav("{$out}/slot_parada.wav", mix(tone(140, 0.12, 30, 0.6, 0.2), lowpass(array_map(fn ($i) => 0.3 * noise() * exp(-120 * $i / RATE), range(0, (int) (RATE * 0.05))), 0.3)));

// Slots: explosión de símbolos en cascada ("pop").
$pop = [];
$n = (int) (RATE * 0.12);
for ($i = 0; $i < $n; $i++) {
    $t = $i / RATE;
    $f = 900 - 5000 * $t;
    $pop[] = 0.5 * exp(-35 * $t) * sin(2 * M_PI * max(200, $f) * $t);
}
wav("{$out}/slot_cascada.wav", $pop);

// Slots: moneda / bombón / rayo (ding metálico).
wav("{$out}/slot_moneda.wav", mix(tone(1975.5, 0.45, 7, 0.35, 0.4), offset(tone(2637.0, 0.4, 8, 0.25, 0.3), 0.05)));

// Slots: entrada a un bonus (fanfarria corta).
wav("{$out}/slot_bonus.wav", mix(
    tone(392.0, 0.25, 4, 0.4), offset(tone(523.25, 0.25, 4, 0.4), 0.12), offset(tone(659.25, 0.25, 4, 0.4), 0.24),
    offset(tone(783.99, 0.8, 2.5, 0.45), 0.36), offset(tone(1046.5, 0.8, 2.5, 0.3), 0.36),
));

// Slots: gran premio (arpegio largo con brillo).
$notes = [523.25, 659.25, 783.99, 1046.5, 783.99, 1046.5, 1318.5];
$big = [];
foreach ($notes as $k => $f) {
    $big[] = offset(tone($f, 0.5, 4, 0.35, 0.3), $k * 0.11);
}
$big[] = offset(tone(1568.0, 1.2, 2, 0.3, 0.2), 0.8);
$big[] = offset(tone(2093.0, 1.2, 2, 0.2, 0.1), 0.8);
wav("{$out}/slot_granpremio.wav", mix(...$big));

echo "Sonidos generados en {$out}\n";
