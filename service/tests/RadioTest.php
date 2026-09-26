<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\Tests\Support\AppTestCase;

final class RadioTest extends AppTestCase
{
    protected function tearDown(): void
    {
        foreach (glob($this->radioDir . '/radio/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->radioDir . '/radio/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->radioDir . '/radio');
        @rmdir($this->radioDir);
        parent::tearDown();
    }

    /**
     * Simula yt-dlp (deja el audio "bajado") y ffmpeg (deja $chunks trozos de 6 s) para el
     * proceso que esté corriendo, y avanza el servicio.
     */
    private function prepare(string $title = 'Tema de prueba', int $duration = 12, int $chunks = 2): void
    {
        $dl = $this->runner->running();
        $this->assertNotNull($dl, 'no se lanzó yt-dlp');
        $cmd = $this->runner->procs[$dl]['cmd'];
        $dir = dirname($cmd[array_search('-o', $cmd, true) + 1]);
        file_put_contents("{$dir}/src.webm", 'audio');
        $this->runner->finish($dl, 0, "{$title}|||{$duration}|||{$dir}/src.webm\n");
        $this->app->radio->tick();

        // Primera pasada de ffmpeg: medición de volumen.
        $an = $this->runner->running();
        $this->assertNotNull($an, 'no se lanzó la medición');
        $this->assertContains('loudnorm=print_format=json', $this->runner->procs[$an]['cmd']);
        $this->runner->finish($an, 0, '', "[Parsed_loudnorm_0 @ 0000]\n{\n\t\"input_i\" : \"-10.00\",\n\t\"input_tp\" : \"-0.50\",\n\t\"input_lra\" : \"6.00\"\n}\n");
        $this->app->radio->tick();

        $conv = $this->runner->running();
        $filter = $this->runner->procs[$conv]['cmd'][array_search('-af', $this->runner->procs[$conv]['cmd'], true) + 1];
        $this->assertStringContainsString('volume=-8.00dB', $filter); // de -10 a -18 LUFS
        $this->assertStringNotContainsString('loudnorm', $filter);    // sin compresión dinámica
        $this->assertNotNull($conv, 'no se lanzó ffmpeg');
        $this->assertStringContainsString('ffmpeg', $this->runner->procs[$conv]['cmd'][0]);
        for ($i = 0; $i < $chunks; $i++) {
            // 6 s de audio a 16 kHz, 16 bits: 192.000 bytes + cabecera.
            file_put_contents(sprintf('%s/%03d.wav', $dir, $i), str_repeat("\0", 44 + 192000));
        }
        $this->runner->finish($conv, 0);
        $this->app->radio->tick();
    }

    private function plugin(string $type): array
    {
        return array_values(array_map(fn ($e) => $e['data'], array_filter($this->events, fn ($e) => $e['type'] === $type)));
    }

    private function listener(): Session
    {
        return $this->player(1, 'Ana');
    }

    public function testRequestDownloadsConvertsAndPlays(): void
    {
        $ana = $this->listener();
        $this->cmd($ana, 'radio yendo a la casa de damian');
        $this->assertStringContainsString('buscando', $this->lastChat($ana->slot));

        $cmd = $this->runner->procs[$this->runner->running()]['cmd'];
        $this->assertStringContainsString('yt-dlp', $cmd[0]);
        $this->assertSame('ytsearch1:yendo a la casa de damian', end($cmd));
        $this->assertSame('--', $cmd[count($cmd) - 2]); // nada de lo que escribe el jugador se toma como opción

        $this->prepare('Yendo a la casa de Damián', 292, 2);
        $play = $this->plugin('radio.play');
        $this->assertCount(1, $play);
        $this->assertSame('radio/1', $play[0]['dir']);
        $this->assertSame([6000, 6000], $play[0]['chunks']);
        $this->assertStringContainsString('sonando Yendo a la casa de Damián (4:52), pedido por Ana', implode("\n", $this->chats(Out::ALL)));
        $this->assertFileDoesNotExist($this->radioDir . '/radio/1/src.webm'); // el original se borra

        // El plugin avisa que terminó: se libera y la carpeta se borra más tarde.
        $this->app->radio->done(1);
        $this->assertNull($this->app->radio->status()['playing']);
    }

    public function testLinearGainTargetsLoudnessWithoutClipping(): void
    {
        $this->assertSame(-8.0, \Claudia\Radio\RadioService::linearGain(-10.0, -8.0, -18.0, -1.5)); // bajar al objetivo
        $this->assertSame(4.5, \Claudia\Radio\RadioService::linearGain(-25.0, -6.0, -18.0, -1.5));  // subir, sin pasar el pico
        $this->assertSame(0.0, \Claudia\Radio\RadioService::linearGain(-99.0, -99.0, -18.0, -1.5)); // silencio
    }

    public function testQueuePlaysInOrderOneDownloadAtATime(): void
    {
        $ana = $this->listener();
        $beto = $this->player(2, 'Beto');
        $this->cmd($ana, 'radio tema uno');
        $this->cmd($beto, 'radio tema dos');
        $this->assertCount(1, array_filter($this->runner->procs, fn ($p) => $p['result'] === null));

        $this->prepare('Uno');
        $this->assertSame('Uno', $this->plugin('radio.play')[0]['title']);
        $this->prepare('Dos'); // se prepara mientras suena el primero
        $this->assertCount(1, $this->plugin('radio.play'));
        $this->app->radio->done(1);
        $this->assertSame('Dos', $this->plugin('radio.play')[1]['title']);
    }

    public function testLimitsAndUrlValidation(): void
    {
        $ana = $this->listener();
        $this->cmd($ana, 'radio https://example.com/virus.mp3');
        $this->assertStringContainsString('Solo acepto links de YouTube', $this->lastChat($ana->slot));
        $this->cmd($ana, 'radio https://youtu.be/abc123');
        $this->assertStringContainsString('buscando', $this->lastChat($ana->slot));
        $cmd = $this->runner->procs[$this->runner->running()]['cmd'];
        $this->assertSame('https://youtu.be/abc123', end($cmd));
        $this->cmd($ana, 'radio otro');
        $this->cmd($ana, 'radio tercero');
        $this->assertStringContainsString('Ya tenés 2 temas', $this->lastChat($ana->slot));
    }

    public function testNotFoundOrTooLongNotifiesRequester(): void
    {
        $ana = $this->listener();
        $this->cmd($ana, 'radio algo de tres horas');
        $this->runner->finish($this->runner->running(), 0, ''); // --match-filter lo descartó
        $this->app->radio->tick();
        $this->assertStringContainsString('dura más de 8 minutos', $this->lastChat($ana->slot));
        $this->assertSame([], $this->app->radio->status()['queue']);
    }

    public function testSkipByRequesterStaffOrVote(): void
    {
        $ana = $this->listener();
        $beto = $this->player(2, 'Beto');
        $caro = $this->player(3, 'Caro');
        $dani = $this->player(4, 'Dani');
        $this->cmd($ana, 'radio tema');
        $this->prepare();

        // 4 conectados: hacen falta 2 votos.
        $this->cmd($beto, 'radio saltar');
        $this->assertStringContainsString('(1/2)', implode("\n", $this->chats(Out::ALL)));
        $this->assertNotNull($this->app->radio->status()['playing']);
        $this->cmd($caro, 'radio saltar');
        $this->assertNull($this->app->radio->status()['playing']);
        $this->assertSame([['id' => 1]], $this->plugin('radio.stop'));

        // El que lo pidió lo corta al instante.
        $this->cmd($dani, 'radio otro');
        $this->prepare();
        $this->cmd($dani, 'radio saltar');
        $this->assertNull($this->app->radio->status()['playing']);
    }

    public function testQueueListAndRemove(): void
    {
        $ana = $this->listener();
        $beto = $this->player(2, 'Beto');
        $this->cmd($ana, 'radio primero');
        $this->prepare('Primero');
        $this->cmd($beto, 'radio segundo');
        $this->cmd($ana, 'radio cola');
        $this->assertStringContainsString('Sonando: Primero (Ana) | 1. segundo (Beto)', $this->lastChat($ana->slot));
        $this->cmd($ana, 'radio sacar 1');
        $this->assertStringContainsString('Solo podés sacar tus temas', $this->lastChat($ana->slot));
        $this->cmd($beto, 'radio sacar 1');
        $this->assertSame([], $this->app->radio->status()['queue']);
    }
}
