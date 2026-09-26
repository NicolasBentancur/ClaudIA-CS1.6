<?php

declare(strict_types=1);

namespace Claudia\Radio;

use Claudia\App;
use Claudia\Clock;
use Claudia\Log;
use Claudia\Net\Out;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Radio por el chat de voz (config/radio.json).
 *
 * /radio <búsqueda o link de YouTube> encola un tema. De a uno por vez, el servicio lo baja con
 * yt-dlp y ffmpeg lo convierte a wav mono de 16 kHz partido en trozos de pocos segundos dentro de
 * la carpeta del juego (cstrike/radio/<id>/000.wav...). Cuando le toca, manda "radio.play" y el
 * plugin claudia_radio reproduce cada trozo con VoiceTranscoder (VTC_PlaySound) a los que la
 * escuchan. Los trozos permiten cortar un tema o apagar la radio sin esperar a que termine.
 */
final class RadioService
{
    /**
     * @var list<array{id:int, uid:int, nick:string, query:string, title:string, duration:int,
     *   status:string, proc:?int, step:string, started:int, src:string, chunks:list<int>}>
     */
    private array $queue = [];
    private ?array $playing = null;
    private float $endsAt = 0.0;
    /** @var array<int, true> votos para saltar el tema actual */
    private array $skipVotes = [];
    /** @var array<int, int> carpetas de temas terminados -> cuándo borrarlas */
    private array $trash = [];
    private int $nextId = 1;

    public function __construct(private readonly App $app, private readonly ProcessRunner $runner)
    {
    }

    private function cfg(string $key, mixed $default): mixed
    {
        return $this->app->config->get("radio.{$key}", $default);
    }

