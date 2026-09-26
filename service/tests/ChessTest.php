<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Games\Chess\Board;
use Claudia\Games\GameSession;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\Tests\Support\AppTestCase;

final class ChessTest extends AppTestCase
{
    /* ---------------- motor ---------------- */

    public function testPerftMatchesReferenceCounts(): void
    {
        $this->assertSame(8902, Board::start()->perft(3));
        // "Kiwipete": enroques, capturas al paso, coronaciones y clavadas.
        $this->assertSame(2039, Board::fromFen('r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1')->perft(2));
        $this->assertSame(2812, Board::fromFen('8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1')->perft(3));
        $this->assertSame(1486, Board::fromFen('rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8')->perft(2));
    }

    public function testSpanishNotation(): void
    {
        $b = Board::start();
        $m = $b->findMove(Board::index('g1'), Board::index('f3'));
        $this->assertSame('Cf3', $b->san($m));

        $b = Board::fromFen('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1');
        $this->assertSame('O-O', $b->san($b->findMove(Board::index('e1'), Board::index('g1'))));
        $this->assertSame('O-O-O', $b->san($b->findMove(Board::index('e1'), Board::index('c1'))));

        // Coronación con jaque y desambiguación de torres.
        $b = Board::fromFen('4k3/1P6/8/8/8/8/4K3/R6R w - - 0 1');
        $this->assertSame('b8=D+', $b->san($b->findMove(Board::index('b7'), Board::index('b8'), 'q')));
        $this->assertSame('b8=C', $b->san($b->findMove(Board::index('b7'), Board::index('b8'), 'n')));
        $this->assertSame('Tad1', $b->san($b->findMove(Board::index('a1'), Board::index('d1'))));
    }

    public function testDrawDetection(): void
    {
        $this->assertTrue(Board::fromFen('7k/5Q2/6K1/8/8/8/8/8 b - - 0 1')->isStalemate());
        $this->assertTrue(Board::fromFen('8/8/4k3/8/8/2B5/4K3/8 w - - 0 1')->insufficientMaterial());
        $this->assertTrue(Board::fromFen('8/8/4k3/8/2b5/8/2B1K3/8 w - - 0 1')->insufficientMaterial()); // alfiles del mismo color
        $this->assertFalse(Board::fromFen('8/8/4k3/8/3b4/8/2B1K3/8 w - - 0 1')->insufficientMaterial());
        $this->assertFalse(Board::fromFen('8/8/4k3/8/8/8/3PK3/8 w - - 0 1')->insufficientMaterial());
    }

    /* ---------------- partida ---------------- */

    /** @return array{0:Session, 1:Session} jugadores con saldo */
    private function players(int $coins = 5000): array
    {
        $a = $this->player(1, 'Ana');
        $b = $this->player(2, 'Beto');
        $this->app->wallet->credit((int) $a->userId, $coins, 'admin');
        $this->app->wallet->credit((int) $b->userId, $coins, 'admin');
        return [$a, $b];
    }

    /** Desafío + aceptar; devuelve las ventanas de blancas (Ana) y negras (Beto) ya conectadas. */
    private function start(Session $a, Session $b, int $bet = 1000): array
    {
        $this->cmd($a, "ajedrez Beto {$bet}");
        $this->events = [];
        $this->cmd($b, 'ajedrez aceptar');
        $w = $this->windowOf($a);
        $k = $this->windowOf($b);
        $this->app->games->receive($w, ['type' => 'hello']);
        $this->app->games->receive($k, ['type' => 'hello']);
        return [$w, $k];
    }

    private function windowOf(Session $p): GameSession
    {
        foreach (array_reverse($this->events) as $e) {
            if ($e['type'] === 'motd' && $e['data']['slot'] === $p->slot) {
                preg_match('/[?&]t=([0-9a-f]+)/', $e['data']['url'], $m);
                $s = $this->app->games->get($m[1]);
                $this->assertNotNull($s);
                return $s;
            }
        }
        $this->fail('No se abrió el tablero');
    }

    private function play(GameSession $s, string $from, string $to, string $promo = ''): void
    {
        $this->app->games->receive($s, ['type' => 'move', 'from' => $from, 'to' => $to, 'promo' => $promo]);
    }

