<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Games\GameSession;
use Claudia\Games\Mines\MinesGame;
use Claudia\Net\Out;
use Claudia\Tests\Support\AppTestCase;

final class MinesTest extends AppTestCase
{
    private function open(int $coins = 1000): GameSession
    {
        $p = $this->player(1, 'Ana');
        $this->app->wallet->credit((int) $p->userId, $coins, 'admin');
        $s = $this->app->games->open($p, 'minas');
        $this->app->games->receive($s, ['type' => 'hello']);
        return $s;
    }

    private function last(GameSession $s, string $type = 'state'): array
    {
        $list = array_values(array_filter($s->since(0), fn ($m) => $m['type'] === $type));
        return (array) end($list);
    }

    /** @return list<int> casillas sin mina de la ronda en juego */
    private function safeCells(GameSession $s): array
    {
        return array_values(array_diff(range(0, 24), $s->state['round']['positions']));
    }

    public function testMultiplierFormula(): void
    {
        $this->assertSame(1.0, MinesGame::multiplier(3, 0, 0.97));
        $this->assertSame(1.1, MinesGame::multiplier(3, 1, 0.97));   // 25/22 * 0.97 = 1.102
        $this->assertSame(24.25, MinesGame::multiplier(24, 1, 0.97)); // 25/1 * 0.97
        $this->assertSame(24.25, MinesGame::multiplier(1, 24, 0.97)); // 25 casillas, 1 mina, todas destapadas
    }

    public function testMinesAreHiddenUntilTheEnd(): void
    {
        $s = $this->open();
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100, 'mines' => 3]);
        $state = $this->last($s);
        $this->assertSame('playing', $state['phase']);
        $this->assertSame(900, $state['balance']);
        $this->assertArrayNotHasKey('positions', $state['round']);
        $this->assertCount(3, $s->state['round']['positions']);
        $this->assertCount(3, array_unique($s->state['round']['positions']));
    }

    public function testCashoutPaysMultiplier(): void
    {
        $s = $this->open();
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100, 'mines' => 3]);
        $safe = $this->safeCells($s);
        $this->app->games->receive($s, ['type' => 'reveal', 'cell' => $safe[0]]);
        $this->app->games->receive($s, ['type' => 'reveal', 'cell' => $safe[1]]);
        $state = $this->last($s);
        $expected = (int) floor(100 * MinesGame::multiplier(3, 2, 0.97));
        $this->assertSame($expected, $state['round']['win']);

        $this->app->games->receive($s, ['type' => 'cashout']);
        $state = $this->last($s);
        $this->assertSame('done', $state['phase']);
        $this->assertSame($expected, $state['last']['payout']);
        $this->assertCount(3, $state['last']['mines']);
        $this->assertSame(900 + $expected, $this->app->wallet->balance($s->userId));
    }

    public function testMineLosesBet(): void
    {
        $s = $this->open();
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100, 'mines' => 5]);
        $mine = $s->state['round']['positions'][0];
        $this->app->games->receive($s, ['type' => 'reveal', 'cell' => $mine]);
        $state = $this->last($s);
        $this->assertSame('done', $state['phase']);
        $this->assertSame(0, $state['last']['payout']);
        $this->assertSame($mine, $state['last']['hit']);
        $this->assertSame(900, $this->app->wallet->balance($s->userId));
    }

    public function testCannotCashoutWithoutRevealingAndLimits(): void
    {
        $s = $this->open();
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100, 'mines' => 25]);
        $this->assertStringContainsString('minas', $this->last($s, 'error')['message']);
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100, 'mines' => 3]);
        $this->app->games->receive($s, ['type' => 'cashout']);
        $this->assertStringContainsString('Destapá', $this->last($s, 'error')['message']);
    }

    public function testClosingMidRoundCashesOut(): void
    {
        $s = $this->open();
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 100, 'mines' => 3]);
        $this->app->games->receive($s, ['type' => 'reveal', 'cell' => $this->safeCells($s)[0]]);
        $this->app->games->closeForUser($s->userId);
        $round = $this->app->db->one('SELECT * FROM game_rounds ORDER BY id DESC LIMIT 1');
        $this->assertSame('settled', $round['status']);
        $this->assertSame(110, (int) $round['payout']);
    }

    public function testBigWinAndBigLossAreAnnounced(): void
    {
        $s = $this->open(20000);
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 10000, 'mines' => 24]);
        $this->app->games->receive($s, ['type' => 'reveal', 'cell' => $this->safeCells($s)[0]]); // x24,25 -> termina solo
        $this->assertStringContainsString('Gran victoria', implode("\n", $this->chats(Out::ALL)));
        $this->assertStringContainsString('Ana ganó 232.500', implode("\n", $this->chats(Out::ALL)));

        $this->events = [];
        $this->app->games->receive($s, ['type' => 'bet', 'amount' => 10000, 'mines' => 3]);
        $this->app->games->receive($s, ['type' => 'reveal', 'cell' => $s->state['round']['positions'][0]]);
        $this->assertStringContainsString('perdió 10.000 URU Coins en Minas', implode("\n", $this->chats(Out::ALL)));
    }

    public function testFirstRegistrationIsAnnounced(): void
    {
        $this->player(2, 'Beto');
        $this->assertStringContainsString('Beto se registró por primera vez', implode("\n", $this->chats(Out::ALL)));
    }
}
