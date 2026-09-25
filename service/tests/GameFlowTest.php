<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Games\GameSession;
use Claudia\Tests\Support\AppTestCase;

final class GameFlowTest extends AppTestCase
{
    private function openGame(string $game, int $coins = 1000): GameSession
    {
        $p = $this->player(1, 'Ana');
        $this->app->wallet->credit((int) $p->userId, $coins, 'admin');
        $this->events = [];
        $s = $this->app->games->open($p, $game);
        $motd = array_values(array_filter($this->events, fn ($e) => $e['type'] === 'motd'));
        $this->assertCount(1, $motd);
        $this->assertStringContainsString("/juegos/{$game}/?t={$s->token}", $motd[0]['data']['url']);
        $this->app->games->receive($s, ['type' => 'hello']);
        return $s;
    }

    /** @return list<array> */
    private function messages(GameSession $s, string $type): array
    {
        return array_values(array_filter($s->since(0), fn ($m) => $m['type'] === $type));
    }

    /** @return list<string> */
    private function sounds(): array
    {
        return array_map(fn ($e) => $e['data']['sound'], array_values(array_filter($this->events, fn ($e) => $e['type'] === 'sound')));
    }

    public function testRouletteFullSpin(): void
    {
        $s = $this->openGame('ruleta');
        $init = $this->messages($s, 'init')[0];
        $this->assertSame(1000, $init['balance']);
        $this->assertSame([10, 25, 50, 100, 250], $init['chips']);

        $this->app->games->receive($s, ['type' => 'spin', 'bets' => [
            ['type' => 'rojo', 'amount' => 100],
            ['type' => 'pleno', 'numbers' => [0], 'amount' => 50],
        ]]);
        $start = $this->messages($s, 'spin_start')[0];
        $this->assertSame(850, $start['balance']);
        $this->assertSame(30, $start['fps']);

        // No se puede girar de nuevo mientras gira.
        $this->app->games->receive($s, ['type' => 'spin', 'bets' => [['type' => 'rojo', 'amount' => 100]]]);
        $this->assertStringContainsString('Esperá', $this->messages($s, 'error')[0]['message']);

        $this->timers->run();
        $frames = [];
        foreach ($this->messages($s, 'frames') as $m) {
            array_push($frames, ...$m['f']);
        }
        $this->assertGreaterThan(200, count($frames));
        $result = $this->messages($s, 'result')[0];
        $last = end($frames);
        $this->assertSame($result['number'], \Claudia\Games\Roulette\BallPhysics::pocketAt($last[1], $last[2]));

        $n = $result['number'];
        $expected = ($n === 0 ? 50 + 50 * 35 + 50 : 0) + (in_array($n, \Claudia\Games\Roulette\Wheel::RED, true) ? 200 : 0);
        $this->assertSame($expected, $result['payout']);
        $this->assertSame(850 + $expected, $result['balance']);
        $this->assertSame(850 + $expected, $this->app->wallet->balance($s->userId));

        $sounds = $this->sounds();
        $this->assertSame('claudia/ruleta_giro', $sounds[0]);
        $this->assertContains('claudia/ruleta_rebote', $sounds);
        $this->assertContains(end($sounds), ['claudia/ganar', 'claudia/perder']);

        // Queda como "juego reciente" para la IA.
        $this->assertNotNull($this->app->recentGames->recent($s->userId, \Claudia\Clock::now()));
    }

    public function testRouletteRejectsOutOfLimits(): void
    {
        $s = $this->openGame('ruleta');
        $this->app->games->receive($s, ['type' => 'spin', 'bets' => [['type' => 'rojo', 'amount' => 5]]]);
        $this->assertStringContainsString('entre', $this->messages($s, 'error')[0]['message']);
        $this->app->games->receive($s, ['type' => 'spin', 'bets' => [['type' => 'rojo', 'amount' => 20000]]]);
        $this->assertCount(2, $this->messages($s, 'error'));
        $this->assertSame(1000, $this->app->wallet->balance($s->userId));
    }

    public function testClosingMidSpinStillSettles(): void
    {
        $s = $this->openGame('ruleta');
        $this->app->games->receive($s, ['type' => 'spin', 'bets' => [['type' => 'rojo', 'amount' => 100]]]);
        $this->app->games->closeForUser($s->userId);
        $round = $this->app->db->one('SELECT * FROM game_rounds ORDER BY id DESC LIMIT 1');
        $this->assertSame('settled', $round['status']);
    }

    public function testBlackjackHandIsSettled(): void
    {
        $s = $this->openGame('blackjack');
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100]);
        // Jugar hasta que termine: plantarse siempre.
        for ($i = 0; $i < 5; $i++) {
            $states = $this->messages($s, 'state');
            $state = end($states);
            if ($state['phase'] === 'player') {
                $this->app->games->receive($s, ['type' => 'stand']);
            }
            $this->timers->run();
        }
        $states = $this->messages($s, 'state');
        $final = end($states);
        $this->assertSame('done', $final['phase']);
        $this->assertArrayHasKey('net', $final);
        $round = $this->app->db->one('SELECT * FROM game_rounds ORDER BY id DESC LIMIT 1');
        $this->assertSame('settled', $round['status']);
        $this->assertSame(1000 - 100 + (int) $round['payout'], $this->app->wallet->balance($s->userId));
        $this->assertNotContains('?', array_column($final['dealer']['cards'], 'r'));
    }

    public function testBlackjackDoubleNeedsBalance(): void
    {
        $s = $this->openGame('blackjack', 100);
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100]);
        $states = $this->messages($s, 'state');
        $state = end($states);
        if ($state['phase'] !== 'player') {
            $this->markTestSkipped('Salió blackjack en el reparto');
        }
        $this->assertFalse($state['actions']['double']);
        $this->app->games->receive($s, ['type' => 'double']);
        $this->assertNotEmpty($this->messages($s, 'error'));
    }

    public function testNewGameReplacesOldSession(): void
    {
        $s = $this->openGame('ruleta');
        $p = $this->app->sessions->get(1);
        $s2 = $this->app->games->open($p, 'blackjack');
        $this->assertNull($this->app->games->get($s->token));
        $this->assertNotNull($this->app->games->get($s2->token));
    }
}
