<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Tests\Support\AppTestCase;
use Claudia\UserError;

final class JobsTest extends AppTestCase
{
    public function testApplyClaimAndCooldown(): void
    {
        $a = $this->player(1, 'Ana');
        $uid = (int) $a->userId;
        $this->app->jobs->setRandom(fn () => 0.99); // sin bonus ni despido
        $this->app->jobs->apply($uid, 'taxista', 'radiotaxi');
        $res = $this->app->jobs->claim($uid);
        $this->assertSame(200, $res['salary']);
        $this->assertSame(200, $this->app->wallet->balance($uid));
        try {
            $this->app->jobs->claim($uid);
            $this->fail('No debería poder cobrar dos veces');
        } catch (UserError $e) {
            $this->assertStringContainsString('Faltan', $e->getMessage());
        }
        Clock::advance(24 * 3600);
        $this->app->jobs->claim($uid);
        $this->assertGreaterThanOrEqual(400, $this->app->wallet->balance($uid));
    }

    public function testRequirementsAreChecked(): void
    {
        $a = $this->player(1, 'Ana');
        $this->expectException(UserError::class);
        $this->app->jobs->apply((int) $a->userId, 'policia', 'ministerio'); // pide 20 kills
    }

    public function testPromotionWithActivityAndSeniority(): void
    {
        $a = $this->player(1, 'Ana');
        $uid = (int) $a->userId;
        $this->app->jobs->setRandom(fn () => 0.99);
        $this->app->jobs->apply($uid, 'taxista', 'radiotaxi');
        Clock::advance(3 * 86400);
        $this->app->stats->add($uid, ['playtime' => 3 * 3600, 'kills' => 50]);
        $res = $this->app->jobs->claim($uid);
        $this->assertTrue($res['promoted']);
        $this->assertSame(1, $this->app->jobs->info($uid)['level']);
        // La actividad se reinicia al cobrar.
        $this->assertSame(0, (int) $this->app->jobs->employment($uid)['act_kills']);
    }

    public function testLowPerformanceGetsFiredAndRehireLowersLevel(): void
    {
        $a = $this->player(1, 'Ana');
        $uid = (int) $a->userId;
        $this->app->jobs->setRandom(fn () => 0.99);
        $this->app->jobs->apply($uid, 'taxista', 'radiotaxi');
        $this->app->db->exec('UPDATE employment SET level = 2 WHERE user_id = ?', [$uid]);

        $this->app->jobs->setRandom(fn () => 0.0); // rendimiento 0 y el sorteo de despido sale
        $res = $this->app->jobs->claim($uid);
        $this->assertTrue($res['fired']);
        $this->assertNull($this->app->jobs->employment($uid));

        // No puede volver enseguida.
        try {
            $this->app->jobs->apply($uid, 'taxista', 'app');
            $this->fail('Debería haber cooldown de recontratación');
        } catch (UserError) {
        }
        Clock::advance(25 * 3600);
        $r = $this->app->jobs->apply($uid, 'taxista', 'app');
        $this->assertSame(1, $r['level']); // estaba en nivel 2 (índice), vuelve a 1
    }

    public function testPerformanceMixesActivityAndRandom(): void
    {
        $this->app->jobs->setRandom(fn () => 0.5);
        $p = $this->app->jobs->performance(['act_playtime' => 0, 'act_kills' => 0, 'act_messages' => 0]);
        $this->assertEqualsWithDelta(0.175, $p, 0.001);
        $p = $this->app->jobs->performance(['act_playtime' => 7200, 'act_kills' => 0, 'act_messages' => 0]);
        $this->assertEqualsWithDelta(0.825, $p, 0.001);
    }
}
