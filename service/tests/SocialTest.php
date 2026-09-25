<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Players\Session;
use Claudia\Tests\Support\AppTestCase;

final class SocialTest extends AppTestCase
{
    private function couple(Session $a, Session $b): void
    {
        $this->cmd($a, 'pareja ' . $b->nick);
        $this->cmd($b, 'aceptar');
        $this->assertSame((int) $b->userId, $this->app->family->partnerOf((int) $a->userId));
    }

    private function marry(Session $a, Session $b): void
    {
        $this->couple($a, $b);
        $this->app->wallet->credit((int) $a->userId, 1500, 'admin');
        $this->cmd($a, 'comprar anillo');
        $this->cmd($a, 'casarse');
        $this->cmd($b, 'si');
        $this->assertTrue($this->app->family->isMarried((int) $a->userId));
    }

    public function testPartnershipRules(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $caro = $this->player(3, 'Caro');

        $this->cmd($ana, 'pareja Beto');
        $this->assertStringContainsString('te pide que seas su pareja', $this->lastChat(2));
        $this->cmd($beto, 'aceptar');
        $this->assertSame((int) $beto->userId, $this->app->family->partnerOf((int) $ana->userId));
        $this->assertStringContainsString('ahora son pareja', $this->lastChat(0));

        // No se puede tener dos parejas.
        $this->cmd($ana, 'pareja Caro');
        $this->assertStringContainsString('Ya tenés pareja', $this->lastChat(1));
        $this->cmd($caro, 'pareja Ana');
        $this->assertStringContainsString('ya tiene pareja', $this->lastChat(3));

        // Adoptar sin casarse: no.
        $this->cmd($ana, 'adoptar Caro');
        $this->assertStringContainsString('Solo las parejas casadas', $this->lastChat(1));

        $this->cmd($ana, 'terminar');
        $this->assertNull($this->app->family->partnerOf((int) $beto->userId));
        $this->assertCount(1, $this->app->family->exes((int) $ana->userId));
        $this->assertSame('Beto', $this->app->family->exes((int) $ana->userId)[0]['nick']);
    }

    public function testRejectAndExpiry(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->cmd($ana, 'pareja Beto');
        $this->cmd($beto, 'rechazar');
        $this->assertStringContainsString('te rechazó', $this->lastChat(1));
        $this->cmd($beto, 'aceptar');
        $this->assertStringContainsString('No tenés ninguna propuesta', $this->lastChat(2));

        $this->cmd($ana, 'pareja Beto');
        Clock::advance(200);
        $this->app->tick(Clock::now());
        $this->assertStringContainsString('no contestó', $this->lastChat(1));
        $this->cmd($beto, 'aceptar');
        $this->assertNull($this->app->family->partnerOf((int) $ana->userId));
    }

    public function testMutualProposalBecomesCouple(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->cmd($ana, 'pareja Beto');
        $this->cmd($beto, 'pareja Ana');
        $this->assertSame((int) $ana->userId, $this->app->family->partnerOf((int) $beto->userId));
    }

    public function testExPartnersAreCappedAtFive(): void
    {
        $ana = $this->player(1, 'Ana');
        for ($i = 0; $i < 7; $i++) {
            $p = $this->player(10 + $i, "Novio{$i}");
            $this->couple($ana, $p);
            Clock::advance(10);
            $this->cmd($ana, 'terminar');
        }
        $exes = $this->app->family->exes((int) $ana->userId);
        $this->assertCount(5, $exes);
        $this->assertSame('Novio6', $exes[0]['nick']);
    }

    public function testMarriageNeedsRingAndConsumesIt(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->couple($ana, $beto);
        $this->cmd($ana, 'casarse');
        $this->assertStringContainsString('anillo', $this->lastChat(1));

        $this->app->wallet->credit((int) $ana->userId, 2000, 'admin');
        $this->cmd($ana, 'comprar anillo');
        $this->assertSame(500, $this->app->wallet->balance((int) $ana->userId));
        $this->assertSame(1, $this->app->shop->count((int) $ana->userId, 'anillo'));

        $this->cmd($ana, 'casarse');
        $this->assertStringContainsString('/si', $this->lastChat(2));
        $this->cmd($beto, 'si');
        $this->assertTrue($this->app->family->isMarried((int) $beto->userId));
        $this->assertSame(0, $this->app->shop->count((int) $ana->userId, 'anillo'));
        $this->assertNotNull($this->app->family->currentFamily((int) $ana->userId));
    }