    private function last(GameSession $s, string $type = 'state'): array
    {
        $list = array_values(array_filter($s->since(0), fn ($m) => $m['type'] === $type));
        return (array) end($list);
    }

    /** @return list<array> eventos del plugin de ese tipo */
    private function pluginEvents(string $type): array
    {
        return array_values(array_map(fn ($e) => $e['data'], array_filter($this->events, fn ($e) => $e['type'] === $type)));
    }

    public function testChallengeAcceptAndCheckmatePaysWinnerMinusHouseCut(): void
    {
        [$a, $b] = $this->players();
        [$w, $k] = $this->start($a, $b);

        // Las apuestas quedan retenidas y los dos pasan a espectador con voz privada.
        $this->assertSame(4000, $this->app->wallet->balance((int) $a->userId));
        $this->assertSame(4000, $this->app->wallet->balance((int) $b->userId));
        $this->assertCount(2, array_filter($this->pluginEvents('chess.spec'), fn ($d) => $d['on'] === true));
        $this->assertSame([['a' => 1, 'b' => 2, 'on' => true]], $this->pluginEvents('chess.pair'));

        $st = $this->last($w);
        $this->assertSame('w', $st['you']);
        $this->assertSame('b', $this->last($k)['you']);
        $this->assertSame(1900, $st['prize']); // 2000 menos el 5 %
        $this->assertNull($st['legal']);        // sin pistas no se mandan los movimientos

        // Mate del loco: 1.f3 e5 2.g4 Dh4#
        $this->play($w, 'f2', 'f3');
        $this->play($k, 'e7', 'e5');
        $this->play($w, 'g2', 'g4');
        $this->play($k, 'd8', 'h4');

        $final = $this->last($k);
        $this->assertTrue($final['over']);
        $this->assertSame('b', $final['result']['winner']);
        $this->assertSame('mate', $final['result']['reason']);
        $this->assertSame(900, $final['result']['net']);
        $this->assertSame(['f3', 'e5', 'g4', 'Dh4#'], $final['moves']);
        $this->assertSame(4000, $this->app->wallet->balance((int) $a->userId));
        $this->assertSame(5900, $this->app->wallet->balance((int) $b->userId));
        $this->assertStringContainsString('Beto le ganó a Ana por jaque mate', implode("\n", $this->chats(Out::ALL)));
        // Vuelven a su equipo y se corta la voz privada.
        $this->assertCount(2, array_filter($this->pluginEvents('chess.spec'), fn ($d) => $d['on'] === false));
        $this->assertNull($this->app->chess->matchOf((int) $a->userId));
    }

    public function testIllegalMoveAndWrongTurnAreRejected(): void
    {
        [$a, $b] = $this->players();
        [$w, $k] = $this->start($a, $b);
        $this->play($k, 'e7', 'e5');
        $this->assertStringContainsString('turno', $this->last($k, 'error')['message']);
        $this->play($w, 'e2', 'e5');
        $this->assertStringContainsString('ilegal', $this->last($w, 'error')['message']);
        $this->assertSame([], $this->last($w)['moves']);
    }

    public function testHintIsPaidOnceAndShowsLegalMoves(): void
    {
        [$a, $b] = $this->players();
        [$w, $k] = $this->start($a, $b);
        $this->app->games->receive($w, ['type' => 'hint']);
        $st = $this->last($w);
        $this->assertTrue($st['hint']);
        $this->assertEqualsCanonicalizing(['f3', 'h3'], $st['legal']['g1']);
        $this->assertEqualsCanonicalizing(['e3', 'e4'], $st['legal']['e2']);
        $this->assertSame(3750, $this->app->wallet->balance((int) $a->userId)); // 5000 - 1000 - 250
        $this->assertStringContainsString('compró las pistas', $this->last($k, 'chat')['text']);

        $this->app->games->receive($w, ['type' => 'hint']);
        $this->assertStringContainsString('Ya tenés', $this->last($w, 'error')['message']);
        $this->assertSame(3750, $this->app->wallet->balance((int) $a->userId));

        // Sigue valiendo en las jugadas siguientes (solo cuando le toca).
        $this->play($w, 'e2', 'e4');
        $this->assertNull($this->last($w)['legal']);
        $this->play($k, 'e7', 'e5');
        $this->assertArrayHasKey('d1', $this->last($w)['legal']);
        $this->assertNull($this->last($k)['legal']);
    }

