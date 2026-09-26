<?php

declare(strict_types=1);

namespace Claudia\Tests\Support;

use Claudia\App;
use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Log;
use Claudia\Players\Session;
use PHPUnit\Framework\TestCase;

/**
 * Levanta la app completa con la configuración real, base en memoria, IA falsa y timers manuales.
 */
abstract class AppTestCase extends TestCase
{
    protected App $app;
    protected Config $config;
    protected FakeProvider $gemini;
    protected FakeProvider $groq;
    protected FakeTimers $timers;

    /** @var list<array{type:string, data:array}> */
    protected array $events = [];

    protected FakeRunner $runner;
    protected string $radioDir;

    protected function setUp(): void
    {
        Log::configure(null, 'error', false);
        Clock::freeze(1_800_000_000);
        Clock::setOffset(0);
        $this->config = new Config(dirname(__DIR__, 2) . '/config');
        $db = new Db(':memory:');
        $db->migrate(dirname(__DIR__, 2) . '/migrations');
        $this->gemini = new FakeProvider();
        $this->groq = new FakeProvider();
        $this->timers = new FakeTimers();
        $this->runner = new FakeRunner();
        // La radio escribe en una carpeta temporal, nunca en la del servidor de juego.
        $this->radioDir = sys_get_temp_dir() . '/claudia-test-cstrike-' . getmypid();
        $this->config->set('radio.cstrike_dir', $this->radioDir);
        $this->events = [];
        $this->app = new App(
            $this->config,
            $db,
            function (string $type, array $data): void {
                $this->events[] = ['type' => $type, 'data' => $data];
            },
            ['gemini' => $this->gemini, 'groq' => $this->groq],
            $this->timers,
            $this->runner,
        );
        $this->app->start();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        Clock::setOffset(0);
    }

    /** Conecta y registra un jugador. */
    protected function player(int $slot, string $nick, string $password = 'secreto1'): Session
    {
        $s = $this->app->sessions->join($slot, $nick, '10.0.0.' . $slot, 'STEAM_0:0:' . $slot);
        $this->app->auth->register($s, $password);
        return $s;
    }

    /** @return list<string> textos de chat enviados (sin códigos de color) */
    protected function chats(?int $to = null): array
    {
        $out = [];
        foreach ($this->events as $e) {
            if ($e['type'] === 'print' && $e['data']['channel'] === 'chat' && ($to === null || $e['data']['to'] === $to)) {
                $out[] = preg_replace('/[\x01-\x04]/', '', $e['data']['text']);
            }
        }
        return $out;
    }

    protected function lastChat(?int $to = null): string
    {
        $c = $this->chats($to);
        return (string) end($c);
    }

    protected function cmd(Session $s, string $line, bool $staff = false): void
    {
        [$name, $args] = array_pad(explode(' ', $line, 2), 2, '');
        $this->app->commands->dispatch($s, $name, $args, $staff);
    }

    protected function reply(string $text, bool $responder = true): array
    {
        return ['responder' => $responder, 'respuesta' => $text, 'pensamiento' => 'piensa algo', 'trato' => 'normal', 'datos' => []];
    }
}