    public function testAdoptionSurnameAndTree(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $hijo = $this->player(3, 'Hijo');
        $hija = $this->player(4, 'Hija');
        $this->marry($ana, $beto);

        $this->cmd($ana, 'apellido Pereira');
        $this->cmd($ana, 'adoptar Hijo');
        $this->assertStringContainsString('te quieren adoptar', $this->lastChat(3));
        $this->cmd($hijo, 'si');
        $this->cmd($beto, 'adoptar Hija');
        $this->cmd($hija, 'si');

        $this->assertSame('Pereira', $this->app->family->surnameOf((int) $hijo->userId));
        $this->assertSame([(int) $ana->userId, (int) $beto->userId], $this->app->family->parentsOf((int) $hijo->userId));
        $this->assertSame([(int) $hija->userId], $this->app->family->siblingsOf((int) $hijo->userId));

        // El apellido nuevo les llega a todos los hijos.
        $this->cmd($beto, 'apellido Rodríguez');
        $this->assertSame('Rodríguez', $this->app->family->surnameOf((int) $hija->userId));
        $this->assertStringContainsString('Rodríguez', $this->lastChat(3));

        // Hermanos no pueden ser pareja.
        $this->cmd($hijo, 'pareja Hija');
        $this->assertStringContainsString('familia directa', $this->lastChat(3));

        // Nieto: Hijo se casa y adopta; Ana y Beto quedan como abuelos, Hija como tía.
        $nuera = $this->player(5, 'Nuera');
        $nieto = $this->player(6, 'Nieto');
        $this->marry($hijo, $nuera);
        $this->cmd($hijo, 'adoptar Nieto');
        $this->cmd($nieto, 'si');
        $tree = $this->app->family->tree((int) $nieto->userId);
        $this->assertEqualsCanonicalizing([(int) $ana->userId, (int) $beto->userId], $tree['grandparents']);
        $this->assertSame([(int) $hija->userId], $tree['uncles']);

        // Un nieto no puede adoptar a su abuela (ciclo).
        $prima = $this->player(7, 'Prima');
        $this->marry($hija, $prima);
        $this->cmd($hija, 'adoptar Ana');
        $this->assertStringContainsString('antepasado', $this->lastChat(4));

        // Primos: hijos de los tíos.
        $primo = $this->player(8, 'Primo');
        $this->cmd($hija, 'adoptar Primo');
        $this->cmd($primo, 'si');
        $this->assertSame([(int) $primo->userId], $this->app->family->cousinsOf((int) $nieto->userId));

        $this->events = [];
        $this->cmd($nieto, 'familia');
        $console = implode("\n", array_map(fn ($e) => $e['data']['text'], array_filter($this->events, fn ($e) => $e['data']['channel'] === 'console')));
        $this->assertStringContainsString('Abuelos: Ana, Beto', $console);
        $this->assertStringContainsString('Primos: Primo', $console);
    }

    public function testMaxChildrenAndLeaving(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->marry($ana, $beto);
        $kids = [];
        for ($i = 0; $i < 5; $i++) {
            $kids[] = $this->player(10 + $i, "Chico{$i}");
        }
        for ($i = 0; $i < 4; $i++) {
            $this->cmd($ana, 'adoptar ' . $kids[$i]->nick);
            $this->cmd($kids[$i], 'si');
        }
        $this->cmd($ana, 'adoptar Chico4');
        $this->assertStringContainsString('máximo', $this->lastChat(1));

        $this->cmd($kids[0], 'emancipar');
        $this->assertNull($this->app->family->childFamily((int) $kids[0]->userId));
        $this->cmd($beto, 'desheredar Chico1');
        $this->assertNull($this->app->family->childFamily((int) $kids[1]->userId));
        $this->assertCount(2, $this->app->family->childrenOf((int) $ana->userId));

        // Divorcio: los hijos siguen siendo de los dos, pero ya no pueden adoptar.
        $this->cmd($ana, 'terminar');
        $this->assertCount(2, $this->app->family->childrenOf((int) $beto->userId));
        $this->cmd($ana, 'adoptar Chico4');
        $this->assertStringContainsString('Solo las parejas casadas', $this->lastChat(1));

        $list = $this->app->family->familiesBySize();
        $this->assertSame(4, $list[0]['size']);
        $this->assertFalse($list[0]['married']);
    }

    public function testCupidKissAndYesNo(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->cmd($ana, 'formarpareja');
        $this->assertStringContainsString('/aceptar', $this->lastChat(0));
        $this->cmd($ana, 'aceptar');
        $this->assertNull($this->app->family->partnerOf((int) $ana->userId));
        $this->cmd($beto, 'aceptar');
        $this->assertSame((int) $beto->userId, $this->app->family->partnerOf((int) $ana->userId));

        // Cooldown de la celestina.
        $this->cmd($ana, 'formarpareja');
        $this->assertStringContainsString('hace poco', $this->lastChat(1));

        $this->cmd($ana, 'besar Beto');
        $this->assertStringContainsString('(1 besos)', $this->lastChat(0));
        $this->cmd($ana, 'besar Beto');
        $this->assertStringContainsString('Esperá', $this->lastChat(1));

        $this->cmd($ana, 'siono ¿va a llover?');
        $this->assertStringContainsString('Ana pregunta: ¿va a llover?', $this->lastChat(0));
    }

    public function testFactsIncludeFamilyAndGroup(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->marry($ana, $beto);
        $facts = implode("\n", $this->app->factsFor((int) $ana->userId));
        $this->assertStringContainsString('Casado/a con Beto', $facts);
    }
}