    public function testResignAndDrawAgreement(): void
    {
        [$a, $b] = $this->players();
        [$w, $k] = $this->start($a, $b);
        $this->app->games->receive($w, ['type' => 'draw']);
        $this->assertSame('in', $this->last($k)['draw']);
        $this->app->games->receive($k, ['type' => 'draw_accept']);
        $this->assertSame('agreement', $this->last($w)['result']['reason']);
        $this->assertSame(5000, $this->app->wallet->balance((int) $a->userId));
        $this->assertSame(5000, $this->app->wallet->balance((int) $b->userId));

        $this->events = [];
        [$w, $k] = $this->start($a, $b);
        $this->app->games->receive($k, ['type' => 'resign']);
        $this->assertSame('w', $this->last($w)['result']['winner']);
        $this->assertSame(5900, $this->app->wallet->balance((int) $a->userId));
    }

    public function testChatReachesBothWithAntiFlood(): void
    {
        [$a, $b] = $this->players();
        [$w, $k] = $this->start($a, $b);
        $this->app->games->receive($w, ['type' => 'chat', 'text' => 'suerte che']);
        $this->assertEquals(['from' => 'Ana', 'text' => 'suerte che', 'own' => false, 'system' => false, 'type' => 'chat'], array_diff_key($this->last($k, 'chat'), ['seq' => 0]));
        $this->assertTrue($this->last($w, 'chat')['own']);
        $this->app->games->receive($w, ['type' => 'chat', 'text' => 'otra']);
        $this->assertStringContainsString('despacio', $this->last($w, 'error')['message']);
    }

    public function testVoiceButtonOnlyAffectsOwnPlayer(): void
    {
        [$a, $b] = $this->players();
        [$w] = $this->start($a, $b);
        $this->app->games->receive($w, ['type' => 'voice', 'on' => true]);
        $this->assertSame([['slot' => 1, 'on' => true]], $this->pluginEvents('chess.voice'));
        $this->assertTrue($this->last($w)['voice']);
        $this->app->games->receive($w, ['type' => 'voice', 'on' => false]);
        $this->assertSame(['slot' => 1, 'on' => false], $this->pluginEvents('chess.voice')[1]);
    }

    public function testTimeoutAndAbandon(): void
    {
        Clock::freeze(1_800_000_000);
        [$a, $b] = $this->players();
        [$w, $k] = $this->start($a, $b);
        $this->play($w, 'e2', 'e4');
        Clock::freeze(1_800_000_000 + 301); // a las negras se les termina el tiempo
        $this->app->chess->tick();
        $this->assertSame('time', $this->last($w)['result']['reason']);
        $this->assertSame('w', $this->last($w)['result']['winner']);

        // Se va del servidor en medio de la partida: pierde.
        $this->events = [];
        [$w, $k] = $this->start($a, $b);
        $this->app->chess->onLeave((int) $b->userId);
        $this->assertSame('abandon', $this->last($w)['result']['reason']);
        $this->assertSame(5900 + 900, $this->app->wallet->balance((int) $a->userId));
    }

    public function testCannotOpenOtherGamesOrChallengeWhilePlaying(): void
    {
        [$a, $b] = $this->players();
        $this->start($a, $b);
        $this->cmd($a, 'ruleta');
        $this->assertStringContainsString('Estás jugando al ajedrez', $this->lastChat($a->slot));
        $c = $this->player(3, 'Caro');
        $this->cmd($c, 'ajedrez Ana 100');
        $this->assertStringContainsString('ya está jugando', $this->lastChat($c->slot));
    }

    public function testChallengeValidation(): void
    {
        [$a] = $this->players();
        $this->cmd($a, 'ajedrez Ana 100');
        $this->assertStringContainsString('vos mismo', $this->lastChat($a->slot));
        $this->cmd($a, 'ajedrez Beto 10');
        $this->assertStringContainsString('entre', $this->lastChat($a->slot));
        $this->cmd($a, 'ajedrez Beto 999999');
        $this->assertStringContainsString('entre', $this->lastChat($a->slot));
    }
}
