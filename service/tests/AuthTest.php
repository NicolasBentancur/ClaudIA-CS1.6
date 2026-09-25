<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Tests\Support\AppTestCase;
use Claudia\UserError;

final class AuthTest extends AppTestCase
{
    public function testRegisterAndLogin(): void
    {
        $s = $this->player(1, 'Ana', 'clave123');
        $this->assertTrue($s->logged());
        $this->app->sessions->leave(1);

        Clock::advance(3600); // la reanudación ya expiró
        $s2 = $this->app->sessions->join(2, 'Ana', '10.0.0.9', '');
        $this->assertNull($this->app->sessions->takeResume('Ana', '10.0.0.9'));
        $this->app->auth->login($s2, 'clave123');
        $this->assertTrue($s2->logged());
    }

    public function testWrongPasswordAndLock(): void
    {
        $this->player(1, 'Ana', 'clave123');
        $this->app->sessions->leave(1);
        Clock::advance(3600);
        $s = $this->app->sessions->join(1, 'Ana', '10.0.0.1', '');
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->app->auth->login($s, 'mal');
            } catch (UserError $e) {
                $this->assertSame('bad_password', $e->errorCode);
            }
        }
        try {
            $this->app->auth->login($s, 'clave123');
            $this->fail('Debería estar bloqueado');
        } catch (UserError $e) {
            $this->assertSame('locked', $e->errorCode);
        }
        Clock::advance(121);
        $this->app->auth->login($s, 'clave123');
        $this->assertTrue($s->logged());
    }

    public function testNickIsCaseInsensitiveAndUnique(): void
    {
        $this->player(1, 'Ana');
        $s = $this->app->sessions->join(2, 'ANA', '10.0.0.2', '');
        $this->expectException(UserError::class);
        $this->app->auth->register($s, 'otra1234');
    }

    public function testDefaultNicksCannotRegister(): void
    {
        $s = $this->app->sessions->join(1, 'Player', '10.0.0.1', '');
        $this->expectException(UserError::class);
        $this->app->auth->register($s, 'clave123');
    }

    public function testSessionResumesAfterMapChangeFromSameIp(): void
    {
        $s = $this->player(1, 'Ana');
        $uid = $s->userId;
        $this->app->sessions->leaveAll(); // el plugin se reconecta en el cambio de mapa
        Clock::advance(20);
        $this->assertSame($uid, $this->app->sessions->takeResume('Ana', '10.0.0.1'));
        // Otra IP no reanuda.
        $this->app->sessions->join(1, 'Ana', '10.0.0.1', '');
        $this->app->sessions->leaveAll();
        $this->assertNull($this->app->sessions->takeResume('Ana', '99.9.9.9'));
    }

    public function testCommandsRequireLogin(): void
    {
        $s = $this->app->sessions->join(1, 'Visitante', '10.0.0.1', '');
        $this->cmd($s, 'saldo');
        $this->assertStringContainsString('loguearte', $this->lastChat(1));
        $this->cmd($s, 'ruleta');
        $this->assertStringContainsString('loguearte', $this->lastChat(1));
    }
}
