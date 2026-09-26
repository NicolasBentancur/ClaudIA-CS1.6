<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Tests\Support\AppTestCase;

final class StatsTest extends AppTestCase
{
    public function testStatsAccumulateAndRankings(): void
    {
        $a = $this->player(1, 'Ana');
        $b = $this->player(2, 'Beto');
        $c = $this->player(3, 'Caro');
        $this->app->stats->add((int) $a->userId, ['kills' => 10, 'deaths' => 2, 'headshots' => 4, 'shots' => 100, 'hits' => 25, 'playtime' => 600]);
        $this->app->stats->add((int) $b->userId, ['kills' => 30, 'deaths' => 20, 'headshots' => 1]);
        $this->app->stats->add((int) $c->userId, ['kills' => 1, 'deaths' => 9]);
        $this->app->wallet->credit((int) $c->userId, 900, 'admin');
        $this->app->wallet->credit((int) $a->userId, 100, 'admin');

        $s = $this->app->stats->get((int) $a->userId);
        $this->assertSame(25.0, $s['accuracy']);
        $this->assertSame(5.0, $s['kd']);
        $this->assertSame(11.0, $s['score']); // 10 - 1 + 2

        // Juego: Beto 30-10+0.5 = 20.5, Ana 11, Caro 1-4.5 = -3.5
        $this->assertSame(1, $this->app->stats->position('juego', (int) $b->userId));
        $this->assertSame(2, $this->app->stats->position('juego', (int) $a->userId));
        $this->assertSame(3, $this->app->stats->position('juego', (int) $c->userId));
        $this->assertSame(['Beto', 'Ana', 'Caro'], array_column($this->app->stats->top('juego'), 'nick'));

        // Economía: Caro 900, Ana 100, Beto 0
        $this->assertSame(1, $this->app->stats->position('economia', (int) $c->userId));
        $this->assertSame(3, $this->app->stats->position('economia', (int) $b->userId));
    }

    public function testProfileCommandsPublicAndStaff(): void
    {
        $a = $this->player(1, 'Ana');
        $b = $this->player(2, 'Beto');
        $this->app->memory->remember((int) $b->userId, 'pensamiento', 'Beto es un camper');
        $this->events = [];
        $this->cmd($a, 'perfil Beto');
        $chat = implode("\n", $this->chats(1));
        $this->assertStringContainsString('Perfil de Beto', $chat);
        $this->assertStringNotContainsString('Deuda', $chat);

        $this->events = [];
        $this->cmd($a, 'perfil beto', true);
        $console = implode("\n", array_map(fn ($e) => $e['data']['text'], array_filter($this->events, fn ($e) => $e['type'] === 'print' && $e['data']['channel'] === 'console')));
        $this->assertStringContainsString('Beto es un camper', $console);
        $this->assertStringContainsString('Deuda', implode("\n", $this->chats(1)));
    }

    public function testFullProfileFollowsRolesJson(): void
    {
        $a = $this->player(1, 'Ana');
        $b = $this->player(2, 'Beto');
        $this->app->memory->remember((int) $b->userId, 'pensamiento', 'Beto es un camper');
        // Como si en roles.json el perfil completo quedara solo para el owner.
        $this->config->set('roles.permissions', ['players.profile_full' => 'owner'] + $this->config->array('roles.permissions'));
        $this->events = [];
        $this->cmd($a, 'perfil beto', true);
        $chat = implode("\n", $this->chats(1));
        $console = implode("\n", array_map(fn ($e) => $e['data']['text'], array_filter($this->events, fn ($e) => $e['type'] === 'print' && $e['data']['channel'] === 'console')));
        $this->assertStringContainsString('Perfil de Beto', $chat);
        $this->assertStringNotContainsString('Beto es un camper', $chat . $console);
    }

    public function testMessagesCountForStatsAndJobActivity(): void
    {
        $a = $this->player(1, 'Ana');
        $this->app->jobs->apply((int) $a->userId, 'taxista', 'radiotaxi');
        $this->app->chat->onMessage($a, 'hola gente', false);
        $this->app->chat->onMessage($a, 'buenas', true);
        $this->assertSame(2, $this->app->stats->get((int) $a->userId)['messages']);
        $this->assertSame(2, (int) $this->app->jobs->employment((int) $a->userId)['act_messages']);
    }
}