    /** Carpeta absoluta del juego (cstrike). */
    private function gameDir(): string
    {
        $dir = (string) $this->cfg('cstrike_dir', '../.tools/hlds_legacy/cstrike');
        if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $dir)) {
            $dir = dirname(__DIR__, 2) . '/' . $dir;
        }
        return rtrim($dir, '/\\');
    }

    private function subdir(): string
    {
        return trim((string) $this->cfg('subdir', 'radio'), '/\\');
    }

    private function trackDir(int $id): string
    {
        return $this->gameDir() . '/' . $this->subdir() . '/' . $id;
    }

    /** Al arrancar: borra lo que haya quedado de una ejecución anterior. */
    public function start(): void
    {
        $base = $this->gameDir() . '/' . $this->subdir();
        if (is_dir($base)) {
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $this->removeDir($d);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Comandos
     * ---------------------------------------------------------------- */

    public function request(int $uid, string $nick, string $query): array
    {
        if (!$this->app->config->bool('radio.enabled', true)) {
            throw new UserError('La radio está apagada por ahora.');
        }
        $query = trim(preg_replace('/\s+/', ' ', Text::sanitize($query)) ?? '');
        if ($query === '' || mb_strlen($query) > 150) {
            throw new UserError('Decime qué tema (o pegá un link de YouTube).');
        }
        if (preg_match('#^https?://#i', $query) && !$this->allowedUrl($query)) {
            throw new UserError('Solo acepto links de YouTube. Si no, escribí el nombre del tema.');
        }
        $mine = count(array_filter($this->queue, fn ($t) => $t['uid'] === $uid));
        $maxPer = (int) $this->cfg('max_per_player', 2);
        if ($mine >= $maxPer) {
            throw new UserError("Ya tenés {$maxPer} temas en la cola. Esperá a que suenen.");
        }
        if (count($this->queue) >= (int) $this->cfg('max_queue', 10)) {
            throw new UserError('La cola está llena. Probá en un rato.');
        }
        $item = ['id' => $this->nextId++, 'uid' => $uid, 'nick' => $nick, 'query' => $query, 'title' => $query,
            'duration' => 0, 'status' => 'pending', 'proc' => null, 'step' => '', 'started' => 0, 'src' => '', 'chunks' => []];
        $this->queue[] = $item;
        $this->pump();
        return $item;
    }

    private function allowedUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'], true);
    }

    /**
     * Saltar el tema: el que lo pidió o el staff lo cortan al instante; el resto vota
     * (hace falta skip_ratio de los jugadores conectados).
     * @return array{skipped:bool, votes:int, needed:int}
     */
    public function skip(int $uid, bool $staff): array
    {
        if ($this->playing === null) {
            throw new UserError('No está sonando nada.');
        }
        if ($staff || $this->playing['uid'] === $uid) {
            $this->stopCurrent('saltado');
            return ['skipped' => true, 'votes' => 0, 'needed' => 0];
        }
        $this->skipVotes[$uid] = true;
        $humans = max(1, count($this->app->sessions->logged()));
        $needed = max(1, (int) ceil($humans * (float) $this->cfg('skip_ratio', 0.5)));
        if (count($this->skipVotes) >= $needed) {
            $this->stopCurrent('saltado por votación');
            return ['skipped' => true, 'votes' => count($this->skipVotes), 'needed' => $needed];
        }
        return ['skipped' => false, 'votes' => count($this->skipVotes), 'needed' => $needed];
    }

    /** Saca de la cola un tema propio (o cualquiera si es staff) que todavía no suena. */
    public function remove(int $uid, bool $staff, int $position): string
    {
        $item = $this->queue[$position - 1] ?? null;
        if ($item === null) {
            throw new UserError('No hay ningún tema en esa posición de la cola.');
        }
        if (!$staff && $item['uid'] !== $uid) {
            throw new UserError('Solo podés sacar tus temas.');
        }
        if ($item['proc'] !== null) {
            $this->runner->kill($item['proc']);
        }
        array_splice($this->queue, $position - 1, 1);
        $this->removeDir($this->trackDir($item['id']));
        $this->pump();
        return $item['title'];
    }

    /** @return array{playing:?array, queue:list<array>} */
    public function status(): array
    {
        return ['playing' => $this->playing, 'queue' => $this->queue];
    }

    /* ------------------------------------------------------------------
     * Preparación (yt-dlp + ffmpeg) y reproducción
     * ---------------------------------------------------------------- */

    /** Cada segundo: avanza las descargas, pasa al tema siguiente y limpia carpetas viejas. */
    public function tick(): void
    {
        foreach ($this->queue as $i => $item) {
            if ($item['proc'] !== null) {
                $this->pollItem($i);
            }
        }
        if ($this->playing !== null && Clock::micro() > $this->endsAt) {
            // El plugin no avisó que terminó (se cortó la conexión, cambio de mapa...).
            $this->finishCurrent();
        }
        $this->pump();
        foreach ($this->trash as $id => $at) {
            if (Clock::now() >= $at) {
                $this->removeDir($this->trackDir($id));
                unset($this->trash[$id]);
            }
        }
    }

    /** El plugin terminó de pasar el último trozo. */
    public function done(int $id): void
    {
        if ($this->playing !== null && $this->playing['id'] === $id) {
            $this->finishCurrent();
            $this->pump();
        }
    }

    /** Arranca la preparación del primero pendiente y, si no suena nada, el primero listo. */
    private function pump(): void
    {
        $busy = false;
        foreach ($this->queue as $item) {
            if ($item['proc'] !== null) {
                $busy = true;
            }
        }
        if (!$busy) {
            foreach ($this->queue as $i => $item) {
                if ($item['status'] === 'pending') {
                    $this->download($i);
                    break;
                }
            }
        }
        if ($this->playing === null && isset($this->queue[0]) && $this->queue[0]['status'] === 'ready') {
            $this->play(array_shift($this->queue));
        }
    }

    private function download(int $i): void
    {
        $item = &$this->queue[$i];
        $dir = $this->trackDir($item['id']);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail($i, 'no pude crear la carpeta de la radio');
            return;
        }
        $query = $item['query'];
        $target = preg_match('#^https?://#i', $query) ? $query : 'ytsearch1:' . $query;
        $max = (int) $this->cfg('max_duration', 480);
        $item['status'] = 'downloading';
        $item['step'] = 'download';
        $item['started'] = Clock::now();
        try {
            $item['proc'] = $this->runner->start([
                $this->tool('yt_dlp', '../.tools/radio/yt-dlp.exe'),
                '--no-playlist', '--no-warnings', '--no-progress', '--encoding', 'utf-8',
                '-f', 'bestaudio/best', '--max-filesize', '60M',
                '--match-filter', "duration <= {$max} & !is_live",
                '-o', $dir . '/src.%(ext)s',
                '--no-simulate', '--print', 'after_move:%(title)s|||%(duration)s|||%(filepath)s',
                '--', $target,
            ], ['PYTHONIOENCODING' => 'utf-8']);
        } catch (\Throwable $e) {
            $item['proc'] = null;
            Log::error('Radio: no pude lanzar yt-dlp: ' . $e->getMessage());
            $this->fail($i, 'la radio no está bien instalada');
        }
    }

    private function pollItem(int $i): void
    {
        $item = &$this->queue[$i];
        $timeout = (int) $this->cfg('download_timeout', 180);
        $r = $this->runner->poll((int) $item['proc']);
        if ($r === null) {
            if (Clock::now() - $item['started'] > $timeout) {
                $this->runner->kill((int) $item['proc']);
                $item['proc'] = null;
                $this->fail($i, 'tardó demasiado en bajar');
            }
            return;
        }
        $item['proc'] = null;
        if ($item['step'] === 'download') {
            $line = trim((string) strtok(trim($r['out']), "\n"));
            $parts = explode('|||', $line);
            if ($r['code'] !== 0 || count($parts) < 3 || !is_file($parts[2])) {
                Log::warning('Radio: yt-dlp falló', ['query' => $item['query'], 'code' => $r['code'], 'err' => mb_substr(trim($r['err']), -300)]);
                $this->fail($i, $line === '' && $r['code'] === 0
                    ? 'no lo encontré, o dura más de ' . intdiv((int) $this->cfg('max_duration', 480), 60) . ' minutos, o es un vivo'
                    : 'no lo pude bajar');
                return;
            }
            $item['title'] = mb_substr(Text::chatSafe(Text::sanitize($parts[0])), 0, 80);
            $item['duration'] = (int) round((float) $parts[1]);
            $item['src'] = $parts[2];
            $this->analyze($i);
            return;
        }
        if ($item['step'] === 'analyze') {
            // loudnorm en modo medición deja un JSON al final de stderr.
            $gain = 0.0;
            if (preg_match('/\{[^{}]*"input_i"[^{}]*\}/s', $r['err'], $m)) {
                $j = json_decode($m[0], true);
                $gain = self::linearGain((float) ($j['input_i'] ?? 0), (float) ($j['input_tp'] ?? 0),
                    (float) $this->cfg('target_lufs', -18), (float) $this->cfg('max_peak_db', -1.5));
            } else {
                Log::warning('Radio: no pude medir el volumen', ['query' => $item['query'], 'err' => mb_substr(trim($r['err']), -300)]);
            }
            $this->convert($i, $gain);
            return;
        }
        // Conversión terminada: se miden los trozos.
        @unlink($item['src']);
        $chunks = [];
        foreach (glob($this->trackDir($item['id']) . '/*.wav') ?: [] as $f) {
            $bytes = (int) filesize($f);
            $chunks[] = (int) round(max(0, $bytes - 44) / 2 / (int) $this->cfg('sample_rate', 16000) * 1000);
        }
        if ($r['code'] !== 0 || $chunks === []) {
            Log::warning('Radio: ffmpeg falló', ['query' => $item['query'], 'code' => $r['code'], 'err' => mb_substr(trim($r['err']), -300)]);
            $this->fail($i, 'no lo pude convertir');
            return;
        }
        $item['chunks'] = $chunks;
        $item['status'] = 'ready';
    }

    /**
     * Ganancia fija (dB) para llevar el tema a $target LUFS sin que el pico pase de $maxPeak:
     * normalización lineal, sin compresor ni limitador (la música conserva su dinámica).
     */
    public static function linearGain(float $inputI, float $inputTp, float $target, float $maxPeak): float
    {
        if ($inputI <= -70.0) {
            return 0.0; // silencio o medición inválida
        }
        return round(min($target - $inputI, $maxPeak - $inputTp), 2);
    }

    /** Primera pasada de ffmpeg: mide la sonoridad y el pico real del tema. */
    private function analyze(int $i): void
    {
        $item = &$this->queue[$i];
        $item['status'] = 'converting';
        $item['step'] = 'analyze';
        $item['started'] = Clock::now();
        try {
            $item['proc'] = $this->runner->start([
                $this->tool('ffmpeg', '../.tools/radio/ffmpeg/bin/ffmpeg.exe'),
                '-hide_banner', '-nostats', '-i', $item['src'], '-vn', '-ac', '1',
                '-af', 'loudnorm=print_format=json', '-f', 'null', '-',
            ]);
        } catch (\Throwable $e) {
            $item['proc'] = null;
            Log::error('Radio: no pude lanzar ffmpeg: ' . $e->getMessage());
            $this->fail($i, 'la radio no está bien instalada');
        }
    }

    /** Segunda pasada: ganancia fija, remuestreo de alta calidad y trozos wav. */
    private function convert(int $i, float $gain): void
    {
        $item = &$this->queue[$i];
        $item['status'] = 'converting';
        $item['step'] = 'convert';
        $item['started'] = Clock::now();
        $seconds = (int) $this->cfg('chunk_seconds', 6);
        $rate = (int) $this->cfg('sample_rate', 16000);
        $pre = trim((string) $this->cfg('prefilter', 'highpass=f=40'));
        $filter = ($pre !== '' ? $pre . ',' : '') . sprintf('volume=%.2fdB', $gain)
            . ",aresample={$rate}:resampler=soxr:precision=28:dither_method=triangular";
        try {
            $item['proc'] = $this->runner->start([
                $this->tool('ffmpeg', '../.tools/radio/ffmpeg/bin/ffmpeg.exe'),
                '-hide_banner', '-loglevel', 'error', '-y', '-i', $item['src'], '-vn',
                '-ac', '1', '-c:a', 'pcm_s16le',
                '-af', $filter, '-map_metadata', '-1', '-fflags', '+bitexact', '-flags:a', '+bitexact',
                '-f', 'segment', '-segment_time', (string) $seconds, '-reset_timestamps', '1',
                $this->trackDir($item['id']) . '/%03d.wav',
            ]);
        } catch (\Throwable $e) {
            $item['proc'] = null;
            Log::error('Radio: no pude lanzar ffmpeg: ' . $e->getMessage());
            $this->fail($i, 'la radio no está bien instalada');
        }
    }

    private function tool(string $key, string $default): string
    {
        $path = (string) $this->cfg($key, $default);
        if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) && str_contains($path, '/')) {
            $path = dirname(__DIR__, 2) . '/' . $path;
        }
        return $path;
    }

    private function fail(int $i, string $why): void
    {
        $item = $this->queue[$i];
        array_splice($this->queue, $i, 1);
        $this->removeDir($this->trackDir($item['id']));
        $this->app->notify($item['uid'], "Radio: \"{$item['query']}\": {$why}.");
    }

    private function play(array $item): void
    {
        $this->playing = $item;
        $this->skipVotes = [];
        $total = array_sum($item['chunks']) / 1000;
        $this->endsAt = Clock::micro() + $total + 15;
        $this->app->out->event('radio.play', [
            'id' => $item['id'],
            'dir' => $this->subdir() . '/' . $item['id'],
            'chunks' => $item['chunks'],
            'title' => $item['title'],
        ]);
        $mins = intdiv($item['duration'], 60) . ':' . str_pad((string) ($item['duration'] % 60), 2, '0', STR_PAD_LEFT);
        $this->app->out->chat(Out::ALL, "Radio: sonando {green}{$item['title']}{default} ({$mins}), pedido por {$item['nick']}. /radio off para no escucharla.");
        Log::info('Radio: sonando', ['titulo' => $item['title'], 'por' => $item['nick']]);
    }

    private function stopCurrent(string $why): void
    {
        if ($this->playing === null) {
            return;
        }
        $this->app->out->event('radio.stop', ['id' => $this->playing['id']]);
        $this->app->out->chat(Out::ALL, "Radio: {$this->playing['title']} ({$why}).");
        $this->finishCurrent();
        $this->pump();
    }

    private function finishCurrent(): void
    {
        if ($this->playing === null) {
            return;
        }
        // Los últimos trozos pueden estar sonando todavía: la carpeta se borra un rato después.
        $this->trash[$this->playing['id']] = Clock::now() + 30;
        $this->playing = null;
        $this->skipVotes = [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
