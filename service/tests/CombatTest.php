<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\Tests\Support\AppTestCase;

final class CombatTest extends AppTestCase
{
    private function coins(Session $s): int
    {
        return $this->app->wallet->balance((int) $s->userId);
    }

    private function give(Session $s, int $amount): void
    {
        $this->app->wallet->credit((int) $s->userId, $amount, 'admin');
    }

    private function kill(?Session $killer, ?Session $victim, string $weapon = 'ak47', bool $team = false): void
    {
        $k = $killer?->userId;
        $v = $victim?->userId;
        $this->app->combat->onKill($k, $v, $weapon, $killer === null || $k === $v, $team);
    }

    /** @return array{Session, Session} dos jugadores con un duelo de $amount aceptado */
    private function duel(int $amount = 1000): array
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->give($ana, 5000);
        $this->give($beto, 5000);
        $this->cmd($ana, "duelo Beto {$amount}");
        $this->cmd($beto, 'aceptar_duelo');
        return [$ana, $beto];
    }

    public function testDuelWinnerTakesDouble(): void
    {
        [$ana, $beto] = $this->duel();
        $this->assertSame(4000, $this->coins($ana));
        $this->assertSame(4000, $this->coins($beto));
        $this->kill($beto, $ana, 'deagle');
        $this->assertSame(4000, $this->coins($ana));
        $this->assertSame(6000, $this->coins($beto));
        $this->assertNull($this->app->combat->activeDuelOf((int) $ana->userId));
        $this->assertStringContainsString('ganó el duelo', $this->lastChat(Out::ALL));
    }

    public function testDuelCancelledByOtherDeathRefundsBoth(): void
    {
        [$ana, $beto] = $this->duel();
        $carlos = $this->player(3, 'Carlos');
        $this->kill($carlos, $ana);
        $this->assertSame(5000, $this->coins($ana));
        $this->assertSame(5000, $this->coins($beto));

        $this->cmd($ana, 'duelo Beto 500');
        $this->cmd($beto, 'aceptar_duelo');
        $this->kill($ana, $ana, 'world');   // suicidio
        $this->assertSame(5000, $this->coins($ana));
        $this->assertSame(5000, $this->coins($beto));
    }

    public function testDuelForfeitOnLeave(): void
    {
        [$ana, $beto] = $this->duel();
        $this->app->combat->onLeave((int) $ana->userId);
        $this->assertSame(4000, $this->coins($ana));
        $this->assertSame(6000, $this->coins($beto));
    }

    public function testDuelValidationAndPairCap(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->give($ana, 100);
        $this->cmd($ana, 'duelo Beto 50');
        $this->assertStringContainsString('no tiene', $this->lastChat(1));
        $this->cmd($ana, 'duelo Beto 30001');
        $this->assertStringContainsString('30.000', $this->lastChat(1));
        $this->cmd($beto, 'aceptar_duelo');
        $this->assertStringContainsString('pendiente', $this->lastChat(2));

        $this->give($beto, 100);
        for ($i = 0; $i < 5; $i++) {
            $this->cmd($ana, 'duelo Beto 1');
            $this->cmd($beto, 'aceptar_duelo');
            $this->kill($ana, $beto);
        }
        $this->cmd($ana, 'duelo Beto 1');
        $this->assertStringContainsString('5 duelos seguidos', $this->lastChat(1));

        Clock::freeze(Clock::now() + 3600);
        $this->cmd($ana, 'duelo Beto 1');
        $this->assertStringContainsString('Retaste', $this->lastChat(1));
    }

    public function testPendingDuelExpires(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->give($ana, 100);
        $this->give($beto, 100);
        $this->cmd($ana, 'duelo Beto 10');
        Clock::freeze(Clock::now() + 61);
        $this->app->combat->tick();
        $this->assertStringContainsString('no aceptó', $this->lastChat(1));
        $this->cmd($beto, 'aceptar_duelo');
        $this->assertSame(100, $this->coins($beto));
    }

    public function testOpenDuelsRefundedOnStart(): void
    {
        [$ana, $beto] = $this->duel();
        $this->app->combat->start();
        $this->assertSame(5000, $this->coins($ana));
        $this->assertSame(5000, $this->coins($beto));
    }

    public function testStreakRewardsAndReset(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        for ($i = 0; $i < 5; $i++) {
            $this->kill($ana, $beto);
        }
        $this->assertSame(35, $this->coins($ana));   // 2→5, 3→10, 5→20
        $this->assertSame(['kills' => 5, 'pot' => 35], $this->app->combat->streak((int) $ana->userId));
        $this->cmd($ana, 'racha');
        $this->assertStringContainsString('5', $this->lastChat(1));

        $this->kill($beto, $ana);
        $this->assertSame(['kills' => 0, 'pot' => 0], $this->app->combat->streak((int) $ana->userId));
        $this->assertSame(35, $this->coins($ana));   // sin cuchillo no le roban nada

        $this->kill($ana, $beto, 'ak47', true);       // team kill no suma
        $this->assertSame(0, $this->app->combat->streak((int) $ana->userId)['kills']);
    }

    public function testKnifeStealUsesTable(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        for ($i = 0; $i < 3; $i++) {
            $this->kill($ana, $beto);
        }
        $this->assertSame(15, $this->coins($ana));
        // 5% => 100%, 10% => 75%, 20% => 50%...: 10 cae en el tramo 75%.
        $this->app->combat->setRandom(fn (int $min, int $max): int => 10);
        $this->kill($beto, $ana, 'knife');
        $this->assertSame(4, $this->coins($ana));     // floor(15 * 0.75) = 11
        $this->assertSame(11, $this->coins($beto));
        $this->assertStringContainsString('robó', $this->lastChat(Out::ALL));
    }

    public function testKnifeStealMinimumOne(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->kill($ana, $beto);
        $this->kill($ana, $beto);                      // pozo 5
        $this->app->combat->setRandom(fn (int $min, int $max): int => 100);   // 10%
        $this->kill($beto, $ana, 'knife');
        $this->assertSame(1, $this->coins($beto));   // floor(0.5) → mínimo 1
    }

    public function testMvpRewardStreakAndKillerBonus(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->app->combat->setRandom(fn (int $min, int $max): int => $min);   // arma bonus: ak47
        $this->app->combat->roundStart();
        $this->kill($ana, $beto, 'usp');
        $this->kill($beto, $ana, 'usp');
        $this->kill($beto, $ana, 'usp');   // Beto 2 kills (racha 2: +5)
        $this->app->combat->roundEnd();
        $this->assertSame(25, $this->coins($beto));   // 5 + MVP 20

        $this->app->combat->roundStart();
        $this->kill($beto, $ana, 'usp');
        $this->app->combat->roundEnd();
        $this->assertSame(25 + 10 + 21, $this->coins($beto));   // racha 3 +10, MVP 20*1.025 = 20.5 → 21
        $this->assertSame(['current' => 2, 'best' => 2, 'isMvp' => true], $this->app->combat->mvpInfo((int) $beto->userId));

        $before = $this->coins($ana);
        $this->kill($ana, $beto, 'usp');
        $this->assertSame($before + 2, $this->coins($ana));
    }

    public function testWeaponBonus(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->app->combat->setRandom(fn (int $min, int $max): int => 2);   // awp
        $this->app->combat->roundStart();
        $this->assertSame('awp', $this->app->combat->weapon());
        $this->assertStringContainsString('awp', $this->lastChat(Out::ALL));
        $this->kill($ana, $beto, 'awp');
        $this->assertSame(5, $this->coins($ana));
        $this->kill($beto, $ana, 'ak47');
        $this->assertSame(0, $this->coins($beto));
    }

    public function testBounty(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $carlos = $this->player(3, 'Carlos');
        $this->give($ana, 1000);
        $this->give($carlos, 1000);

        $this->cmd($ana, 'bounty Beto 50');
        $this->assertStringContainsString('mínima', $this->lastChat(1));
        $this->cmd($ana, 'bounty Ana 200');
        $this->assertStringContainsString('vos mismo', $this->lastChat(1));
        $this->cmd($ana, 'bounty Beto 200');
        $this->cmd($carlos, 'bounty Beto 300');
        $this->assertSame(['target' => (int) $beto->userId, 'total' => 500], $this->app->combat->bounty());
        $this->cmd($carlos, 'bounty Ana 100');
        $this->assertStringContainsString('Ya hay una recompensa', $this->lastChat(3));

        $this->kill($carlos, $beto, 'ak47');   // no es a cuchillo
        $this->assertNotNull($this->app->combat->bounty());
        $this->kill($carlos, $beto, 'knife');
        $this->assertNull($this->app->combat->bounty());
        $this->assertSame(700 + 500 + 5, $this->coins($carlos));   // + racha de 2
        $this->cmd($ana, 'bounty');
        $this->assertStringContainsString('No hay ninguna', $this->lastChat(1));
    }
}
