<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Tests\Support\AppTestCase;

final class AiTest extends AppTestCase
{
    public function testMentionDetection(): void
    {
        $p = $this->app->policy;
        $this->assertTrue($p->mentions('che Claudia que onda'));
        $this->assertTrue($p->mentions('clau vení'));
        $this->assertTrue($p->mentions('@CLAUDIA!'));
        $this->assertFalse($p->mentions('la clausura del torneo'));
        $this->assertFalse($p->mentions('hola a todos'));
    }

    public function testRepliesToMentionAndStoresMemory(): void
    {
        $a = $this->player(1, 'Ana');
        $this->events = [];
        $this->gemini->queue[] = ['responder' => true, 'respuesta' => 'Qué querés, Ana?', 'pensamiento' => 'Ana es insistente', 'trato' => 'respetuosa', 'datos' => ['Es de Salto']];
        $this->app->chat->onMessage($a, 'hola claudia', false);
        $this->assertSame('Claudia: Qué querés, Ana?', $this->lastChat(0));
        $mem = $this->app->memory->forPrompt((int) $a->userId);
        $this->assertSame(['Ana es insistente'], $mem['pensamientos']);
        $this->assertSame(['Es de Salto'], $mem['datos']);
        $this->assertSame(['respetuosa'], $mem['tratos']);
        // El prompt lleva la memoria y el mensaje.
        $this->assertStringContainsString('ES QUIEN TE HABLA', $this->gemini->calls[0]['prompt']['user']);
        $this->assertStringContainsString('hola claudia', $this->gemini->calls[0]['prompt']['user']);
    }

    public function testNoMentionNoRequest(): void
    {
        $a = $this->player(1, 'Ana');
        $this->app->chat->onMessage($a, 'alguien juega dust2?', false);
        $this->assertCount(0, $this->gemini->calls);
    }

    public function testCooldownPerPlayer(): void
    {
        $a = $this->player(1, 'Ana');
        $b = $this->player(2, 'Beto');
        $this->gemini->queue = [$this->reply('uno'), $this->reply('dos'), $this->reply('tres')];
        $this->app->chat->onMessage($a, 'claudia hola', false);
        Clock::advance(10);
        $this->app->chat->onMessage($a, 'claudia otra vez', false);   // en cooldown
        $this->app->chat->onMessage($b, 'claudia soy beto', false);   // otro jugador sí
        $this->assertCount(2, $this->gemini->calls);
        Clock::advance(26);
        $this->app->chat->onMessage($a, 'claudia ahora sí', false);
        $this->assertCount(3, $this->gemini->calls);
    }

    public function testMentionsDuringCooldownExtendIt(): void
    {
        $a = $this->player(1, 'Ana');
        $this->gemini->queue = [$this->reply('uno'), $this->reply('dos')];
        $this->app->chat->onMessage($a, 'claudia', false);
        Clock::advance(20);
        $this->app->chat->onMessage($a, 'claudia', false); // bloqueada, pero cuenta como mención
        Clock::advance(20);
        $this->app->chat->onMessage($a, 'claudia', false); // 20 s desde la última mención: sigue bloqueada
        $this->assertCount(1, $this->gemini->calls);
    }

    public function testGlobalLimitPerMinute(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $s = $this->player($i, 'Jugador' . $i);
            $this->gemini->queue[] = $this->reply('ok ' . $i);
            $this->app->chat->onMessage($s, 'claudia', false);
        }
        $this->assertCount(5, $this->gemini->calls);
        Clock::advance(61);
        $s = $this->app->sessions->get(6);
        $this->app->chat->onMessage($s, 'claudia', false);
        $this->assertCount(6, $this->gemini->calls);
    }

    public function testRecentGameTriggersWithContext(): void
    {
        $a = $this->player(1, 'Ana');
        $this->app->recentGames->record((int) $a->userId, 'ruleta', 'Jugó a la ruleta: salió el 17 (negro). Apostó 100, perdió 100 URU Coins.', Clock::now());
        $this->gemini->queue[] = $this->reply('Te fundiste, ja');
        Clock::advance(5);
        $this->app->chat->onMessage($a, 'que mala suerte', false);
        $this->assertCount(1, $this->gemini->calls);
        $this->assertStringContainsString('salió el 17', $this->gemini->calls[0]['prompt']['user']);

        Clock::advance(30);
        $this->app->chat->onMessage($a, 'otra cosa', false);
        $this->assertCount(1, $this->gemini->calls); // pasaron más de 20 s
    }

    public function testFallbackChainAndAllFailMessage(): void
    {
        $a = $this->player(1, 'Ana');
        $this->gemini->queue = ['HTTP 429', 'HTTP 429', 'timeout'];
        $this->groq->queue = [$this->reply('Te contesto yo desde Groq')];
        $this->app->chat->onMessage($a, 'claudia', false);
        $this->assertCount(3, $this->gemini->calls);
        $this->assertSame('Claudia: Te contesto yo desde Groq', $this->lastChat(0));

        Clock::advance(30);
        $this->gemini->queue = ['x', 'x', 'x'];
        $this->groq->queue = ['x', 'x', 'x'];
        $this->app->chat->onMessage($a, 'claudia', false);
        $this->assertStringContainsString('sin cuota', $this->lastChat(0));
    }

    public function testCanDecideNotToAnswer(): void
    {
        $a = $this->player(1, 'Ana');
        $this->events = [];
        $this->gemini->queue[] = $this->reply('', false);
        $this->app->chat->onMessage($a, 'claudia', false);
        $this->assertSame([], $this->chats(0));
    }

    public function testLongRepliesAreTrimmedToMaxLines(): void
    {
        $a = $this->player(1, 'Ana');
        $this->events = [];
        $this->gemini->queue[] = $this->reply(str_repeat('bla ', 200));
        $this->app->chat->onMessage($a, 'claudia', false);
        $lines = $this->chats(0);
        $this->assertLessThanOrEqual(3, count($lines));
        foreach ($this->events as $e) {
            if ($e['type'] === 'print') {
                $this->assertLessThanOrEqual(190, strlen($e['data']['text']));
            }
        }
    }

    public function testSummarizerReplacesMemoryOverThreshold(): void
    {
        $a = $this->player(1, 'Ana');
        $uid = (int) $a->userId;
        for ($i = 0; $i < 60; $i++) {
            $this->app->memory->remember($uid, 'dato', str_repeat('palabra ', 60));
        }
        $this->assertGreaterThan(3000, $this->app->memory->wordCount($uid));
        $this->gemini->queue[] = ['resumen' => 'Ana es de Salto y le gusta el mate.'];
        $this->app->summarizer->tick();
        $all = $this->app->memory->all($uid);
        $this->assertCount(1, $all);
        $this->assertSame('resumen', $all[0]['kind']);
    }

    public function testDoesNotRespondWhenDisabled(): void
    {
        $a = $this->player(1, 'Ana');
        $this->config->set('ai.enabled', false);
        $this->app->chat->onMessage($a, 'claudia', false);
        $this->assertCount(0, $this->gemini->calls);
    }
}
