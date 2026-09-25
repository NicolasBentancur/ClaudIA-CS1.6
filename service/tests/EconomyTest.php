<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Tests\Support\AppTestCase;
use Claudia\UserError;

final class EconomyTest extends AppTestCase
{
    public function testUsersStartWithZeroAndCanTransfer(): void
    {
        $a = $this->player(1, 'Ana');
        $b = $this->player(2, 'Beto');
        $this->assertSame(0, $this->app->wallet->balance((int) $a->userId));
        $this->app->wallet->credit((int) $a->userId, 1000, 'admin');
        $this->cmd($a, 'transferir Beto 300');
        $this->assertSame(700, $this->app->wallet->balance((int) $a->userId));
        $this->assertSame(300, $this->app->wallet->balance((int) $b->userId));
        $this->assertStringContainsString('te pasó', $this->lastChat(2));
    }

    public function testTransferFailsWithoutFunds(): void
    {
        $a = $this->player(1, 'Ana');
        $this->player(2, 'Beto');
        $this->cmd($a, 'transferir Beto 50');
        $this->assertStringContainsString('No te alcanza', $this->lastChat(1));
    }

    public function testAdminCoinsNeverGoNegative(): void
    {
        $a = $this->player(1, 'Ana');
        \Claudia\Commands\EconomyCommands::adminCoins($this->app, 0, 'admin', 'Ana', '500', true);
        $this->assertSame(500, $this->app->wallet->balance((int) $a->userId));
        \Claudia\Commands\EconomyCommands::adminCoins($this->app, 0, 'admin', 'Ana', '800', false);
        $this->assertSame(0, $this->app->wallet->balance((int) $a->userId));
    }

    public function testBrouRequiresJob(): void
    {
        $a = $this->player(1, 'Ana');
        $this->expectException(UserError::class);
        $this->app->loans->request((int) $a->userId, 'brou', 1000, 7);
    }

    public function testLoanInterestSurchargesClearingAndConfiscation(): void
    {
        $a = $this->player(1, 'Ana');
        $uid = (int) $a->userId;
        $loan = $this->app->loans->request($uid, 'santander', 1000, 7);
        $this->assertSame(1000, $this->app->wallet->balance($uid));
        $this->assertSame(1100, (int) $loan['outstanding']); // 10% a 7 días

        // 4 días de atraso: recargos pero todavía no entra al Clearing.
        Clock::advance(7 * 86400 + 4 * 86400 + 60);
        $this->app->loans->tick();
        $this->assertFalse($this->app->loans->inClearing($uid));
        $expected = 1100;
        for ($i = 0; $i < 4; $i++) {
            $expected += (int) ceil($expected * 0.03);
        }
        $this->assertSame($expected, $this->app->loans->totalDebt($uid));

        // 5 días: entra al Clearing, ya no puede pedir.
        Clock::advance(86400);
        $this->app->loans->tick();
        $this->assertTrue($this->app->loans->inClearing($uid));
        try {
            $this->app->loans->request($uid, 'anda', 100, 3);
            $this->fail('No debería poder pedir estando en el Clearing');
        } catch (UserError $e) {
            $this->assertSame('clearing', $e->errorCode);
        }

        // Gana 400 netos en un juego: se confisca el 50% para la deuda.
        $debtBefore = $this->app->loans->totalDebt($uid);
        $round = $this->app->casino->openRound($uid, 'ruleta', 100);
        $res = $this->app->casino->settle($round, 500, 'test');
        $this->assertSame(200, $res['confiscated']);
        $this->assertSame($debtBefore - 200, $this->app->loans->totalDebt($uid));
        $this->assertSame(1000 - 100 + 500 - 200, $this->app->wallet->balance($uid));

        // Paga todo: sale del Clearing.
        $this->app->wallet->credit($uid, 5000, 'admin');
        $this->app->loans->pay($uid, null, null);
        $this->assertSame(0, $this->app->loans->totalDebt($uid));
        $this->assertFalse($this->app->loans->inClearing($uid));
    }

    public function testLiquidityLimitsLoans(): void
    {
        $a = $this->player(1, 'Ana');
        $this->app->db->exec("UPDATE banks SET liquidity = 300 WHERE id = 'santander'");
        $this->assertSame(300, $this->app->loans->maxFor((int) $a->userId, 'santander'));
        $this->expectException(UserError::class);
        $this->app->loans->request((int) $a->userId, 'santander', 1000, 7);
    }

    public function testPromotionChangesRate(): void
    {
        $a = $this->player(1, 'Ana');
        $promo = $this->app->promos->configured()['brou_tasa_rebajada'];
        $this->app->promos->activate('brou_tasa_rebajada', $promo);
        $this->assertEqualsWithDelta(0.025, $this->app->loans->effectiveRate('brou', ['days' => 7, 'rate' => 0.05]), 1e-9);
        $this->assertStringContainsString('BROU', $this->lastChat(0));
        Clock::advance(25 * 3600);
        $this->assertEqualsWithDelta(0.05, $this->app->loans->effectiveRate('brou', ['days' => 7, 'rate' => 0.05]), 1e-9);
    }

    public function testChipsScaleWithBalance(): void
    {
        $this->assertSame([10], $this->app->casino->chipsFor(0));
        $this->assertSame([10, 25, 50, 100, 250, 500], $this->app->casino->chipsFor(2000));
        $this->assertSame([250, 500, 1000, 2500, 5000, 10000], $this->app->casino->chipsFor(1_000_000));
    }

    public function testOpenRoundsAreRefundedOnStart(): void
    {
        $a = $this->player(1, 'Ana');
        $uid = (int) $a->userId;
        $this->app->wallet->credit($uid, 100, 'admin');
        $this->app->casino->openRound($uid, 'blackjack', 60);
        $this->assertSame(40, $this->app->wallet->balance($uid));
        $this->assertSame(1, $this->app->casino->refundOpenRounds());
        $this->assertSame(100, $this->app->wallet->balance($uid));
    }
}
